<?php

namespace App\Http\Controllers;

use App\Mail\ResetCodeMail;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class PasswordResetController extends Controller
{
    protected SmsService $sms;

    public function __construct(SmsService $sms)
    {
        $this->sms = $sms;
    }

    // 找回密码：选择方式页
    public function showForgot()
    {
        return view('auth.forgot');
    }

    // 发送验证码（按 channel 分流：手机走短信，邮箱走邮件/自测兜底）
    public function sendCode(Request $request)
    {
        $request->validate([
            'channel' => ['required', 'in:phone,email'],
            'phone' => ['nullable', 'string', 'regex:/^1[3-9]\d{9}$/'],
            'email' => ['nullable', 'email'],
        ]);

        return $request->channel === 'phone'
            ? $this->sendByPhone($request)
            : $this->sendByEmail($request);
    }

    // 手机渠道：短信验证码（依赖 TENCENT_SMS_* 配置，未配置会明确报错）
    protected function sendByPhone(Request $request)
    {
        $phone = $request->phone;

        if (! User::where('phone', $phone)->exists()) {
            return back()->withErrors(['phone' => '该手机号未注册，请确认或改用邮箱方式。'])->withInput();
        }

        $dailyKey = 'reset_daily_' . $phone;
        $sent = (int) cache()->get($dailyKey, 0);
        if ($sent >= 10) {
            return back()->withErrors(['phone' => '今日验证码发送次数已达上限，请明天再试或联系管理员。'])->withInput();
        }

        if (cache()->has('reset_throttle_' . $phone)) {
            return back()->withErrors(['phone' => '验证码已发送，请 60 秒后再试。'])->withInput();
        }

        $code = (string) random_int(100000, 999999);
        cache()->put('reset_code_' . $phone, $code, now()->addMinutes(5));
        cache()->put('reset_throttle_' . $phone, true, now()->addMinutes(1));
        cache()->put($dailyKey, $sent + 1, now()->endOfDay());

        try {
            $this->sms->sendCode($phone, $code);
        } catch (\Exception $e) {
            // 发送失败：清理刚写入的码，避免用户拿到码却以为成功
            cache()->forget('reset_code_' . $phone);
            return back()->withErrors(['phone' => $e->getMessage()])->withInput();
        }

        return back()
            ->with('channel', 'phone')
            ->with('account', $phone)
            ->with('code_sent', true)
            ->with('status', '验证码已发送至 ' . substr($phone, 0, 3) . '****' . substr($phone, -4) . '，5 分钟内有效。');
    }

    // 邮箱渠道：邮件验证码；邮件服务未真正送达时自测兜底，页面直接显示验证码
    protected function sendByEmail(Request $request)
    {
        $email = $request->email;

        if (! User::where('email', $email)->exists()) {
            return back()->withErrors(['email' => '该邮箱未注册，请确认或改用手机方式。'])->withInput();
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

        // 邮件服务未真正送达（log 邮件器或未配置凭据）时，进入自测兜底：页面显示验证码
        if (! $delivered || config('mail.default') === 'log') {
            $msg = '验证码已生成（演示模式：邮件服务未配置，请使用下方验证码直接重置）。';
            $devCode = $code;
        } else {
            $msg = '验证码已发送至 ' . $email . '，5 分钟内有效。';
            $devCode = null;
        }

        return back()
            ->with('channel', 'email')
            ->with('account', $email)
            ->with('code_sent', true)
            ->with('dev_code', $devCode)
            ->with('status', $msg);
    }

    // 重置密码页（带 channel / account）
    public function showReset(Request $request)
    {
        $channel = $request->query('channel', old('channel', 'phone'));
        $account = $request->query('account', old('account', ''));
        return view('auth.reset', compact('channel', 'account'));
    }

    // 校验验证码 + 设新密码（支持 phone / email 两种账号）
    public function reset(Request $request)
    {
        $request->validate([
            'channel' => ['required', 'in:phone,email'],
            'account' => ['required', 'string'],
            'code' => ['required', 'string', 'digits:6'],
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
            'channel.required' => '请选择找回方式。',
            'account.required' => '请填写手机号或邮箱。',
            'code.required' => '请填写验证码。',
            'code.digits' => '验证码为 6 位数字。',
            'password.required' => '请设置新密码。',
            'password.confirmed' => '两次输入的密码不一致。',
            'password.min' => '密码至少 6 位。',
            'password.regex' => '密码需含大小写字母，或数字与特殊字符组合。',
        ]);

        $channel = $request->channel;
        $account = $request->account;

        if ($channel === 'phone') {
            if (! preg_match('/^1[3-9]\d{9}$/', $account)) {
                return back()->withErrors(['account' => '手机号格式不正确。'])->withInput();
            }
            $cached = cache()->get('reset_code_' . $account);
            $user = User::where('phone', $account)->first();
        } else {
            if (! filter_var($account, FILTER_VALIDATE_EMAIL)) {
                return back()->withErrors(['account' => '邮箱格式不正确。'])->withInput();
            }
            $cached = cache()->get('reset_code_email_' . $account);
            $user = User::where('email', $account)->first();
        }

        if (! $user) {
            return back()->withErrors(['account' => '该账号未注册。'])->withInput();
        }

        if (! $cached || $cached !== $request->code) {
            return back()->withErrors(['code' => '验证码不正确或已过期。'])->withInput();
        }

        $user->update(['password' => Hash::make($request->password)]);

        // 用完即焚
        $codeKey = $channel === 'phone' ? 'reset_code_' . $account : 'reset_code_email_' . $account;
        $throttleKey = $channel === 'phone' ? 'reset_throttle_' . $account : 'reset_throttle_email_' . $account;
        cache()->forget($codeKey);
        cache()->forget($throttleKey);

        return redirect('/login')->with('status', '密码已重置，请用新密码登录。');
    }
}
