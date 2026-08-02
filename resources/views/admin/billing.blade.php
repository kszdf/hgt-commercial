<x-app-layout>
<div class="mx-auto max-w-4xl p-6">
    <header class="mb-5 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-semibold text-slate-800">计费与配额</h2>
            <p class="mt-0.5 text-sm text-slate-400">
                租户：<span class="font-medium text-slate-700">{{ $tenant->name }}</span>
                · 当前套餐：{{ $planLabel }}
            </p>
        </div>
        <a href="/dashboard" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-500 hover:border-brand-300 hover:text-brand-600 transition">返回工作台</a>
    </header>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-2.5 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <!-- 用量概览 -->
    <section class="luxury-glass mb-5 p-5">
        <h3 class="mb-3 text-sm font-semibold text-slate-700">本月用量</h3>
        <div class="flex items-end gap-6">
            <div>
                <div class="text-3xl font-bold text-brand-500">{{ $usage }}</div>
                <div class="text-xs text-slate-400 mt-0.5">已生成次数</div>
            </div>
            <div class="text-slate-300 text-2xl font-light pb-1">/</div>
            <div>
                <div class="text-3xl font-bold text-slate-800">{{ $unlimited ? '∞' : $quota }}</div>
                <div class="text-xs text-slate-400 mt-0.5">{{ $unlimited ? '不限量' : '月度额度' }}</div>
            </div>
            <div class="ml-auto text-sm text-slate-400">
                剩余：<span class="font-semibold text-slate-700">{{ $unlimited ? '不限' : $remaining }}</span>
            </div>
        </div>
        @if (! $unlimited && $quota > 0)
            <div class="mt-4 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                <div class="h-full rounded-full bg-brand-500" style="width: {{ min(100, round($usage / $quota * 100)) }}%"></div>
            </div>
        @endif
    </section>

    <!-- 套餐切换 -->
    <section class="luxury-glass mb-5 p-5">
        <h3 class="mb-3 text-sm font-semibold text-slate-700">切换套餐</h3>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            @foreach ($plans as $k => $p)
                <div class="rounded-lg border {{ $tenant->plan === $k ? 'border-brand-300 bg-brand-50 ring-1 ring-brand-200' : 'border-slate-200 bg-white' }} p-4">
                    <div class="font-medium text-slate-800">{{ $p['label'] }}</div>
                    <div class="mb-3 text-xs text-slate-400 mt-0.5">额度：{{ is_numeric($p['quota']) ? $p['quota'].' 次/月' : $p['quota'] }}</div>
                    @if ($tenant->plan === $k)
                        <div class="rounded-lg bg-brand-500 px-3 py-1.5 text-center text-sm font-medium text-white shadow-sm">当前套餐</div>
                    @else
                        <form method="POST" action="{{ route('admin.billing.upgrade') }}">
                            @csrf
                            <input type="hidden" name="plan" value="{{ $k }}">
                            <button class="w-full rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-600 hover:border-brand-300 hover:bg-brand-50 hover:text-brand-600 transition">切换</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <!-- 最近任务 -->
    <section class="luxury-glass p-5">
        <h3 class="mb-3 text-sm font-semibold text-slate-700">最近出片任务</h3>
        @if ($recent->isEmpty())
            <p class="text-sm text-slate-400">暂无出片记录。</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-slate-400">
                        <tr>
                            <th class="py-2 pr-4 font-medium">时间</th>
                            <th class="py-2 pr-4 font-medium">模式</th>
                            <th class="py-2 pr-4 font-medium">标题</th>
                            <th class="py-2 font-medium">状态</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($recent as $j)
                            <tr class="hover:bg-slate-50/50">
                                <td class="py-2.5 pr-4 text-slate-500 whitespace-nowrap">{{ $j->created_at->format('m-d H:i') }}</td>
                                <td class="py-2.5 pr-4 text-slate-600">{{ $j->mode === 'avatar' ? '数字人' : '字幕卡' }}</td>
                                <td class="py-2.5 pr-4 text-slate-700">{{ $j->title ?? '—' }}</td>
                                <td class="py-2.5">
                                    @if ($j->status === 'done')
                                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-600">完成</span>
                                    @elseif ($j->status === 'failed')
                                        <span class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-600">失败</span>
                                    @else
                                        <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-600">排队中</span>
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
