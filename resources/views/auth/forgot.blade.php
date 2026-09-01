<x-app-layout>
<div class="flex min-h-screen flex-col items-center justify-center p-6">
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
            <img src="/images/logo.jpg" alt="追梦" class="mx-auto h-20 w-auto rounded-2xl shadow-md">
            <h1 class="mt-3 text-xl font-bold tracking-tight text-slate-800">找回密码</h1>
            <p class="mt-1 text-sm text-slate-500">邮箱验证码 · 安全重置登录密码</p>
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

        <form class="space-y-4" method="POST" action="/forgot-password">
            @csrf
            <div>
                <flux:input label="注册邮箱" name="email" type="email" placeholder="注册时填写的邮箱，如 you@company.com"
                    required value="{{ old('email', '') }}" />
                <p class="mt-1 text-xs text-slate-400">验证码将发送到该邮箱，5 分钟内有效。</p>
            </div>

            <flux:button variant="primary" type="submit" class="w-full !bg-brand-500 hover:!bg-brand-600 !shadow-sm mt-2">发送验证码</flux:button>
        </form>

        @if (session('code_sent'))
            <div class="mt-5 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-600">
                {{ session('status') ?? '验证码已发送，请查收。' }}
            </div>

            @if (session('dev_code') && ! app()->environment('production'))
                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                    演示模式验证码：<span class="font-bold tracking-widest">{{ session('dev_code') }}</span>
                </div>
            @endif

            <a href="/reset-password?account={{ urlencode(session('account', '')) }}"
                class="mt-4 block w-full rounded-lg bg-brand-600 px-4 py-2.5 text-center text-sm font-medium text-white shadow-sm transition hover:bg-brand-700">
                已收到验证码，去重置密码
            </a>
        @endif

        <p class="mt-5 text-center text-sm text-slate-400">
            <a href="/login" class="font-medium text-brand-500 hover:underline">返回登录</a>
            <span class="mx-2 text-slate-300">·</span>
            <a href="/reset-password" class="font-medium text-brand-500 hover:underline">已有验证码？直接去重置密码</a>
        </p>
    </div>

    <p class="mt-6 shrink-0 text-center text-xs text-slate-300">
        © 2026 追梦 · 短视频智能生产平台<br>
        <a href="https://beian.miit.gov.cn" target="_blank" rel="nofollow" class="underline hover:text-white">苏ICP备2026023229号-2</a>
    </p>
</div>
</x-app-layout>
