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

    /** 供出片页下拉拉取可用模特（仅 ready）。 */
    public function modelsJson()
    {
        $tenant = $this->studioTenant(request());
        $assets = ModelAsset::where('tenant_id', $tenant->id)
            ->where('status', 'ready')
            ->get(['id', 'name', 'scene', 'resolution', 'duration']);
        return response()->json(['ok' => true, 'models' => $assets]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'file'  => ['required', 'file', 'mimes:mp4,mov,webm', 'max:204800'], // ≤200MB
            'name'  => ['nullable', 'string', 'max:60'],
            'scene' => ['nullable', 'string', 'max:40'],
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

    /** 预览（内联播放）。 */
    public function preview(ModelAsset $modelAsset)
    {
        $this->authorizeTenant($modelAsset);
        $containerPath = $this->hostStorageToContainer($modelAsset->preview_path ?? '');
        if (! $containerPath || ! file_exists($containerPath)) {
            abort(404);
        }
        return Response::file($containerPath);
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
        $request->validate([
            'file' => ['required', 'file', 'mimes:mp4,mov,webm', 'max:204800'],
        ]);
        $tenant = $request->user()->tenant;
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
