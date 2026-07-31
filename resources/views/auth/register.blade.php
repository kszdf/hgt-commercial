<x-app-layout>
<div class="flex min-h-screen items-center justify-center p-6">
    <div class="luxury-glass magnetic w-full max-w-md rounded-3xl p-8 shadow-2xl">
        <div class="mb-7 flex items-start justify-between">
            <div>
                <h1 class="text-3xl font-semibold tracking-tight text-gradient">创建租户</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">开通你的商用短视频工作台</p>
            </div>
            <button onclick="toggleTheme()" title="切换明暗"
                class="rounded-full border border-white/15 bg-white/5 p-2.5 text-slate-500 transition hover:text-brand-500 dark:text-slate-300">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" />
                </svg>
            </button>
        </div>

        @if ($errors->any())
            <div class="mb-4 rounded-xl border border-red-400/40 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                {{ $errors->first() }}
            </div>
        @endif

        <form class="space-y-5" method="POST" action="/register">
            @csrf
            <flux:input label="企业 / 团队名称" name="tenant_name" placeholder="慧根堂" required />
            <flux:input label="管理员姓名" name="name" placeholder="张老师" required />
            <flux:input label="账号 / 邮箱" name="email" type="email" placeholder="you@company.com" required />
            <flux:input label="密码" name="password" type="password" placeholder="••••••••" required />
            <flux:input label="确认密码" name="password_confirmation" type="password" placeholder="••••••••" required />

            <flux:button variant="primary" type="submit" class="w-full !bg-brand-600 hover:!bg-brand-500">开通工作台</flux:button>
        </form>

        <p class="mt-6 text-center text-sm text-slate-500 dark:text-slate-400">
            已有账号？
            <a href="/login" class="font-medium text-brand-500 hover:underline">直接登录</a>
        </p>
    </div>

    <p class="absolute bottom-6 text-xs text-slate-400 dark:text-slate-600">
        © 2026 慧根堂 · 财税短视频智能生产平台
    </p>
</div>
</x-app-layout>
