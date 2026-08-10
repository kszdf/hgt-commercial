<x-app-layout>
<div class="flex min-h-screen items-center justify-center p-6">
    <div class="luxury-glass w-full max-w-md p-8">
        <div class="relative mb-7 text-center">
            <div class="absolute right-0 top-0">
                <button onclick="toggleTheme()" title="切换明暗"
                    class="rounded-lg border border-slate-200 bg-white p-2 text-slate-400 transition hover:border-brand-300 hover:text-brand-500">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                    </svg>
                </button>
            </div>
            <img src="/images/logo.jpg" alt="追梦" class="mx-auto h-20 w-auto rounded-2xl shadow-sm">
            <p class="mt-2 text-xs text-slate-400">设置新密码</p>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-600">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="mb-4 space-y-1 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
                @foreach ($errors->all() as $err)
                    <div>{{ $err }}</div>
                @endforeach
            </div>
        @endif

        <form class="space-y-4" method="POST" action="/reset-password">
            @csrf
            <input type="hidden" name="channel" value="{{ $channel }}">

            @if ($channel === 'email')
                <flux:input label="邮箱" name="account" type="email" placeholder="注册时填写的邮箱" required value="{{ old('account', $account ?? '') }}" />
            @else
                <flux:input label="手机号" name="account" type="tel" placeholder="注册时填写的手机号" required value="{{ old('account', $account ?? '') }}" />
            @endif

            <flux:input label="验证码" name="code" type="text" inputmode="numeric" placeholder="6 位数字验证码" required value="{{ old('code') }}" />
            <flux:input label="新密码" name="password" type="password" placeholder="••••••••" required />
            <p class="text-xs text-slate-400 -mt-2">至少 6 位；需含大小写字母，或数字与特殊字符组合。</p>
            <flux:input label="确认新密码" name="password_confirmation" type="password" placeholder="••••••••" required />

            <flux:button variant="primary" type="submit" class="w-full !bg-brand-500 hover:!bg-brand-600 !shadow-sm mt-2">重置密码</flux:button>
        </form>

        <p class="mt-5 text-center text-sm text-slate-400">
            <a href="/login" class="font-medium text-brand-500 hover:underline">返回登录</a>
        </p>
    </div>

    <p class="absolute bottom-5 left-0 right-0 text-center text-xs text-slate-300">
        © 2026 追梦 · 短视频智能生产平台<br>
        <a href="https://beian.miit.gov.cn" target="_blank" rel="nofollow" class="underline hover:text-white">苏ICP备2026023229号-2</a>
    </p>
</div>
</x-app-layout>
