@props([
    'title' => '追梦 · 短视频智能工作台',
    'breadcrumbs' => [],
])

@php
    $t = auth()->user()->tenant;
    // 超管(tenant_id=null)使用默认主题，不依赖租户配置
    $isAdmin = is_null($t);
    if ($isAdmin) {
        $preset = 'indigo';
        $ov = [];
        $density = 'comfortable';
        $accent = null;
        $pageTint = null;
    } else {
        $preset = in_array($t->theme_preset, ['indigo', 'warm', 'teal'], true) ? $t->theme_preset : 'indigo';
        $ov = is_array($t->theme_overrides) ? $t->theme_overrides : (json_decode($t->theme_overrides ?? '{}', true) ?: []);
        $density = in_array($ov['density'] ?? null, ['comfortable', 'compact'], true) ? $ov['density'] : 'comfortable';
        $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $ov['accent'] ?? '') ? $ov['accent'] : null;
        $pageTint = preg_match('/^#[0-9a-fA-F]{6}$/', $ov['page_tint'] ?? '') ? $ov['page_tint'] : null;
    }
@endphp
<script>
  (function () {
    var root = document.documentElement;
    root.setAttribute('data-theme', '{{ $preset }}');
    root.setAttribute('data-density', '{{ $density }}');
    var css = ':root{';
    @if($accent)
    css += '--color-brand-500:{{ $accent }};--color-brand-600:{{ $accent }};--color-brand-700:{{ $accent }};--nav-active-fg:{{ $accent }};';
    @endif
    @if($pageTint)
    css += '--surface-page:{{ $pageTint }};';
    @endif
    css += '}';
    if ('{{ $accent }}{{ $pageTint }}' !== '') {
      var s = document.createElement('style');
      s.setAttribute('data-tenant-theme', '1');
      s.textContent = css;
      document.head.appendChild(s);
    }
  })();
</script>

