<?php

namespace App\Http\Controllers;

use App\Exceptions\PipelineUnavailableException;
use App\Models\ModelAsset;
use App\Services\PipelineClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;

/**
 * 用户自传数字人模特素材管理：上传 / 列表 / 预览 / 删除 / 重新上传。
 * 上传经 8500 /process-asset 转码(静音化)+双写(HEYGEM 渲染 + Laravel 预览)+ asset QC。
 *
 * 路径说明（混合云架构）：Laravel 跑在 Docker 容器，8500 微服务跑在宿主 Windows。
 *  - 容器内 storage_path() 是 /var/www/... ，宿主对应 D:/heygem_data/hgt-commercial/...
 *  - 写库统一存「宿主路径」(8500 返回即宿主路径)，使用时再按需转换：
 *      containerPath()    : file_path(宿主 face2face) -> /code/data/...  (HEYGEM 容器可读)
 *      hostStorageToContainer(): preview_path(宿主 storage) -> /var/www/... (本容器可读)
 */
class ModelAssetController extends Controller
{
    private function pipelineUrl(): string
    {
        return env('PYTHON_PIPELINE_URL', 'http://host.docker.internal:8500');
    }

    /** 宿主项目根（. 挂载到容器 /var/www）。 */
    private function hostRoot(): string
    {
        return rtrim(str_replace('\\', '/', env('HOST_PROJECT_ROOT', 'D:/heygem_data/hgt-commercial')), '/');
    }

    /** 容器路径 -> 宿主路径（发给 8500 处理）。 */
    private function containerToHost(string $containerPath): string
    {
        $p = str_replace('\\', '/', $containerPath);
        if (str_starts_with($p, '/var/www')) {
            return $this->hostRoot() . substr($p, strlen('/var/www'));
        }
        return $p;
    }

    /** 宿主 storage 路径 -> 本容器可读路径（bind mount）。 */
    private function hostStorageToContainer(string $hostPath): string
    {
        $p = str_replace('\\', '/', $hostPath);
        $root = $this->hostRoot();
        if (str_starts_with($p, $root)) {
            return '/var/www' . substr($p, strlen($root));
        }
        return $p;
    }

    /** 列表 + 上传表单。 */
    public function index()
    {
        $tenant = $this->studioTenant(request());
        $assets = ModelAsset::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->get();
        return view('studio.models', compact('assets'));
    }

    /** 供出片页下拉拉取可用模特（仅 ready）；默认出镜的排最前，出片时自动选中。 */
    public function modelsJson()
    {
        $tenant = $this->studioTenant(request());
        $assets = ModelAsset::where('tenant_id', $tenant->id)
            ->where('status', 'ready')
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get(['id', 'name', 'scene', 'resolution', 'duration', 'is_default', 'source_type']);
        return response()->json(['ok' => true, 'models' => $assets]);
    }

    /**
     * 设为默认出镜数字人：客户用自己的视频建好专属形象后，出片不再每次手选。
     * 同一租户内唯一——设置新默认时先把旧的清掉。
     */
    public function setDefault(Request $request, ModelAsset $modelAsset)
    {
        $this->authorizeTenant($modelAsset);
        if ($modelAsset->status !== 'ready') {
            return redirect()->route('studio.models')
                ->with('error', '该素材质检未通过，不能设为默认出镜。');
        }
        ModelAsset::where('tenant_id', $modelAsset->tenant_id)->update(['is_default' => false]);
        $modelAsset->update(['is_default' => true]);

        return redirect()->route('studio.models')
            ->with('success', '已设为默认出镜数字人：' . $modelAsset->name . '，之后出片会自动用它。');
    }

