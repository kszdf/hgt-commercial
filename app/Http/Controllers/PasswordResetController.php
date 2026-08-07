<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    protected SmsService $sms;

    public function __construct(SmsService $sms)
    {
        $this->sms = $sms;
    }

    // 忘记密码：输入手机号页
    public function showForgot()
    {
        return view('auth.forgot');
    }

    // 发送验证码（带 60 秒重发限制 + 每日上限）
    public function sendCode(Request $request)
    {
        $request->validate([
            'phone' => ['required', 'string', 'regex:/^1[3-9]\d{9}$/'],
        ], [
            'phone.required' => '请填写手机号。',
            'phone.regex' => '手机号格式不正确。',
        ]);

        $phone = $request->phone;

        // 手机号必须已注册
        if (! User::where('phone', $phone)->exists()) {
            return back()->withErrors(['phone' => '该手机号未注册，请确认或改用其他登录方式。'])->withInput();
        }

        // 每日上限（每个手机号每天最多 10 条）
        $dailyKey = 'reset_daily_' . $phone;
        $sent = (int) cache()->get($dailyKey, 0);
        if ($sent >= 10) {
            return back()->withErrors(['phone' => '今日验证码发送次数已达上限，请明天再试或联系管理员。'])->withInput();
        }

        // 60 秒重发限制
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
            ->with('phone', $phone)
            ->with('code_sent', true)
            ->with('status', '验证码已发送至 ' . substr($phone, 0, 3) . '****' . substr($phone, -4) . '，5 分钟内有效。');
    }

    // 重置密码页（带手机号）
    public function showReset(Request $request)
    {
        $phone = $request->query('phone', old('phone', ''));
        return view('auth.reset', ['phone' => $phone]);
    }

    // 校验验证码 + 设新密码
    public function reset(Request $request)
    {
        $request->validate([
            'phone' => ['required', 'string', 'regex:/^1[3-9]\d{9}$/'],
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
            'phone.required' => '请填写手机号。',
            'phone.regex' => '手机号格式不正确。',
            'code.required' => '请填写验证码。',
            'code.digits' => '验证码为 6 位数字。',
            'password.required' => '请设置新密码。',
            'password.confirmed' => '两次输入的密码不一致。',
            'password.min' => '密码至少 6 位。',
            'password.regex' => '密码需含大小写字母，或数字与特殊字符组合。',
        ]);

        $phone = $request->phone;
        $cached = cache()->get('reset_code_' . $phone);

        if (! $cached || $cached !== $request->code) {
            return back()->withErrors(['code' => '验证码不正确或已过期。'])->withInput();
        }

        $user = User::where('phone', $phone)->firstOrFail();
        $user->update(['password' => Hash::make($request->password)]);

        // 用完即焚
        cache()->forget('reset_code_' . $phone);
        cache()->forget('reset_throttle_' . $phone);

        return redirect('/login')->with('status', '密码已重置，请用新密码登录。');
    }
}
