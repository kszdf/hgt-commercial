<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 统一支付服务：微信支付 V3（Native 扫码）+ 支付宝（电脑网站支付）。
 *
 * 所有密钥走 config('services.wechat_pay' / 'alipay')，缺失时抛出 RuntimeException，
 * 由调用方降级为「支付通道配置中」提示，不影响站点其余功能。
 *
 * 注意：微信平台证书（platform_cert）需从商户平台下载后粘贴进 .env，
 *      用于回调报文验签；APIv3 密钥用于回调解密。
 */
class PaymentService
{
    // 套餐价格（元/月）。free 不收款。用户可在此调整。
    public const PLAN_PRICE = [
        'free'       => 0,
        'pro'        => 199,
        'enterprise' => 1999,
    ];

    public const PLAN_QUOTA = [
        'free'       => 10,
        'pro'        => 200,
        'enterprise' => 0, // 0 = 不限量
    ];

    /** 创建支付订单，返回支付参数（或免费套餐直接生效）。 */
    public function createOrder(Tenant $tenant, string $plan, string $gateway): array
    {
        if (! array_key_exists($plan, self::PLAN_PRICE)) {
            throw new RuntimeException('未知套餐');
        }

        $amount = self::PLAN_PRICE[$plan];
        if ($amount <= 0) {
            // 免费套餐无需收款，直接激活
            $this->activatePlan($tenant, $plan);
            return ['gateway' => 'free', 'paid' => true];
        }

        $order = Order::create([
            'tenant_id'         => $tenant->id,
            'plan'              => $plan,
            'amount'            => $amount,
            'currency'          => 'CNY',
            'gateway'           => $gateway,
            'gateway_order_no'  => $this->genOutTradeNo($tenant, $plan),
            'status'            => 'pending',
        ]);

        if ($gateway === 'wechat') {
            return [
                'gateway'  => 'wechat',
                'code_url' => $this->wechatNative($order),
                'order_id' => $order->id,
            ];
        }
        if ($gateway === 'alipay') {
            return [
                'gateway' => 'alipay',
                'pay_url' => $this->alipayPage($order),
                'order_id' => $order->id,
            ];
        }
        throw new RuntimeException('不支持的支付通道');
    }

    // ============================================================
    // 微信支付 V3（Native 扫码）
    // ============================================================

    private function wechatNative(Order $order): string
    {
        $cfg = config('services.wechat_pay');
        foreach (['mchid', 'appid', 'private_key', 'serial_no', 'api_v3_key'] as $k) {
            if (empty($cfg[$k])) {
                throw new RuntimeException('微信支付通道未配置');
            }
        }

        $url = 'https://api.mch.weixin.qq.com/v3/pay/transactions/native';
        $body = [
            'appid'        => $cfg['appid'],
            'mchid'        => $cfg['mchid'],
            'description'  => '追梦短视频平台-' . $this->planName($order->plan) . '套餐',
            'out_trade_no' => $order->gateway_order_no,
            'notify_url'   => rtrim(config('app.url'), '/') . '/pay/wechat/notify',
            'amount'       => [
                'total'    => (int) round($order->amount * 100),
                'currency' => 'CNY',
            ],
        ];
        $bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE);
        $auth = $this->wechatBuildAuth('POST', '/v3/pay/transactions/native', $bodyJson, $cfg);

        $resp = Http::withHeaders([
            'Authorization' => $auth,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'User-Agent'    => 'hgt-commercial',
        ])->post($url, $body);

        if (! $resp->successful()) {
            Log::error('wechat pay create failed', ['resp' => $resp->body()]);
            throw new RuntimeException('微信下单失败：' . $resp->body());
        }