    public function store(Request $request)
    {
        // 上限 96MB：受中转链路带宽约束（服务器出网约 3Mbps，96MB 约需 5 分钟；
        // 2026-10-05 起 Cloudflare 已撤出链路，不再是 CF 的 100MB 硬限在卡）。
        // 与 php.ini / nginx / 前端提示四处保持一致。
        $data = $request->validate([
            'file'  => ['required', 'file', 'mimes:mp4,mov,webm', 'max:98304'], // ≤96MB
            'name'  => ['nullable', 'string', 'max:60'],
            'scene' => ['nullable', 'string', 'max:40'],
        ], [
            'file.max' => '模特视频超过 96MB 上限（大文件经中转上传较慢），请先压缩后再上传。',
        ]);

        $user = $request->user();
        $tenant = $this->studioTenant(request());
        $file = $request->file('file');
        $ext = $file->getClientOriginalExtension();
        $rawRel = $file->storeAs('models', '_raw_' . uniqid() . '.' . $ext);
        $rawPath = \Illuminate\Support\Facades\Storage::disk('local')->path($rawRel); // 真实落盘路径（local 磁盘根含 private）

        // 发给 8500 的是「宿主路径」，否则宿主进程读不到
        \Illuminate\Support\Facades\Log::info('MODEL_ASSET_RAW', [
            'rawPath' => $rawPath,
            'exists_container' => file_exists($rawPath) ? 'YES' : 'NO',
            'realpath' => realpath($rawPath) ?: 'false',
        ]);
        try {
            $resp = app(PipelineClient::class)->post('/process-asset', [
                'file_path' => $this->containerToHost($rawPath),
                'tenant_id' => $tenant->id,
            ], 300);
        } catch (PipelineUnavailableException $e) {
            @unlink($rawPath);
            return redirect()->back()->with('error', '出片服务暂时不可用，请稍后重试（' . $e->getMessage() . '）');
        }
        \Illuminate\Support\Facades\Log::info('MODEL_ASSET_PROC', [
            'url' => $this->pipelineUrl() . '/process-asset',
            'status' => $resp->status(),
            'ok' => $resp->json('ok'),
            'body' => substr($resp->body(), 0, 400),
            'sent_path' => $this->containerToHost($rawPath),
        ]);

        if (! $resp->successful() || ! ($resp->json('ok') ?? false)) {
            @unlink($rawPath);
            return redirect()->back()->with('error', '素材处理失败：' . ($resp->json('error') ?? '服务不可用'));
        }
        $r = $resp->json();
        $qc = $r['qc'] ?? [];
        $status = ($qc['status'] ?? 'passed') === 'blocked' ? 'rejected' : 'ready';
        $previewContainer = $this->hostStorageToContainer($r['preview_path'] ?? '');

        ModelAsset::create([
            'tenant_id'    => $tenant->id,
            'user_id'      => $user->id,
            'name'         => $data['name'] ?: $file->getClientOriginalName(),
            'scene'        => $data['scene'] ?? null,
            'file_path'    => $r['file_path'] ?? null,   // 宿主 face2face 路径
            'preview_path' => $r['preview_path'] ?? null, // 宿主 storage 路径
            'size'         => (int) (@filesize($previewContainer) ?: 0),
            'duration'     => $r['duration'] ?? null,
            'resolution'   => $r['resolution'] ?? null,
            'status'       => $status,
            'qc_result'    => $qc,
        ]);

        @unlink($rawPath);
        return redirect()->route('studio.models')->with('success',
            $status === 'ready' ? '上传成功，素材已通过质检可用。' : '上传完成，但质检未通过（' . ($qc['level'] ?? '') . '），暂不可用于出片。');
    }

