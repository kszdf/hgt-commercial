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

    // 微信支付 V3（Native 扫码）
    'wechat_pay' => [
        'mchid'         => env('WECHAT_PAY_MCHID'),
        'appid'         => env('WECHAT_PAY_APPID'),
        'serial_no'     => env('WECHAT_PAY_SERIAL_NO'),
        'private_key'   => env('WECHAT_PAY_PRIVATE_KEY'),    // 商户 API 私钥 PEM（含 -----BEGIN PRIVATE KEY-----）
        'api_v3_key'    => env('WECHAT_PAY_API_V3_KEY'),     // APIv3 密钥（32 位）
        'platform_cert' => env('WECHAT_PAY_PLATFORM_CERT'),  // 微信平台证书公钥 PEM（回调验签用）
    ],

    // 支付宝（电脑网站支付 page.pay）
    'alipay' => [
        'app_id'            => env('ALIPAY_APP_ID'),
        'private_key'       => env('ALIPAY_PRIVATE_KEY'),        // 应用私钥 PEM
        'alipay_public_key' => env('ALIPAY_PUBLIC_KEY'),          // 支付宝公钥 PEM（回调验签用）
        'notify_url'        => env('ALIPAY_NOTIFY_URL'),
    ],

    // 试用到期提醒提前天数
    'trial_notify_days' => env('TRIAL_NOTIFY_DAYS', 3),

];
