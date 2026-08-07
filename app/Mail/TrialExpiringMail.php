<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TrialExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Tenant $tenant, public int $daysLeft)
    {
    }

    public function build()
    {
        return $this->subject('您的追梦免费试用即将结束')
            ->view('emails.trial-expiring');
    }
}
