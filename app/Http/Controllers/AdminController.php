<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\PaymentService;
use Illuminate\Http\Request;

/**
 * 计费与配额（SaaS 商用基础）。
 * 当前实现：套餐/月度额度/用量统计 + 升级切换（支付网关为后续集成点，预留接口）。
 */
class AdminController extends Controller
{
    // 套餐额度表（quota_monthly = 0 表示不限量）
    private const PLAN_QUOTA = [
        'free' => 10,
        'pro' => 200,
        'enterprise' => 0,
    ];

    public function billing(Request $request)
    {
        $tenant = $request->user()->tenant;
        $recent = $tenant->videoJobs()->latest()->limit(20)->get();

        return view('admin.billing', [
            'tenant' => $tenant,
            'planLabel' => $tenant->planLabel(),
            'usage' => $tenant->usageThisMonth(),
            'quota' => $tenant->quota_monthly,
            'remaining' => $tenant->remainingQuota(),
            'unlimited' => $tenant->isUnlimited(),
            'recent' => $recent,
            'prices' => PaymentService::PLAN_PRICE,
            'plans' => [
                'free' => ['label' => '免费版', 'quota' => self::PLAN_QUOTA['free']],
                'pro' => ['label' => '专业版', 'quota' => self::PLAN_QUOTA['pro']],
                'enterprise' => ['label' => '企业版', 'quota' => '不限'],
            ],
        ]);
    }

    /**
     * 升级/切换套餐（兼容入口：引导用户走支付流程）。
     * 真正扣费与套餐切换由 PaymentController + PaymentService 在支付回调中完成。
     */
    public function upgrade(Request $request)
    {
        return redirect()->route('admin.billing')
            ->with('status', '请在下方选择支付方式完成套餐升级');
    }
}