        return $resp->json('code_url');
    }

    private function wechatBuildAuth(string $method, string $urlPath, string $body, array $cfg): string
    {
        $timestamp = time();
        $nonce = bin2hex(random_bytes(12));
        $message = $method . "\n" . $urlPath . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $key = openssl_pkey_get_private($cfg['private_key']);
        if ($key === false) {
            throw new RuntimeException('微信商户私钥格式错误');
        }
        openssl_sign($message, $sig, $key, OPENSSL_ALGO_SHA256);
        $signature = base64_encode($sig);

        return sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%d",serial_no="%s"',
            $cfg['mchid'], $nonce, $signature, $timestamp, $cfg['serial_no']
        );
    }

    /** 微信异步回调：验签 + 解密 + 标记支付成功。 */
    public function handleWechatNotify(string $body, array $headers): void
    {
        $cfg = config('services.wechat_pay');
        if (empty($cfg['platform_cert'])) {
            throw new RuntimeException('微信平台证书未配置');
        }

        $timestamp = $headers['wechatpay-timestamp'] ?? '';
        $nonce     = $headers['wechatpay-nonce'] ?? '';
        $sig       = $headers['wechatpay-signature'] ?? '';
        $message   = $timestamp . "\n" . $nonce . "\n" . $body . "\n";

        $pub = openssl_pkey_get_public($cfg['platform_cert']);
        if ($pub === false || openssl_verify($message, base64_decode($sig), $pub, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('微信回调验签失败');
        }

        $data = json_decode($body, true);
        $raw  = $data['resource'] ?? [];
        $plain = $this->wechatDecrypt($raw['ciphertext'] ?? '', $raw['nonce'] ?? '', $raw['associated_data'] ?? '', $cfg['api_v3_key']);
        $notify = json_decode($plain, true);

        if (($notify['trade_state'] ?? '') !== 'SUCCESS') {
            return;
        }
        $this->completeOrder($notify['out_trade_no'], $data);
    }

    private function wechatDecrypt(string $ciphertext, string $nonce, string $aad, string $key): string
    {
        $key = substr($key, 0, 32); // APIv3 密钥固定 32 字节
        $plain = openssl_decrypt(base64_decode($ciphertext), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        if ($plain === false) {
            throw new RuntimeException('微信回调解密失败');
        }
        return $plain;
    }

    // ============================================================
    // 支付宝（电脑网站支付 page.pay）
    // ============================================================

    private function alipayPage(Order $order): string
    {
        $cfg = config('services.alipay');
        foreach (['app_id', 'private_key', 'alipay_public_key', 'notify_url'] as $k) {
            if (empty($cfg[$k])) {
                throw new RuntimeException('支付宝通道未配置');
            }
        }

        $biz = [
            'out_trade_no' => $order->gateway_order_no,
            'product_code' => 'FAST_INSTANT_TRADE_PAY',
            'total_amount' => number_format($order->amount, 2, '.', ''),
            'subject'      => '追梦短视频平台-' . $this->planName($order->plan) . '套餐',
        ];

        $params = [
            'app_id'      => $cfg['app_id'],
            'method'      => 'alipay.trade.page.pay',
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'notify_url'  => $cfg['notify_url'],
            'return_url'  => rtrim(config('app.url'), '/') . '/pay/return',
            'biz_content' => json_encode($biz, JSON_UNESCAPED_UNICODE),
        ];
        $params['sign'] = $this->alipaySign($params, $cfg['private_key']);

        return 'https://openapi.alipay.com/gateway.do?' . http_build_query($params);
    }

    private function alipaySign(array $params, string $privateKey): string
    {
        ksort($params);
        $str = '';
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $str .= $k . '=' . $v . '&';
        }
        $str = rtrim($str, '&');

        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new RuntimeException('支付宝应用私钥格式错误');
        }
        openssl_sign($str, $sig, $key, OPENSSL_ALGO_SHA256);
        return base64_encode($sig);
    }

    public function handleAlipayNotify(array $post): void
    {
        $cfg = config('services.alipay');
        if (empty($cfg['alipay_public_key'])) {
            throw new RuntimeException('支付宝公钥未配置');
        }
        if (! $this->alipayVerify($post, $cfg['alipay_public_key'])) {
            throw new RuntimeException('支付宝回调验签失败');
        }
        if (($post['trade_status'] ?? '') !== 'TRADE_SUCCESS' && ($post['trade_status'] ?? '') !== 'TRADE_FINISHED') {
            return;
        }
        $this->completeOrder($post['out_trade_no'] ?? '', $post);
    }

    private function alipayVerify(array $post, string $publicKey): bool
    {
        $sign = $post['sign'] ?? '';
        $signType = $post['sign_type'] ?? 'RSA2';
        $data = $post;
        unset($data['sign'], $data['sign_type']);
        ksort($data);
        $str = '';
        foreach ($data as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $str .= $k . '=' . $v . '&';
        }
        $str = rtrim($str, '&');

        $key = openssl_pkey_get_public($publicKey);
        $algo = $signType === 'RSA2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
        return openssl_verify($str, base64_decode($sign), $key, $algo) === 1;
    }

    // ============================================================
    // 公共
    // ============================================================

    private function completeOrder(string $outTradeNo, array $raw = []): void
    {
        $order = Order::where('gateway_order_no', $outTradeNo)
            ->where('status', 'pending')
            ->first();
        if (! $order) {
            return;
        }
        $order->markPaid($raw);
        $this->activatePlan($order->tenant, $order->plan);
    }

    public function activatePlan(Tenant $tenant, string $plan): void
    {
        $tenant->update([
            'plan'          => $plan,
            'quota_monthly' => self::PLAN_QUOTA[$plan],
            'trial_ends_at' => null,
            'status'        => 'active',
        ]);
    }

    private function genOutTradeNo(Tenant $tenant, string $plan): string
    {
        return 'ZM' . date('YmdHis') . $tenant->id . strtoupper(substr(md5($plan . uniqid()), 0, 4));
    }

    private function planName(string $plan): string
    {
        return match ($plan) {
            'pro'        => '专业版',
            'enterprise' => '企业版',
            default      => '免费版',
        };
    }
}
