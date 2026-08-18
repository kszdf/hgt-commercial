<x-app-layout>
<x-workspace-layout title="工作台总览">
<div class="p-6">

    {{-- 套餐 / 试用状态条（超管跳过试用提示） --}}
    @php
        $isAdmin = auth()->user()->isGlobalAdmin();
        $tenant = auth()->user()->tenant;

        if ($isAdmin) {
            // 超管：全局统计（跨全部租户）
            $recentJobs = App\Models\VideoJob::orderByDesc('created_at')->with('coverAsset')->limit(5)->get();
            $doneCount = App\Models\VideoJob::where('status', 'done')->count();
            $queuedCount = App\Models\VideoJob::where('status', 'queued')->count();
            $usage = '-';
            $quota = '-';
            $remaining = '-';
        } else {
            // 普通租户：按租户统计
            $trialActive = $tenant->plan === 'free' && $tenant->isTrialActive();
            $trialExpired = $tenant->isTrialExpired();
            $usage = $tenant->usageThisMonth();
            $quota = $tenant->quota_monthly;
            $remaining = $tenant->remainingQuota();

            $recentJobs = App\Models\VideoJob::where('tenant_id', $tenant->id)
                ->orderByDesc('created_at')
                ->with('coverAsset')
                ->limit(5)
                ->get();
            $doneCount = App\Models\VideoJob::where('tenant_id', $tenant->id)->where('status', 'done')->count();
            $queuedCount = App\Models\VideoJob::where('tenant_id', $tenant->id)->where('status', 'queued')->count();
        }

        $statusMeta = [
            'done'   => ['label' => '已生成', 'cls' => 'bg-emerald-50 text-emerald-600'],
            'queued' => ['label' => '生成中', 'cls' => 'bg-amber-50 text-amber-600'],
            'failed' => ['label' => '失败',   'cls' => 'bg-rose-50 text-rose-600'],
        ];
    @endphp

    @if ($isAdmin)
        <div class="mb-5 flex items-center justify-between rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3">
            <div class="text-sm text-indigo-800"><span class="font-semibold">超级管理员模式</span> · 全局管理视角 · 不受租户配额限制</div>
            <a href="/admin/tenants" class="rounded-lg bg-indigo-500 px-3 py-1.5 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-600">租户管理</a>
        </div>
    @elseif ($trialExpired)
        <div class="mb-5 flex items-center justify-between rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
            <div class="text-sm text-amber-800"><span class="font-semibold">免费试用已结束。</span> 升级订阅套餐后即可继续生成视频。</div>
            <a href="/admin/billing" class="rounded-lg bg-amber-500 px-3 py-1.5 text-sm font-medium text-white shadow-sm transition hover:bg-amber-600">去升级</a>
        </div>
    @elseif ($trialActive)
        <div class="mb-5 flex items-center justify-between rounded-xl border border-brand-200 bg-brand-50 px-4 py-3">
            <div class="text-sm text-brand-700">免费试用中 · 剩余 <span class="font-semibold">{{ $tenant->trialDaysLeft() }}</span> 天 · 本月已用 {{ $usage }} / {{ $quota }} 次 · 单条 ≤ 10 分钟 · 不含批量外发</div>
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
                    <svg class="h-5 w-5 opacity-85" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </div>
                <h3>智能选题</h3>
                <p>联网热点检索 / 行业关键词挖掘<br/>竞争度评估 / 爆款潜力分析</p>
                <span class="hero-btn">去创作 →</span>
            </div>
        </a>

        <div class="hero-card hero-purple magnetic group relative cursor-pointer">
            <div class="relative z-10">
                <div class="mb-3 flex items-center gap-2">
                    <svg class="h-5 w-5 opacity-85" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </div>
                <h3>智能二创</h3>
                <p>选题改写 / 自有稿二创<br/>违禁词自动标红 / 口语润色</p>
                <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                    <a href="/studio/rewrite" class="hero-btn">选题二创 →</a>
                    <a href="/studio/rewrite-original" class="font-medium text-white/85 underline-offset-2 transition hover:underline">自由稿二创 →</a>
                </div>
            </div>
        </div>

        <a href="/studio/scroll" class="hero-card hero-green magnetic group cursor-pointer">
            <div class="relative z-10">
                <div class="mb-3 flex items-center gap-2">
                    <svg class="h-5 w-5 opacity-85" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                </div>
                <h3>视频出片</h3>
                <p>配音频 · 配模特 · 一键生成<br/>滚动字幕卡 / 数字人出镜</p>
                <span class="hero-btn">去创作 →</span>
            </div>
        </a>
    </section>

    <!-- ========== 快捷仪表盘 ========== -->
    <section class="mt-6">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-700">快捷仪表盘</h3>
            <a href="/studio/videos" class="text-sm font-medium text-brand-600 hover:underline">全部视频 →</a>
        </div>

        <!-- 数据概览 -->
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="luxury-glass p-4">
                <div class="flex items-center gap-1.5 text-slate-400">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    <span class="text-xs">本月已生成</span>
                </div>
                <div class="mt-2 text-2xl font-semibold text-slate-800">{{ $usage }}</div>
                <div class="text-xs text-slate-400">次</div>
            </div>

            <div class="luxury-glass p-4">
                <div class="flex items-center gap-1.5 text-slate-400">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span class="text-xs">剩余额度</span>
                </div>
                <div class="mt-2 text-2xl font-semibold text-slate-800">{{ $quota === 0 ? '不限' : $remaining }}</div>
                <div class="text-xs text-slate-400">{{ $quota === 0 ? '畅用' : '次 / 月' }}</div>
            </div>

            <div class="luxury-glass p-4">
                <div class="flex items-center gap-1.5 text-slate-400">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span class="text-xs">累计成片</span>
                </div>
                <div class="mt-2 text-2xl font-semibold text-slate-800">{{ $doneCount }}</div>
                <div class="text-xs text-slate-400">部</div>
            </div>

            <div class="luxury-glass p-4">
                <div class="flex items-center gap-1.5 text-slate-400">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <span class="text-xs">生成中</span>
                </div>
                <div class="mt-2 text-2xl font-semibold text-slate-800">{{ $queuedCount }}</div>
                <div class="text-xs text-slate-400">任务</div>
            </div>
        </div>

        <!-- 最近生成的视频 -->
        <div class="mt-4 luxury-glass p-5">
            <h4 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">最近生成的视频</h4>

            @if($recentJobs->isEmpty())
                <div class="py-8 text-center text-sm text-slate-400">还没有生成记录，去 <a href="/studio/topic" class="font-medium text-brand-600 hover:underline">智能选题</a> 开始创作吧。</div>
            @else
                <div class="divide-y divide-slate-100">
                    @foreach($recentJobs as $job)
                        <a href="/studio/videos" class="flex items-center gap-3 py-2.5 transition hover:bg-slate-50/70">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium text-slate-700">{{ $job->title ?: '未命名视频' }}</div>
                                <div class="text-xs text-slate-400">{{ $job->created_at->diffForHumans() }}</div>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-medium {{ $statusMeta[$job->status]['cls'] ?? 'bg-slate-100 text-slate-500' }}">{{ $statusMeta[$job->status]['label'] ?? '未知' }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

</div>
</x-workspace-layout>
</x-app-layout>
