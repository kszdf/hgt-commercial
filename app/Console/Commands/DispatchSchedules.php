<?php

namespace App\Console\Commands;

use App\Models\PublishSchedule;
use App\Services\PublishRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 发布排期调度：处理到点的排期。
 *
 * - auto_publish=true ：走 PublishRunner 自动发布（真实/模拟透明）；
 * - auto_publish=false：标记 due（日历页"今日待发"高亮，仅提醒）；
 * - 视频不可用 / 账号未授权 / 已达上限 → failed / skipped，error 记录原因。
 *
 * 建议调度：每分钟一次（routes/console.php 已注册）。
 * 并发防护：仅处理 status=pending 且 schedule_at<=now 的行；单 worker 下天然串行。
 */
class DispatchSchedules extends Command
{
    protected $signature = 'schedules:dispatch';

    protected $description = '处理到点的发布排期（自动发布或标记到期提醒）';

    public function handle(): int
    {
        $now = now();
        $due = PublishSchedule::where('status', PublishSchedule::STATUS_PENDING)
            ->where('schedule_at', '<=', $now)
            ->with('videoJob', 'account')
            ->limit(50)
            ->get();

        $published = 0;
        $reminded = 0;
        $failed = 0;

        foreach ($due as $schedule) {
            $job = $schedule->videoJob;
            $account = $schedule->account;

            // 前置可用性
            if (! $job || $job->status !== 'done') {
                $schedule->update([
                    'status' => PublishSchedule::STATUS_SKIPPED,
                    'error' => '视频未完成渲染或已删除',
                ]);
                $failed++;
                continue;
            }

            if (! $schedule->auto_publish) {
                // 仅提醒：标记 due，日历页高亮
                $schedule->update(['status' => PublishSchedule::STATUS_DUE]);
                $reminded++;
                continue;
            }

            if (! $account || ! $account->isAuthorized()) {
                $schedule->update([
                    'status' => PublishSchedule::STATUS_FAILED,
                    'error' => '账号未授权（请先在「平台账号」完成授权）',
                ]);
                $failed++;
                continue;
            }

            // 自动发布（PublishRunner 内部含每日上限/模拟透明）
            $schedule->update(['status' => PublishSchedule::STATUS_PUBLISHING]);
            try {
                $r = app(PublishRunner::class)->run($job, $account, $schedule->tenant);
            } catch (\Throwable $e) {
                $r = ['ok' => false, 'reason' => '异常：' . $e->getMessage()];
            }

            $schedule->update([
                'status' => $r['ok'] ? PublishSchedule::STATUS_PUBLISHED : PublishSchedule::STATUS_FAILED,
                'published_at' => $r['ok'] ? now() : null,
                'error' => $r['ok'] ? null : ($r['reason'] ?? '发布失败'),
            ]);

            if ($r['ok']) {
                $published++;
                if (! empty($r['simulated'])) {
                    Log::warning('schedules:dispatch simulated publish', [
                        'schedule' => $schedule->id,
                        'video' => $job->id,
                        'account' => $account->id,
                    ]);
                }
            } else {
                $failed++;
            }
        }

        $this->info("schedules:dispatch done — published={$published}, reminded={$reminded}, failed={$failed}");
        return self::SUCCESS;
    }
}
