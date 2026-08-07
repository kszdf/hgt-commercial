<?php

namespace App\Services;

use TencentCloud\Common\Credential;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Sms\V20210111\SmsClient;
use TencentCloud\Sms\V20210111\Models\SendSmsRequest;

/**
 * 腾讯云短信封装：仅负责"发送验证码"这个动作。
 * 配置见 config/services.php 的 tencent_sms（对应 .env 的 TENCENT_SMS_*）。
 */
class SmsService
{
    /**
     * 发送短信验证码。
     *
     * @param  string  $phone  11 位大陆手机号（不含 +86）
     * @param  string  $code   6 位验证码
     * @return bool   发送成功返回 true
     * @throws \Exception 配置缺失或腾讯云返回非 Ok
     */
    public function sendCode(string $phone, string $code): bool
    {
        $cfg = config('services.tencent_sms');
        foreach (['secret_id', 'secret_key', 'sdk_app_id', 'sign_name', 'template_id'] as $k) {
            if (empty($cfg[$k])) {
                throw new \Exception('短信服务未配置完整（请填写 .env 的 TENCENT_SMS_* 五项）。');
            }
        }

        $cred = new Credential($cfg['secret_id'], $cfg['secret_key']);
        $client = new SmsClient($cred, 'ap-guangzhou');

        $req = new SendSmsRequest();
        $req->setSmsSdkAppId($cfg['sdk_app_id']);
        $req->setSignName($cfg['sign_name']);
        $req->setTemplateId($cfg['template_id']);
        $req->setPhoneNumberSet(['+86' . $phone]);
        // 模板参数：{1} 为验证码，{2} 为有效期（分钟）。模板需自行在腾讯云控制台申请。
        $req->setTemplateParamSet([$code, '5']);

        try {
            $resp = $client->SendSms($req);
        } catch (TencentCloudSDKException $e) {
            throw new \Exception('短信网关异常：' . $e->getMessage());
        }

        $statusSet = $resp->SendStatusSet ?? [];
        if (!empty($statusSet) && ($statusSet[0]->Code ?? '') === 'Ok') {
            return true;
        }

        $reason = $statusSet[0]->Message ?? '未知错误';
        throw new \Exception('短信发送失败：' . $reason);
    }
}
