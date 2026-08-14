<?php

namespace App\Console\Commands;

use App\Exceptions\PipelineUnavailableException;
use App\Models\VideoJob;
use App\Services\PipelineClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * 服务端兜底同步：把 video_jobs 中卡在 queued 的任务，按 8500 真实状态回写为 done/failed。
 *
 * 为什么需要它：video_jobs.status 的推进原本只靠前端轮询 /studio/scroll/status/{jobId}
 * 端点触发。若用户提交批量出片后关掉页面，任务会永久卡 queued，既挡住并发闸
 * （TENANT_MAX_CONCURRENT_JOBS，报 429）又让视频库状态不准。
 * 本命令作为常驻 Docker 服务（--daemon）周期性兜底，从根本上消除该风险。
 */
class SyncVideoJobs extends Command
{
    protected $signature = 'video:sync
        {--daemon : 常驻循环同步（作为 Docker 服务运行）}
        {--interval=30 : 守护模式轮询间隔（秒）}
        {--limit=200 : 单次最多同步的任务数}';

    protected $description = '服务端兜底：把卡在 queued 的出片任务按 8500 真实状态回写为 done/failed';

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

        // 兜底回收阈值（秒）：任务无心跳超过该时长即进入回收判定。
        // 默认 300s（5 分钟），可在 .env 用 VIDEO_STALE_TIMEOUT_SEC 覆盖。
        // 注意：阈值仅决定「何时开始判定」，是否真回收仍由 8500 真实状态把关，
        // 绝不会仅凭前端心跳断开就误杀正在渲染的任务。
        $staleSec = (int) env('VIDEO_STALE_TIMEOUT_SEC', 300);

        // 渲染卡死回收阈值（秒）：8500 长期返回 rendering/rerender 但任务总耗时超过该值，
        // 视为渲染进程假死/丢失，自动标 failed 释放并发槽。默认 1800s（30 分钟），
        // 数字人重渲染场景可配置为 1200–1800s；滚动字幕卡可配更短。
        $renderStuckSec = (int) env('VIDEO_RENDER_STUCK_TIMEOUT_SEC', 1800);
        $limit = (int) $this->option('limit');

        try {
            // —— 1) 正常状态同步：按 8500 真实状态推进 queued 任务 ——
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
                    $this->warn("job {$job->job_id} 8500 不可达，跳过");
                    continue;
                }
                // 8500 侧已查无此任务（被回收 / 从未真正提交成功）→ 直接标记失败释放槽位，
                // 不再让孤儿任务永久占用并发闸。
                if ($resp->status() === 404) {
                    $job->update([
                        'status' => 'failed',
                        'review_note' => ($job->review_note ? $job->review_note . '；' : '')
                            . '[系统]8500 侧任务不存在，已自动回收',
                    ]);
                    $released404++;
                    $synced++;
                    $this->info("job {$job->job_id} 8500 404 -> failed（回收释放槽位）");
                    continue;
                }
                if (! $resp->successful()) {
                    $this->warn("job {$job->job_id} 状态查询失败 http={$resp->status()}，跳过");
                    continue;
                }
                $json = $resp->json();

                // —— 渲染卡死兜底：8500 长期停留在 rendering/rerender 且任务总耗时超限 ——
                // 典型场景：8500 的自动重渲染只记日志不真正执行，状态卡成"幽灵渲染"；
                // 前端用户会看到一个永远不动的"视频渲染中"。到达本阈值后强制回收，
                // 避免并发槽被无限占用、用户无限等待。
                $remoteStatus = $json['status'] ?? null;
                $remoteStep   = $json['step'] ?? $remoteStatus;
                if (in_array($remoteStatus, ['rendering', 'rerender'], true)
                    && in_array($remoteStep, ['rendering', 'rerender'], true)
                    && $job->created_at->copy()->addSeconds($renderStuckSec)->isPast()
                ) {
                    $job->update([
                        'status' => 'failed',
                        'review_note' => ($job->review_note ? $job->review_note . '；' : '')
                            . "[系统]8500 持续 {$remoteStatus}/{$remoteStep} 超过 {$renderStuckSec} 秒无终态，判定渲染卡死并回收",
                    ]);
                    $synced++;
                    $this->info("job {$job->job_id} 渲染卡死 -> failed（{$remoteStatus} 超 {$renderStuckSec} 秒）");
                    continue;
                }

