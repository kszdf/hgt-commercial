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
            <p class="mt-2 text-xs text-slate-400">找回密码 · 手机或邮箱</p>
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

        <!-- 方式切换 -->
        <div class="mb-5 flex rounded-xl border border-slate-200 bg-slate-50 p-1">
            <button type="button" id="tabPhone" onclick="switchChannel('phone')"
                class="flex-1 rounded-lg px-3 py-2 text-sm font-medium transition">手机号</button>
            <button type="button" id="tabEmail" onclick="switchChannel('email')"
                class="flex-1 rounded-lg px-3 py-2 text-sm font-medium transition">邮箱</button>
        </div>

        <form class="space-y-4" method="POST" action="/forgot-password">
            @csrf
            <input type="hidden" name="channel" id="channelInput" value="phone">

            <div id="phoneField">
                <flux:input label="手机号" name="phone" type="tel" placeholder="注册时填写的 11 位手机号" required value="{{ old('phone', '') }}" />
            </div>
            <div id="emailField" style="display:none">
                <flux:input label="邮箱" name="email" type="email" placeholder="注册时填写的邮箱" value="{{ old('email', '') }}" />
            </div>

            <flux:button variant="primary" type="submit" class="w-full !bg-brand-500 hover:!bg-brand-600 !shadow-sm mt-2">发送验证码</flux:button>
        </form>

        @if (session('code_sent'))
            <div class="mt-5 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-600">
                {{ session('status') ?? '验证码已发送，请查收。' }}
            </div>

            @if (session('dev_code'))
                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                    演示模式验证码：<span class="font-bold tracking-widest">{{ session('dev_code') }}</span>
                </div>
            @endif

            <a href="/reset-password?channel={{ session('channel', 'phone') }}&account={{ urlencode(session('account', '')) }}"
                class="mt-4 block w-full rounded-lg bg-brand-600 px-4 py-2.5 text-center text-sm font-medium text-white shadow-sm transition hover:bg-brand-700">
                已收到验证码，去重置密码
            </a>
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

<script>
    function switchChannel(ch) {
        var isPhone = ch === 'phone';
        document.getElementById('channelInput').value = ch;
        document.getElementById('phoneField').style.display = isPhone ? 'block' : 'none';
        document.getElementById('emailField').style.display = isPhone ? 'none' : 'block';
        var tp = document.getElementById('tabPhone'), te = document.getElementById('tabEmail');
        [tp, te].forEach(function (b) {
            b.style.background = 'transparent';
            b.style.color = '#64748b';
            b.style.boxShadow = 'none';
        });
        var active = isPhone ? tp : te;
        active.style.background = '#ffffff';
        active.style.color = '#4f46e5';
        active.style.boxShadow = '0 1px 2px rgba(15,23,42,.08)';
        // 切换方式时清空另一个输入框，避免误提交；并只让当前输入框参与必填校验
        var phoneInput = document.querySelector('input[name=phone]');
        var emailInput = document.querySelector('input[name=email]');
        if (isPhone) {
            emailInput.value = '';
            phoneInput.required = true;
            emailInput.required = false;
        } else {
            phoneInput.value = '';
            phoneInput.required = false;
            emailInput.required = true;
        }
    }
    // 初始化高亮
    switchChannel('phone');
</script>
</x-app-layout>
