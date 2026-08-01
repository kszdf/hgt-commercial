<x-app-layout>
<div class="flex min-h-screen items-center justify-center p-6">
    <div class="luxury-glass w-full max-w-md p-8">
        <!-- 品牌区 -->
        <div class="mb-7 flex items-center gap-2.5">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-600 text-lg font-bold text-white shadow-sm">追</div>
            <div>
                <h1 class="text-xl font-bold text-slate-800">创建租户</h1>
                <p class="text-xs text-slate-400">开通你的商用短视频工作台</p>
            </div>
            <div class="ml-auto">
                <button onclick="toggleTheme()" title="切换明暗"
                    class="rounded-lg border border-slate-200 bg-white p-2 text-slate-400 transition hover:border-brand-300 hover:text-brand-500">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                    </svg>
                </button>
            </div>
        </div>

        @if ($errors->any())
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
                {{ $errors->first() }}
            </div>
        @endif

        <form class="space-y-4" method="POST" action="/register">
            @csrf
            <flux:input label="企业 / 团队名称" name="tenant_name" placeholder="追梦科技" required />
            <flux:input label="管理员姓名" name="name" placeholder="您的姓名" required />
            <flux:input label="账号 / 邮箱" name="email" type="email" placeholder="you@company.com" required />
            <flux:input label="密码" name="password" type="password" placeholder="••••••••" required />
            <flux:input label="确认密码" name="password_confirmation" type="password" placeholder="••••••••" required />

            <flux:button variant="primary" type="submit" class="w-full !bg-brand-500 hover:!bg-brand-600 !shadow-sm mt-2">开通工作台</flux:button>
        </form>

        <p class="mt-5 text-center text-sm text-slate-400">
            已有账号？
            <a href="/login" class="font-medium text-brand-500 hover:underline">直接登录</a>
        </p>
    </div>

    <p class="absolute bottom-5 text-xs text-slate-300">
        © 2026 追梦 · 财税短视频智能生产平台
    </p>
</div>
</x-app-layout>
