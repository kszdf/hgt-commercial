<x-app-layout>
<div class="mx-auto max-w-4xl p-6">
    <header class="mb-6 flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-semibold">计费与配额</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                租户：<span class="font-medium text-slate-700 dark:text-slate-200">{{ $tenant->name }}</span>
                · 当前套餐：{{ $planLabel }}
            </p>
        </div>
        <a href="/dashboard" class="rounded-xl border border-white/15 px-3 py-2 text-sm text-slate-500 hover:text-brand-500">返回工作台</a>
    </header>

    @if (session('status'))
        <div class="mb-4 rounded-xl border border-green-400/40 bg-green-500/10 px-4 py-2 text-sm text-green-300">{{ session('status') }}</div>
    @endif

    <!-- 用量概览 -->
    <section class="luxury-glass mb-6 rounded-2xl p-5">
        <h3 class="mb-3 text-base font-semibold">本月用量</h3>
        <div class="flex items-end gap-6">
            <div>
                <div class="text-3xl font-bold text-brand-500">{{ $usage }}</div>
                <div class="text-xs text-slate-500">已生成次数</div>
            </div>
            <div class="text-slate-400">/</div>
            <div>
                <div class="text-3xl font-bold">{{ $unlimited ? '∞' : $quota }}</div>
                <div class="text-xs text-slate-500">{{ $unlimited ? '不限量' : '月度额度' }}</div>
            </div>
            <div class="ml-auto text-sm text-slate-500">
                剩余：<span class="font-semibold text-slate-200">{{ $unlimited ? '不限' : $remaining }}</span>
            </div>
        </div>
        @if (! $unlimited && $quota > 0)
            <div class="mt-4 h-2 w-full overflow-hidden rounded-full bg-white/10">
                <div class="h-full rounded-full bg-brand-500" style="width: {{ min(100, round($usage / $quota * 100)) }}%"></div>
            </div>
        @endif
    </section>

    <!-- 套餐切换 -->
    <section class="luxury-glass mb-6 rounded-2xl p-5">
        <h3 class="mb-3 text-base font-semibold">切换套餐</h3>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            @foreach ($plans as $k => $p)
                <div class="rounded-xl border border-white/10 p-4 {{ $tenant->plan === $k ? 'ring-2 ring-brand-500' : '' }}">
                    <div class="font-medium">{{ $p['label'] }}</div>
                    <div class="mb-3 text-sm text-slate-500">额度：{{ is_numeric($p['quota']) ? $p['quota'].' 次/月' : $p['quota'] }}</div>
                    @if ($tenant->plan === $k)
                        <div class="rounded-lg bg-brand-600/20 px-3 py-1.5 text-center text-sm text-brand-200">当前套餐</div>
                    @else
                        <form method="POST" action="{{ route('admin.billing.upgrade') }}">
                            @csrf
                            <input type="hidden" name="plan" value="{{ $k }}">
                            <button class="w-full rounded-lg border border-white/15 px-3 py-1.5 text-sm text-slate-200 hover:bg-white/5">切换</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-xs text-slate-500">注：支付网关（微信支付 / Stripe）为 Phase 3 后续集成点，当前切换为直接生效的额度调整，用于商用测试。</p>
    </section>

    <!-- 最近任务 -->
    <section class="luxury-glass rounded-2xl p-5">
        <h3 class="mb-3 text-base font-semibold">最近出片任务</h3>
        @if ($recent->isEmpty())
            <p class="text-sm text-slate-500">暂无出片记录。</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-slate-500">
                        <tr>
                            <th class="py-2 pr-4">时间</th>
                            <th class="py-2 pr-4">模式</th>
                            <th class="py-2 pr-4">标题</th>
                            <th class="py-2">状态</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recent as $j)
                            <tr class="border-t border-white/5">
                                <td class="py-2 pr-4 text-slate-400">{{ $j->created_at->format('m-d H:i') }}</td>
                                <td class="py-2 pr-4">{{ $j->mode === 'avatar' ? '数字人' : '字幕卡' }}</td>
                                <td class="py-2 pr-4 text-slate-200">{{ $j->title ?? '—' }}</td>
                                <td class="py-2">
                                    @if ($j->status === 'done')
                                        <span class="rounded-full bg-green-500/20 px-2 py-0.5 text-xs text-green-300">完成</span>
                                    @elseif ($j->status === 'failed')
                                        <span class="rounded-full bg-red-500/20 px-2 py-0.5 text-xs text-red-300">失败</span>
                                    @else
                                        <span class="rounded-full bg-amber-500/20 px-2 py-0.5 text-xs text-amber-300">排队中</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
</x-app-layout>
