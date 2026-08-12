<?php

namespace App\Http\Controllers;

use App\Exceptions\PipelineUnavailableException;
use App\Models\VideoJob;
use App\Models\TenantVoice;
use App\Services\PipelineClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * 视频出片：代理到 Windows 宿主上的 Python 微服务。
 * 服务地址经 host.docker.internal 访问（见 .env PYTHON_PIPELINE_URL）。
 * 支持两种模式：scroll（滚动字幕卡·不出镜） / avatar（本地数字人出镜）。
 */
class VideoController extends Controller
{
    private function pipelineUrl(): string
    {
        return env('PYTHON_PIPELINE_URL', 'http://host.docker.internal:8500');
    }

    public function showScroll()
    {
        $tenant = request()->user()->tenant;
        $maleVoices = TenantVoice::where('tenant_id', $tenant->id)
            ->where('gender', 'male')->where('status', 'ready')
            ->orderByDesc('is_default')->orderByDesc('created_at')
            ->get(['id', 'name', 'voice_id', 'is_default']);
        $femaleVoices = TenantVoice::where('tenant_id', $tenant->id)
            ->where('gender', 'female')->where('status', 'ready')
            ->orderByDesc('is_default')->orderByDesc('created_at')
            ->get(['id', 'name', 'voice_id', 'is_default']);
        return view('studio.scroll', compact('maleVoices', 'femaleVoices'));
    }

