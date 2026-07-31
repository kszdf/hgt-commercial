<x-app-layout>
<div class="flex min-h-screen">

    <!-- 侧边栏 -->
    <aside class="luxury-glass hidden w-64 shrink-0 flex-col border-r border-white/10 p-5 md:flex">
        <div class="mb-8 flex items-center gap-2">
            <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-600 text-lg font-bold text-white">慧</div>
            <span class="text-lg font-semibold text-gradient">慧根堂</span>
        </div>

        <nav class="flex-1 space-y-1 text-sm">
            <a href="#" class="flex items-center gap-3 rounded-xl bg-brand-600/15 px-3 py-2.5 font-medium text-brand-600 dark:text-brand-300">
                <span>🏠</span> 工作台
            </a>
            <a href="#" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-slate-500 hover:bg-white/5 dark:text-slate-400">
                <span>🔍</span> 选题中心
            </a>
            <a href="#" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-slate-500 hover:bg-white/5 dark:text-slate-400">
                <span>✍️</span> 智能二创
            </a>
            <a href="/studio/scroll" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-slate-500 hover:bg-white/5 dark:text-slate-400">
                <span>🎬</span> 视频出片
            </a>
            <a href="#" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-slate-500 hover:bg-white/5 dark:text-slate-400">
                <span>🏢</span> 租户管理
            </a>
            <a href="/admin/billing" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-slate-500 hover:bg-white/5 dark:text-slate-400">
                <span>💳</span> 计费订阅
            </a>
        </nav>

        <div class="mt-6 rounded-xl bg-white/5 p-3 text-xs text-slate-500 dark:text-slate-400">
            当前租户：<span class="font-medium text-slate-700 dark:text-slate-200">{{ auth()->user()->tenant->name ?? '平台' }}</span>
        </div>
    </aside>

    <!-- 主区 -->
    <main class="flex-1 overflow-y-auto">
        <!-- 顶栏 -->
        <header class="luxury-glass sticky top-0 z-10 flex items-center justify-between border-b border-white/10 px-6 py-4">
            <div>
                <h2 class="text-xl font-semibold">工作台</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">欢迎回来，{{ auth()->user()->name }} · 财税短视频智能生产</p>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="toggleTheme()" title="切换明暗"
                    class="rounded-full border border-white/15 bg-white/5 p-2 text-slate-500 transition hover:text-brand-500 dark:text-slate-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" />
                    </svg>
                </button>
                <form method="POST" action="/logout" class="m-0">
                    @csrf
                    <button type="submit" title="退出登录"
                        class="rounded-full border border-white/15 bg-white/5 px-3 py-2 text-sm text-slate-500 transition hover:text-red-400 dark:text-slate-300">
                        退出
                    </button>
                </form>
                <div class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white">{{ mb_substr(auth()->user()->name, 0, 1) }}</div>
            </div>
        </header>

        <!-- 模块卡 -->
        <section class="grid grid-cols-1 gap-5 p-6 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ([
                ['🔍','选题中心','联网检索 + 手动导入，热度/竞争度评估','即将上线'],
                ['✍️','智能二创','三模式改写，违禁词标红，时长控字数','即将上线'],
                ['🎙️','脚本与配音','专属模板，音色可切，改后实时试听','即将上线'],
                ['🎬','视频出片','数字人出镜 / 滚动字幕卡，真实克隆配音','可用'],
                ['🏢','租户管理','独立账号，自定义形象与声音隔离','可用'],
                ['💳','计费订阅','套餐 / 配额 / 用量统计','可用'],
            ] as $m)
                <div class="luxury-glass magnetic rounded-2xl p-5">
                    <div class="mb-3 flex items-center justify-between">
                        <span class="text-2xl">{{ $m[0] }}</span>
                        <span class="rounded-full bg-brand-600/15 px-2.5 py-1 text-xs font-medium text-brand-600 dark:text-brand-300">{{ $m[3] }}</span>
                    </div>
                    <h3 class="text-base font-semibold">{{ $m[1] }}</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $m[2] }}</p>
                </div>
            @endforeach
        </section>

        <div class="px-6 pb-10">
            <div class="luxury-glass rounded-2xl p-6 text-center text-sm text-slate-500 dark:text-slate-400">
                Phase 1–3 已落地 · 真实多租户账号 / 滚动字幕卡 / 数字人出片 / 计费配额已可用；选题与二创为下一阶段
            </div>
        </div>
    </main>
</div>
</x-app-layout>
