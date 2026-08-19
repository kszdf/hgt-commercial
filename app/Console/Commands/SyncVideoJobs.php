<?php

namespace App\Console\Commands;

use App\Exceptions\PipelineUnavailableException;
use App\Models\VideoJob;
use App\Services\PipelineClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * 出片任务兜底看门狗：周期性把 video_jobs 中卡在 queued 的任务按真实状态回写 / 检测卡死。
 *
 * 彻底解决「假出片」四要件：
 *  1) 任务状态检测：每次轮询都向 8500 查询真实阶段（queued/editing/rendering/rerender），
 *     记录阶段最后推进时间；某阶段长时间不前进即识别为卡死/进程丢失。
 *  2) 超时机制：双阈值——阶段卡死超时（按阶段给不同阈值）+ 任务绝对超时（总耗时硬上限）。
 *  3) 失败自动提示：失败写入结构化 failed_reason，前端据此展示明确失败原因，不再无限等待。
 *  4) 状态可追溯：每个任务写 storage/logs/video-jobs/{job_id}.log，含阶段切换、卡死判定、
 *     失败原因与原始错误，便于排查长时间无输出的根因。
 *
 * 作为常驻 Docker 服务（--daemon）每 30s 运行；routes/console.php 另挂每 5 分钟调度冗余。
 */
class SyncVideoJobs extends Command
{
    protected $signature = 'video:sync
        {--daemon : 常驻循环同步（作为 Docker 服务运行）}
        {--interval=30 : 守护模式轮询间隔（秒）}
        {--limit=200 : 单次最多同步的任务数}';

    protected $description = '出片看门狗：卡死检测 + 超时回收 + 失败原因结构化，根治假出片';

    private bool $stopping = false;

    public function handle(): int
    {
        if ($this->option('daemon')) {
            $this->info('进入常驻同步模式（SIGTERM/SIGINT 优雅退出）');
            if (extension_loaded('pcntl')) {
                pcntl_async_signals(true);
                pcntl_signal(SIGTERM, fn () => $this->stopping = true);
                pcntl_signal(SIGINT, fn () => $this->stopping = true);
            }
            while (! $this->stopping) {
                $this->runOnce();
                // 分段 sleep，以便及时响应 SIGTERM（Docker stop 默认宽限 10s）
                $secs = (int) $this->option('interval');
                for ($i = 0; $i < $secs && ! $this->stopping; $i++) {
                    sleep(1);
                }
            }
            $this->info('常驻同步已停止');
            return self::SUCCESS;
        }

        return $this->runOnce();
    }

