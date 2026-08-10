<x-app-layout>
<x-workspace-layout title="数据复盘看板" :breadcrumbs="[['label' => '工作台总览', 'url' => '/dashboard'], ['label' => '数据复盘看板']]">
    <x-container>
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-slate-800">数据复盘看板</h1>
                <p class="text-slate-500 text-sm mt-1">播放 · 互动 · 转化全景，按出片与平台拆解</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('studio.metrics') }}" class="text-sm rounded-lg border border-slate-300 px-3 py-1.5 text-slate-600 hover:bg-slate-100">录入数据</a>
                <a href="{{ route('dashboard') }}" class="text-sm text-slate-500 hover:text-slate-800">← 工作台</a>
            </div>
        </div>

        @if ($totals->videos == 0)
            <div class="rounded-xl border border-slate-200 bg-white p-10 text-center">
                <p class="text-slate-400">还没有数据。先去 <a href="{{ route('studio.metrics') }}" class="text-brand-600 underline">录入 / 导入</a> 各平台播放互动数据。</p>
            </div>
        @else
            {{-- KPI 卡片 --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                @php
                    $kpis = [
                        ['label'=>'总播放', 'value'=>number_format($totals->views), 'sub'=>'次', 'color'=>'brand'],
                        ['label'=>'总互动', 'value'=>number_format($interactions), 'sub'=>'转发+评论+点赞', 'color'=>'emerald'],
                        ['label'=>'出片数', 'value'=>number_format($totals->videos), 'sub'=>'条已追踪', 'color'=>'sky'],
                        ['label'=>'覆盖天数', 'value'=>number_format($totals->days), 'sub'=>'天', 'color'=>'amber'],
                    ];
                @endphp
                @foreach ($kpis as $k)
                    <div class="luxury-glass rounded-xl p-5">
                        <div class="text-xs text-slate-300">{{ $k['label'] }}</div>
                        <div class="mt-2 text-3xl font-bold tracking-tight text-white">{{ $k['value'] }}</div>
                        <div class="text-[11px] text-slate-300 mt-1">{{ $k['sub'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- 平台分布 --}}
                <div class="luxury-glass rounded-xl p-5">
                    <h2 class="font-semibold mb-4 text-white">平台播放分布</h2>
                    <div class="space-y-3">
                        @foreach ($byPlatform as $p)
                            @php
                                $pct = $maxPlatformViews ? round($p->views / $maxPlatformViews * 100) : 0;
                                $name = match($p->platform) {
                                    'wechat' => '视频号', 'douyin' => '抖音',
                                    'xiaohongshu' => '小红书', 'manual' => '手动', default => $p->platform
                                };
                            @endphp
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="text-slate-200">{{ $name }}</span>
                                    <span class="text-slate-300">{{ number_format($p->views) }}</span>
                                </div>
                                <div class="h-2 rounded-full bg-white/20 overflow-hidden">
                                    <div class="h-full rounded-full bg-gradient-to-r from-brand-500 to-emerald-500 transition-all" style="width: {{ $pct }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- 趋势 SVG --}}
                <div class="luxury-glass rounded-xl p-5">
                    <h2 class="font-semibold mb-4 text-white">播放趋势</h2>
                    @if ($trend->count() > 0)
                        @php
                            $w = 520; $h = 180; $pad = 24;
                            $pts = $trend->map(fn($t) => $t->views)->all();
                            $max = max($pts) ?: 1; $min = min($pts);
                            $n = count($pts);
                            $stepX = $n > 1 ? ($w - $pad*2) / ($n-1) : 0;
                            $coords = $trend->map(function($t, $i) use ($w,$h,$pad,$max,$min,$stepX,$n) {
                                $x = $n > 1 ? $pad + $i*$stepX : $w/2;
                                $y = $h - $pad - (($t->views - $min) / ($max - $min ?: 1)) * ($h - $pad*2);
                                return [$x, $y, $t->metric_date->format('m-d'), $t->views];
                            });
                            $poly = $coords->map(fn($c) => $c[0].','.$c[1])->implode(' ');
                            $area = $pad.','.($h-$pad).' '.$poly.' '.($w-$pad).','.($h-$pad);
                        @endphp
                        <svg viewBox="0 0 {{ $w }} {{ $h }}" class="w-full h-auto" preserveAspectRatio="none">
                            <defs>
                                <linearGradient id="trendFill" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#4f46e5" stop-opacity="0.35"/>
                                    <stop offset="100%" stop-color="#4f46e5" stop-opacity="0"/>
                                </linearGradient>
                            </defs>
                            <polygon points="{{ $area }}" fill="url(#trendFill)"></polygon>
                            <polyline points="{{ $poly }}" fill="none" stroke="#4f46e5" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"></polyline>
                            @foreach ($coords as $c)
                                <circle cx="{{ $c[0] }}" cy="{{ $c[1] }}" r="3" fill="#4f46e5"></circle>
                            @endforeach
                        </svg>
                        <div class="flex justify-between text-[10px] text-slate-300 mt-1">
                            <span>{{ $trend->first()->metric_date->format('Y-m-d') }}</span>
                            <span>峰值 {{ number_format($max) }}</span>
                            <span>{{ $trend->last()->metric_date->format('Y-m-d') }}</span>
                        </div>
                    @else
                        <p class="text-slate-300 text-sm">暂无趋势数据</p>
                    @endif
                </div>
            </div>

            {{-- Top 出片 --}}
            <div class="luxury-glass rounded-xl p-5 mt-6">
                <h2 class="font-semibold mb-4 text-white">Top 出片（按播放）</h2>
                <div class="space-y-2">
                    @php $maxV = $topVideos->max('views') ?: 1; @endphp
                    @foreach ($topVideos as $tv)
                        @php $pct = round($tv->views / $maxV * 100); @endphp
                        <div class="flex items-center gap-3 text-sm">
                            <div class="w-40 truncate text-slate-200">{{ $tv->videoJob?->title ?: ($tv->videoJob?->job_id ?? '—') }}</div>
                            <div class="flex-1 h-2 rounded-full bg-white/20 overflow-hidden">
                                <div class="h-full rounded-full bg-brand-500" style="width: {{ $pct }}%"></div>
                            </div>
                            <div class="w-20 text-right text-slate-300">{{ number_format($tv->views) }}</div>
                            <div class="w-20 text-right text-slate-300">互动 {{ number_format($tv->interactions) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </x-container>
</x-workspace-layout>
</x-app-layout>
