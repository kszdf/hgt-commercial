<x-app-layout>
<x-workspace-layout title="数据效果">
<div class="mx-auto max-w-6xl p-6">

    @include('components.flash')

    {{-- 顶部说明 + 同步入口 --}}
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
        <div class="text-sm text-slate-500">
            <span class="font-semibold text-slate-700">播放 · 互动 · 留资，验证每条视频的效果。</span>
            <span class="ml-2 text-xs">数据来源：手动录入（半自动）或抖音自动同步（需平台授权）。「未同步」的数据不会被计算。</span>
        </div>
        <form method="POST" action="{{ route('studio.metrics.sync') }}">
            @csrf
            <button class="rounded-lg bg-brand-600 px-3.5 py-1.5 text-sm font-medium text-white hover:bg-brand-700">同步抖音数据</button>
        </form>
    </div>

    {{-- KPI --}}
    <section class="grid grid-cols-2 gap-4 lg:grid-cols-5">
        @php
            $kpis = [
                ['label' => '总播放', 'value' => number_format($totals->views ?? 0), 'sub' => '次'],
                ['label' => '总互动', 'value' => number_format($totals->interactions ?? 0), 'sub' => '转发+评论+点赞+收藏'],
                ['label' => '留资', 'value' => number_format($totals->leads ?? 0), 'sub' => '条线索'],
                ['label' => '已追踪出片', 'value' => number_format($totals->videos ?? 0), 'sub' => '条'],
                ['label' => '覆盖天数', 'value' => number_format($totals->days ?? 0), 'sub' => '天'],
            ];
        @endphp
        @foreach($kpis as $k)
            <div class="luxury-glass p-4">
                <div class="text-xs text-slate-400">{{ $k['label'] }}</div>
                <div class="mt-1 text-2xl font-semibold text-slate-800">{{ $k['value'] }}</div>
                <div class="text-[11px] text-slate-400">{{ $k['sub'] }}</div>
            </div>
        @endforeach
    </section>

    {{-- 平台分布 + 趋势 --}}
    <section class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="luxury-glass p-5">
            <h3 class="mb-3 text-sm font-semibold text-slate-700">平台播放分布</h3>
            @php $maxP = $byPlatform->max('views') ?: 1; @endphp
            @forelse($byPlatform as $p)
                <div class="mb-2.5">
                    <div class="mb-1 flex justify-between text-xs">
                        <span class="text-slate-600">{{ \App\Models\PlatformAccount::PLATFORM_LABELS[$p->platform] ?? $p->platform }}</span>
                        <span class="text-slate-500">{{ number_format($p->views) }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full bg-gradient-to-r from-brand-500 to-emerald-500" style="width: {{ round($p->views / $maxP * 100) }}%"></div>
                    </div>
                </div>
            @empty
                <p class="py-6 text-center text-sm text-slate-400">暂无数据，先录入或同步。</p>
            @endforelse
        </div>
        <div class="luxury-glass p-5">
            <h3 class="mb-3 text-sm font-semibold text-slate-700">近 30 天播放趋势</h3>
            @if($trend->isNotEmpty())
                @php
                    $maxT = $trend->max('views') ?: 1;
                @endphp
                <div class="flex h-40 items-end gap-1">
                    @foreach($trend as $t)
                        <div class="group relative flex-1">
                            <div class="mx-auto rounded-t bg-brand-500/80 transition hover:bg-brand-600"
                                 style="height: {{ max(2, round($t->views / $maxT * 150)) }}px"
                                 title="{{ $t->metric_date }} · {{ number_format($t->views) }} 播放"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-1 flex justify-between text-[10px] text-slate-400">
                    <span>{{ $trend->first()->metric_date }}</span>
                    <span>峰值 {{ number_format($maxT) }}</span>
                    <span>{{ $trend->last()->metric_date }}</span>
                </div>
            @else
                <p class="py-6 text-center text-sm text-slate-400">暂无趋势数据。</p>
            @endif
        </div>
    </section>

    {{-- Top 出片 --}}
    <section class="mt-5 luxury-glass p-5">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-700">Top 出片（按播放）</h3>
            <span class="text-[11px] text-slate-400">数据好的直接 ⭐ 标记 + ↻ 复刻</span>
        </div>
        @if($topVideos->isNotEmpty())
            @php $maxV = $topVideos->max('views') ?: 1; @endphp
            <div class="space-y-2">
                @foreach($topVideos as $tv)
                    @php $vj = $tv->videoJob; @endphp
                    <div class="flex items-center gap-3 text-sm">
                        <div class="w-44 truncate text-slate-700">
                            @if($vj && $vj->is_hit)<span class="mr-0.5">⭐</span>@endif{{ $vj?->title ?: ('#' . $tv->video_job_id) }}
                        </div>
                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-brand-500" style="width: {{ round($tv->views / $maxV * 100) }}%"></div>
                        </div>
                        <div class="w-16 text-right text-slate-600">{{ number_format($tv->views) }}</div>
                        <div class="w-16 text-right text-xs text-slate-400">互动 {{ number_format($tv->interactions) }}</div>
                        @if($vj && $vj->isRendered() && $vj->dialogue)
                            <a href="/studio/scroll?src=clone&job_id={{ $vj->id }}" title="复用此条文稿与形式去出片"
                               class="shrink-0 rounded-md border border-slate-200 bg-white px-2 py-1 text-[11px] text-slate-600 hover:bg-slate-50">↻ 复刻</a>
                            <form action="{{ route('studio.videos.hit', $vj) }}" method="POST">
                                @csrf
                                <button class="shrink-0 rounded-md border px-2 py-1 text-[11px] transition {{ $vj->is_hit ? 'border-amber-300 bg-amber-50 text-amber-600' : 'border-slate-200 bg-white text-slate-500 hover:bg-amber-50' }}"
                                        title="标记为爆款">{{ $vj->is_hit ? '⭐ 爆款' : '☆ 标记' }}</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <p class="py-6 text-center text-sm text-slate-400">暂无数据，先录入或同步。</p>
        @endif
    </section>

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- 手动速填 --}}
        <div class="luxury-glass p-5" id="record-form">
            <h3 class="mb-3 text-sm font-semibold text-slate-700">手动录入数据</h3>
            <p class="mb-3 text-xs text-slate-400">已发布但平台无数据接口（小红书/视频号）的视频，请到创作者后台看数字后填到这里（只填 6 个数，1 分钟搞定）。</p>
            <form method="POST" action="{{ route('studio.metrics.record') }}" class="grid grid-cols-2 gap-3 text-sm">
                @csrf
                <div class="col-span-2">
                    <label class="mb-1 block text-slate-500">出片任务</label>
                    <select name="video_job_id" required class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        @foreach($videos as $v)
                            <option value="{{ $v->id }}">{{ $v->title ?: $v->job_id }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-slate-500">发布账号</label>
                    <select name="platform_account_id" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        <option value="">（不指定账号）</option>
                        @foreach($accounts as $a)
                            <option value="{{ $a->id }}">{{ $a->platformLabel() }} · {{ $a->account_name ?: '未命名' }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-slate-500">日期</label>
                    <input type="date" name="metric_date" required value="{{ now()->toDateString() }}"
                        class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
                </div>
                <div><label class="mb-1 block text-slate-500">播放</label><input type="number" name="views" min="0" value="0" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700"></div>
                <div><label class="mb-1 block text-slate-500">点赞</label><input type="number" name="likes" min="0" value="0" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700"></div>
                <div><label class="mb-1 block text-slate-500">评论</label><input type="number" name="comments" min="0" value="0" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700"></div>
                <div><label class="mb-1 block text-slate-500">转发</label><input type="number" name="shares" min="0" value="0" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700"></div>
                <div><label class="mb-1 block text-slate-500">收藏</label><input type="number" name="favorites" min="0" value="0" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700"></div>
                <div><label class="mb-1 block text-slate-500">留资</label><input type="number" name="leads" min="0" value="0" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700"></div>
                <div class="col-span-2">
                    <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">保存</button>
                </div>
            </form>
        </div>

        {{-- 待回填 --}}
        <div class="luxury-glass p-5">
            <h3 class="mb-3 text-sm font-semibold text-slate-700">待回填（已发布 · 暂无数据）</h3>
            @if($unSynced->isEmpty())
                <p class="py-6 text-center text-sm text-slate-400">太棒了，近 30 天发布的视频都有数据了。</p>
            @else
                <div class="max-h-96 space-y-2 overflow-y-auto">
                    @foreach($unSynced as $rec)
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-100 bg-white px-3 py-2">
                            <div class="min-w-0">
                                <div class="truncate text-xs font-medium text-slate-700">{{ $rec->videoJob?->title ?: ('#' . $rec->video_job_id) }}</div>
                                <div class="text-[11px] text-slate-400">
                                    {{ \App\Models\PlatformAccount::PLATFORM_LABELS[$rec->platform] ?? $rec->platform }}
                                    · {{ $rec->account?->account_name ?: '未指定账号' }}
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                @if($rec->post_url)
                                    <a href="{{ $rec->post_url }}" target="_blank" rel="noopener" class="text-[11px] text-brand-600 hover:underline">查看作品 ↗</a>
                                @endif
                                <a href="{{ route('studio.metrics') }}#record-form" class="rounded bg-brand-50 px-2 py-1 text-[11px] text-brand-600 hover:bg-brand-100">去填数据</a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- 最近明细 --}}
    <section class="mt-5 luxury-glass overflow-hidden">
        <div class="px-5 py-4 text-sm font-semibold text-slate-700">最近数据明细</div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500">
                    <tr>
                        <th class="px-4 py-2.5">日期</th>
                        <th class="px-4 py-2.5">出片</th>
                        <th class="px-4 py-2.5">平台/账号</th>
                        <th class="px-4 py-2.5 text-right">播放</th>
                        <th class="px-4 py-2.5 text-right">互动</th>
                        <th class="px-4 py-2.5 text-right">留资</th>
                        <th class="px-4 py-2.5">来源</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($recentMetrics as $m)
                        <tr>
                            <td class="px-4 py-2.5 text-slate-600">{{ $m->metric_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $m->videoJob?->title ?: ('#' . $m->video_job_id) }}</td>
                            <td class="px-4 py-2.5 text-slate-500">{{ $m->platformLabel() }}{{ $m->account ? ' · ' . $m->account->account_name : '' }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-700">{{ number_format($m->views) }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-600">{{ number_format($m->interactions()) }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-600">{{ number_format($m->leads) }}</td>
                            <td class="px-4 py-2.5">
                                <span class="rounded px-1.5 py-0.5 text-[10px] {{ $m->data_source === 'auto' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                                    {{ $m->sourceLabel() }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center text-slate-400">暂无数据，先录入或同步。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
</x-workspace-layout>
</x-app-layout>
