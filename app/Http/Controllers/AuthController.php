<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Mail\WelcomeMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required'],
        ]);

        // 支持「手机号或邮箱」作为登录名：按输入格式判定字段。
        $login = $request->input('login');
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        if (Auth::attempt([$field => $login, 'password' => $request->password], $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended('/dashboard');
        }

        return back()->withErrors([
            'login' => '邮箱或手机号与密码不匹配。',
        ])->onlyInput('login');
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tenant_name' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'unique:users,email'],
            // 手机号必填：合法大陆手机号且全局唯一（用于手机登录与找回密码）。
            'phone' => ['required', 'string', 'regex:/^1[3-9]\d{9}$/', 'unique:users,phone'],
            // 至少 6 位；且需含大小写字母，或含数字与特殊字符组合。
            // 用闭包自定义规则：跨版本兼容，且规避正则中 | 被规则分隔符误拆的问题。
            'password' => [
                'required', 'confirmed', 'string', 'min:6',
                function ($attribute, $value, $fail) {
                    $hasMixedCase = preg_match('/^(?=.*[a-z])(?=.*[A-Z]).*$/', $value);
                    $hasNumSymbol = preg_match('/^(?=.*\d)(?=.*[^A-Za-z0-9]).*$/', $value);
                    if (! ($hasMixedCase || $hasNumSymbol)) {
                        $fail('密码需含大小写字母，或数字与特殊字符组合。');
                    }
                },
            ],
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
            'password.confirmed' => '两次输入的密码不一致，请重新输入密码。',
            'password.min' => '密码至少 6 位。',
            'password.regex' => '密码需含大小写字母，或数字与特殊字符组合。',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $base = \Illuminate\Support\Str::slug($request->tenant_name) ?: ('t' . time());
        $slug = $base;
        $i = 1;
        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        // 自动开通：注册即创建 active 租户并进入免费试用（无需人工审批）。
        $tenant = Tenant::create([
            'name' => $request->tenant_name,
            'slug' => $slug,
            'plan' => 'free',
            'status' => 'active',
            'trial_ends_at' => now()->addDays((int) env('TRIAL_DAYS', 7)),
            'quota_monthly' => (int) env('TRIAL_VIDEO_QUOTA', 10),
            'default_avatar' => 'BGZSP20260721_t18_silent.mp4',
            // 新租户初始无自带声音：default_male_voice/default_female_voice 留 NULL，
            // 必须由租户自行克隆或选择公开模板后显式传入（遵循通用行业平台铁律，禁止把特定克隆音当通用音分发）。
            // 运营者 huigentang 租户的声音由其专属 DatabaseSeeder 预设，不受此影响。
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone ?: null,
            'password' => Hash::make($request->password),
            'email_verified_at' => now(),
        ]);

        Auth::login($user);

        // 注册欢迎邮件（邮件服务未配置时静默失败，不阻断注册）
        try {
            Mail::to($user->email)->send(new WelcomeMail($tenant));
        } catch (\Throwable $e) {
            report($e);
        }

        return redirect('/dashboard');
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    // 账号安全：改密码页
    public function showChangePassword()
    {
        return view('settings.password');
    }

    // 账号安全：执行改密码（需校验当前密码 + 新密码规则）
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required'],
            'password' => [
                'required', 'confirmed', 'string', 'min:6',
                function ($attribute, $value, $fail) {
                    $hasMixedCase = preg_match('/^(?=.*[a-z])(?=.*[A-Z]).*$/', $value);
                    $hasNumSymbol = preg_match('/^(?=.*\d)(?=.*[^A-Za-z0-9]).*$/', $value);
                    if (! ($hasMixedCase || $hasNumSymbol)) {
                        $fail('密码需含大小写字母，或数字与特殊字符组合。');
                    }
                },
            ],
        ], [
            'current_password.required' => '请填写当前密码。',
            'password.required' => '请设置新密码。',
            'password.confirmed' => '两次输入的密码不一致。',
            'password.min' => '密码至少 6 位。',
            'password.regex' => '密码需含大小写字母，或数字与特殊字符组合。',
        ]);

        if (! Hash::check($request->current_password, $user->password)) {
            return back()->withErrors(['current_password' => '当前密码不正确。'])->withInput();
        }

        $user->update(['password' => Hash::make($request->password)]);

        return back()->with('success', '密码已更新成功，下次登录请使用新密码。');
    }
}
