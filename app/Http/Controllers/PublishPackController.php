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
        // 2026-09-01 加固：JSON body 若带 BOM（utf-8-sig）Laravel 解析为空，手动清理后回填
        if (empty($data) && ! empty($request->getContent())) {
            $raw = ltrim($request->getContent(), "\xEF\xBB\xBF");
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $data = array_merge($data, $parsed);
                $request->merge($parsed);
            }
        }

        $text = trim((string) ($data['text'] ?? ''));
        $videoHost = '';
        $coverPhoto = '';
        // 超管(tenant_id=null)回退 pro/enterprise 租户上下文，避免 portraitPath(null) 语义错误
        $portrait = $this->portraitPath($this->studioTenant($request)->id);

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
                // 行业贯穿：出片任务的老板行业（选题→二创→出片→包装同一口径）
                if (empty($data['industry']) && ! empty($job->industry)) {
                    $data['industry'] = $job->industry;
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

        // 2026-09-01 修复：8500 返回 Windows 反斜杠路径，容器为 Linux，
        // basename() 不认 '\'。先统一为 '/' 再取文件名（否则 cover_name 带子目录 → 前端 404）
        $coverName = $r['cover_path'] ? basename(str_replace('\\', '/', (string) $r['cover_path'])) : '';
        return response()->json([
            'ok' => true,
            'title' => $r['title'] ?? '',
            'subtitle' => $r['subtitle'] ?? '',
            'cover_name' => $coverName,
        ]);
    }

    /** 租户品牌（settings.brand 旧通道 → tenants.ip_name 新字段；默认昆山老张讲财税）。 */
    private function tenantBrand(Request $request): string
    {
        $tenant = $request->user()->isGlobalAdmin()
            ? \App\Models\Tenant::whereIn('plan', ['pro', 'enterprise'])->first()
            : $request->user()->tenant;
        $settings = $tenant ? (is_array($tenant->settings) ? $tenant->settings : []) : [];
        $brand = trim((string) ($settings['brand'] ?? ''));
        return $brand ?: trim((string) ($tenant->ip_name ?? '')) ?: '昆山老张讲财税';
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

    /**
     * 服务封面图（仅允许 footage / jobs / portrait 目录下 *_pack_cover.*）。
     * 封面产物位置：
     *   - jobs/{jobId}/out_pack_cover.jpg   （出片产物）
     *   - footage/{uuid}_edited_pack_cover.jpg （精剪产物）
     *   - covers/portrait/portrait_pack_cover.jpg （形象照封面，2026-09-02 修复：此前漏查此目录
     *     导致"上传形象照生成封面后下载 404"）
     */
    public function cover(string $file)
    {
        $name = basename($file);
        if (! str_contains($name, '_pack_cover.')) {
            abort(404);
        }
        // 候选根目录：footage（精剪产物） + jobs（出片产物） + portrait（形象照封面）
        $roots = [
            storage_path('app/footage'),
            storage_path('..') . '/python-pipeline/jobs',
            storage_path('app/covers/portrait'),
        ];
        foreach ($roots as $root) {
            // 1) 直接命中（footage / portrait 产物在根目录）
            $direct = realpath($root . '/' . $name);
            if ($direct && is_file($direct)) {
                return response()->file($direct, ['Content-Type' => 'image/jpeg']);
            }
            // 2) jobs/{jobId}/ 子目录命中（出片产物）
            if (is_dir($root)) {
                foreach (glob($root . '/*/' . $name) ?: [] as $cand) {
                    $full = realpath($cand);
                    if ($full && is_file($full)) {
                        return response()->file($full, ['Content-Type' => 'image/jpeg']);
                    }
                }
            }
        }
        abort(404);
    }

    /**
     * 人工发布物料包（2026-08-28）：成片视频 + 封面 + 分平台发布文案，打包 zip 下载。
     * 各平台无开放接口（或未授权）时，用户下载物料包后到 App 手工上传。
     */
    public function material(Request $request, string $jobId)
    {
        $jobId = basename($jobId);
        $user = $request->user();
        $tenant = $user->isGlobalAdmin()
            ? \App\Models\Tenant::whereIn('plan', ['pro', 'enterprise'])->first()
            : $user->tenant;
        $job = VideoJob::withTrashed()->where('job_id', $jobId)->first();
        if (! $job || (! $user->isGlobalAdmin() && $job->tenant_id != ($tenant->id ?? 0))) {
            return response()->json(['error' => '视频不存在或无权访问'], 404);
        }
        // 1) 成片视频（容器内 /var/www/python-pipeline/jobs/... 可读）
        $jobsDir = storage_path('..') . '/python-pipeline/jobs/' . $jobId;
        $video = null;
        foreach (['out.edited.mp4', 'out.mp4'] as $cand) {
            $p = realpath($jobsDir . '/' . $cand);
            if ($p && is_file($p)) { $video = $p; break; }
        }
        if (! $video) {
            return response()->json(['error' => '成片文件不存在（可能渲染未完成）'], 404);
        }
        // 2) 标题/副标题：不调 AI（秒级打包）——用任务已有标题；副标题用对话首句实义前 20 字
        $title = $job->title ?: '财税干货';
        $subtitle = '';
        $firstLine = trim((string) preg_replace('/^(女|男)\s*[：:]\s*/u', '', (string) $job->dialogue));
        if ($firstLine) {
            $subtitle = mb_substr($firstLine, 0, 20);
        }
        // 3) 封面（make_cover 产物，若存在）
        $cover = null;
        foreach (glob($jobsDir . '/*_pack_cover.*') ?: [] as $c) {
            if (is_file($c)) { $cover = $c; break; }
        }
        // 4) 分平台发布文案
        $ip = $tenant->ip_name ?: '昆山老张讲财税';
        $txt = $this->materialCopy($title, $subtitle, $ip);
        // 5) 打包 zip
        $dir = storage_path('app/material');
        if (! is_dir($dir)) { mkdir($dir, 0755, true); }
        $zipFile = $dir . '/' . $jobId . '.zip';
        @unlink($zipFile);
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE) !== true) {
            return response()->json(['error' => '打包失败（服务器无 zip 支持）'], 500);
        }
        $safeName = preg_replace('/[\\\\\/:*?"<>|]/', '_', $title) ?: '成片';
        $zip->addFile($video, $safeName . '.mp4');
        if ($cover) { $zip->addFile($cover, '封面.jpg'); }
        $zip->addFromString('发布文案.txt', $txt);
        $zip->close();
        $dlName = '发布物料_' . $safeName . '.zip';
        return response()->download($zipFile, $dlName)->deleteFileAfterSend(true);
    }

    /** 分平台发布文案模板（抖音/小红书/视频号）。 */
    private function materialCopy(string $title, string $subtitle, string $ip): string
    {
        $sub = $subtitle ?: '';
        $tags = "#税务风险 #老板必看 #财税干货 #{$ip}";
        $lines = [
            "【发布物料 · {$ip}】",
            "成片视频：同目录成片.mp4（或 封面.jpg 作封面图）",
            '',
            '━━━━ 抖音 ━━━━',
            '标题：' . mb_substr($title, 0, 30),
            '描述：' . ($sub ? mb_substr($sub, 0, 200) : mb_substr($title, 0, 100)),
            '话题：' . $tags,
            '发布：创作者中心 → 发布作品 → 选成片/封面 → 粘贴标题描述话题 → 勾选「声明原创」',
            '',
            '━━━━ 小红书 ━━━━',
            '标题：' . mb_substr($title, 0, 20),
            '正文：' . ($sub ? mb_substr($sub, 0, 180) : mb_substr($title, 0, 80)) . "\n" . '有同样问题的老板，评论区聊聊。' . "\n" . $tags,
            '发布：底部「+」→ 选成片 → 粘贴标题正文话题 → 封面选清晰一帧',
            '',
            '━━━━ 视频号 ━━━━',
            '标题/描述：' . mb_substr($title, 0, 30) . ($sub ? ' ' . mb_substr($sub, 0, 80) : ''),
            '发布：微信「发现 → 视频号 → 发表视频」→ 选成片填描述',
            '',
            '提示：封面也可在「视频库 → 发布包装」生成后，从包装弹窗下载。',
        ];
        return implode("\n", $lines);
    }
}
