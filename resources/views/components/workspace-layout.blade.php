@props(['title' => '追梦 · 短视频智能工作台'])

<div class="flex min-h-screen">
    <!-- ===== 左侧功能菜单栏 ===== -->
    <aside id="workspaceSidebar" class="ws-sidebar group flex w-56 shrink-0 flex-col border-r border-slate-200/80 bg-slate-50/50 transition-all duration-200 md:w-56">
        <!-- 品牌 -->
        <div class="flex h-16 items-center gap-2.5 border-b border-slate-200/60 px-4">
            <a href="/dashboard" class="flex items-center gap-2.5 no-underline">
                <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-base font-bold text-white shadow-md">追</div>
                <span class="text-lg font-bold text-slate-800 tracking-tight">追梦</span>
            </a>
            <!-- 移动端折叠按钮 -->
            <button onclick="toggleSidebar()" class="ml-auto rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 md:hidden" aria-label="收起侧栏">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
        </div>

        <!-- 导航 -->
        <nav class="flex-1 overflow-y-auto px-3 py-3">
            <!-- 首页入口 -->
            <a href="/dashboard" class="{{ request()->is('dashboard') ? 'ws-nav-active' : 'ws-nav-item' }}">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                <span class="font-semibold">工作台总览</span>
            </a>

            <!-- 分组：生产管线 -->
            <p class="mb-2 mt-4 px-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">生产管线</p>
            <ul class="space-y-0.5">
                <li>
                    <a href="/studio/topic" class="{{ request()->is('studio/topic*') ? 'ws-nav-active' : 'ws-nav-item' }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <span>智能选题</span>
                        <span class="ws-nav-num">01</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/rewrite" class="{{ request()->is('studio/rewrite*') ? 'ws-nav-active' : 'ws-nav-item' }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        <span>智能二创</span>
                        <span class="ws-nav-num">02</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/scroll{{ Request::has('from') ? '?from=' . Request::get('from') : '' }}" class="{{ (request()->is('studio/scroll*') && !request()->is('studio/scroll/qc*')) ? 'ws-nav-active' : 'ws-nav-item' }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        <span>视频出片</span>
                        <span class="ws-nav-num">03–05</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/qc" class="{{ request()->is('studio/qc*') ? 'ws-nav-active' : 'ws-nav-item' }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span>智能质检</span>
                        <span class="ws-nav-num">06</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/review" class="{{ request()->is('studio/review*') ? 'ws-nav-active' : 'ws-nav-item' }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        <span>人工审核</span>
                        <span class="ws-nav-num">07</span>
                    </a>
                </li>
                @php $isTrialNav = auth()->user()->tenant->plan === 'free'; @endphp
                <li>
                    @if($isTrialNav)
                        <a href="/admin/billing" class="ws-nav-item" title="免费试用版暂不支持批量外发，升级后解锁">
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                            <span>批量外发</span>
                            <span class="ws-nav-num">08</span>
                            <svg class="h-3.5 w-3.5 ml-auto text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        </a>
                    @else
                        <a href="/studio/publish" class="{{ request()->is('studio/publish*') ? 'ws-nav-active' : 'ws-nav-item' }}">
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                            <span>批量外发</span>
                            <span class="ws-nav-num">08</span>
                        </a>
                    @endif
                </li>
            </ul>

            <!-- 分组：素材管理 -->
            <p class="mb-2 mt-5 px-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">素材管理</p>
            <ul class="space-y-0.5">
                <li>
                    <a href="/studio/voices" class="{{ request()->is('studio/voices*') || request()->is('voice-clone*') ? 'ws-nav-active' : 'ws-nav-item' }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/></svg>
                        <span>声音库</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/covers" class="{{ request()->is('studio/covers*') ? 'ws-nav-active' : 'ws-nav-item' }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <span>封面库</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/models" class="{{ request()->is('studio/models*') ? 'ws-nav-active' : 'ws-nav-item' }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        <span>数字人模特</span>
                    </a>
                </li>
            </ul>

            <!-- 分组：数据运营 -->
            <p class="mb-2 mt-5 px-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">数据运营</p>
            <ul class="space-y-0.5">
                <li>
                    <a href="/studio/metrics" class="{{ request()->is('studio/metrics*') ? 'ws-nav-active' : 'ws-nav-item' }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M4 12h16M4 17h10"/></svg>
                        <span>数据录入</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/analytics" class="{{ request()->is('studio/analytics*') ? 'ws-nav-active' : 'ws-nav-item' }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                        <span>数据复盘</span>
                        <span class="ws-nav-num">09</span>
                    </a>
                </li>
            </ul>

            <!-- 分组：系统 -->
            <p class="mb-2 mt-5 px-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">系统</p>
            <ul class="space-y-0.5">
                <li>
                    <a href="/admin/billing" class="{{ request()->is('admin/billing*') ? 'ws-nav-active' : 'ws-nav-item' }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                        <span>计费订阅</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- 底部租户信息 -->
        <div class="border-t border-slate-200/60 px-4 py-3">
            <div class="flex items-center justify-between">
                <div class="min-w-0">
                    <p class="truncate text-xs text-slate-500">{{ auth()->user()->tenant->name ?? '平台' }}</p>
                </div>
                <form method="POST" action="/logout" class="m-0">
                    @csrf
                    <button type="submit" title="退出登录" class="rounded-lg p-1.5 text-slate-400 transition hover:bg-red-50 hover:text-red-500" aria-label="退出">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <!-- ===== 右侧主内容区 ===== -->
    <main class="flex min-w-0 flex-1 flex-col overflow-hidden bg-white">
        <!-- 顶栏 -->
        <header class="flex h-14 shrink-0 items-center justify-between border-b border-slate-200/60 px-6 bg-white/90 backdrop-blur-sm">
            <div class="flex items-center gap-3">
                <!-- 移动端菜单按钮 -->
                <button onclick="toggleSidebar()" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 md:hidden" aria-label="展开侧栏">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <h1 class="text-base font-semibold text-slate-800">{{ $title }}</h1>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="toggleTheme()" title="切换明暗"
                    class="rounded-lg border border-slate-200 bg-white p-2 text-slate-400 transition hover:border-brand-300 hover:text-brand-500 hover:shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                    </svg>
                </button>
                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-brand-100 to-brand-200 text-brand-600 ring-2 ring-white shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                </div>
            </div>
        </header>

        <!-- 内容区（可滚动） -->
        <div class="flex-1 overflow-y-auto">
            {{ $slot }}
        </div>
    </main>
</div>

<!-- 移动端遮罩 -->
<div id="sidebarOverlay" onclick="toggleSidebar()" class="fixed inset-0 z-20 hidden bg-black/30 backdrop-blur-[2px] md:hidden"></div>

<style>
/* ===== 工作区导航项 ===== */
.ws-nav-item {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
    font-size: 0.8125rem;
    font-weight: 500;
    color: #475569;
    text-decoration: none;
    transition: all 0.15s ease;
}
.ws-nav-item:hover {
    background-color: #f1f5f9;
    color: #334155;
}
.ws-nav-item svg {
    opacity: 0.65;
    transition: opacity 0.15s;
}
.ws-nav-item:hover svg {
    opacity: 1;
}

/* 激活态 */
.ws-nav-active {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
    font-size: 0.8125rem;
    font-weight: 600;
    color: #4f46e5; /* brand-600 */
    background-color: #eef2ff; /* brand-50 */
    text-decoration: none;
}
.ws-nav-active svg {
    color: #4f46e5;
    opacity: 1;
}

/* 步骤编号 */
.ws-nav-num {
    margin-left: auto;
    padding: 0 0.375rem;
    border-radius: 0.25rem;
    font-size: 0.6875rem;
    font-weight: 700;
    line-height: 1.5;
    background-color: #e2e8f0;
    color: #64748b;
}
.ws-nav-active .ws-nav-num {
    background-color: #c7d2fe; /* brand-200 */
    color: #3730a3; /* brand-800 */
}

/* 侧栏折叠（移动端） */
@media (max-width: 767px) {
    .ws-sidebar {
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        z-index: 40;
        transform: translateX(-100%);
        box-shadow: none;
    }
    .ws-sidebar.open {
        transform: translateX(0);
        box-shadow: 4px 0 24px rgba(0,0,0,0.1);
    }
}
</style>

<script>
function toggleSidebar() {
    var sb = document.getElementById('workspaceSidebar');
    var ov = document.getElementById('sidebarOverlay');
    var isOpen = sb.classList.toggle('open');
    ov.classList.toggle('hidden', !isOpen);
}

// 移动端：选中导航后自动收起侧栏
document.querySelectorAll('.ws-sidebar a').forEach(function(a) {
    a.addEventListener('click', function() {
        if (window.innerWidth < 768) toggleSidebar();
    });
});
</script>
