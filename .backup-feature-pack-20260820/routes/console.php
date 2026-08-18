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
