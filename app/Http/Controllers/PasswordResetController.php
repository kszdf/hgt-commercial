<?php

namespace App\Http\Controllers;

use App\Mail\ResetCodeMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * 密码找回（仅邮箱）：输入注册时填写的邮箱 → 发送 6 位验证码邮件 → 校验后重置密码。
 * 已移除手机短信通道（不再依赖 TENCENT_SMS_*）。
 */
class PasswordResetController extends Controller
{
    // 找回密码：输入邮箱页
    public function showForgot()
    {
        return view('auth.forgot');
    }

    // 发送邮箱验证码（60s 节流 + 每日 10 次上限）
    public function sendCode(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => '请填写注册时使用的邮箱。',
            'email.email' => '邮箱格式不正确。',
        ]);

        $email = strtolower(trim($request->email));

        if (! User::where('email', $email)->exists()) {
            return back()->withErrors(['email' => '该邮箱未注册，请确认填写的是注册时使用的邮箱。'])->withInput();
        }

        $dailyKey = 'reset_daily_email_' . $email;
        $sent = (int) cache()->get($dailyKey, 0);
        if ($sent >= 10) {
            return back()->withErrors(['email' => '今日验证码发送次数已达上限，请明天再试或联系管理员。'])->withInput();
        }

        if (cache()->has('reset_throttle_email_' . $email)) {
            return back()->withErrors(['email' => '验证码已发送，请 60 秒后再试。'])->withInput();
        }

        $code = (string) random_int(100000, 999999);
        cache()->put('reset_code_email_' . $email, $code, now()->addMinutes(5));
        cache()->put('reset_throttle_email_' . $email, true, now()->addMinutes(1));
        cache()->put($dailyKey, $sent + 1, now()->endOfDay());

        $delivered = false;
        try {
            Mail::to($email)->send(new ResetCodeMail($code));
            $delivered = true;
        } catch (\Throwable $e) {
            $delivered = false;
        }

        // 验证码仅允许在【非生产环境】（local/staging 等）回显，供自测跑通流程；
        // 生产环境（APP_ENV=production）绝不在页面显示验证码——否则 SMTP 故障时
        // 任何知道他人邮箱的人都能直接看到验证码并重置密码，构成账号接管漏洞。
        $devCode = null;
        if (! $delivered) {
            if (app()->environment('production')) {
                $msg = '邮件发送失败，请稍后重试；若持续失败请联系客服处理。';
            } else {
                $msg = '邮件服务未配置或发送失败（演示模式）：请使用下方验证码重置密码。';
                $devCode = $code;
            }
        } else {
            $msg = '验证码已发送至 ' . $email . '，5 分钟内有效。';
        }

        return back()
            ->with('account', $email)
            ->with('code_sent', true)
            ->with('dev_code', $devCode)
            ->with('status', $msg);
    }

    // 重置密码页
    public function showReset(Request $request)
    {
        $account = $request->query('account', old('account', ''));
        return view('auth.reset', compact('account'));
    }

    // 校验验证码 + 设新密码（仅邮箱）
    public function reset(Request $request)
    {
        $request->validate([
            'account' => ['required', 'email'],
            'code' => ['required', 'string', 'digits:6'],
            'password' => [
                'required', 'confirmed', 'string', 'min:8', 'max:16',
                new \App\Rules\StrongPassword(),
            ],
        ], [
            'account.required' => '请填写注册时使用的邮箱。',
            'account.email' => '邮箱格式不正确。',
            'code.required' => '请填写验证码。',
            'code.digits' => '验证码为 6 位数字。',
            'password.required' => '请设置新密码。',
            'password.confirmed' => '两次输入的密码不一致。',
            'password.min' => '密码至少 8 位。',
            'password.max' => '密码最多 16 位。',
            'password.regex' => '密码至少 6 位，且需由大写字母、小写字母、数字、特殊字符中至少两种组合。',
        ]);

        $email = strtolower(trim($request->account));
        $cached = cache()->get('reset_code_email_' . $email);
        $user = User::where('email', $email)->first();

        if (! $user) {
            return back()->withErrors(['account' => '该邮箱未注册。'])->withInput();
        }
        if (! $cached || $cached !== $request->code) {
            return back()->withErrors(['code' => '验证码不正确或已过期。'])->withInput();
        }

        $user->update(['password' => Hash::make($request->password)]);

        // 用完即焚
        cache()->forget('reset_code_email_' . $email);
        cache()->forget('reset_throttle_email_' . $email);

        return redirect('/login')->with('status', '密码已重置，请用新密码登录。');
    }
}
