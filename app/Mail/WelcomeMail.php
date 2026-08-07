<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Tenant $tenant)
    {
    }

    public function build()
    {
        return $this->subject('欢迎使用追梦短视频智能生产平台')
            ->view('emails.welcome');
    }
}
