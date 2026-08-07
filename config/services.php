<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // 腾讯云短信（手机验证码登录/找回密码）
    'tencent_sms' => [
        'secret_id'  => env('TENCENT_SMS_SECRET_ID'),
        'secret_key' => env('TENCENT_SMS_SECRET_KEY'),
        'sdk_app_id' => env('TENCENT_SMS_SDK_APP_ID'),
        'sign_name'  => env('TENCENT_SMS_SIGN_NAME'),
        'template_id' => env('TENCENT_SMS_TEMPLATE_ID'),
    ],

];