    private function runOnce(): int
    {
        // 缓存锁防重入：避免 --daemon 与手动/调度触发时的并发写
        $lock = Cache::lock('video:sync', 120);
        if (! $lock->get()) {
            $this->warn('另一实例正在运行，跳过本次');
            return self::SUCCESS;
        }

        // —— 卡死 / 超时阈值（秒）——
        // 阶段卡死：某阶段（含排队 queued / 配音字幕合成 editing / 渲染 rendering / 重渲染 rerender）
        //   长时间无推进即判失败。排队等待渲染资源给更长阈值（避免多任务排队误杀），
        //   已进入渲染/合成的阶段给较严阈值（正常应 15–25 分钟出片）。
        $stepStuckSec  = (int) env('VIDEO_STEP_STUCK_TIMEOUT_SEC', 1500);   // 25 分钟（活动阶段）
        $queueStuckSec = (int) env('VIDEO_QUEUE_STUCK_TIMEOUT_SEC', 3000);  // 50 分钟（排队等锁）
        // 绝对超时：任务总耗时硬上限（兜底网，无论卡在哪个阶段都拦得住）。
        $absoluteSec   = (int) env('VIDEO_ABSOLUTE_TIMEOUT_SEC', 5400);     // 90 分钟
        // 服务不可达：8500 持续不可达超过该时长才判失败（避免瞬时抖动误杀）。
        $serviceDownSec = (int) env('VIDEO_SERVICE_DOWN_TIMEOUT_SEC', 600); // 10 分钟
        // 提交孤儿回收阈值（无 job_id 的任务）：默认 300s，可由 VIDEO_STALE_TIMEOUT_SEC 覆盖。
        $staleSec = (int) env('VIDEO_STALE_TIMEOUT_SEC', 300);
        $limit = (int) $this->option('limit');

        try {
            // —— 1) 全量监测：所有带 job_id 的 queued 任务，按 8500 真实状态推进 + 卡死检测 ——
            // 每轮都覆盖（不依赖前端心跳），从根上消除「客户端关页面 / 一直开着轮询」导致的漏检。
            $jobs = VideoJob::where('status', 'queued')
                ->whereNotNull('job_id')
                ->orderBy('created_at')
                ->limit($limit)
                ->get();

            $synced = 0;
            $released404 = 0;
            foreach ($jobs as $job) {
                try {
                    $resp = app(PipelineClient::class)->get('/status/' . $job->job_id, 15);
                } catch (PipelineUnavailableException $e) {
                    // 8500 不可达：累计不可达时长，超阈值才判失败（瞬时抖动不误杀）
                    $downKey = 'video_job_svc_down:' . $job->job_id;
                    $first = Cache::get($downKey);
                    if (! $first) {
                        Cache::put($downKey, now()->timestamp, $serviceDownSec + 120);
                        $first = now()->timestamp;
                    }
                    $downFor = now()->timestamp - $first;
                    if ($downFor >= $serviceDownSec) {
                        $job->markFailed('service_unavailable', null,
                            '出片微服务持续不可达约 ' . round($downFor / 60) . ' 分钟，任务已自动终止');
                        $this->logEvent($job->job_id, 'failed', 'service_unavailable',
                            '8500 持续不可达 ' . $downFor . 's');
                        $synced++;
                        $this->info("job {$job->job_id} 服务不可达超时 -> failed（service_unavailable）");
                    } else {
                        $this->warn("job {$job->job_id} 8500 暂不可达（已持续 {$downFor}s / 阈值 {$serviceDownSec}s）");
                    }
                    continue;
                }
                // 8500 恢复：清除不可达计时
                Cache::forget('video_job_svc_down:' . $job->job_id);

                // 8500 侧已查无此任务（被回收 / 从未真正提交成功）→ 标记失败释放槽位
                if ($resp->status() === 404) {
                    $job->markFailed('job_lost', null,
                        '出片服务中已无此任务记录，可能已被回收，已自动终止');
                    $this->logEvent($job->job_id, 'failed', 'job_lost', '8500 返回 404');
                    $released404++;
                    $synced++;
                    $this->info("job {$job->job_id} 8500 404 -> failed（job_lost，释放槽位）");
                    continue;
                }
                if (! $resp->successful()) {
                    $this->warn("job {$job->job_id} 状态查询失败 http={$resp->status()}，跳过");
                    continue;
                }
                $json = $resp->json();
                $remoteStatus = $json['status'] ?? null;
                $remoteStep   = $json['step'] ?? $remoteStatus;
                $remoteError  = isset($json['error'])
                    ? (is_string($json['error']) ? $json['error'] : json_encode($json['error'], JSON_UNESCAPED_UNICODE))
                    : null;

                // 阶段推进记录（真实进度基线，用于卡死检测）
                $job->recordStep($remoteStep);

                // 8500 已到终态：复用模型方法回写（含失败原因分类）
                if (in_array($remoteStatus, ['done', 'failed'], true)) {
                    if ($job->applyPipelineStatus($json)) {
                        if ($remoteStatus === 'failed') {
                            $this->logEvent($job->job_id, 'failed', $job->failed_reason ?? 'unknown', $remoteError ?? '');
                        }
                        $synced++;
                        $this->info("job {$job->job_id} -> {$job->status}");
                    }
                    continue;
                }

                // —— 卡死检测（核心）：覆盖 queued / editing / rendering / rerender 全阶段 ——
                // 1) 阶段长时间无推进：比「仅看创建时间」更准——真正卡在某个阶段（如等渲染锁、
                //    配音字幕合成阻塞）会被即时识别，而不必等总耗时爆表。
                $stuckSec = $job->secondsSinceProgress();
                $stepTimeout = ($remoteStep === 'queued') ? $queueStuckSec : $stepStuckSec;
                if ($stuckSec >= $stepTimeout) {
                    $job->markFailed('timeout', $remoteError,
                        '任务停留在「' . ($remoteStep ?: '未知') . '」阶段约 ' . round($stuckSec / 60)
                        . ' 分钟无进展，判定卡死并终止');
                    $this->logEvent($job->job_id, 'failed', 'timeout',
                        "step={$remoteStep} 无进展 {$stuckSec}s >= {$stepTimeout}s");
                    $synced++;
                    $this->info("job {$job->job_id} 阶段卡死 -> failed（{$remoteStep} 无进展 {$stuckSec}s）");
                    continue;
                }
                // 2) 绝对超时：总耗时超过硬上限（兜底网，必拦得住任何卡死）
                $elapsed = (int) now()->diffInSeconds($job->created_at);
                if ($elapsed >= $absoluteSec) {
                    $job->markFailed('timeout', $remoteError,
                        '任务总耗时约 ' . round($elapsed / 60) . ' 分钟仍未出片，触发绝对超时并终止');
                    $this->logEvent($job->job_id, 'failed', 'timeout',
                        "elapsed {$elapsed}s >= {$absoluteSec}s");
                    $synced++;
                    $this->info("job {$job->job_id} 绝对超时 -> failed（elapsed {$elapsed}s）");
                    continue;
                }
            }

            // —— 2) 提交孤儿回收：job_id 为空（提交后未拿到标识，8500 侧根本无此任务）——
            // 这类任务不可能在渲染，超时直接回收释放槽位。带 job_id 的任务已由第 1 部分统一监测。
            $cutoff = now()->subSeconds($staleSec);
            $orphans = VideoJob::where('status', 'queued')
                ->whereNull('job_id')
                ->where(function ($q) use ($cutoff) {
                    $q->where('heartbeat_at', '<', $cutoff)
                      ->orWhere(function ($q2) use ($cutoff) {
                          $q2->whereNull('heartbeat_at')
                             ->where('created_at', '<', $cutoff);
                      });
                })
                ->orderBy('created_at')
                ->limit($limit)
                ->get();

            $released = 0;
            foreach ($orphans as $job) {
                if ($job->markFailed('job_lost', null, '提交后未获得任务标识，已自动回收')) {
                    $released++;
                    $this->logEvent(($job->job_id ?: ('db#' . $job->id)), 'failed', 'job_lost', '提交孤儿超时回收');
                    $this->info('job 提交孤儿（无 job_id）超时回收 -> failed（释放槽位）');
                }
            }

            if ($jobs->isEmpty() && $orphans->isEmpty()) {
                $this->info('[' . now()->toDateTimeString() . '] 无待同步 / 待回收任务');
            } else {
                $this->info('[' . now()->toDateTimeString() . "] 同步完成：状态推进 {$synced} 条"
                    . "（含 404 回收 {$released404}），提交孤儿回收 {$released} 条"
                    . "，扫描 queued(有job_id) {$jobs->count()} / 提交孤儿 {$orphans->count()}");
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    /**
     * 写结构化事件日志到 storage/logs/video-jobs/{job_id}.log（与控制器 logJobProgress 同源路径）。
     * 失败事件含原因分类 + 原始错误，便于溯源长时间无输出的根因。写入失败静默吞掉。
     */
    private function logEvent(string $jobId, string $type, string $reason, string $detail): void
    {
        try {
            $dir = storage_path('logs/video-jobs');
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $file = $dir . '/' . preg_replace('/[^A-Za-z0-9_-]/', '', $jobId) . '.log';
            $ts = date('Y-m-d H:i:s');
            $line = sprintf("[%s] EVENT type=%s reason=%s detail=%s\n", $ts, $type, $reason, $detail);
            @file_put_contents($file, $line, FILE_APPEND);
        } catch (\Throwable $e) {
            // 日志异常静默吞掉，不阻断看门狗
        }
    }
}
