<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
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
            'plans' => [
                'free' => ['label' => '免费版', 'quota' => self::PLAN_QUOTA['free']],
                'pro' => ['label' => '专业版', 'quota' => self::PLAN_QUOTA['pro']],
                'enterprise' => ['label' => '企业版', 'quota' => '不限'],
            ],
        ]);
    }

    /**
     * 升级/切换套餐（支付网关集成前的占位实现：直接切换额度）。
     * TODO(Phase 3 后续): 接入支付（微信支付/Stripe）后再真正扣费并切换。
     */
    public function upgrade(Request $request)
    {
        $plan = $request->input('plan');
        if (! array_key_exists($plan, self::PLAN_QUOTA)) {
            return back()->withErrors(['plan' => '未知套餐']);
        }

        $tenant = $request->user()->tenant;
        $tenant->update([
            'plan' => $plan,
            'quota_monthly' => self::PLAN_QUOTA[$plan],
        ]);

        return redirect()->route('admin.billing')
            ->with('status', '已切换到「' . (self::PLAN_QUOTA[$plan] === 0 ? '企业版(不限量)' : $plan) . '」');
    }
}
