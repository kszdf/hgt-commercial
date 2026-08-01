<x-app-layout>
<div class="flex min-h-screen">

    <!-- ===== 侧边导航 ===== -->
    <aside class="sidebar-nav hidden w-56 shrink-0 flex-col p-4 md:flex">
        <div class="mb-6 flex items-center gap-2.5">
            <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-base font-bold text-white shadow-md">追</div>
            <span class="text-lg font-bold text-slate-800 tracking-tight">追梦</span>
        </div>

        <nav class="flex-1 space-y-0.5 text-[13px]">
            <a href="#" class="nav-item active">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                工作台
            </a>
            <a href="/studio/scroll" class="nav-item">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                视频出片
            </a>
            <a href="/admin/billing" class="nav-item">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                计费订阅
            </a>
        </nav>

        <div class="mt-auto rounded-lg border border-slate-200/80 bg-slate-50 px-3 py-2.5 text-xs text-slate-500">
            当前租户：<span class="font-medium text-slate-700">{{ auth()->user()->tenant->name ?? '平台' }}</span>
        </div>
    </aside>

    <!-- ===== 主内容区 ===== -->
    <main class="flex-1 overflow-y-auto bg-white">

        <!-- 顶栏 -->
        <header class="top-header sticky top-0 z-10 flex items-center justify-between px-6 py-3 bg-white/90 backdrop-blur-sm">
            <div>
                <h2 class="text-base font-semibold text-slate-800">工作台</h2>
                <p class="mt-0.5 text-xs text-slate-400">欢迎回来，{{ auth()->user()->name }} · 财税短视频智能生产平台</p>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="toggleTheme()" title="切换明暗"
                    class="rounded-lg border border-slate-200 bg-white p-2 text-slate-400 transition hover:border-brand-300 hover:text-brand-500 hover:shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                    </svg>
                </button>
                <form method="POST" action="/logout" class="m-0">
                    @csrf
                    <button type="submit" title="退出登录"
                        class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-500 transition hover:border-red-200 hover:text-red-500 hover:shadow-sm">退出</button>
                </form>
                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-brand-100 to-brand-200 text-brand-600 ring-2 ring-white shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                </div>
            </div>
        </header>

        <!-- ========== Hero 卡片区 ========== -->
        <section class="grid grid-cols-1 gap-4 p-6 sm:grid-cols-3">
            <a href="/studio/topic" class="hero-card hero-blue magnetic group cursor-pointer">
                <div class="relative z-10">
                    <div class="mb-3 flex items-center gap-2">
                        <span class="step-num">01</span>
                        <svg class="h-5 w-5 opacity-85" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>
                    <h3>智能选题</h3>
                    <p>联网热点检索 / 行业关键词挖掘<br/>竞争度评估 / 爆款潜力分析</p>
                    <span class="hero-btn">去创作 →</span>
                </div>
            </a>

            <a href="/studio/rewrite" class="hero-card hero-purple magnetic group cursor-pointer">
                <div class="relative z-10">
                    <div class="mb-3 flex items-center gap-2">
                        <span class="step-num">02</span>
                        <svg class="h-5 w-5 opacity-85" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </div>
                    <h3>智能二创</h3>
                    <p>三模式改写 / 违禁词自动标红<br/>时长控字数 / 专家口语润色</p>
                    <span class="hero-btn">去创作 →</span>
                </div>
            </a>

            <a href="/studio/scroll" class="hero-card hero-green magnetic group cursor-pointer">
                <div class="relative z-10">
                    <div class="mb-3 flex items-center gap-2">
                        <span class="step-num">03–05</span>
                        <svg class="h-5 w-5 opacity-85" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    </div>
                    <h3>视频出片</h3>
                    <p>配音频 · 配模特 · 一键生成<br/>滚动字幕卡 / 数字人出镜</p>
                    <span class="hero-btn">去创作 →</span>
                </div>
            </a>
        </section>

        <!-- ========== 完整工作流面板（9步）========== -->
        <section class="px-6 pb-2">
            <div class="mb-4 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-semibold text-slate-700">完整生产管线</h3>
                    <span class="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-medium text-brand-600">9 步闭环</span>
                </div>
            </div>

            <div class="luxury-glass overflow-hidden p-5">
                <div class="grid grid-cols-3 gap-x-5 gap-y-3 sm:grid-cols-9">
                    @php
                        $steps = [
                            ['num'=>'01','name'=>'智能选题','desc'=>'热点/关键词/竞争度','icon'=>'search','color'=>'blue',   'status'=>'ready', 'link'=>'/studio/topic'],
                            ['num'=>'02','name'=>'智能二创','desc'=>'改写/违禁词/润色',   'icon'=>'edit',   'color'=>'purple', 'status'=>'ready', 'link'=>'/studio/rewrite'],
                            ['num'=>'03','name'=>'配音频',   'desc'=>'音色/语速/感情',     'icon'=>'mic',    'color'=>'sky',    'status'=>'ready', 'link'=>'/studio/scroll'],
                            ['num'=>'04','name'=>'配模特',   'desc'=>'数字人必选·字幕卡跳过', 'icon'=>'model',  'color'=>'teal',   'status'=>'cond', 'link'=>'/studio/models'],
                            ['num'=>'05','name'=>'出片',     'desc'=>'一键生成视频',       'icon'=>'video',  'color'=>'green',  'status'=>'ready', 'link'=>'/studio/scroll'],
                            ['num'=>'06','name'=>'质检',     'desc'=>'字幕/音画/时长',     'icon'=>'check',  'color'=>'amber',  'status'=>'ready', 'link'=>'/studio/qc'],
                            ['num'=>'07','name'=>'人工审核', 'desc'=>'逐帧确认质量',       'icon'=>'eye',    'color'=>'rose',   'status'=>'ready', 'link'=>'/studio/review'],
                            ['num'=>'08','name'=>'批量外发', 'desc'=>'多平台一键分发',     'icon'=>'upload', 'color'=>'indigo', 'status'=>'ready', 'link'=>'/studio/publish'],
                            ['num'=>'09','name'=>'数据复盘', 'desc'=>'播放/互动/转化',     'icon'=>'chart',  'color'=>'slate',  'status'=>'ready', 'link'=>'/studio/analytics'],
                        ];
                        $icons = [
                            'search' => '<svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>',
                            'edit'   => '<svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>',
                            'mic'    => '<svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/></svg>',
                            'model'  => '<svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>',
                            'video'  => '<svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>',
                            'check'  => '<svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
                            'eye'    => '<svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>',
                            'upload' => '<svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>',
                            'chart'  => '<svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>',
                        ];
                    @endphp

                    @foreach($steps as $i => $s)
                        @if(!empty($s['link']))
                        <a href="{{ $s['link'] }}" class="workflow-step group" data-color="{{ $s['color'] }}" data-status="{{ $s['status'] }}">
                        @else
                        <div class="workflow-step group" data-color="{{ $s['color'] }}" data-status="{{ $s['status'] }}">
                        @endif

                            <div class="step-icon-wrap tool-{{ $s['color'] }}">
                                {!! $icons[$s['icon']] ?? '' !!}
                            </div>
                            <div class="mt-2 text-center">
                                <div class="text-[11px] font-semibold text-slate-700">{{ $s['name'] }}</div>
                                <div class="mt-0.5 text-[10px] text-slate-400 leading-tight">{{ $s['desc'] }}</div>
                                @if(($s['status'] ?? '') === 'ready')
                                    <span class="mt-1 inline-block rounded-full bg-fresh-50 px-1.5 py-0.5 text-[10px] font-medium text-fresh-600">可用</span>
                                @elseif(($s['status'] ?? '') === 'cond')
                                    <span class="mt-1 inline-block rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-600">按模式</span>
                                @else
                                    <span class="mt-1 inline-block rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-400">规划中</span>
                                @endif
                            </div>

                        @if(!empty($s['link']))
                        </a>
                        @else
                        </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </section>

        <!-- 底部状态 -->
        <div class="px-6 pb-8 pt-3">
            <div class="luxury-glass px-5 py-3.5 text-center text-[11px] text-slate-400">
                Phase 1–3 已落地 · 多租户账号 / 滚动字幕卡 / 数字人出片 / 克隆配音 / 计费配额已可用
                <span class="mx-1.5 text-slate-300">|</span>
                9 步生产管线全打通 · 选题/二创/配音/模特/出片/质检/审核/外发/复盘闭环可用
            </div>
        </div>
    </main>
</div>
</x-app-layout>
