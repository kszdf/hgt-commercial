<x-app-layout>
<x-workspace-layout title="工作台总览">
<div class="p-6">

    {{-- 套餐 / 试用状态条 --}}
    @php
        $tenant = auth()->user()->tenant;
        $trialActive = $tenant->plan === 'free' && $tenant->isTrialActive();
        $trialExpired = $tenant->isTrialExpired();
        $usage = $tenant->usageThisMonth();
        $quota = $tenant->quota_monthly;
        $remaining = $tenant->remainingQuota();
    @endphp

    @if ($trialExpired)
        <div class="mb-5 flex items-center justify-between rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
            <div class="text-sm text-amber-800"><span class="font-semibold">免费试用已结束。</span> 升级订阅套餐后即可继续生成视频。</div>
            <a href="/admin/billing" class="rounded-lg bg-amber-500 px-3 py-1.5 text-sm font-medium text-white shadow-sm transition hover:bg-amber-600">去升级</a>
        </div>
    @elseif ($trialActive)
        <div class="mb-5 flex items-center justify-between rounded-xl border border-brand-200 bg-brand-50 px-4 py-3">
            <div class="text-sm text-brand-700">免费试用中 · 剩余 <span class="font-semibold">{{ $tenant->trialDaysLeft() }}</span> 天 · 本月已用 {{ $usage }} / {{ $quota }} 次（约 5 元）· 单条 ≤ 10 分钟 · 不含批量外发</div>
            <a href="/admin/billing" class="text-sm font-medium text-brand-600 hover:underline">查看套餐</a>
        </div>
    @else
        <div class="mb-5 flex items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3">
            <div class="text-sm text-slate-600">当前套餐：{{ $tenant->planLabel() }} · 本月已用 {{ $usage }} / {{ $quota === 0 ? '不限' : $quota }}（剩余 {{ $quota === 0 ? '不限' : $remaining }}）</div>
            <a href="/admin/billing" class="text-sm font-medium text-brand-600 hover:underline">计费与配额</a>
        </div>
    @endif

    <!-- ========== Hero 卡片区 ========== -->
    <section class="grid grid-cols-1 gap-4 sm:grid-cols-3">
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

    <!-- ========== 完整工作流面板 ========== -->
    <section class="mt-6">
        <div class="mb-4 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <h3 class="text-sm font-semibold text-slate-700">完整生产管线</h3>
                <span class="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-medium text-brand-600">完整工作流</span>
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
                    <a href="{{ $s['link'] }}" class="workflow-step group relative" data-color="{{ $s['color'] }}" data-status="{{ $s['status'] }}" data-desc="{{ $s['desc'] }}">
                    @else
                    <div class="workflow-step group relative" data-color="{{ $s['color'] }}" data-status="{{ $s['status'] }}" data-desc="{{ $s['desc'] }}">
                    @endif

                        <div class="step-icon-wrap tool-{{ $s['color'] }}">
                            {!! $icons[$s['icon']] ?? '' !!}
                        </div>
                        <div class="mt-2 text-center">
                            <div class="text-[11px] font-semibold text-slate-700">{{ $s['name'] }}</div>
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

    <!-- 工作流卡片 Tooltip 交互 -->
    <style>
        .wf-tooltip {
            position: absolute;
            z-index: 50;
            padding: 8px 12px;
            border-radius: 10px;
            background: #1e293b;
            color: #f8fafc;
            font-size: 12px;
            line-height: 1.5;
            white-space: nowrap;
            pointer-events: none;
            opacity: 0;
            transform: translateY(4px) scale(0.96);
            transition: opacity 0.2s ease, transform 0.2s ease;
            box-shadow: 0 8px 24px rgba(0,0,0,0.18), 0 2px 6px rgba(0,0,0,0.1);
        }
        .wf-tooltip.visible {
            opacity: 1;
            transform: translateY(0) scale(1);
            pointer-events: auto;
        }
        /* 暗色模式适配 */
        [data-theme="dark"] .wf-tooltip,
        .dark .wf-tooltip {
            background: #e2e8f0;
            color: #0f172a;
            box-shadow: 0 8px 24px rgba(0,0,0,0.35);
        }
    </style>
    <script>
    (function(){
        var activeTooltip = null;

        document.querySelectorAll('.workflow-step[data-desc]').forEach(function(el){
            el.addEventListener('click', function(e){
                e.preventDefault();
                e.stopPropagation();
                var desc = this.getAttribute('data-desc');
                if (!desc) return;

                // 如果当前已有 tooltip 且属于这个元素，关闭它
                if (activeTooltip && activeTooltip._owner === this) {
                    hideTip();
                    return;
                }
                // 关闭旧的
                if (activeTooltip) hideTip();

                // 创建新 tooltip
                var tip = document.createElement('div');
                tip.className = 'wf-tooltip';
                tip.textContent = desc;
                tip._owner = this;
                document.body.appendChild(tip);

                // 定位：在按钮正下方居中
                var rect = this.getBoundingClientRect();
                var tipW = tip.offsetWidth;
                var left = rect.left + (rect.width - tipW) / 2;
                var top = rect.bottom + 8;

                // 边界修正：不超出视口左右
                if (left < 4) left = 4;
                if (left + tipW > window.innerWidth - 4) left = window.innerWidth - tipW - 4;

                tip.style.left = left + 'px';
                tip.style.top = top + 'px';

                // 下一帧显示（触发 transition）
                requestAnimationFrame(function(){ tip.classList.add('visible'); });
                activeTooltip = tip;
            });
        });

        function hideTip() {
            if (!activeTooltip) return;
            activeTooltip.classList.remove('visible');
            var t = activeTooltip;
            setTimeout(function(){ if (t.parentNode) t.parentNode.removeChild(t); }, 220);
            activeTooltip = null;
        }

        // 点击页面其他区域关闭
        document.addEventListener('click', function(e){
            if (!e.target.closest('.workflow-step')) hideTip();
        });

        // ESC 关闭
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape') hideTip();
        });
    })();
    </script>

</div>
</x-workspace-layout>
</x-app-layout>
