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
        $user = request()->user();
        // 超管可查看全部可用声音（跨租户）；普通用户仅看本租户
        if ($user->isGlobalAdmin()) {
            $tenantId = \App\Models\Tenant::whereIn('plan', ['pro', 'enterprise'])->first()->id ?? 0;
        } else {
            $tenantId = $user->tenant->id;
        }
        $maleVoices = TenantVoice::where('tenant_id', $tenantId)
            ->where('gender', 'male')->where('status', 'ready')
            ->orderByDesc('is_default')->orderByDesc('created_at')
            ->get(['id', 'name', 'voice_id', 'is_default']);
        $femaleVoices = TenantVoice::where('tenant_id', $tenantId)
            ->where('gender', 'female')->where('status', 'ready')
            ->orderByDesc('is_default')->orderByDesc('created_at')
            ->get(['id', 'name', 'voice_id', 'is_default']);
        return view('studio.scroll', compact('maleVoices', 'femaleVoices'));
    }

    public function generate(Request $request)
    {
        $user = $request->user();

        // —— 超级管理员配额豁免 + 操作上下文解析 ——
        if ($user->isGlobalAdmin()) {
            // 超管不受任何配额/试用限制，直接放行
            $request->merge(['_admin_bypass' => true]);
            // 超管需一个租户上下文用于：声音查询 / VideoJob.tenant_id / 8500并发护栏 / 默认音色
            // 优先用 pro/enterprise 租户，回退到任意可用租户
            $tenant = \App\Models\Tenant::whereIn('plan', ['pro', 'enterprise'])->first()
                ?? \App\Models\Tenant::first();
        } else {
            $tenant = $this->studioTenant(request());

            // —— 统一生成拦截：试用到期 / 月度额度 / 试用累计条数 / 试用累计时长 ——
            $block = $tenant->generationBlockReason();
            if ($block) {
                $code = $block['code'];
                $http = in_array($code, ['trial_expired', 'quota_exceeded', 'trial_jobs_exceeded', 'trial_minutes_exceeded'], true)
                    ? 402 : 403;
                return response()->json([
                    'error' => $block['message'],
                    'code' => $code,
                    'usage' => $tenant->usageThisMonth(),
                    'quota' => $tenant->quota_monthly,
                ], $http);
            }
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
            'subtitle_style' => ['sometimes', 'string', 'in:dynamic,minimal,bubble'],
            'subtitle_font' => ['sometimes', 'string', 'in:hei,yahei,kaiti,song,fangsong'],
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

        // —— 记录用量（先落库，用于配额计量）；dialogue 存档供爆款复刻 ——
        $job = VideoJob::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'job_id' => '',           // 待微服务返回后回填
            'batch_id' => $data['batch_id'] ?? null,
            'mode' => $mode,
            'title' => $title,
            'dialogue' => $data['dialogue'],
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
        foreach (['subtitle_size', 'subtitle_lines', 'subtitle_outline', 'subtitle_position', 'subtitle_style', 'subtitle_font'] as $k) {
            if ($request->has($k)) {
                $payload[$k] = $request->input($k);
            }
        }

        // 参数快照（爆款复刻用）：存本次出片的完整入参
        $job->update(['render_config' => $payload]);

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
     * 从对话稿粗略估算 TTS 时长（中文约 2.4 字/秒 ≈ 145 字/分钟，去除 女：/男： 前缀）。
     * 与 8500 server.py 及前端 estimateDuration 算法保持一致。
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
        return max(1, (int) round($chars / 2.4));
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
        // 用 withTrashed：任务可能被用户在「我的视频」移入回收站，但 8500 仍会继续渲染/失败，
        // 轮询时必须把终态回写，否则前端会永远卡在"出片中"。
        $job = VideoJob::withTrashed()->where('job_id', $jobId)->first();
        if ($job) {
            $job->applyPipelineStatus($json);
            // 客户端轮询即代表「仍在线」，刷新心跳（孤儿回收的判定依据）
            $job->touchHeartbeat();
        }
        // 增补租户端进度字段（中文分步 / 百分比 / 预计剩余）+ 按 job_id 时间戳落盘日志
        $json = $this->enrichPipelineStatus($json);
        $this->logJobProgress($jobId, $json);
        return response()->json($json);
    }

    /**
     * 出片中止：前端「中止」按钮 onAbort 调此端点，转发 8500 标记 job 为已取消。
     * 兼容 job_id 取自 query(?job=) 或 body({job_id:}) 两种调用方式。
     */
    public function cancel(Request $request)
    {
        $jobId = $request->query('job') ?: $request->input('job_id');
        if (! $jobId) {
            return response()->json(['ok' => false, 'error' => 'job_id required'], 400);
        }

        try {
            $resp = app(PipelineClient::class)->cancel($jobId);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['ok' => false, 'error' => '出片服务暂不可用，请稍后重试'], 503);
        }

        $json = $resp->json();
        if (! $resp->successful()) {
            return response()->json($json ?: ['ok' => false, 'error' => '中止失败'], $resp->status());
        }
        return response()->json($json);
    }

    /**
     * 把 8500 原始状态 enriched 成租户端友好的进度结构：
     * 中文分步文案 + 百分比 + 预计剩余秒。
     *
     * 进度策略：
     * - 8500 返回真实 progress 时优先使用，并基于真实进度 + 已耗时动态推算 ETA；
     * - 无真实 progress 时 fallback 到阶段固定百分比，ETA 返回 0，前端显示区间文案（避免"永远停在 2分30秒"）。
     */
    private function enrichPipelineStatus(array $json): array
    {
        $step   = $json['step'] ?? null;
        $status = $json['status'] ?? null;

        // 流水线分步（顺序即阶段推进）：提交 → 配音字幕 → 视频渲染 → 完成
        $stepMap = [
            'queued'    => ['label' => '排队等待渲染资源', 'percent' => 8,  'eta_hint' => '预计还需 1–10 分钟（视排队情况）'],
            'editing'   => ['label' => '智能配音与字幕合成', 'percent' => 40, 'eta_hint' => '预计还需 1–3 分钟'],
            'rendering' => ['label' => '视频 / 数字人渲染中', 'percent' => 75, 'eta_hint' => '预计还需 5–15 分钟'],
            'rerender'  => ['label' => '画面精修（自动重渲染）', 'percent' => 92, 'eta_hint' => '预计还需 3–8 分钟'],
            'done'      => ['label' => '已完成', 'percent' => 100, 'eta_hint' => ''],
            'failed'    => ['label' => '出片失败', 'percent' => 100, 'eta_hint' => ''],
        ];

        $info = $stepMap[$step] ?? ($status === 'done' ? $stepMap['done']
            : ($status === 'failed' ? $stepMap['failed'] : ['label' => '出片处理中', 'percent' => 50, 'eta_hint' => '预计还需 5–15 分钟']));

        // 单条平均渲染秒，与队列预估（queueEstimate）同源 env('AVG_RENDER_MIN')
        $avgRenderSec = (int) env('AVG_RENDER_MIN', 10) * 60;

        // 追加租户端已等待时长（从任务创建到当前），便于前端恢复轮询时展示真实总耗时
        $job = VideoJob::withTrashed()->where('job_id', $json['job_id'] ?? '')->first();
        $elapsedSec = 0;
        if ($job && $job->created_at) {
            $json['created_at'] = $job->created_at->toDateTimeString();
            $elapsedSec = max(0, (int) abs($job->created_at->diffInSeconds(now())));
            $json['elapsed_sec'] = $elapsedSec;
        } else {
            $json['created_at']  = null;
            $json['elapsed_sec'] = 0;
        }

        // 真实进度优先；无真实进度 fallback 到阶段固定百分比
        $realProgress = (isset($json['progress']) && is_numeric($json['progress'])) ? (int) $json['progress'] : null;
        $percent = $realProgress ?? $info['percent'];

        // 预计剩余：排队按前面任务数估算；渲染中优先按真实进度动态推算，无真实进度则 eta=0（前端显示区间文案）
        $etaSec = 0;
        if ($status === 'queued' || $step === 'queued') {
            $queuePos = (int) ($json['queue_pos'] ?? 0);
            $etaSec = ($queuePos + 1) * $avgRenderSec;
        } elseif ($status !== 'done' && $status !== 'failed') {
            if ($realProgress !== null && $realProgress > 0 && $elapsedSec > 0) {
                // 按已完成比例线性外推剩余时间（例如 20% 用了 100 秒 → 剩余 400 秒）
                $etaSec = (int) round(($elapsedSec / $realProgress) * (100 - $realProgress));
                $etaSec = max(0, min($etaSec, 3600)); // 封顶 60 分钟，避免异常值
            }
            // realProgress 缺失时 etaSec 保持 0，前端会显示 $info['eta_hint']
        }

        $json['step_label']      = $info['label'];
        $json['progress']        = $percent;
        $json['eta_sec']         = $etaSec;
        $json['eta_hint']        = $info['eta_hint'];
        $json['has_real_progress'] = $realProgress !== null;
        $json['avg_render_sec']  = $avgRenderSec;

        // 透传结构化失败信息给前端（失败提示 + 溯源）
        $json['failed_reason']  = $job ? $job->failed_reason : null;
        $json['failed_at']      = $job && $job->failed_at ? $job->failed_at->toDateTimeString() : null;
        $json['pipeline_error'] = $job ? $job->pipeline_error : null;

        return $json;
    }

    /**
     * 按 job_id 写时间戳进度日志，仅在 step/status 切换或首次/终态时追加一行，
     * 便于出片问题追溯（路径：storage/logs/video-jobs/{job_id}.log）。
     * 写入失败绝不影响主流程。
     */
    private function logJobProgress(string $jobId, array $json): void
    {
        try {
            $dir = storage_path('logs/video-jobs');
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $file = $dir . '/' . $jobId . '.log';

            $prevKey = null;
            if (is_file($file)) {
                $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (! empty($lines)) {
                    if (preg_match('/step=(\S+)\s+status=(\S+)/', end($lines), $m)) {
                        $prevKey = $m[1] . '|' . $m[2];
                    }
                }
            }

            $curKey = ($json['step'] ?? '?') . '|' . ($json['status'] ?? '?');
            if ($curKey === $prevKey) {
                return; // 无切换，不重复写
            }

            $ts   = date('Y-m-d H:i:s');
            $failSuffix = '';
            if (($json['status'] ?? '') === 'failed' && ! empty($json['failed_reason'])) {
                $failSuffix = ' reason=' . $json['failed_reason']
                    . ' error=' . mb_substr((string) ($json['pipeline_error'] ?? ''), 0, 200);
            }
            $line = sprintf(
                "[%s] step=%s status=%s label=%s progress=%d%% eta=%ds%s\n",
                $ts,
                $json['step'] ?? '-',
                $json['status'] ?? '-',
                $json['step_label'] ?? '-',
                $json['progress'] ?? 0,
                $json['eta_sec'] ?? 0,
                $failSuffix
            );
            @file_put_contents($file, $line, FILE_APPEND);
        } catch (\Throwable $e) {
            // 日志异常静默吞掉，不阻断出片状态查询
        }
    }

    /**
     * 租户前台查看某任务的出片进度记录（时间戳日志）。
     * 安全：jobId 仅允许安全字符（防路径穿越）；仅允许查看「本人租户」的任务，杜绝跨租户偷看。
     * 日志路径：storage/logs/video-jobs/{job_id}.log（由 logJobProgress 写入）。
     */
    public function jobLog(string $jobId)
    {
        if (! preg_match('/^[A-Za-z0-9_-]{8,128}$/', $jobId)) {
            abort(404);
        }
        $job = VideoJob::where('job_id', $jobId)->first();
        if (! $job) {
            abort(404);
        }
        $this->authorizeTenant($job);   // 非本租户 → 403

        $file = storage_path('logs/video-jobs/' . $jobId . '.log');
        if (! is_file($file)) {
            return response()->json(['exists' => false, 'entries' => []]);
        }

        $raw = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $entries = [];
        if (! empty($raw)) {
            foreach ($raw as $ln) {
                if (preg_match(
                    '/^\[([^\]]+)\]\s+step=(\S+)\s+status=(\S+)\s+label=(.+?)\s+progress=(\d+)%\s+eta=(\d+)s$/',
                    $ln, $m)) {
                    $entries[] = [
                        'time'     => $m[1],
                        'step'     => $m[2],
                        'status'   => $m[3],
                        'label'    => $m[4],
                        'progress' => (int) $m[5],
                        'eta'      => (int) $m[6],
                    ];
                }
            }
        }
        return response()->json(['exists' => true, 'entries' => $entries]);
    }

    /**
     * 出片队列预估：供提交页在提交前 / 轮询时展示「当前队列数 + 预计等待分钟」。
     * 仅读 video_jobs，无外部依赖、无写入，可高频调用。
     * 并发模型：出片全局并发 C（默认 3，由 8500 侧执行），单条约 10 分钟；
     * 预计等待 = ceil(全局未完成任务数 / C) × 平均渲染分钟（保守：整批算满，不假设前批已渲进度）。
     */
    public function queueEstimate(Request $request)
    {
        $user = $request->user();
        // 超管查看全局队列（不限租户）
        if ($user->isGlobalAdmin()) {
            $tenantQueued = 0; // 超管不受租户并发限制
        } else {
            $tenant = $this->studioTenant(request());
            $tenantQueued = \App\Models\VideoJob::where('tenant_id', $tenant->id)
                ->where('status', 'queued')->count();
        }

        $concurrency  = (int) env('GLOBAL_MAX_JOBS', 3);
        $tenantMax    = (int) env('TENANT_MAX_CONCURRENT_JOBS', 2);
        $avgRenderMin = (int) env('AVG_RENDER_MIN', 10);

        // 全局未完成任务数（跨租户），用于估算新提交后的排队等待
        $globalQueued = \App\Models\VideoJob::where('status', 'queued')->count();

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
        $tenant = $this->studioTenant(request());
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

    /** 标记 / 取消爆款（复刻候选）。 */
    public function markHit(Request $request, VideoJob $videoJob)
    {
        $this->authorizeTenant($videoJob);
        $videoJob->update(['is_hit' => ! $videoJob->is_hit]);
        return redirect()->route('studio.videos')->with(
            'success',
            $videoJob->is_hit ? '已标记为爆款 ⭐（可一键复刻）' : '已取消爆款标记'
        );
    }

    /** 复刻数据：返回该条的文稿与出片参数，供出片页一键带入。 */
    public function cloneData(Request $request, VideoJob $videoJob)
    {
        $this->authorizeTenant($videoJob);
        return response()->json([
            'ok' => true,
            'dialogue' => $videoJob->dialogue,
            'title' => $videoJob->title,
            'mode' => $videoJob->mode,
            'config' => $videoJob->render_config ?: [],
        ]);
    }

    /** 回收站：本租户已软删除的视频。 */
    public function recycle()
    {
        $tenant = $this->studioTenant(request());
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
        $tenant = $this->studioTenant(request());
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
        if (! request()->user()->isGlobalAdmin() && $job->tenant_id !== request()->user()->tenant_id) {
            abort(403);
        }
    }
}
