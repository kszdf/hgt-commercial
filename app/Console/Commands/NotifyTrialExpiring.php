<?php

namespace App\Console\Commands;

use App\Mail\TrialExpiringMail;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class NotifyTrialExpiring extends Command
{
    protected $signature = 'tenants:notify-trial-expiring';
    protected $description = '提前提醒免费试用即将到期的租户（默认提前 3 天）';

    public function handle(): int
    {
        $days = (int) config('services.trial_notify_days', 3);
        $from = now();
        $to   = now()->addDays($days);

        $tenants = Tenant::where('plan', 'free')
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [$from, $to])
            ->get();

        $sent = 0;
        foreach ($tenants as $tenant) {
            $daysLeft = max(0, now()->diffInDays($tenant->trial_ends_at, false));
            $cacheKey = 'trial_notified_' . $tenant->id . '_' . now()->toDateString();
            if (Cache::has($cacheKey)) {
                continue; // 当日已提醒，避免重复
            }

            $user = $tenant->users()->first();
            if (! $user || ! $user->email) {
                continue;
            }

            try {
                Mail::to($user->email)->send(new TrialExpiringMail($tenant, $daysLeft));
                Cache::put($cacheKey, true, now()->addDay());
                $sent++;
                $this->info("已提醒租户 #{$tenant->id}（剩余 {$daysLeft} 天）");
            } catch (\Throwable $e) {
                $this->error("租户 #{$tenant->id} 提醒失败：{$e->getMessage()}");
            }
        }

        $this->info("试用到期提醒完成，共发送 {$sent} 封");
        return self::SUCCESS;
    }
}
