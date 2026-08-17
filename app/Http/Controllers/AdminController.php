<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * 计费与配额（SaaS 商用基础）。
 * 当前实现：套餐/月度额度/用量统计 + 升级切换（支付网关为后续集成点，预留接口）。
 * 另含超级管理员专属的租户（试用账号）管理：列表 / 新建试用账号 / 行内编辑权限。
 */
class AdminController extends Controller
{
    // 套餐额度表（quota_monthly = 0 表示不限量）
    private const PLAN_QUOTA = [
        'free' => 10,
        'pro' => 200,
        'enterprise' => 0,
    ];

    // 新建试用账号默认值（超管可在表单覆盖）
    private const TRIAL_DEFAULT_DAYS = 7;
    private const TRIAL_DEFAULT_MAX_JOBS = 20;     // 累计总条数（0=不限）
    private const TRIAL_DEFAULT_MAX_MINUTES = 0;   // 累计总时长（0=不限）
    private const TRIAL_DEFAULT_ALLOW_BATCH = false;

    public function __construct()
    {
        // 仅超管（tenant_id === null）可访问租户管理相关方法；其余计费方法不受影响。
        $this->middleware(function ($request, $next) {
            abort_unless(
                $request->user() && $request->user()->isGlobalAdmin(),
                403,
                '仅超级管理员可访问'
            );
            return $next($request);
        })->only(['tenants', 'storeTrial', 'updateTrial']);
    }

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

    /**
     * 超级管理员：租户（试用账号）管理列表。
     * 仅超管（tenant_id === null）可访问，调用方需在路由/中间件层守卫。
     * 列出全部租户，并附各试用权限与用量快照。
     */
    public function tenants(Request $request)
    {
        $tenants = Tenant::orderByDesc('created_at')->get();

        $rows = $tenants->map(function (Tenant $t) {
            return [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'plan' => $t->plan,
                'plan_label' => $t->planLabel(),
                'status' => $t->status,
                'trial_ends_at' => $t->trial_ends_at?->format('Y-m-d H:i'),
                'trial_days_left' => $t->trialDaysLeft(),
                'quota_monthly' => $t->quota_monthly,
                'usage_month' => $t->usageThisMonth(),
                'trial_max_jobs' => $t->trial_max_jobs,
                'trial_jobs_used' => $t->trialJobsUsed(),
                'trial_max_minutes' => $t->trial_max_minutes,
                'trial_minutes_used' => $t->trialMinutesUsed(),
                'allow_batch' => (bool) $t->allow_batch,
                'admin_email' => $t->users()->orderBy('id')->value('email'),
                'created_at' => $t->created_at?->format('Y-m-d H:i'),
            ];
        })->all();

        return view('admin.tenants', [
            'rows' => $rows,
            'defaults' => [
                'days' => self::TRIAL_DEFAULT_DAYS,
                'max_jobs' => self::TRIAL_DEFAULT_MAX_JOBS,
                'max_minutes' => self::TRIAL_DEFAULT_MAX_MINUTES,
                'allow_batch' => self::TRIAL_DEFAULT_ALLOW_BATCH,
            ],
        ]);
    }

    /**
     * 超级管理员：新建试用账号（租户 + 首个管理员用户）。
     * 试用账号数量不限（超管可创建任意多个）；每个试用账号的权限由表单设定。
     */
    public function storeTrial(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tenant_name' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^1[3-9]\d{9}$/', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:6'],
            'trial_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'trial_max_jobs' => ['required', 'integer', 'min:0'],
            'trial_max_minutes' => ['required', 'integer', 'min:0'],
            'allow_batch' => ['sometimes', 'boolean'],
        ], [
            'tenant_name.required' => '请填写企业 / 团队名称。',
            'name.required' => '请填写管理员姓名。',
            'email.required' => '请填写邮箱登录账号。',
            'email.email' => '邮箱格式不正确。',
            'email.unique' => '该邮箱已注册。',
            'phone.required' => '请填写手机号。',
            'phone.regex' => '手机号格式不正确（须为 11 位大陆手机号）。',
            'phone.unique' => '该手机号已注册。',
            'password.required' => '请设置登录密码。',
            'password.min' => '密码至少 6 位。',
            'trial_days.required' => '请填写试用天数。',
            'trial_days.integer' => '试用天数须为整数。',
            'trial_max_jobs.required' => '请填写累计生成条数上限（0 表示不限）。',
            'trial_max_minutes.required' => '请填写累计生成时长上限（0 表示不限）。',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // 试用账号亦可在注册层被限流；此处由超管主动创建，不设独立限流。
        $base = \Illuminate\Support\Str::slug($request->tenant_name) ?: ('t' . time());
        $slug = $base;
        $i = 1;
        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        $tenant = Tenant::create([
            'name' => $request->tenant_name,
            'slug' => $slug,
            'plan' => 'free',
            'status' => 'active',
            'trial_ends_at' => now()->addDays((int) $request->trial_days),
            'quota_monthly' => (int) env('TRIAL_VIDEO_QUOTA', 10),
            'trial_max_jobs' => (int) $request->trial_max_jobs,
            'trial_max_minutes' => (int) $request->trial_max_minutes,
            'allow_batch' => (bool) $request->boolean('allow_batch'),
            'default_avatar' => 'BGZSP20260721_t18_silent.mp4',
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'email_verified_at' => now(),
        ]);

        return redirect()->route('admin.tenants')
            ->with('success', '试用账号「' . $tenant->name . '」已创建（' . $request->trial_days . ' 天 / 累计 ' . ($request->trial_max_jobs ?: '不限') . ' 条）。');
    }

    /**
     * 超级管理员：行内编辑某租户的试用权限（天数 / 累计条数 / 累计时长 / 批量外发）。
     * 仅影响权限字段，不重建账号；立即生效（下次生成即按新限额校验）。
     */
    public function updateTrial(Request $request, Tenant $tenant)
    {
        $validator = Validator::make($request->all(), [
            'trial_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:3650'],
            'trial_max_jobs' => ['sometimes', 'required', 'integer', 'min:0'],
            'trial_max_minutes' => ['sometimes', 'required', 'integer', 'min:0'],
            'allow_batch' => ['sometimes', 'required', 'boolean'],
        ], [
            'trial_days.integer' => '试用天数须为整数。',
            'trial_max_jobs.integer' => '累计条数须为整数。',
            'trial_max_minutes.integer' => '累计时长须为整数。',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $patch = [];
        if ($request->has('trial_days')) {
            // 以当前为基准顺延：避免每次编辑都把到期日拉回今天
            $patch['trial_ends_at'] = now()->addDays((int) $request->trial_days);
        }
        if ($request->has('trial_max_jobs')) {
            $patch['trial_max_jobs'] = (int) $request->trial_max_jobs;
        }
        if ($request->has('trial_max_minutes')) {
            $patch['trial_max_minutes'] = (int) $request->trial_max_minutes;
        }
        if ($request->has('allow_batch')) {
            $patch['allow_batch'] = (bool) $request->boolean('allow_batch');
        }

        $tenant->update($patch);

        return redirect()->route('admin.tenants')
            ->with('success', '租户「' . $tenant->name . '」的试用权限已更新。');
    }
}