    /**
     * 单图口播：上传一张照片 -> 自动生成"照片数字人"。
     *
     * 原理：HEYGEM 只吃 video_url，故先调 8500 /photo-model 把照片转成一段稳定竖屏微动视频，
     * 落 model_assets（source_type=photo），出片时与视频数字人一样用（HEYGEM 会重绘嘴部+面部）。
     */
    public function photoStore(Request $request)
    {
        $data = $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:20480'], // ≤20MB
            'name'  => ['nullable', 'string', 'max:60'],
            'still' => ['nullable', 'boolean'],
        ], [
            'photo.max' => '照片超过 20MB 上限，请压缩后再上传。',
        ]);

        $user = $request->user();
        $tenant = $this->studioTenant($request);
        $file = $request->file('photo');
        $ext = $file->getClientOriginalExtension() ?: 'jpg';

        // 原始照片落 storage（便于回看/重建），并保留一份在项目 storage 供预览
        $photoRel = $file->storeAs('models/photos', '_src_' . uniqid() . '.' . $ext);
        $photoPath = \Illuminate\Support\Facades\Storage::disk('local')->path($photoRel); // 容器真实路径

        try {
            $resp = app(PipelineClient::class)->post('/photo-model', [
                'photo_path' => $this->containerToHost($photoPath),
                'name'       => 'photo_' . $tenant->id . '_' . uniqid(),
                'duration'   => 6,
                'still'      => (bool) ($data['still'] ?? false),
            ], 300);
        } catch (PipelineUnavailableException $e) {
            return redirect()->back()->with('error', '出片服务暂时不可用，请稍后重试（' . $e->getMessage() . '）');
        }

        if (! $resp->successful() || ! ($resp->json('ok') ?? false)) {
            return redirect()->back()->with('error', '照片生成数字人失败：' . ($resp->json('error') ?? '服务不可用'));
        }
        $r = $resp->json();
        $videoHost   = $r['video_path'] ?? null;    // 宿主 face2face 路径（HEYGEM 出片用；containerPath() 映射为 /code/data）
        $previewHost = $r['preview_path'] ?? '';    // 宿主项目 storage 路径（容器可读，内联预览用；由 8500 拷贝生成）
        if (! $previewHost) {
            $previewHost = $videoHost;              // 兜底
        }
        $sizeContainer = $this->hostStorageToContainer($previewHost);

        // 生成的微动视频再做一次技术质检（音轨/画幅/时长），与上传视频同一把关口径
        $qc = [];
        try {
            $qcResp = app(PipelineClient::class)->post('/qc-asset', [
                'file_path' => $videoHost,
            ], 120);
            if ($qcResp->successful()) {
                $qc = $qcResp->json() ?: [];
            }
        } catch (\Throwable $e) {
            $qc = [];
        }
        $status = ($qc['status'] ?? 'passed') === 'blocked' ? 'rejected' : 'ready';

        ModelAsset::create([
            'tenant_id'    => $tenant->id,
            'user_id'      => $user->id,
            'name'         => $data['name'] ?: ('照片数字人 ' . now()->format('m-d H:i')),
            'scene'        => 'photo',
            'source_type'  => 'photo',
            'source_photo' => $this->containerToHost($photoPath),
            'file_path'    => $videoHost,
            'preview_path' => $previewHost,
            'size'         => (int) (@filesize($sizeContainer) ?: 0),
            'duration'     => $r['duration'] ?? null,
            'resolution'   => ($r['width'] ?? 1080) . 'x' . ($r['height'] ?? 1920),
            'status'       => $status,
            'qc_result'    => $qc,
        ]);

        return redirect()->route('studio.models')->with('success',
            $status === 'ready'
                ? '照片数字人已生成，可直接出片讲稿。建议到「我的数字人」把它设为默认出镜。'
                : '照片已处理，但质检未通过（' . ($qc['level'] ?? '') . '），暂不可用于出片。');
    }

    /** 预览（内联播放）。 */
    public function preview(ModelAsset $modelAsset)
    {
        $this->authorizeTenant($modelAsset);
        $containerPath = $this->hostStorageToContainer($modelAsset->preview_path ?? '');
        if ($containerPath && file_exists($containerPath)) {
            return Response::file($containerPath);
        }
        // 兜底：预览文件不在项目 storage（如照片数字人的 face2face 路径，容器读不到）→ 经 8500 代理回传
        $hostPath = str_replace('\\', '/', $modelAsset->file_path ?: $modelAsset->preview_path ?: '');
        if ($hostPath) {
            try {
                $resp = app(PipelineClient::class)->get('/asset-file?path=' . urlencode($hostPath), 60);
                if ($resp->successful()) {
                    return response($resp->body(), 200)
                        ->header('Content-Type', 'video/mp4')
                        ->header('Accept-Ranges', 'none');
                }
            } catch (\Throwable $e) {
                // 落到 404
            }
        }
        abort(404);
    }

    public function destroy(ModelAsset $modelAsset)
    {
        $this->authorizeTenant($modelAsset);
        // 宿主文件由 8500 清理（容器无法直接删宿主文件）
        $hostPaths = array_values(array_filter([$modelAsset->file_path, $modelAsset->preview_path]));
        if ($hostPaths) {
            try {
                Http::timeout(30)->post($this->pipelineUrl() . '/delete-asset', ['paths' => $hostPaths]);
            } catch (\Throwable $e) {
                // 忽略清理失败，记录仍删除
            }
        }
        $modelAsset->delete();
        return redirect()->route('studio.models')->with('success', '素材已删除。');
    }

    /** 重新上传（保留名称/场景，替换文件）。 */
    public function reupload(Request $request, ModelAsset $modelAsset)
    {
        $this->authorizeTenant($modelAsset);
        // 上限 96MB（同 store()，受中转链路带宽约束；Cloudflare 已于 2026-10-05 撤出）
        $request->validate([
            'file' => ['required', 'file', 'mimes:mp4,mov,webm', 'max:98304'],
        ], [
            'file.max' => '模特视频超过 96MB 上限（大文件经中转上传较慢），请先压缩后再上传。',
        ]);
        // 超管(tenant_id=null)回退 pro/enterprise 租户作为操作上下文，避免 tenant_id 为 null 传给出片管线
        $tenant = $this->studioTenant($request);
        $file = $request->file('file');
        $ext = $file->getClientOriginalExtension();
        $rawRel = $file->storeAs('models', '_raw_' . uniqid() . '.' . $ext);
        $rawPath = \Illuminate\Support\Facades\Storage::disk('local')->path($rawRel);

        try {
            $resp = app(PipelineClient::class)->post('/process-asset', [
                'file_path' => $this->containerToHost($rawPath),
                'tenant_id' => $tenant->id,
            ], 300);
        } catch (PipelineUnavailableException $e) {
            @unlink($rawPath);
            return redirect()->back()->with('error', '出片服务暂时不可用，请稍后重试（' . $e->getMessage() . '）');
        }
        if (! $resp->successful() || ! ($resp->json('ok') ?? false)) {
            @unlink($rawPath);
            return redirect()->back()->with('error', '重新处理失败。');
        }
        $r = $resp->json();
        $qc = $r['qc'] ?? [];
        $status = ($qc['status'] ?? 'passed') === 'blocked' ? 'rejected' : 'ready';
        $previewContainer = $this->hostStorageToContainer($r['preview_path'] ?? '');

        $modelAsset->update([
            'file_path'    => $r['file_path'] ?? $modelAsset->file_path,
            'preview_path' => $r['preview_path'] ?? $modelAsset->preview_path,
            'size'         => (int) (@filesize($previewContainer) ?: 0),
            'duration'     => $r['duration'] ?? null,
            'resolution'   => $r['resolution'] ?? null,
            'status'       => $status,
            'qc_result'    => $qc,
        ]);
        @unlink($rawPath);
        return redirect()->route('studio.models')->with('success', '已重新上传并质检。');
    }

    private function authorizeTenant(ModelAsset $asset): void
    {
        if (! request()->user()->isGlobalAdmin() && $asset->tenant_id !== request()->user()->tenant_id) {
            abort(403);
        }
    }
}
