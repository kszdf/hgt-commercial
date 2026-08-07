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
            <p class="mt-2 text-xs text-slate-400">找回密码 · 手机验证码</p>
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
            <flux:input label="手机号" name="phone" type="tel" placeholder="注册时填写的 11 位手机号" required value="{{ old('phone', session('phone', '')) }}" />
            <flux:button variant="primary" type="submit" class="w-full !bg-brand-500 hover:!bg-brand-600 !shadow-sm mt-2">发送验证码</flux:button>
        </form>

        @if (session('code_sent'))
            <div class="mt-5 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-600">
                {{ session('status') ?? '验证码已发送，请查收短信。' }}
            </div>
            <form class="space-y-4 mt-4" method="GET" action="/reset-password">
                @csrf
                <input type="hidden" name="phone" value="{{ session('phone', old('phone')) }}">
                <flux:button variant="primary" type="submit" class="w-full !bg-brand-600 hover:!bg-brand-700 !shadow-sm">已收到验证码，去重置密码</flux:button>
            </form>
        @endif

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
