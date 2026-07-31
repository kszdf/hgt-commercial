<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

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
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'password.min' => '密码至少 8 位。',
            'email.unique' => '该邮箱已注册。',
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

        $tenant = Tenant::create([
            'name' => $request->tenant_name,
            'slug' => $slug,
            'plan' => 'free',
            'status' => 'active',
            'quota_monthly' => 10,
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