<div class="flex min-h-screen">
    <!-- ===== 左侧功能菜单栏 ===== -->
    <aside id="workspaceSidebar" class="ws-sidebar group flex w-56 shrink-0 flex-col border-r border-[var(--surface-card-border)] bg-[var(--sidebar-bg)] transition-all duration-200 md:w-56">
        <!-- 品牌 LOGO 标识 -->
        <div class="flex h-16 items-center gap-2.5 border-b border-slate-200/60 px-4">
            <a href="/dashboard" class="flex items-center gap-2.5 no-underline">
                <img src="/images/logo.jpg" alt="追梦" class="h-14 w-14 shrink-0 select-none rounded-xl object-cover shadow-lg ring-1 ring-black/10">
            </a>
            <!-- 移动端折叠按钮 -->
            <button onclick="toggleSidebar()" class="ml-auto rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 md:hidden" aria-label="收起侧栏">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
        </div>

        <!-- 导航 -->
        <nav class="flex-1 overflow-y-auto px-3 py-3">
            <!-- 首页入口 -->
            <a href="/dashboard" class="{{ request()->is('dashboard') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-brand">
                <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                <span class="font-semibold">工作台总览</span>
            </a>

            <!-- 分组：生产管线 -->
            <p class="mb-2.5 mt-4 px-2 text-xs font-semibold uppercase tracking-wider text-slate-400">生产管线</p>
            <ul class="space-y-1">
                <li>
                    <a href="/studio/topic" class="{{ request()->is('studio/topic*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-sky">
                        <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <span>智能选题</span>
                    </a>
                </li>
                <!-- 智能二创（折叠分组：选题二创 / 原始稿二创，不再与其他功能平级排列） -->
                <li class="space-y-0.5">
                    <button type="button" onclick="toggleSub(this)"
                        class="ws-nav-item w-full ws-nav-violet {{ (request()->is('studio/rewrite') || request()->is('studio/rewrite-original*')) ? 'ws-nav-active' : '' }}">
                        <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        <span>智能二创</span>
                        <svg class="chev h-3.5 w-3.5 ml-auto text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </button>
                    <ul class="rewrite-sub ml-3.5 mt-0.5 space-y-0.5 border-l border-slate-200 pl-2.5">
                        <li>
                            <a href="/studio/rewrite" class="{{ request()->is('studio/rewrite') && !request()->is('studio/rewrite-original*') ? 'ws-nav-active' : 'ws-nav-item' }}">
                                <span>选题二创</span>
                            </a>
                        </li>
                        <li>
                            <a href="/studio/rewrite-original" class="{{ request()->is('studio/rewrite-original*') ? 'ws-nav-active' : 'ws-nav-item' }}">
                                <span>原始稿二创</span>
                            </a>
                        </li>
                    </ul>
                </li>
                <li>
                <li>
                    <a href="/studio/dissect" class="{{ request()->is('studio/dissect*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-rose">
                        <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.344 5.657z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z"/></svg>
                        <span>爆款拆解</span>
                    </a>
                </li>
                    <a href="/studio/scroll{{ Request::has('from') ? '?from=' . Request::get('from') : '' }}" class="{{ (request()->is('studio/scroll*') && !request()->is('studio/scroll/qc*')) ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-fresh">
                        <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        <span>视频出片</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/qc" class="{{ request()->is('studio/qc*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-amber">
                        <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span>智能质检</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/review" class="{{ request()->is('studio/review*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-indigo">
                        <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        <span>人工审核</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/videos" class="{{ request()->is('studio/videos*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-teal">
                        <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                        <span>视频生成列表</span>
                    </a>
                </li>
                @php $batchAllowed = $isAdmin ? true : (auth()->user()->tenant->allow_batch ?? false); @endphp
                <li>
                    @if(!$batchAllowed)
                        <a href="/admin/billing" class="ws-nav-item ws-nav-rose" title="当前账号未开放批量外发，开通或升级后解锁">
                            <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                            <span>批量外发</span>
                            <svg class="h-3.5 w-3.5 ml-auto text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        </a>
                    @else
                        <a href="/studio/publish" class="{{ request()->is('studio/publish*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-rose">
                            <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                            <span>批量外发</span>
                        </a>
                    @endif
                </li>
            </ul>

            <!-- 分组：素材管理 -->
            <p class="mb-2.5 mt-6 px-2 text-xs font-semibold uppercase tracking-wider text-slate-400">素材管理</p>
            <ul class="space-y-1">
                <li>
                    <a href="/studio/voices" class="{{ request()->is('studio/voices*') || request()->is('voice-clone*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-violet">
                        <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/></svg>
                        <span>声音库</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/covers" class="{{ request()->is('studio/covers*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-rose">
                        <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <span>封面库</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/xhs" class="{{ request()->is('studio/xhs*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-red">
                        <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v17m0 0c-5.523 0-10-4.477-10-10S6.477 0 12 0s10 4.477 10 10-4.477 10-10 10z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 9l3 3 4-4"/></svg>
                        <span>小红书图文</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/models" class="{{ request()->is('studio/models*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-amber">
                        <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        <span>数字人模特</span>
                    </a>
                </li>
            </ul>

            <!-- 分组：系统 -->
            <p class="mb-2.5 mt-6 px-2 text-xs font-semibold uppercase tracking-wider text-slate-400">系统</p>
            <ul class="space-y-1">
                <li>
                    <a href="/studio/recycle" class="{{ request()->is('studio/recycle*') ? 'ws-nav-active' : 'ws-nav-item' }}">
                        <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        <span>回收站</span>
                    </a>
                </li>
                <li>
                    <a href="/admin/billing" class="{{ request()->is('admin/billing*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-brand">
                        <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                        <span>计费订阅</span>
                    </a>
                </li>
                @if(auth()->user()->isGlobalAdmin())
                <li>
                    <a href="/admin/tenants" class="{{ request()->is('admin/tenants*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-brand">
                        <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a3 3 0 10-2.5-4.5"/></svg>
                        <span>租户管理</span>
                    </a>
                </li>
                <li>
                    <a href="/admin/monitor" class="{{ request()->is('admin/monitor*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-brand">
                        <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
                        <span>监控大盘</span>
                    </a>
                </li>
                @endif
                <li>
                    <a href="/studio/settings/appearance" class="{{ request()->is('studio/settings/appearance*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-brand">
                        <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4 2 2 0 000-4zm0 10v-2m0 2a2 2 0 100 4 2 2 0 000-4zm-6 0H4m2 0a2 2 0 104 0 2 2 0 00-4 0zm12 0h-2m2 0a2 2 0 10-4 0 2 2 0 014 0zM6 6H4m2 0a2 2 0 114 0 2 2 0 01-4 0zm12 0h-2m2 0a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                    <span>外观设置</span>
                </a>
            </li>
            <li>
                <a href="/settings/password" class="{{ request()->is('settings/password*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-brand">
                    <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <span>账号安全</span>
                </a>
            </li>
            </ul>
        </nav>

        <!-- 底部：退出登录（做成明显白卡按钮，自由进出） -->
        <div class="border-t border-slate-200/60 px-3 py-3">
            <form method="POST" action="/logout" class="m-0">
                @csrf
                <button type="submit" title="退出登录" class="ws-nav-item w-full justify-between text-left hover:border-red-200 hover:bg-red-50 hover:text-red-600" aria-label="退出登录">
                    <div class="flex items-center gap-3">
                        <svg class="h-[18px] w-[18px] shrink-0 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        <span class="font-medium">退出登录</span>
                    </div>
                </button>
            </form>
        </div>
    </aside>

    <!-- ===== 右侧主内容区 ===== -->
    <main class="flex min-w-0 flex-1 flex-col overflow-hidden bg-[var(--surface-page)]">
        <!-- 顶栏 -->
        <header class="flex h-14 shrink-0 items-center justify-between border-b border-[var(--surface-card-border)] px-6 bg-[var(--topbar-bg)] backdrop-blur-sm">
            <div class="flex items-center gap-3">
                <!-- 移动端菜单按钮 -->
                <button onclick="toggleSidebar()" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 md:hidden" aria-label="展开侧栏">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <button id="hgtBackBtn" onclick="hgtBack()" aria-label="返回上一页" title="返回上一页"
                    class="hidden rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <h1 class="text-base font-semibold text-slate-800">{{ $title }}</h1>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="location.href='/studio/settings/appearance'" title="外观设置"
                    class="rounded-lg border border-slate-200 bg-white p-2 text-slate-400 transition hover:border-brand-300 hover:text-brand-500 hover:shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4 2 2 0 000-4zm0 10v-2m0 2a2 2 0 100 4 2 2 0 000-4zm-6 0H4m2 0a2 2 0 104 0 2 2 0 00-4 0zm12 0h-2m2 0a2 2 0 10-4 0 2 2 0 014 0zM6 6H4m2 0a2 2 0 114 0 2 2 0 01-4 0zm12 0h-2m2 0a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                </button>
                {{-- 当前用户信息 --}}
                <div class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 shadow-sm">
                    <div class="flex h-7 w-7 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-brand-600 text-[11px] font-bold text-white ring-2 ring-white">
                        {{ strtoupper(mb_substr(auth()->user()->name ?: '?', 0, 1)) }}
                    </div>
                    <div class="flex flex-col leading-tight">
                        <span class="max-w-[120px] truncate text-xs font-semibold text-slate-700">{{ auth()->user()->name }}</span>
                        @if($isAdmin)
                            <span class="text-[10px] font-medium text-brand-600">超级管理员</span>
                        @else
                            <span class="max-w-[120px] truncate text-[10px] text-slate-400">{{ auth()->user()->email }}</span>
                        @endif
                    </div>
                </div>
            </div>
        </header>

        @if($breadcrumbs)
        <div class="border-b border-[var(--surface-card-border)] bg-[var(--topbar-bg)] px-6 py-3">
            <nav class="flex flex-wrap items-center gap-1.5 text-xs text-slate-400" aria-label="breadcrumb">
                @foreach($breadcrumbs as $bc)
                    @if(!empty($bc['url']))
                        <a href="{{ $bc['url'] }}" class="transition hover:text-brand-600">{{ $bc['label'] }}</a>
                        <span class="text-slate-300">/</span>
                    @else
                        <span class="font-medium text-slate-600">{{ $bc['label'] }}</span>
                    @endif
                @endforeach
            </nav>
        </div>
        @endif

        <!-- 内容区（可滚动） -->
        <div class="flex-1 overflow-y-auto">
            {{ $slot }}
        </div>
    </main>
</div>

<!-- 全局 Toast 容器（z-60，顶部居中） -->
<div id="hgtToastWrap" class="pointer-events-none fixed left-1/2 top-5 z-[60] flex -translate-x-1/2 flex-col items-center gap-2"></div>

<!-- 8500 微服务宕机红字预警（全局，心跳轮询触发） -->
<div id="pipelineDownBanner" class="hidden px-4 py-2 text-center text-sm font-medium" style="position:fixed;top:0;left:0;right:0;z-index:70;background:#dc2626;color:#fff;box-shadow:0 4px 12px rgba(0,0,0,.25);">
    <span>⚠ 出片微服务（8500）无响应，选题 / 二创 / 出片 / 爆款拆解等功能暂不可用。请重启 Windows 服务 <b>HGTCommercial8500</b> 后刷新本页。</span>
</div>

<!-- 品牌化删除/操作二次确认模态（z-50） -->
<div id="hgtConfirmModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 px-4 backdrop-blur-sm">
    <div class="luxury-glass w-full max-w-sm p-5">
        <div class="flex items-start gap-3">
            <div id="hgtConfirmIcon" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-500">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            </div>
            <div class="min-w-0 flex-1">
                <div id="hgtConfirmTitle" class="text-sm font-semibold text-slate-800">确认操作</div>
                <div id="hgtConfirmMsg" class="mt-1 text-sm text-slate-500"></div>
            </div>
        </div>
        <div class="mt-4 flex justify-end gap-2">
            <button id="hgtConfirmCancel" type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">取消</button>
            <button id="hgtConfirmOk" type="button" class="rounded-lg bg-red-500 px-4 py-2 text-sm font-medium text-white hover:bg-red-600">确认删除</button>
        </div>
    </div>
</div>

<!-- 移动端遮罩 -->
<div id="sidebarOverlay" onclick="toggleSidebar()" class="fixed inset-0 z-20 hidden bg-black/30 backdrop-blur-[2px] md:hidden"></div>

<style>
/* ===== 工作区导航项（凸起白卡 + 功能色图标） ===== */
.ws-nav-item,
.ws-nav-active {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: var(--nav-py) 0.75rem;
    border-radius: 0.625rem;
    font-size: 0.875rem;
    text-decoration: none;
    border: 1px solid var(--surface-card-border);
    transition: background .15s ease, box-shadow .16s ease, transform .16s ease, color .15s ease, border-color .15s ease;
}

/* 默认态：白色凸起卡片 + 柔和阴影（与侧栏底色拉开，形成立体感） */
.ws-nav-item {
    font-weight: 500;
    color: var(--text-body);
    background: #ffffff;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05), 0 1px 1px rgba(15, 23, 42, 0.03);
}
.ws-nav-item:hover {
    color: var(--text-strong);
    border-color: rgba(15, 23, 42, 0.10);
    box-shadow: 0 4px 10px rgba(15, 23, 42, 0.09), 0 1px 2px rgba(15, 23, 42, 0.05);
    transform: translateY(-1px);
}

/* 图标默认稍淡，悬停时常亮 */
.ws-nav-item svg { opacity: 0.9; transition: opacity .15s ease, transform .15s ease; }
.ws-nav-item:hover svg { opacity: 1; }

/* 功能色：仅作用于每个菜单的第一个图标，文字保持中性（协调不扎眼） */
.ws-nav-item > svg:first-of-type { opacity: 1; }
.ws-nav-fresh  > svg:first-of-type { color: var(--color-fresh-600); }
.ws-nav-violet > svg:first-of-type { color: var(--color-violet-600); }
.ws-nav-sky    > svg:first-of-type { color: var(--color-sky-600); }
.ws-nav-amber  > svg:first-of-type { color: var(--color-amber-600); }
.ws-nav-indigo > svg:first-of-type { color: var(--color-indigo-600); }
.ws-nav-teal   > svg:first-of-type { color: var(--color-teal-600); }
.ws-nav-rose   > svg:first-of-type { color: var(--color-rose-600); }
.ws-nav-brand  > svg:first-of-type { color: var(--color-brand-600); }

/* 激活态：主题强调色填充 + 略强阴影，并覆盖功能色（避免撞色） */
.ws-nav-active {
    font-weight: 600;
    color: var(--nav-active-fg);
    background: var(--nav-active-bg);
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.10), 0 1px 2px rgba(15, 23, 42, 0.05);
}
.ws-nav-active > svg:first-of-type { color: var(--nav-active-fg); opacity: 1; }

/* 二创折叠分组 */
.rewrite-sub.collapsed { display: none; }
.chev { transition: transform 0.15s ease; transform: rotate(90deg); }

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

/* ===== 长任务按钮加载态：凹陷 + 等待光标，明确提示「处理中，请勿重复点击」 ===== */
.zw-btn-loading {
    position: relative;
    cursor: progress !important;
    opacity: 0.92;
    filter: brightness(0.96);
    box-shadow: inset 0 3px 6px rgba(15, 23, 42, 0.22), inset 0 1px 2px rgba(15, 23, 42, 0.16) !important;
    transform: translateY(1px);
    transition: transform .12s ease, box-shadow .12s ease, filter .12s ease;
}
.zw-btn-loading * { pointer-events: none; }
.zw-spinner {
    display: inline-block;
    width: 1em; height: 1em;
    margin-right: 0.5em;
    vertical-align: -0.125em;
    border: 2px solid currentColor;
    border-right-color: transparent;
    border-radius: 50%;
    animation: zw-spin 0.7s linear infinite;
    opacity: 0.95;
}
@keyframes zw-spin { to { transform: rotate(360deg); } }

/* ===== 全局中止浮层：长任务运行中显眼出现，橙红渐变 + 停止图标，固定底部居中 ===== */
#hgtAbortBar {
    position: fixed;
    left: 50%;
    bottom: 26px;
    transform: translateX(-50%) translateY(24px);
    z-index: 85;
    opacity: 0;
    pointer-events: none;
    transition: opacity .18s ease, transform .18s ease;
}
#hgtAbortBar.show {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
    pointer-events: auto;
}
.hgt-abort-btn {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    padding: 13px 26px;
    border-radius: 9999px;
    background: linear-gradient(180deg, #f87171 0%, #ef4444 100%);
    color: #fff;
    font-size: 15px;
    font-weight: 700;
    letter-spacing: .03em;
    border: 2px solid #fff;
    box-shadow: 0 12px 30px rgba(239, 68, 68, .5), 0 3px 8px rgba(0, 0, 0, .22);
    cursor: pointer;
    transition: transform .12s ease, box-shadow .12s ease, filter .12s ease;
}
.hgt-abort-btn:hover {
    filter: brightness(1.06);
    transform: translateY(-1px);
    box-shadow: 0 16px 38px rgba(239, 68, 68, .55), 0 3px 8px rgba(0, 0, 0, .22);
}
.hgt-abort-btn:active { transform: translateY(1px) scale(.98); }
.hgt-abort-btn .stop-ico {
    width: 15px; height: 15px; border-radius: 3px; background: #fff; flex: none;
}
.hgt-abort-btn .pulse-ring {
    position: absolute;
    inset: -2px;
    border-radius: 9999px;
    border: 2px solid rgba(239, 68, 68, .55);
    animation: hgt-abort-pulse 1.4s ease-out infinite;
}
@keyframes hgt-abort-pulse {
    0%   { transform: scale(1);   opacity: .7; }
    70%  { transform: scale(1.18); opacity: 0; }
    100% { transform: scale(1.18); opacity: 0; }
}
</style>

<script>
function toggleSidebar() {
    var sb = document.getElementById('workspaceSidebar');
    var ov = document.getElementById('sidebarOverlay');
    var isOpen = sb.classList.toggle('open');
    ov.classList.toggle('hidden', !isOpen);
}

// 智能二创折叠分组：切换子菜单展开/收起，并联动箭头方向
function toggleSub(btn) {
    var li = btn.closest('li');
    var ul = li.querySelector('.rewrite-sub');
    var chev = btn.querySelector('.chev');
    var collapsed = ul.classList.toggle('collapsed');
    if (chev) chev.style.transform = collapsed ? 'rotate(0deg)' : 'rotate(90deg)';
}

// 移动端：选中导航后自动收起侧栏
document.querySelectorAll('.ws-sidebar a').forEach(function(a) {
    a.addEventListener('click', function() {
        if (window.innerWidth < 768) toggleSidebar();
    });
});

/* ============================================================
   全局 UX 基础设施：Toast / 二次确认 / 返回 / flash 自动转 Toast
   ============================================================ */

// 智能返回：有同域来源则后退，否则回工作台总览
function hgtBack() {
    try {
        if (document.referrer && document.referrer.indexOf(location.origin) === 0) {
            history.back();
            return;
        }
    } catch (e) {}
    location.href = '/dashboard';
}

// 有同域来源时才显示「返回上一页」按钮
(function () {
    var btn = document.getElementById('hgtBackBtn');
    if (btn && document.referrer && document.referrer.indexOf(location.origin) === 0) {
        btn.classList.remove('hidden');
    }
})();

// 全局 Toast：hgtToast(type, msg, duration?)  type ∈ success|error|warn|info
window.hgtToast = function (type, msg, duration) {
    duration = duration || 3200;
    var wrap = document.getElementById('hgtToastWrap');
    if (!wrap) return;
    var palette = {
        success: { bg: '#ecfdf5', bd: '#a7f3d0', fg: '#047857', ic: '✓' },
        error:   { bg: '#fef2f2', bd: '#fecaca', fg: '#b91c1c', ic: '✕' },
        warn:    { bg: '#fffbeb', bd: '#fde68a', fg: '#b45309', ic: '!' },
        info:    { bg: '#eef2ff', bd: '#c7d2fe', fg: '#4338ca', ic: 'i' }
    };
    var c = palette[type] || palette.info;
    var el = document.createElement('div');
    el.style.cssText = 'pointer-events:auto;display:flex;align-items:center;gap:8px;min-width:220px;max-width:92vw;'
        + 'padding:10px 14px;border-radius:10px;background:' + c.bg + ';border:1px solid ' + c.bd + ';'
        + 'color:' + c.fg + ';font-size:13px;font-weight:500;box-shadow:0 8px 24px rgba(15,23,42,.12);'
        + 'opacity:0;transform:translateY(-8px);transition:opacity .2s ease,transform .2s ease;';
    var badge = document.createElement('span');
    badge.style.cssText = 'display:inline-flex;width:18px;height:18px;border-radius:50%;background:' + c.fg
        + ';color:#fff;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex:none;';
    badge.textContent = c.ic;
    var text = document.createElement('span');
    text.textContent = msg;
    el.appendChild(badge);
    el.appendChild(text);
    wrap.appendChild(el);
    requestAnimationFrame(function () { el.style.opacity = '1'; el.style.transform = 'translateY(0)'; });
    setTimeout(function () {
        el.style.opacity = '0'; el.style.transform = 'translateY(-8px)';
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 220);
    }, duration);
};

// 品牌化二次确认：hgtConfirm({title, message, okText, cancelText, danger, onConfirm})
window.hgtConfirm = function (opts) {
    opts = opts || {};
    var modal = document.getElementById('hgtConfirmModal');
    var titleEl = document.getElementById('hgtConfirmTitle');
    var msgEl = document.getElementById('hgtConfirmMsg');
    var okBtn = document.getElementById('hgtConfirmOk');
    var cancelBtn = document.getElementById('hgtConfirmCancel');
    var iconBox = document.getElementById('hgtConfirmIcon');
    titleEl.textContent = opts.title || '确认操作';
    msgEl.textContent = opts.message || '';
    cancelBtn.textContent = opts.cancelText || '取消';
    var danger = opts.danger !== false; // 默认危险态（红）
    if (danger) {
        okBtn.textContent = opts.okText || '确认删除';
        okBtn.className = 'rounded-lg bg-red-500 px-4 py-2 text-sm font-medium text-white hover:bg-red-600';
        iconBox.className = 'flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-500';
    } else {
        okBtn.textContent = opts.okText || '确认';
        okBtn.className = 'rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700';
        iconBox.className = 'flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600';
    }
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    function cleanup() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        okBtn.onclick = null; cancelBtn.onclick = null; modal.onclick = null;
    }
    okBtn.onclick = function () { cleanup(); if (opts.onConfirm) opts.onConfirm(); };
    cancelBtn.onclick = cleanup;
    modal.onclick = function (e) { if (e.target === modal) cleanup(); };
};

// 删除类表单的二次确认：<button onclick="hgtDel(this)" data-msg="...">；点击后确认再提交表单
function hgtDel(btn) {
    var form = btn.closest('form');
    if (!form) return;
    hgtConfirm({
        title: '删除确认',
        message: btn.getAttribute('data-msg') || '确定执行删除？此操作不可撤销。',
        danger: true,
        okText: '确认删除',
        onConfirm: function () { form.submit(); }
    });
}

// 统一的长任务按钮加载态：按钮凹陷 + 旋转图标 + 文案提示；自动禁用，杜绝重复点击
// 用法：zwSetLoading(btn, {loading:true, text:'AI 改写中…'})  /  zwSetLoading(btn, {loading:false})
window.zwSetLoading = function (btn, opts) {
    if (!btn) return;
    opts = opts || {};
    if (opts.loading) {
        if (btn.dataset.zwOrig === undefined) {
            btn.dataset.zwOrig = btn.innerHTML;
            btn.dataset.zwOrigDisabled = btn.disabled ? '1' : '0';
        }
        btn.disabled = true;
        btn.classList.add('zw-btn-loading');
        var label = opts.text || '处理中…';
        btn.innerHTML = '<span class="zw-spinner" aria-hidden="true"></span>' + label;
    } else {
        btn.classList.remove('zw-btn-loading');
        if (btn.dataset.zwOrig !== undefined) {
            btn.innerHTML = btn.dataset.zwOrig;
            btn.disabled = btn.dataset.zwOrigDisabled === '1';
            delete btn.dataset.zwOrig;
            delete btn.dataset.zwOrigDisabled;
        }
    }
};

// ===== 全局中止控制器：任何长任务调用 HGTAbort.begin() 即在底部浮层显示醒目「中止」按钮 =====
// 点击后：① 立即 abort() 当前 fetch（AbortError）；② 可选回调 onAbort 复位 UI；③ 可选 serverCancel 真实停止服务端任务。
// 用法（页面内）：
//   const signal = HGTAbort.begin('中止：AI 改写中…', { serverCancel: '/studio/scroll/cancel?job=' + id });
//   const resp = await fetch(url, { signal });            // fetch 支持 signal，abort 即中断
//   ...finally { HGTAbort.end(); }                        // 无论成功失败都收起浮层
//   catch (e) { if (e.name === 'AbortError') { hgtToast('warn','已中止操作'); return; } }  // 复位/提示
window.HGTAbort = (function () {
    var controller = null;
    var meta = { label: '', onAbort: null, serverCancel: null, serverCancelMethod: 'POST', serverCancelDone: false };
    var bar = null, btn = null, labelEl = null;

    function ensureDom() {
        if (bar) return;
        bar = document.createElement('div');
        bar.id = 'hgtAbortBar';
        btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'hgt-abort-btn';
        btn.innerHTML = '<span class="pulse-ring"></span><span class="stop-ico"></span><span class="hgt-abort-label">中止</span>';
        btn.addEventListener('click', abort);
        bar.appendChild(btn);
        document.body.appendChild(bar);
    }

    function show(label) {
        ensureDom();
        labelEl = btn.querySelector('.hgt-abort-label');
        if (labelEl) labelEl.textContent = label || '中止当前操作';
        // 强制重排后再加 show，确保过渡动画生效
        void bar.offsetWidth;
        bar.classList.add('show');
    }

    function hide() { if (bar) bar.classList.remove('show'); }

    function begin(label, opts) {
        opts = opts || {};
        // 若已有进行中流程，先强制结束旧的（避免信号串台）
        if (controller) { try { controller.abort(); } catch (e) {} }
        controller = new AbortController();
        meta = {
            label: label || '中止当前操作',
            onAbort: opts.onAbort || null,
            serverCancel: opts.serverCancel || null,
            serverCancelMethod: opts.serverCancelMethod || 'POST',
            serverCancelDone: false
        };
        show(label);
        return controller.signal;
    }

    function abort() {
        if (!controller) return;
        try { controller.abort(); } catch (e) {}
        if (meta.onAbort) { try { meta.onAbort(); } catch (e) {} }
        if (meta.serverCancel && !meta.serverCancelDone) {
            meta.serverCancelDone = true;
            callServerCancel();
        }
        hide();
    }

    function callServerCancel() {
        try {
            var token = '';
            var m = document.querySelector('meta[name="csrf-token"]');
            if (m) token = m.getAttribute('content') || '';
            var init = {
                method: meta.serverCancelMethod,
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                keepalive: true
            };
            if (meta.serverCancelMethod.toUpperCase() !== 'GET') {
                init.headers['Content-Type'] = 'application/json';
                init.body = JSON.stringify({});
            }
            fetch(meta.serverCancel, init).catch(function () {});
        } catch (e) {}
    }

    function end() {
        controller = null;
        meta = { label: '', onAbort: null, serverCancel: null, serverCancelMethod: 'POST', serverCancelDone: false };
        hide();
    }

    return {
        begin: begin,
        end: end,
        abort: abort,
        isActive: function () { return !!controller; }
    };
})();

// 服务端 flash（success/error）自动转为 Toast
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-hgt-flash]').forEach(function (el) {
        hgtToast(el.getAttribute('data-hgt-flash'), el.textContent.trim());
        el.remove();
    });
});
</script>

<!-- 全局在线心跳 + 活动上报：仅 /studio/* 页面启用，供超级管理员监控大盘聚合在线态 -->
<script>
(function () {
    var path = window.location.pathname;
    if (typeof path === 'undefined' || path.indexOf('/studio/') !== 0) return;

    function currentAction() {
        if (path.indexOf('/studio/topic') === 0) return 'topic';
        if (path.indexOf('/studio/rewrite') === 0) return 'rewrite';
        if (path.indexOf('/studio/scroll') === 0) return 'video';
        return 'studio';
    }

    function ping() {
        try {
            var token = '';
            var meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) token = meta.getAttribute('content') || '';
            fetch('/studio/activity', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ action: currentAction() }),
                keepalive: true
            }).catch(function () {});
        } catch (e) {}
    }

    ping();                                   // 进入页面立即上报一次
    setInterval(ping, 20000);                 // 每 20s 续报
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) ping();         // 切回前台补报
    });
})();
</script>

<!-- 8500 微服务心跳：进入页面即探测 + 每 60s 轮询，崩了显示红字预警 -->
<script>
(function () {
    var banner = document.getElementById('pipelineDownBanner');
    if (!banner) return;
    function check() {
        fetch('/studio/pipeline-health', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || d.ok === false) {
                    banner.classList.remove('hidden');
                } else {
                    banner.classList.add('hidden');
                }
            })
            .catch(function () {
                // 探测请求本身失败（如会话过期/网络抖动）不强制报红，避免误报；
                // 仅当接口明确返回 ok:false 才显示，防止误伤正常使用。
            });
    }
    check();
    setInterval(check, 60000);
})();
</script>
