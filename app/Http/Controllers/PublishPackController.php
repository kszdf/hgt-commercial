<?php

namespace App\Http\Controllers;

use App\Exceptions\PipelineUnavailableException;
use App\Models\VideoJob;
use App\Services\PipelineClient;
use Illuminate\Http\Request;

/**
 * 发布包装：为成片（真人素材精剪产物 / 自动生成出片）一键生成
 * 标题 + 副标题 + 高级感封面（对标主流财税IP，拒绝简单堆砌）。
 * 代理 8500 /publish-pack：DeepSeek 标题/副标题 + make_cover 智能选帧封面。
 */
class PublishPackController extends Controller
{
    /** 容器路径 → 宿主绝对路径（8500 与宿主同机直接读文件）。 */
    private function hostPath(string $containerPath): string
    {
        $hostBase = rtrim((string) env('FOOTAGE_HOST_BASE', 'D:/heygem_data/hgt-commercial'), '/\\');
        return $hostBase . str_replace('/var/www', '', $containerPath);
    }

    public function generate(Request $request)
    {
        $data = $request->validate([
            'uuid'     => ['nullable', 'string', 'max:80'],      // 真人素材精剪产物
            'job_id'   => ['nullable', 'string', 'max:80'],      // 自动生成出片
            'text'     => ['nullable', 'string', 'max:4000'],
            'industry' => ['nullable', 'string', 'max:40'],
            'use_photo' => ['sometimes', 'boolean'],             // 用个人形象照做封面底图
        ]);

        $text = trim((string) ($data['text'] ?? ''));
        $videoHost = '';
        $coverPhoto = '';
        $portrait = $this->portraitPath($request->user()->tenant_id);

        if (! empty($data['use_photo']) && $portrait && is_file($portrait)) {
            $coverPhoto = $this->hostPath($portrait);
        }

        if (! empty($data['uuid'])) {
            // 精剪产物：storage/app/footage/{uuid}_edited.mp4
            $uuid = basename((string) $data['uuid']);
            $dir = storage_path('app/footage');
            $cand = $dir . '/' . $uuid . '_edited.mp4';
            if (is_file($cand)) {
                $videoHost = $this->hostPath($cand);
                if ($text === '') {
                    $text = $this->transcriptOf($uuid);
                }
            }
        } elseif (! empty($data['job_id'])) {
            $job = VideoJob::withTrashed()->where('job_id', $data['job_id'])->first();
            if ($job) {
                if ($text === '' && ! empty($job->dialogue)) {
                    $text = $job->dialogue;
                }
                $pipeline = rtrim((string) env('PYTHON_PIPELINE_URL', 'http://host.docker.internal:8500'), '/');
                $out = storage_path('..') . '/python-pipeline/jobs/' . basename((string) $data['job_id']) . '/out.mp4';
                if (is_file($out)) {
                    $videoHost = $this->hostPath($out);
                }
            }
        }

        if ($text === '' && $videoHost === '') {
            return response()->json(['error' => '缺少可用的文案或视频，请先上传/生成后再试'], 422);
        }

        try {
            $resp = app(PipelineClient::class)->post('/publish-pack', [
                'text'        => mb_substr($text, 0, 4000),
                'video_path'  => $videoHost,
                'cover_photo' => $coverPhoto,
                'industry'    => $data['industry'] ?? '财税',
                'brand'       => $this->tenantBrand($request),
            ], 240);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '包装服务暂不可用，请确认 8500 已重启加载最新代码'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '包装服务返回异常：' . substr((string) $resp->body(), 0, 200)], 502);
        }
        $r = $resp->json();
        if (empty($r['ok'])) {
            return response()->json(['error' => $r['error'] ?? '生成失败'], 422);
        }

        $coverName = $r['cover_path'] ? preg_replace('/^.*[\/\\\\]/', '', (string) $r['cover_path']) : '';
        return response()->json([
            'ok' => true,
            'title' => $r['title'] ?? '',
            'subtitle' => $r['subtitle'] ?? '',
            'cover_name' => $coverName,
        ]);
    }

    /** 租户品牌（settings.brand，用于封面水印/黑金兜底；默认昆山老张讲财税）。 */
    private function tenantBrand(Request $request): string
    {
        $tenant = $request->user()->isGlobalAdmin()
            ? \App\Models\Tenant::whereIn('plan', ['pro', 'enterprise'])->first()
            : $request->user()->tenant;
        $settings = $tenant ? (is_array($tenant->settings) ? $tenant->settings : []) : [];
        return trim((string) ($settings['brand'] ?? '')) ?: '昆山老张讲财税';
    }

    /** 个人形象照（海马体等专业肖像）路径：storage/app/covers/portrait/{tenant_id}.jpg */
    private function portraitPath($tenantId): string
    {
        return storage_path('app/covers/portrait/' . (int) $tenantId . '.jpg');
    }

    /** 上传个人形象照（≤10MB 图片），作为封面底图素材。 */
    public function uploadPhoto(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);
        $tenantId = $request->user()->tenant_id;
        if ($tenantId === null) {
            // 超管：借用第一个租户的目录存储（个人形象照按租户隔离）
            $tenantId = \App\Models\Tenant::whereIn('plan', ['pro', 'enterprise'])->value('id') ?? 1;
        }
        $dir = storage_path('app/covers/portrait');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $request->file('file');
        $target = $dir . '/' . (int) $tenantId . '.jpg';
        // 统一转成 JPG（PIL 风格：白底正装照直接可用，不改变构图）
        try {
            $img = \Intervention\Image\ImageManager::gd()->read($file->getRealPath());
            $img->orient()->encode('jpg', 90)->save($target);
        } catch (\Throwable $e) {
            // 无 Intervention 时退回原样存储
            $file->move($dir, (int) $tenantId . '.' . $file->extension());
        }
        if (! is_file($target) && ! is_file($dir . '/' . (int) $tenantId . '.' . $file->extension())) {
            return response()->json(['error' => '保存失败'], 500);
        }
        return response()->json(['ok' => true, 'name' => (int) $tenantId . '.jpg']);
    }

    /** 展示个人形象照。 */
    public function portrait(Request $request)
    {
        $tenantId = $request->user()->tenant_id ?? \App\Models\Tenant::whereIn('plan', ['pro', 'enterprise'])->value('id') ?? 1;
        $full = realpath(storage_path('app/covers/portrait/' . (int) $tenantId . '.jpg'));
        if (! $full || ! is_file($full)) {
            abort(404);
        }
        return response()->file($full, ['Content-Type' => 'image/jpeg']);
    }

    /** 精剪产物的字幕稿（readme 同目录 *.ass / 由会话带入，这里兜底读 ass 文本）。 */
    private function transcriptOf(string $uuid): string
    {
        $ass = storage_path('app/footage') . '/' . $uuid . '.ass';
        if (! is_file($ass)) {
            return '';
        }
        $lines = file($ass, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $out = [];
        foreach (array_slice($lines ?: [], -40) as $ln) {
            if (str_starts_with($ln, 'Dialogue:')) {
                $parts = explode(',', $ln, 10);
                if (count($parts) === 10) {
                    $out[] = preg_replace('/\{[^}]*\}/', '', $parts[9]);
                }
            }
        }
        return implode('', $out);
    }

    /** 服务封面图（仅允许 footage / jobs 目录下 *_pack_cover.*）。 */
    public function cover(string $file)
    {
        $name = basename($file);
        $dirs = [storage_path('app/footage'), storage_path('..') . '/python-pipeline/jobs'];
        foreach ($dirs as $d) {
            $full = realpath($d . '/' . $name);
            if ($full && is_file($full) && str_contains($full, '_pack_cover.')) {
                return response()->file($full, ['Content-Type' => 'image/jpeg']);
            }
        }
        abort(404);
    }
}
