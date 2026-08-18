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
