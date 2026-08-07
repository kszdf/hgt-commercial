<x-app-layout>
<div class="flex min-h-screen items-center justify-center p-6">
    <div class="luxury-glass w-full max-w-md p-8">
        <!-- 品牌区 -->
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
            <p class="mt-2 text-xs text-slate-400">商用短视频智能工作台</p>
        </div>

        @if ($errors->any())
            <div class="mb-4 space-y-1 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
                @foreach ($errors->all() as $err)
                    <div>{{ $err }}</div>
                @endforeach
            </div>
        @endif

        <form class="space-y-4" method="POST" action="/login">
            @csrf
            <flux:input label="账号 / 手机号或邮箱" name="login" type="text" placeholder="手机号或邮箱" required value="{{ old('login') }}" />
            <flux:input label="密码" name="password" type="password" placeholder="••••••••" required />

            <div class="flex items-center justify-between text-sm">
                <label class="flex items-center gap-2 text-slate-500">
                    <flux:checkbox name="remember" /> 记住我
                </label>
                <a href="/forgot-password" class="text-brand-500 hover:underline text-sm">忘记密码？</a>
            </div>

            <flux:button variant="primary" type="submit" class="w-full !bg-brand-500 hover:!bg-brand-600 !shadow-sm mt-2">登录工作台</flux:button>
        </form>

        <p class="mt-5 text-center text-sm text-slate-400">
            还没有账号？
            <a href="/register" class="font-medium text-brand-500 hover:underline">注册</a>
        </p>
    </div>

    <p class="absolute bottom-5 left-0 right-0 text-center text-xs text-slate-300">
        © 2026 追梦 · 短视频智能生产平台<br>
        <a href="https://beian.miit.gov.cn" target="_blank" rel="nofollow" class="underline hover:text-white">苏ICP备2026023229号-2</a>
        <span class="mx-1">·</span>
        <a href="/privacy" class="underline hover:text-white">隐私政策</a>
        <span class="mx-1">·</span>
        <a href="/terms" class="underline hover:text-white">用户协议</a>
    </p>
</div>
</x-app-layout>
