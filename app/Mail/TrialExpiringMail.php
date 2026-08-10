<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TrialExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $expiresAtText;

    public function __construct(public Tenant $tenant, public int $daysLeft)
    {
        // trial_ends_at 以 UTC 存储，转北京时间展示精确到期时刻
        $this->expiresAtText = $tenant->trial_ends_at
            ? $tenant->trial_ends_at->clone()->tz('Asia/Shanghai')->format('Y年n月j日 H:i')
            : '未设置';
    }

    public function build()
    {
        return $this->subject('您的追梦免费试用即将结束')
            ->view('emails.trial-expiring');
    }
}
