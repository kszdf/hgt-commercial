<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $pay)
    {
    }

    /** 下单：接收 plan + gateway，返回支付参数（JSON）。 */
    public function checkout(Request $request)
    {
        $plan = $request->input('plan');
        $gateway = $request->input('gateway', 'wechat');
        // 超管(tenant_id=null)回退 pro/enterprise 租户作为操作上下文，与全站 studioTenant 一致，避免强类型 TypeError
        $tenant = $this->studioTenant($request);

        try {
            $result = $this->pay->createOrder($tenant, $plan, $gateway);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    /** 前端轮询订单支付状态。 */
    public function orderStatus(Request $request)
    {
        $order = Order::where('tenant_id', $this->studioTenant($request)->id)
            ->where('id', $request->input('order_id'))
            ->first();

        return response()->json(['paid' => $order ? $order->status === 'paid' : false]);
    }

    /** 微信异步回调（CSRF 豁免，见 routes/web.php）。 */
    public function wechatNotify(Request $request)
    {
        $body = $request->getContent();
        try {
            $this->pay->handleWechatNotify($body, $request->headers->all());
        } catch (\Throwable $e) {
            Log::error('wechat notify error', ['msg' => $e->getMessage()]);
            return response()->json(['code' => 'FAIL', 'message' => $e->getMessage()], 500);
        }
        return response()->json(['code' => 'SUCCESS', 'message' => '成功']);
    }

    /** 支付宝异步回调（CSRF 豁免，见 routes/web.php）。 */
    public function alipayNotify(Request $request)
    {
        try {
            $this->pay->handleAlipayNotify($request->all());
        } catch (\Throwable $e) {
            Log::error('alipay notify error', ['msg' => $e->getMessage()]);
            return response('failure', 500);
        }
        return response('success');
    }

    /** 支付宝同步跳转页（仅展示，不信任其支付状态）。 */
    public function return(Request $request)
    {
        return view('pay.return');
    }
}