                if ($job->applyPipelineStatus($json)) {
                    $synced++;
                    $this->info("job {$job->job_id} -> {$job->status}");
                }
            }

            // —— 2) 孤儿回收：长时间无心跳且仍停留在 queued（客户端断开 / 提交未获标识）——
            // 关键护栏：job_id 非空 的任务说明已真正提交到 8500，回收前必须再向 8500 确认真实状态。
            // 绝不能仅凭「前端心跳断了」就判定死亡——用户关页面后 8500 仍可能在正常渲染。
            // 判定规则：
            //   · 8500 返回 404                → 任务确已不存在，真死，回收释放槽位
            //   · 8500 返回「渲染中」等非终态 → 任务活着，仅续心跳，绝不回收
            //   · 8500 不可达 / 其他异常        → 极可能为瞬时抖动，保守保护（续心跳不回收）
            //   · job_id 为空                  → 提交后未拿到标识，8500 侧根本无此任务，超时直接回收（安全）
            // 这样 5 分钟阈值只杀「真死」的任务，正常渲染一个不误伤。
            $cutoff = now()->subSeconds($staleSec);
            $stale = VideoJob::where('status', 'queued')
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
            foreach ($stale as $job) {
                // 已真正提交到 8500 的任务：以 8500 真实状态为准，禁止误杀
                if (! empty($job->job_id)) {
                    try {
                        $resp = app(PipelineClient::class)->get('/status/' . $job->job_id, 15);
                    } catch (PipelineUnavailableException $e) {
                        // 8500 不可达（极可能为瞬时抖动）：保守保护，续心跳不回收
                        $job->touchHeartbeat();
                        $this->info("job {$job->job_id} 8500 暂不可达，续心跳保护（不回收）");
                        continue;
                    }
                    if ($resp->status() === 404) {
                        // 8500 侧已无此任务 → 真死，回收释放槽位
                        $job->update([
                            'status' => 'failed',
                            'review_note' => ($job->review_note ? $job->review_note . '；' : '')
                                . '[系统]8500 侧任务不存在，已自动回收',
                        ]);
                        $released++;
                        $this->info("job {$job->job_id} 8500 404 -> failed（回收释放槽位）");
                        continue;
                    }
                    if ($resp->successful()) {
                        $json = $resp->json();
                        if (in_array($json['status'] ?? null, ['done', 'failed'], true)) {
                            // 8500 已到终态（理论已被第 1 部分推进，此处兜底）
                            $job->applyPipelineStatus($json);
                            continue;
                        }
                        // 8500 仍在渲染（非终态）→ 任务活着，仅续心跳，绝不回收
                        $job->touchHeartbeat();
                        $this->info("job {$job->job_id} 8500 仍在渲染，续心跳保护（不回收）");
                        continue;
                    }
                    // 其他非预期 http 码：保守保护，续心跳不回收
                    $job->touchHeartbeat();
                    continue;
                }

                // job_id 为空：提交后未拿到标识，8500 侧根本无此任务，超时直接回收（安全）
                if ($job->releaseIfStale($staleSec)) {
                    $released++;
                    $this->info('job 提交孤儿（无 job_id）超时回收 -> failed（释放槽位）');
                }
            }

            if ($jobs->isEmpty() && $stale->isEmpty()) {
                $this->info('[' . now()->toDateTimeString() . '] 无待同步 / 待回收任务');
            } else {
                $this->info('[' . now()->toDateTimeString() . "] 同步完成：状态推进 {$synced} 条"
                    . "（含 404 回收 {$released404}），超时回收 {$released} 条"
                    . "，扫描 queued {$jobs->count()} / 孤儿候选 {$stale->count()}");
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
