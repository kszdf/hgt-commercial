<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 每日 09:00（北京时间）提醒免费试用即将到期的租户
// 注：Laravel 时区为 UTC，故用 01:00(UTC) 对齐北京时间 09:00
Schedule::command('tenants:notify-trial-expiring')->dailyAt('01:00');

// 数据回流：每 6 小时同步已发布视频的播放互动数据（当前仅抖音，经 8500 /metrics/fetch；
// 8500 未升级到功能包一时会静默跳过，不影响其它调度）
Schedule::command('metrics:sync --days=30')->everySixHours();

// 发布排期：每分钟处理到点排期（自动发布或标记到期提醒）
Schedule::command('schedules:dispatch')->everyMinute();

// 出片看门狗冗余调度：每 5 分钟兜底跑一次，与 video-sync 常驻容器双保险，
// 即使 video-sync 容器异常退出，调度器仍能检测卡死任务并标记失败（根治假出片）。
Schedule::command('video:sync')->everyFiveMinutes();
