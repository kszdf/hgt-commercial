<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended('/dashboard');
        }

        return back()->withErrors([
            'email' => '账号或密码不正确。',
        ])->onlyInput('email');
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
            'default_male_voice' => 'cosyvoice-v3-plus-zhangc2-28a7c3541e1c45518a03046c11baeb1d',
            'default_female_voice' => 'cosyvoice-v3-plus-jiangnv3-991b204c1d564ac7a60f0cb9a8fd78bd',
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'email_verified_at' => now(),
        ]);

        Auth::login($user);

        return redirect('/dashboard');
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
