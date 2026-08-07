<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 每日 09:00 提醒免费试用即将到期的租户
Schedule::command('tenants:notify-trial-expiring')->dailyAt('09:00');