    public function generate(Request $request)
    {
        $user = $request->user();
        $tenant = $user->tenant;

        // —— 试用到期拦截（未订阅则禁止继续出片）——
        if ($tenant->isTrialExpired()) {
            return response()->json([
                'error' => '免费试用已结束，请升级订阅套餐后继续生成视频。',
                'code' => 'trial_expired',
            ], 402);
        }

        // —— 配额拦截（计费基础）——
        if ($tenant->isOverQuota()) {
            return response()->json([
                'error' => '本月生成额度已用完，请升级套餐或下月继续使用。',
                'code' => 'quota_exceeded',
                'usage' => $tenant->usageThisMonth(),
                'quota' => $tenant->quota_monthly,
            ], 402);
        }

        $data = $request->validate([
            'mode' => ['sometimes', 'in:scroll,avatar'],
            'dialogue' => ['required', 'string'],
            'title' => ['nullable', 'string', 'max:20'],
            'subtitle' => ['nullable', 'string', 'max:40'],
            // 分声线感情/快慢（可选；不传则用脚本默认值）
            'male_rate' => ['sometimes', 'numeric', 'between:0.5,2.0'],
            'female_rate' => ['sometimes', 'numeric', 'between:0.5,2.0'],
            'male_pitch' => ['sometimes', 'numeric', 'between:0.5,2.0'],
            'female_pitch' => ['sometimes', 'numeric', 'between:0.5,2.0'],
            'male_vol' => ['sometimes', 'integer', 'between:0,100'],
            'female_vol' => ['sometimes', 'integer', 'between:0,100'],
            'subtitle_size' => ['sometimes', 'integer', 'between:48,140'],
            'subtitle_lines' => ['sometimes', 'integer', 'in:1,2,3'],
            'subtitle_outline' => ['sometimes', 'integer', 'between:0,10'],
            'subtitle_position' => ['sometimes', 'string', 'in:bottom,center'],
            'natural' => ['sometimes', 'boolean'],
            'model' => ['nullable', 'string', 'max:120'],
            'scene' => ['nullable', 'string', 'max:40', 'in:office_a,office_b'],
            'cover_id' => ['nullable', 'integer', 'exists:cover_assets,id'],
            'male_voice' => ['nullable', 'string', 'max:120'],
            'female_voice' => ['nullable', 'string', 'max:120'],
            'voice_form' => ['sometimes', 'nullable', 'string', 'in:dialogue,male_mono,female_mono,mono'],
            'batch_id' => ['nullable', 'string', 'max:36'],
        ]);

        // —— 单次时长上限（后端硬约束）——
        // 试用期内更严格：单条 ≤ TRIAL_MAX_DURATION_SEC（默认 600 秒 = 10 分钟）；
        // 已订阅套餐放宽到 MAX_VIDEO_DURATION_SEC（默认 1800 秒 = 30 分钟）。
        $estSec = $this->estimateDurationSec($data['dialogue']);
        $maxDuration = $tenant->isTrialActive()
            ? (int) env('TRIAL_MAX_DURATION_SEC', 600)
            : (int) env('MAX_VIDEO_DURATION_SEC', 1800);
        if ($estSec > $maxDuration) {
            return response()->json([
                'error' => '时长超限：预估约 ' . $estSec . ' 秒，超过单次上限 ' . $maxDuration . ' 秒（' . round($maxDuration / 60) . '分钟）。请拆分内容分批生成。',
                'code' => 'duration_exceeded',
                'estimated_sec' => $estSec,
                'max_sec' => $maxDuration,
            ], 422);
        }

        // 解析模式 / 标题（幂等去重与并发闸均依赖）
        $mode = $data['mode'] ?? 'scroll';
        $title = $data['title'] ?? null;

        // —— 幂等：非批量场景下，60 秒内同键（租户+模式+文案+标题）的重复提交直接复用已有任务，
        // 避免「关页面前连点」造成重复出片并重复占用并发槽位。批量出片逐条独立，跳过去重。 ——
        $dedupeKey = null;
        if (empty($data['batch_id'])) {
            $dedupeKey = \App\Models\VideoJob::computeDedupeKey($tenant->id, $mode, $data['dialogue'], $title);
            $dup = \App\Models\VideoJob::findDuplicate($tenant->id, $dedupeKey);
            if ($dup) {
                return response()->json([
                    'job_id' => $dup->job_id ?: null,
                    'mode' => $dup->mode,
                    'status' => $dup->status,
                    'duplicate' => true,
                    'message' => '检测到重复提交，已复用已有任务',
                    'usage' => $tenant->usageThisMonth(),
                    'quota' => $tenant->quota_monthly,
                ]);
            }
        }

        // —— 兜底同步：提交前先把本租户「已完成的 queued 孤儿」按 8500 真实状态
        // 回写为 done/failed，避免用户关页面后任务永久卡 queued 并持续挡住
        // 后续所有提交（429）。8500 不可达时静默跳过，不影响本次提交。 ——
        \App\Models\VideoJob::where('tenant_id', $tenant->id)
            ->where('status', 'queued')
            ->whereNotNull('job_id')
            ->get()
            ->each(function (\App\Models\VideoJob $j) {
                $j->syncFromPipeline();
            });

        // —— 本租户并发上限（与 8500 双保险；第一道闸在 Laravel 侧）——
        $active = \App\Models\VideoJob::where('tenant_id', $tenant->id)
            ->where('status', 'queued')
            ->count();
        $tenantMax = (int) env('TENANT_MAX_CONCURRENT_JOBS', 2);
        if ($active >= $tenantMax) {
            return response()->json([
                'error' => '并发超限：当前账号已有 ' . $active . ' 个进行中的渲染任务，请等待完成后再提交。',
                'code' => 'tenant_busy',
                'active' => $active,
                'max' => $tenantMax,
            ], 429);
        }

        // 解析自传模特：前端传 "User:{asset_id}" → 查库取 HEYGEM 容器路径后透传
        $modelInput = $request->input('model');
        if ($mode === 'avatar' && $modelInput && str_starts_with($modelInput, 'User:')) {
            $assetId = substr($modelInput, 5);
            $asset = \App\Models\ModelAsset::where('id', $assetId)
                ->where('tenant_id', $tenant->id)
                ->where('status', 'ready')
                ->first();
            if ($asset) {
                $modelInput = $asset->containerPath();
                $asset->increment('use_count');
            }
        }

        // —— 记录用量（先落库，用于配额计量）——
        $job = VideoJob::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'job_id' => '',           // 待微服务返回后回填
            'batch_id' => $data['batch_id'] ?? null,
            'mode' => $mode,
            'title' => $title,
            'status' => 'queued',
            'heartbeat_at' => now(),  // 创建即记一次心跳，避免新建任务立即被误判为孤儿
            'dedupe_key' => $dedupeKey,
        ]);

        // 解析封面素材（本租户上传 或 平台预设均可），落库后回填
        if ($request->filled('cover_id')) {
            $cover = \App\Models\CoverAsset::where('id', $request->input('cover_id'))
                ->where(function ($q) use ($tenant) {
                    $q->where('is_preset', true)->orWhere('tenant_id', $tenant->id);
                })
                ->first();
            if ($cover) {
                $job->update(['cover_asset_id' => $cover->id]);
                $cover->increment('use_count');
            }
        }

        // 真实 TTS（默认）；dry_tts 仅用于内部测试
        $payload = [
            'mode' => $mode,
            'dialogue' => $data['dialogue'],
            'title' => $title,
            'subtitle' => $data['subtitle'] ?? null,
            'dry_tts' => (bool) $request->input('dry_tts', false),
            'male_voice' => $request->input('male_voice') ?: $tenant->default_male_voice,
            'female_voice' => $request->input('female_voice') ?: $tenant->default_female_voice,
            'voice_form' => $request->input('voice_form', 'dialogue'),
            'natural' => (bool) $request->input('natural', false),
            'model' => $modelInput,   // 仅数字人模式使用；已解析 User:{id}->containerPath，否则透传场景名；字幕卡模式为 null，微服务自动跳过
            'scene' => $request->input('scene'),   // 数字人出镜场景（office_a / office_b）
            'tenant_id' => (string) $tenant->id,   // 透传给 8500 做并发护栏（无租户上下文则无法区分）
        ];

        // 分声线感情/快慢：仅当页面传了才透传（不传则脚本用默认值）
        foreach (['male_rate', 'female_rate', 'male_pitch', 'female_pitch', 'male_vol', 'female_vol'] as $k) {
            if ($request->has($k)) {
                $payload[$k] = $request->input($k);
            }
        }
        // 字幕样式可调：仅当页面传了才透传（不传则脚本用默认值）
        foreach (['subtitle_size', 'subtitle_lines', 'subtitle_outline', 'subtitle_position'] as $k) {
            if ($request->has($k)) {
                $payload[$k] = $request->input($k);
            }
        }

        try {
            $resp = app(PipelineClient::class)->post('/generate', $payload, 15);
        } catch (PipelineUnavailableException $e) {
            $job->update(['status' => 'failed']);
            return response()->json(['error' => '出片服务暂时不可用，请稍后重试（' . $e->getMessage() . '）'], 503);
        }

        if (! $resp->successful()) {
            $job->update(['status' => 'failed']);
            return response()->json(['error' => '出片服务暂不可用，请确认微服务已启动'], 502);
        }

        $pj = $resp->json('job_id');
        $job->update(['job_id' => $pj, 'status' => 'queued']);

        return response()->json([
            'job_id' => $pj,
            'mode' => $mode,
            'usage' => $tenant->usageThisMonth(),
            'quota' => $tenant->quota_monthly,
        ]);
    }

    /**
     * 从对话稿粗略估算 TTS 时长（中文约 4.5 字/秒，去除 女：/男： 前缀）。
     * 与 8500 server.py estimate_duration_sec 算法保持一致。
     */
    private function estimateDurationSec(string $dialogue): int
    {
        $chars = 0;
        foreach (explode("\n", $dialogue) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '女：') || str_starts_with($line, '女:')
                || str_starts_with($line, '男：') || str_starts_with($line, '男:')) {
                $line = mb_substr($line, 2);
            }
            $chars += mb_strlen(str_replace(' ', '', $line));
        }
        return max(1, (int) round($chars / 4.5));
    }

    public function status(string $jobId)
    {
        try {
            $resp = app(PipelineClient::class)->get('/status/' . $jobId, 15);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '状态查询服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '状态查询失败'], 502);
        }
        $json = $resp->json();
        // 同步任务状态到本地用量表（复用模型方法，与兜底同步命令同源）
        $job = VideoJob::where('job_id', $jobId)->first();
        if ($job) {
            $job->applyPipelineStatus($json);
            // 客户端轮询即代表「仍在线」，刷新心跳（孤儿回收的判定依据）
            $job->touchHeartbeat();
        }
        return response()->json($json);
    }

    /**
     * 出片队列预估：供提交页在提交前 / 轮询时展示「当前队列数 + 预计等待分钟」。
     * 仅读 video_jobs，无外部依赖、无写入，可高频调用。
     * 并发模型：出片全局并发 C（默认 3，由 8500 侧执行），单条约 10 分钟；
     * 预计等待 = ceil(全局未完成任务数 / C) × 平均渲染分钟（保守：整批算满，不假设前批已渲进度）。
     */
    public function queueEstimate(Request $request)
    {
        $tenant = $request->user()->tenant;

        // 全局未完成任务（含正在渲染 + 排队等待，Laravel 侧统一为 queued）
        $globalQueued = \App\Models\VideoJob::where('status', 'queued')->count();
        // 本账号进行中任务（受租户并发闸约束，达上限将被 429 拦截）
        $tenantQueued = \App\Models\VideoJob::where('tenant_id', $tenant->id)
            ->where('status', 'queued')->count();

        $concurrency  = (int) env('GLOBAL_MAX_JOBS', 3);
        $tenantMax    = (int) env('TENANT_MAX_CONCURRENT_JOBS', 2);
        $avgRenderMin = (int) env('AVG_RENDER_MIN', 10);

        // 新提交使全局队列 +1，其排队批次 = ceil((N+1)/C)，前面 N 条需渲完
        $estWaitMin = (int) ceil($globalQueued / max(1, $concurrency)) * $avgRenderMin;

        return response()->json([
            'global_queued'  => $globalQueued,
            'tenant_queued'  => $tenantQueued,
            'concurrency'    => $concurrency,
            'tenant_max'     => $tenantMax,
            'avg_render_min' => $avgRenderMin,
            'est_wait_min'   => $estWaitMin,
            'will_accept'    => $tenantQueued < $tenantMax,
        ]);
    }

    public function download(string $jobId)
    {
        try {
            $resp = app(PipelineClient::class)->get('/download/' . $jobId, 180);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '下载服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            abort(404);
        }
        $job = VideoJob::where('job_id', $jobId)->first();
        if ($job && $job->status === 'queued') {
            $job->update(['status' => 'done']);
            // 渲染完成即进入「待人工审核」初始态（draft）
            if (is_null($job->publish_status)) {
                $job->update(['publish_status' => 'draft']);
            }
        }
        return response($resp->body(), 200, [
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => 'inline; filename="' . $jobId . '.mp4"',
        ]);
    }

    /** 视频生成列表：本租户全部未删除出片任务，按创建时间倒序。 */
    public function library()
    {
        $tenant = request()->user()->tenant;
        $jobs = VideoJob::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->with('qcReport', 'coverAsset')
            ->get();

        return view('studio.videos', compact('jobs'));
    }

    /** 软删除（移入回收站）。 */
    public function destroy(VideoJob $videoJob)
    {
        $this->authorizeTenant($videoJob);

        $videoJob->delete();

        return redirect()->route('studio.videos')->with('success', '已移入回收站：' . ($videoJob->title ?: '未命名视频'));
    }

    /** 回收站：本租户已软删除的视频。 */
    public function recycle()
    {
        $tenant = request()->user()->tenant;
        $jobs = VideoJob::onlyTrashed()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('deleted_at')
            ->with('coverAsset')
            ->get();

        return view('studio.recycle', compact('jobs'));
    }

    /** 从回收站恢复。 */
    public function restore(VideoJob $videoJob)
    {
        $this->authorizeTenant($videoJob);

        $videoJob->restore();

        return redirect()->route('studio.recycle')->with('success', '已恢复：' . ($videoJob->title ?: '未命名视频'));
    }

    /** 彻底删除（不可恢复）。 */
    public function forceDestroy(VideoJob $videoJob)
    {
        $this->authorizeTenant($videoJob);

        $videoJob->forceDelete();

        return redirect()->route('studio.recycle')->with('success', '已彻底删除');
    }

    // ===== 批量出片（统一形式一键生成 N 条）=====

    /** 本租户可用音色（男/女），供批量统一形式选择器使用。 */
    public function voices(Request $request)
    {
        $tenant = $request->user()->tenant;
        $male = \App\Models\TenantVoice::where('tenant_id', $tenant->id)
            ->where('gender', 'male')->where('status', 'ready')
            ->orderByDesc('is_default')->orderByDesc('created_at')
            ->get(['id', 'name', 'voice_id', 'is_default']);
        $female = \App\Models\TenantVoice::where('tenant_id', $tenant->id)
            ->where('gender', 'female')->where('status', 'ready')
            ->orderByDesc('is_default')->orderByDesc('created_at')
            ->get(['id', 'name', 'voice_id', 'is_default']);
        return response()->json(['male' => $male, 'female' => $female]);
    }

    /** 创建批量出片计划（持久化脚本+统一配置），返回 batch_id。 */
    public function storeBatchPlan(Request $request)
    {
        $user = $request->user();
        $tenant = $user->tenant;
        $data = $request->validate([
            'config' => ['required', 'array'],
            'scripts' => ['required', 'array', 'min:1', 'max:50'],
            'scripts.*.title' => ['nullable', 'string', 'max:20'],
            'scripts.*.cleaned' => ['required', 'string'],
        ]);
        $batchId = (string) \Illuminate\Support\Str::uuid();
        $scripts = collect($data['scripts'])->map(function ($s) {
            return [
                'title' => $s['title'] ?? '',
                'cleaned' => $s['cleaned'],
                'status' => 'pending',
                'job_id' => null,
            ];
        })->all();
        \App\Models\VideoJobBatch::create([
            'batch_id' => $batchId,
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'config' => $data['config'],
            'scripts' => $scripts,
            'total' => count($scripts),
        ]);
        return response()->json(['batch_id' => $batchId]);
    }

    /** 批量计划状态（供前端恢复/进度看板）。 */
    public function batchStatus($batchId)
    {
        $user = request()->user();
        $batch = \App\Models\VideoJobBatch::where('batch_id', $batchId)
            ->where('tenant_id', $user->tenant_id)->first();
        if (!$batch) {
            return response()->json(['error' => 'not found'], 404);
        }
        return response()->json([
            'batch_id' => $batch->batch_id,
            'total' => $batch->total,
            'done' => $batch->done,
            'failed' => $batch->failed,
            'scripts' => $batch->scripts,
        ]);
    }

    /** 编排器回写单条脚本进度（提交/完成/失败），同步 done/failed 计数。 */
    public function batchProgress(Request $request, $batchId)
    {
        $user = $request->user();
        $batch = \App\Models\VideoJobBatch::where('batch_id', $batchId)
            ->where('tenant_id', $user->tenant_id)->first();
        if (!$batch) {
            return response()->json(['error' => 'not found'], 404);
        }
        $data = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'in:pending,submitted,done,failed'],
            'job_id' => ['nullable', 'string'],
        ]);
        $scripts = $batch->scripts;
        if (!isset($scripts[$data['index']])) {
            return response()->json(['error' => 'bad index'], 422);
        }
        $scripts[$data['index']]['status'] = $data['status'];
        if ($data['job_id']) {
            $scripts[$data['index']]['job_id'] = $data['job_id'];
        }
        $done = 0;
        $failed = 0;
        foreach ($scripts as $s) {
            if (($s['status'] ?? '') === 'done') $done++;
            elseif (($s['status'] ?? '') === 'failed') $failed++;
        }
        $batch->update(['scripts' => $scripts, 'done' => $done, 'failed' => $failed]);
        return response()->json(['ok' => true]);
    }

    private function authorizeTenant(VideoJob $job): void
    {
        if ($job->tenant_id !== request()->user()->tenant_id) {
            abort(403);
        }
    }
}
