@props([
    'title' => '追梦 · 短视频智能工作台',
    'breadcrumbs' => [],
])

@php
    // v2.0 对话模式为唯一入口: 砍掉所有老菜单,只保留"对话工作台"
    // 之前 cookie 灰度期已结束,现在默认即 v2,不再回退。
    $v2Mode = true;
    $sidebarWidth = 'w-14';

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
    // 顶栏用量胶囊（计费/升级常驻入口，非技术用户升级路径最短化）
    $usage = null; $quota = null; $remaining = null; $quotaUnlimited = false;
    if (! $isAdmin) {
        $usage = $t->usageThisMonth();
        $quota = (int) $t->quota_monthly;
        $remaining = $t->remainingQuota();
        $quotaUnlimited = $t->isUnlimited();
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

@php $isChat = request()->is('studio/chat*'); @endphp
<div class="flex {{ $isChat ? 'h-screen overflow-hidden' : 'min-h-screen' }}">
    <!-- ===== 左侧功能菜单栏 ===== -->
    <aside id="workspaceSidebar" class="ws-sidebar group flex {{ $sidebarWidth }} shrink-0 flex-col border-r border-[var(--surface-card-border)] bg-[var(--sidebar-bg)] transition-all duration-200 md:{{ $sidebarWidth }}">
        <!-- 品牌 LOGO 标识 -->
        <div class="flex h-16 items-center gap-2.5 border-b border-slate-200/60 px-4">
            <a href="/dashboard" class="flex items-center gap-2.5 no-underline">
                <img src="/images/logo.jpg" alt="追梦" class="h-10 w-10 shrink-0 select-none rounded-lg object-cover">
            </a>
            <!-- 移动端折叠按钮 -->
            <button onclick="toggleSidebar()" class="ml-auto rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 md:hidden" aria-label="收起侧栏">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
        </div>

        <!-- 导航 -->
        <nav class="flex-1 overflow-y-auto px-2 py-2">
            <!-- ① 对话工作台：对话驱动主界面（承接原"工作总览"，/dashboard 已 302 → /studio/chat） -->
            <a href="/studio/chat" title="对话工作台" class="{{ (request()->is('studio/chat*') || request()->is('dashboard')) ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-brand">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                <span class="ws-label font-semibold {{ $v2Mode ? 'sr-only' : '' }}">对话工作台</span>
            </a>

            {{-- 2026-10-03：文章库（公众号文章）已下线，短视频平台不再提供 --}}

            <!-- 2026-09-18：智库/客户档案/1v1预约/AI客服 4个未开发能力图标已摘除（张老师拍板暂不开发），
                 路由与页面文件保留，将来恢复只需把导航项加回来。 -->

            <!-- ⑤ 账号：各平台发布账号登记 / OAuth 授权（抖音多应用，09-12 加回入口） -->
            <a href="/studio/accounts" title="账号（发布账号与授权）" class="{{ request()->is('studio/accounts*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-brand">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span class="ws-label font-semibold {{ $v2Mode ? 'sr-only' : '' }}">账号</span>
            </a>


            <!-- ⑥ 素材与账户（聚合组：收纳其余工具页，默认展开；超管额外见 租户管理） -->
            <button type="button" onclick="toggleGroup(this)" title="素材与账户（声音/封面/模特/质检/审核/发布等）" class="ws-group-toggle"><svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg><span class="ws-label">素材与账户</span><svg class="ws-group-chev h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg></button>
            <ul class="space-y-0.5 ws-group-body" data-group="hub">
                <li>
                    <a href="/studio/voices" title="声音库" class="{{ request()->is('studio/voices*') || request()->is('voice-clone*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-violet">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/></svg>
                        <span class="ws-label">声音库</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/covers" title="封面库" class="{{ request()->is('studio/covers*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-rose">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <span class="ws-label">封面库</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/models" title="数字人模特" class="{{ request()->is('studio/models*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-amber">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        <span class="ws-label">数字人模特</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/queue" title="任务队列" class="{{ request()->is('studio/queue*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-teal">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
                        <span class="ws-label">任务队列</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/qc" title="质检" class="{{ request()->is('studio/qc*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-amber">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span class="ws-label">质检</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/review" title="人工审核" class="{{ request()->is('studio/review*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-indigo">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        <span class="ws-label">人工审核</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/publish" title="发布助手" class="{{ request()->is('studio/publish*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-rose">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        <span class="ws-label">发布助手</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/accounts" title="发布渠道" class="{{ request()->is('studio/accounts*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-brand">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                        <span class="ws-label">发布渠道</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/schedule" title="发布排期" class="{{ request()->is('studio/schedule*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-brand">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <span class="ws-label">发布排期</span>
                    </a>
                </li>
                <li>
                    <a href="/studio/metrics" title="数据效果" class="{{ request()->is('studio/metrics*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-teal">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                        <span class="ws-label">数据效果</span>
                    </a>
                </li>
                <li>
                    <a href="/settings/password" title="账号安全" class="{{ request()->is('settings/password*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-violet">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        <span class="ws-label">账号安全</span>
                    </a>
                </li>
                @if(auth()->user()->isGlobalAdmin())
                <li>
                    <a href="/admin/tenants" title="租户管理" class="{{ request()->is('admin/tenants*') ? 'ws-nav-active' : 'ws-nav-item' }} ws-nav-brand">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a3 3 0 10-2.5-4.5"/></svg>
                        <span class="ws-label">租户管理</span>
                    </a>
                </li>
            </ul>
            @endif
        </nav>

        <!-- 侧栏底部：在线状态(对话模式下文字隐藏,仅留小绿点) -->
        <div class="border-t border-slate-200/60 px-2 py-2 flex items-center justify-center">
            <span class="inline-block h-2 w-2 rounded-full bg-emerald-500" title="在线 · v2026.09"></span>
        </div>
    </aside>

    <!-- ===== 右侧主内容区 ===== -->
    <main class="flex min-w-0 flex-1 flex-col overflow-hidden bg-[var(--surface-page)]">
        <!-- 顶栏 -->
        <!-- 顶栏：粘性常驻，对话滚动时也不滚走（带底阴影以区分） -->
        <header class="sticky top-0 z-30 flex h-16 shrink-0 items-center justify-between border-b border-[var(--surface-card-border)] bg-[var(--topbar-bg)]/95 px-6 backdrop-blur-sm shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
            <div class="flex items-center gap-3">
                <!-- 移动端菜单按钮 -->
                <button onclick="toggleSidebar()" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 md:hidden" aria-label="展开侧栏">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <button id="hgtBackBtn" onclick="hgtBack()" aria-label="返回上一页" title="返回上一页"
                    class="hidden rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <h1 class="ws-topbar-title text-slate-800">{{ $title }}</h1>
            </div>
            <div class="flex items-center gap-2">
                {{-- 用量胶囊 + 升级（常驻，付费转化最短路径） --}}
                @if(!$isAdmin)
                    <a href="/admin/billing" title="本月用量 / 计费订阅"
                       class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 shadow-sm transition hover:border-brand-300 hover:text-brand-600">
                        <span class="text-slate-400">本月</span>
                        <span class="font-semibold text-slate-800">{{ $usage }}</span>
                        <span class="text-slate-400">/</span>
                        <span class="font-semibold {{ $quotaUnlimited ? 'text-emerald-600' : ($remaining === 0 ? 'text-red-600' : 'text-slate-800') }}">
                            {{ $quotaUnlimited ? '不限' : $quota }}
                        </span>
                        @if($remaining === 0 && !$quotaUnlimited)
                            <span class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-600">升级</span>
                        @endif
                    </a>
                @endif
                {{-- 用户菜单（计费/账号安全/退出，替代原"系统"组） --}}
                <details class="relative">
                    <summary class="flex cursor-pointer list-none items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 shadow-sm transition hover:border-brand-300">
                        <div class="ws-avatar flex items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-brand-600 font-bold text-white ring-2 ring-white">
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
                        <svg class="h-3 w-3 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </summary>
                    <div class="absolute right-0 top-11 z-40 w-52 rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl">
                        <a href="/admin/billing" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">计费订阅</a>
                        <a href="/studio/help" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">帮助中心</a>
                        @if($isAdmin)
                            <div class="my-1 border-t border-slate-100"></div>
                            <a href="/admin/tenants" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">租户管理</a>
                        @endif
                        <div class="my-1 border-t border-slate-100"></div>
                        <form method="POST" action="/logout">
                            @csrf
                            <button class="w-full rounded-lg px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50">退出登录</button>
                        </form>
                    </div>
                </details>
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

        <!-- 内容区（可滚动，统一限宽居中；对话工作台等全屏页除外） -->
        <div class="flex-1 min-h-0 {{ request()->is('studio/chat*') ? 'overflow-hidden' : 'overflow-y-auto' }}">
            @if(request()->is('studio/chat*'))
                {{-- chat 全宽三栏：锁死高度为 视口-顶栏，只允许对话区内部滚动 --}}
                <div class="ws-chat-full" style="height: calc(100vh - 4rem); min-height: 420px;">{{ $slot }}</div>
            @else
                <div class="ws-content-wrap" style="max-width:1400px;margin:0 auto;padding:0 1.5rem;">
                    {{ $slot }}
                </div>
            @endif
        </div>
    </main>
</div>

<!-- 全局 Toast 容器（z-60，顶部居中） -->
<div id="hgtToastWrap" class="pointer-events-none fixed left-1/2 top-5 z-[60] flex -translate-x-1/2 flex-col items-center gap-2"></div>

<!-- 8500 微服务宕机红字预警（全局，心跳轮询触发） -->
<div id="pipelineDownBanner" class="hidden px-4 py-2 text-center text-sm font-medium" style="position:fixed;top:0;left:0;right:0;z-index:70;background:#dc2626;color:#fff;box-shadow:0 4px 12px rgba(0,0,0,.25);">
    <span>⚠ 出片服务暂时不可用，选题 / 二创 / 出片 / 爆款拆解等功能暂不可用。请稍后刷新重试；如持续异常请联系客服。</span>
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
/* ===== 工作区导航项（轻量列表式 + 功能色图标） ===== */
.ws-nav-item,
.ws-nav-active {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    padding: 0.35rem 0.5rem;
    border-radius: 0.375rem;
    font-size: 0.8125rem;          /* 13px：主菜单项 */
    line-height: 1.25rem;
    text-decoration: none;
    border: 0;
    background: transparent;
    transition: background .12s ease, color .12s ease;
}

/* 默认态：透明底列表项，hover 仅给极淡背景 */
.ws-nav-item {
    font-weight: 400;
    color: var(--text-body);
}
.ws-nav-item:hover {
    color: var(--text-strong);
    background: var(--nav-hover-bg);
}

/* 图标默认稍淡，悬停时常亮 */
.ws-nav-item svg { opacity: 0.85; transition: opacity .12s ease; }
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

/* 激活态：主题色浅底 + 主题色文字，无阴影 */
.ws-nav-active {
    font-weight: 500;
    color: var(--nav-active-fg);
    background: var(--nav-active-bg);
}
.ws-nav-active > svg:first-of-type { color: var(--nav-active-fg); opacity: 1; }

/* 二创折叠分组：父级保持 13px/500，子级 12px/400，层级正确 */
.rewrite-sub.collapsed { display: none; }
.chev { transition: transform 0.15s ease; transform: rotate(90deg); }
.ws-nav-item-wrap { position: relative; }
.ws-nav-item-wrap:hover > .ws-nav-item { background: var(--nav-hover-bg); }
.ws-nav-item-wrap > .ws-nav-item.ws-nav-active:hover { background: var(--nav-active-bg); }
.ws-nav-sub-toggle {
    flex: none;
    width: 1.75rem;
    height: 1.75rem;
    margin-left: 0.125rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 0;
    background: transparent;
    border-radius: 0.375rem;
    cursor: pointer;
    color: var(--text-muted);
}
.ws-nav-sub-toggle:hover { background: var(--nav-hover-bg); color: var(--text-strong); }
.rewrite-sub .ws-nav-item,
.rewrite-sub .ws-nav-active {
    font-size: 0.75rem;            /* 12px：子菜单更小 */
    color: var(--text-muted);
    padding: 0.25rem 0.5rem;
}
.rewrite-sub .ws-nav-active {
    color: var(--nav-active-fg);
    font-weight: 500;
}

/* 侧栏分组可折叠 */
.ws-group-toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    margin: 0.75rem 0 0.25rem;
    padding: 0 0.5rem;
    background: none;
    border: 0;
    cursor: pointer;
    font-size: 0.75rem;            /* 12px：分组标题小字但加粗 */
    font-weight: 600;
    letter-spacing: 0.04em;
    color: var(--text-muted);
}
.ws-group-toggle:first-of-type { margin-top: 0.25rem; }
.ws-group-chev { transition: transform 0.15s ease; transform: rotate(90deg); }
.ws-group-body.collapsed { display: none; }

/* ===== 对话页图标条联动（body.workspace-chat）=====
   导航文字统一用 .ws-label 包裹；chat 页把侧边栏收成图标条时隐藏文字，
   修复历史 .ws-nav-text/.ws-brand-text 选择器失效导致的收起后文字溢出。 */
body.workspace-chat #workspaceSidebar .ws-label { display: none; }
body.workspace-chat #workspaceSidebar .ws-nav-item,
body.workspace-chat #workspaceSidebar .ws-nav-active {
    justify-content: center;
    padding-left: 0.45rem;
    padding-right: 0.45rem;
}

/* 侧栏折叠（移动端）
   2026-10-05 手机端体检修复：原规则无 !important，被 Tailwind 工具类的 transform 覆盖，
   导致部分加载时序下侧栏"该隐藏却显示"（手机上侧栏糊在左边挡住内容）。
   注意：基础态与 .open 态都用 !important，靠"后者优先"决定；两条规则紧密相邻以保证顺序。 */
@media (max-width: 767px) {
    .ws-sidebar {
        position: fixed !important;
        top: 0;
        left: 0;
        bottom: 0;
        z-index: 40;
        transform: translateX(-100%) !important;
        box-shadow: none;
    }
    .ws-sidebar.open {
        transform: translateX(0) !important;
        box-shadow: 4px 0 24px rgba(0,0,0,0.1);
    }
}

/* ===== 移动端内容区适配（2026-10-05 手机端体检后补） ===== */
@media (max-width: 767px) {
    /* 抽屉打开时：侧栏由 56px 图标条临时展开为「图标+文字」240px，避免手机上靠猜图标含义 */
    .ws-sidebar.open {
        width: 240px !important;
        min-width: 240px !important;
        box-shadow: 4px 0 24px rgba(15,23,42,0.16) !important;
    }
    /* 恢复被 sr-only 藏起来的文字（v2 图标条模式在手机抽屉里不适用） */
    .ws-sidebar.open .ws-label {
        position: static !important;
        width: auto !important;
        height: auto !important;
        margin: 0 !important;
        overflow: visible !important;
        clip: auto !important;
        white-space: nowrap !important;
    }
    /* 展开后菜单项左对齐，图标与文字并排 */
    .ws-sidebar.open .ws-nav-item,
    .ws-sidebar.open .ws-nav-active,
    .ws-sidebar.open .ws-group-toggle,
    .ws-sidebar.open .ws-nav-brand {
        justify-content: flex-start !important;
        gap: 0.625rem !important;
        padding-left: 0.75rem !important;
        padding-right: 0.75rem !important;
        min-height: 42px !important;
    }
    /* 内容区左右留白：桌面 24px 在 390px 窄屏上占掉 12%，收窄到 12px 让内容更舒展 */
    .ws-content-wrap {
        padding-left: 0.75rem !important;
        padding-right: 0.75rem !important;
    }
    /* 顶栏：桌面 px-6 在窄屏上太占，收窄；用户名/邮箱过长时已在元素上 truncate */
    header.sticky {
        padding-left: 0.75rem !important;
        padding-right: 0.75rem !important;
    }
    /* 触控友好：手机上按钮最小 40px 高，避免"点不准" */
    .ws-content-wrap button,
    .ws-content-wrap a[role="button"] {
        min-height: 38px;
    }
    /* 表格类内容（成片库/队列）在窄屏允许横向滚动容器内滚动，而不是撑破整页 */
    .ws-content-wrap table {
        display: block;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        white-space: nowrap;
    }
    /* 对话工作台：手机浏览器地址栏会吃掉视口高度，用 dvh 兜底（不支持则回退 vh） */
    .ws-chat-full {
        height: calc(100vh - 4rem);
        height: calc(100dvh - 4rem);
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

/* 进行中流动条：渲染是黑盒进程无实时百分比，用流动动画表达"进行中"而非假数字 */
@keyframes hgtIndet{0%{left:-35%}50%{left:55%}100%{left:110%}}
.hgt-indet{position:relative;overflow:hidden}
.hgt-indet>i{position:absolute;top:0;bottom:0;left:-35%;width:35%;border-radius:9999px;background:#928eea;animation:hgtIndet 1.3s ease-in-out infinite}
/* 批量出片看板专用：复用同一动画，作用于 .bv-bar */
.bv-flow{position:absolute!important;top:0;bottom:0;left:-35%;width:35%!important;border-radius:9999px;background:#928eea;animation:hgtIndet 1.3s ease-in-out infinite}

/* ===== 全局中止浮层：长任务运行中显眼出现，橙红渐变 + 停止图标，固定底部居中 ===== */
#hgtAbortBar {
    position: fixed;
    right: 22px;
    bottom: 26px;
    z-index: 85;
    opacity: 0;
    pointer-events: none;
    transition: opacity .18s ease, transform .18s ease;
    /* 2026-08-31 改右下角+不居中：原底部居中悬浮按钮会遮挡页面底部/中部操作区(如小红书下载按钮) */
    transform: translateY(24px);
}
#hgtAbortBar.show {
    opacity: 1;
    transform: translateY(0);
    pointer-events: auto;
}
.hgt-abort-btn {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    padding: 12px 22px;
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

/* ===== 悬浮说明(tooltip)：点到才显示，不占版面（2026-08-31 全局说明改悬浮） =====
   用法：<span class="hint" data-tip="说明文字">?</span>
   纯 CSS 实现，hover/focus 显示，无 JS 依赖。 */
.hint {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 15px; height: 15px;
    margin-left: 5px;
    border-radius: 9999px;
    background: var(--color-brand-100, #e0e7ff);
    color: var(--color-brand-700, #6d5cff);
    font-size: 10.5px; font-weight: 700;
    cursor: help;
    vertical-align: middle;
    flex: none;
}
.hint::after {
    content: attr(data-tip);
    position: absolute;
    left: 50%;
    bottom: calc(100% + 8px);
    transform: translateX(-50%) translateY(-2px);
    width: max-content;
    max-width: 260px;
    padding: 8px 12px;
    border-radius: 8px;
    background: #1e293b;
    color: #f8fafc;
    font-size: 12.5px;
    font-weight: 400;
    line-height: 1.55;
    text-align: left;
    white-space: normal;
    opacity: 0;
    pointer-events: none;
    transition: opacity .14s ease, transform .14s ease;
    z-index: 95;
    box-shadow: 0 8px 24px rgba(15, 23, 42, .18);
}
.hint::before {
    content: '';
    position: absolute;
    left: 50%;
    bottom: calc(100% + 2px);
    transform: translateX(-50%);
    border: 6px solid transparent;
    border-top-color: #1e293b;
    opacity: 0;
    transition: opacity .14s ease;
    z-index: 95;
}
.hint:hover::after,
.hint:focus::after,
.hint:focus-visible::after {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
}
.hint:hover::before,
.hint:focus::before,
.hint:focus-visible::before {
    opacity: 1;
}
/* 触屏适配：tap 显示 */
.hint:active::after { opacity: 1; transform: translateX(-50%) translateY(0); }
.hint:active::before { opacity: 1; }

/* 表单旁的内联说明已移除时，用 hint 替代的过渡类（无额外样式） */

/* ===== v2 窄栏图标条（2026-09-22 修复"素材与账户"文字漏出）=====
   侧栏恒为 w-14 窄条：所有 ws-label 视觉隐藏（sr-only 等效，屏幕阅读器/JS 仍可读），
   名称提示统一靠各导航项 title 悬浮；图标居中，与对话页图标条观感一致。 */
#workspaceSidebar .ws-label {
    position: absolute;
    width: 1px; height: 1px;
    padding: 0; margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
#workspaceSidebar .ws-nav-item,
#workspaceSidebar .ws-nav-active,
#workspaceSidebar .ws-group-toggle { justify-content: center; gap: 0.25rem; }
</style>

<script>
function toggleSidebar() {
    var sb = document.getElementById('workspaceSidebar');
    var ov = document.getElementById('sidebarOverlay');
    var isOpen = sb.classList.toggle('open');
    ov.classList.toggle('hidden', !isOpen);
    applyMobileSidebarState();
}
// 2026-10-05 移动端修复：显式设置内联 transform，绕开任何 CSS 优先级竞争。
// 仅当处于移动端宽度时接管；桌面端保持原样（侧栏常驻可见）。
function applyMobileSidebarState() {
    var sb = document.getElementById('workspaceSidebar');
    if (!sb) return;
    if (window.innerWidth >= 768) {
        sb.style.transform = '';        // 桌面：交回 CSS
        return;
    }
    sb.style.transform = sb.classList.contains('open')
        ? 'translateX(0)'
        : 'translateX(-100%)';
}

// 智能二创折叠分组：切换子菜单展开/收起，并联动箭头方向
function toggleSub(btn) {
    var li = btn.closest('li');
    var ul = li.querySelector('.rewrite-sub');
    var chev = btn.querySelector('.chev');
    var collapsed = ul.classList.toggle('collapsed');
    if (chev) chev.style.transform = collapsed ? 'rotate(0deg)' : 'rotate(90deg)';
}

// 侧栏分组折叠：点击组标题收起/展开整组，并联动箭头方向
// 2026-08-31 修复：折叠状态持久化(localStorage)，刷新/重登后保持客户上次的选择
function toggleGroup(btn) {
    var ul = btn.nextElementSibling;
    if (!ul || !ul.classList.contains('ws-group-body')) return;
    var collapsed = ul.classList.toggle('collapsed');
    var chev = btn.querySelector('.ws-group-chev');
    if (chev) chev.style.transform = collapsed ? 'rotate(0deg)' : 'rotate(90deg)';
    try {
        var key = 'hgt_group_' + (ul.dataset.group || btn.textContent.trim());
        localStorage.setItem(key, collapsed ? '1' : '0');
    } catch (e) {}
}

// 页面加载：恢复分组折叠状态（默认展开的内容创作/制作与发布保持展开，其余按记忆）
(function restoreGroups() {
    document.querySelectorAll('.ws-group-body').forEach(function (ul) {
        var btn = ul.previousElementSibling;
        if (!btn || !btn.classList.contains('ws-group-toggle')) return;
        var key = 'hgt_group_' + (ul.dataset.group || btn.textContent.trim());
        var saved = null;
        try { saved = localStorage.getItem(key); } catch (e) {}
        if (saved === null) return;              // 无记忆：保持默认
        var collapsed = saved === '1';
        if (collapsed !== ul.classList.contains('collapsed')) {
            ul.classList.toggle('collapsed', collapsed);
            var chev = btn.querySelector('.ws-group-chev');
            if (chev) chev.style.transform = collapsed ? 'rotate(0deg)' : 'rotate(90deg)';
        }
    });
})();

// 移动端：选中导航后自动收起侧栏
document.querySelectorAll('.ws-sidebar a').forEach(function(a) {
    a.addEventListener('click', function() {
        if (window.innerWidth < 768) toggleSidebar();
    });
});

// 移动端初始化兜底（2026-10-05）：进入页面时强制收起侧栏 + 隐藏遮罩。
// 背景：桌面端侧栏是常驻的；手机上必须是"默认收起、点按钮唤出"。
// 仅靠 CSS !important 仍可能被其他样式干扰，这里用 JS 显式保证初始状态。
(function initMobileSidebar() {
    var sb = document.getElementById('workspaceSidebar');
    var ov = document.getElementById('sidebarOverlay');
    function sync() {
        if (window.innerWidth < 768) {
            if (sb) sb.classList.remove('open');
            if (ov) ov.classList.add('hidden');
        }
        if (typeof applyMobileSidebarState === 'function') applyMobileSidebarState();
    }
    sync();
    // 窗口从桌面尺寸缩到手机尺寸时也收起（平板转屏场景）
    window.addEventListener('resize', sync);
})();

/* ============================================================
   全局 UX 基础设施：Toast / 二次确认 / 返回 / flash 自动转 Toast
   ============================================================ */

// 智能返回：有同域来源则后退，否则回工作总览
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
        controller = null;   // 中止后置空：isActive() 立即返回 false，供循环提前跳出
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

// ===== 长任务异步调用器：把 2 分钟以上的 AI 端点改为「提交→轮询」，避免长连接中途被掐 =====
// 背景：本机 + Cloudflare Tunnel 时代，单次请求源站响应 >125s 必被 CF 掐断返回 524；
//       2026-10-05 改用国内服务器 + frp 后 CF 已整体撤除，异步化仍保留（不占长连接、可轮询进度）。
//       而 /rewrite /dissect /topic /article/write /hotspot 等 AI 端点实测 120~280s。
// 做法：POST 提交（<5s 拿 job_id）→ 轮询 /studio/cap/status/{job_id} 取最终结果。
//       8500 侧「取走即清」，拿到 done 必须停轮询。
// 用法（页面内）：
//   const data = await HGTCap.run('/studio/rewrite/generate', bodyObj, { signal, label: '中止：AI 改写中…' });
//   data 即原来同步接口的响应体；失败会 throw，与同步版 try/catch 写法完全一致。
window.HGTCap = (function () {
    var POLL_MS = 3000;       // 3 秒一轮：AI 端点 20~300s，够灵敏又不打爆后端
    var POLL_MAX = 220;       // 220 × 3s ≈ 11 分钟上限，兜住极端慢请求

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? (m.getAttribute('content') || '') : '';
    }

    function jsonHeaders() {
        return { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() };
    }

    function sleep(ms, signal) {
        return new Promise(function (resolve, reject) {
            var t = setTimeout(resolve, ms);
            if (signal) {
                signal.addEventListener('abort', function () {
                    clearTimeout(t);
                    var e = new Error('aborted'); e.name = 'AbortError'; reject(e);
                }, { once: true });
            }
        });
    }

    // 轮询直到 done / 失效 / 中止 / 超上限
    async function poll(jobId, opts) {
        opts = opts || {};
        for (var i = 0; i < POLL_MAX; i++) {
            if (opts.signal && opts.signal.aborted) { var ae = new Error('aborted'); ae.name = 'AbortError'; throw ae; }
            await sleep(POLL_MS, opts.signal);
            var r = await fetch('/studio/cap/status/' + encodeURIComponent(jobId),
                { headers: { 'Accept': 'application/json' }, signal: opts.signal });
            if (!r.ok) continue;                      // 网络/网关抖动：继续忍，不打断长任务
            var j = await r.json();
            var st = j.status || '';
            if (st === 'pending') continue;
            if (st === 'not_found') throw new Error('任务进度已失效（服务可能重启过），请重新执行一次');
            // done：8500 侧已「取走即清」，此处必须立即返回，不能再轮询
            var data = j.result || {};
            if (!(j.code >= 200 && j.code < 300) || data.ok === false) {
                throw new Error(data.error || ('后台返回 HTTP ' + (j.code || '?')));
            }
            return data;
        }
        throw new Error('任务耗时过长（超过约 11 分钟），请拆分内容后重试');
    }

    // url：Laravel 侧的同步入口地址（后端内部会转成 8500 异步作业）
    // body：与原来直接调该接口时完全相同的 JSON body
    async function run(url, body, opts) {
        opts = opts || {};
        // 第一步：提交。后端识别为长任务后返回 {ok, cap, data:{async:true, job_id}}
        var resp = await fetch(url, {
            method: 'POST',
            signal: opts.signal,
            headers: jsonHeaders(),
            body: JSON.stringify(body || {})
        });
        var data = await resp.json();
        if (!resp.ok) throw new Error(data.error || ('提交失败（HTTP ' + resp.status + '）'));
        if (data.ok === false) throw new Error(data.error || '提交失败');

        // 第二步：已是异步作业 → 轮询；否则说明该端点走的同步路径，结果直接可用
        var inner = data.data || {};
        if (inner.async && inner.job_id) return await poll(inner.job_id, opts);
        return data;
    }

    return { run: run, poll: poll };
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
