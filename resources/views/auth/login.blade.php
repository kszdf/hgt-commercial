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

        @php
            $method = 'email';
            if (old('login')) {
                if (filter_var(old('login'), FILTER_VALIDATE_EMAIL)) {
                    $method = 'email';
                } elseif (preg_match('/^1[3-9]\d{9}$/', old('login'))) {
                    $method = 'phone';
                }
            }
        @endphp

        <form class="space-y-4" method="POST" action="/login" id="login-form">
            @csrf

            <!-- 登录方式切换：分区明显，不并列输入 -->
            <div class="mb-5 grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1">
                <button type="button" id="tab-email" onclick="setLoginMethod('email')"
                    class="rounded-lg px-4 py-2.5 text-sm font-semibold transition-all {{ $method === 'email' ? 'bg-white text-brand-600 shadow-sm' : 'text-slate-500 hover:text-slate-700' }}">
                    邮箱登录
                </button>
                <button type="button" id="tab-phone" onclick="setLoginMethod('phone')"
                    class="rounded-lg px-4 py-2.5 text-sm font-semibold transition-all {{ $method === 'phone' ? 'bg-white text-brand-600 shadow-sm' : 'text-slate-500 hover:text-slate-700' }}">
                    手机号登录
                </button>
            </div>

            <div>
                <label id="login-label" for="login" class="block text-sm font-medium text-slate-700">
                    {{ $method === 'email' ? '邮箱' : '手机号' }}
                </label>
                <input id="login" name="login" type="{{ $method === 'email' ? 'email' : 'tel' }}"
                    placeholder="{{ $method === 'email' ? 'you@company.com' : '11 位手机号' }}"
                    required
                    value="{{ old('login') }}"
                    inputmode="{{ $method === 'email' ? 'email' : 'tel' }}"
                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20">
            </div>

            <flux:input label="密码" name="password" type="password" placeholder="••••••••" required />

            <div class="flex items-center justify-between text-sm">
                <label class="flex items-center gap-2 text-slate-500">
                    <flux:checkbox name="remember" /> 记住我
                </label>
                <a href="/forgot-password" class="flex items-center gap-1 font-medium text-brand-600 hover:text-brand-700 hover:underline">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    忘记密码？
                </a>
            </div>

            <flux:button variant="primary" type="submit" class="w-full !bg-brand-500 hover:!bg-brand-600 !shadow-sm mt-2">登录工作台</flux:button>

            <!-- 找回密码兜底入口：大号独立按钮，避免密码框旁的小链接被忽略 -->
            <a href="/forgot-password" class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-600 shadow-sm transition hover:border-brand-300 hover:text-brand-600">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                忘记密码，用手机号/邮箱重置
            </a>
        </form>

        <p class="mt-5 text-center text-sm text-slate-400">
            还没有？
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

<script>
    function setLoginMethod(method) {
        const loginInput = document.getElementById('login');
        const loginLabel = document.getElementById('login-label');
        const tabEmail = document.getElementById('tab-email');
        const tabPhone = document.getElementById('tab-phone');

        if (method === 'email') {
            loginInput.type = 'email';
            loginInput.placeholder = 'you@company.com';
            loginInput.inputMode = 'email';
            loginLabel.textContent = '邮箱';
            tabEmail.className = 'rounded-lg px-4 py-2.5 text-sm font-semibold transition-all bg-white text-brand-600 shadow-sm';
            tabPhone.className = 'rounded-lg px-4 py-2.5 text-sm font-semibold transition-all text-slate-500 hover:text-slate-700';
        } else {
            loginInput.type = 'tel';
            loginInput.placeholder = '11 位手机号';
            loginInput.inputMode = 'tel';
            loginLabel.textContent = '手机号';
            tabPhone.className = 'rounded-lg px-4 py-2.5 text-sm font-semibold transition-all bg-white text-brand-600 shadow-sm';
            tabEmail.className = 'rounded-lg px-4 py-2.5 text-sm font-semibold transition-all text-slate-500 hover:text-slate-700';
        }
        loginInput.value = '';
        loginInput.focus();
    }
</script>
</x-app-layout>
