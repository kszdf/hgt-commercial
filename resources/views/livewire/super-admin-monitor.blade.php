<div wire:poll.10s="loadData" class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

    {{-- 顶栏 --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold tracking-tight text-slate-900">实时监控大盘</h1>
            <p class="mt-0.5 text-sm text-slate-500">超级管理员视图 · 按租户聚合即时运行状态</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200">
                <span class="h-2 w-2 animate-pulse rounded-full bg-emerald-500"></span>
                每 10 秒自动刷新
            </span>
            <span class="text-xs text-slate-400">最后更新 {{ $updatedAt }}</span>
            <a href="/dashboard" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:border-slate-300 hover:text-slate-900">
                返回工作台
            </a>
        </div>
    </div>

    {{-- 全局汇总卡片 --}}
    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-medium text-slate-400">活跃租户</div>
            <div class="mt-1 text-2xl font-bold text-slate-900">{{ $summary['tenants'] ?? 0 }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-medium text-slate-400">在线用户</div>
            <div class="mt-1 text-2xl font-bold text-emerald-600">{{ $summary['online'] ?? 0 }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-medium text-slate-400">选题中</div>
            <div class="mt-1 text-2xl font-bold text-sky-600">{{ $summary['topic'] ?? 0 }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-medium text-slate-400">二创中</div>
            <div class="mt-1 text-2xl font-bold text-violet-600">{{ $summary['rewrite'] ?? 0 }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-medium text-slate-400">出片中</div>
            <div class="mt-1 text-2xl font-bold text-amber-600">{{ $summary['video'] ?? 0 }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-medium text-slate-400">近 24h 出片</div>
            <div class="mt-1 flex items-baseline gap-1.5 text-sm font-semibold">
                <span class="text-slate-900">{{ ($summary['done'] ?? 0) + ($summary['failed'] ?? 0) }}</span>
                <span class="text-xs font-normal text-slate-400">完成 {{ $summary['done'] ?? 0 }} / 失败 {{ $summary['failed'] ?? 0 }}</span>
            </div>
        </div>
    </div>

    {{-- 各租户实时状态 --}}
    @if(count($tenants) === 0)
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            </div>
            <p class="text-sm font-medium text-slate-600">当前没有租户处于活跃状态</p>
            <p class="mt-1 text-xs text-slate-400">有用户进入工作台或发起生产任务后将自动出现在这里</p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3">
            @foreach($tenants as $t)
                <div class="flex flex-col rounded-2xl border border-slate-200 bg-white shadow-sm">
                    {{-- 租户头部 --}}
                    <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                        <div class="flex items-center gap-2">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-indigo-500 to-violet-500 text-xs font-bold text-white">
                                {{ mb_substr($t['name'], 0, 1) }}
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-slate-900">{{ $t['name'] }}</div>
                                <div class="text-xs text-slate-400">{{ $t['plan'] }}</div>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            {{ count($t['online']) }} 在线
                        </span>
                    </div>

                    {{-- 四态明细 --}}
                    <div class="grid flex-1 grid-cols-1 gap-px bg-slate-100 sm:grid-cols-2">
                        {{-- 在线用户 --}}
                        <div class="bg-white p-3">
                            <div class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-slate-500">
                                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>在线用户
                            </div>
                            @if(count($t['online']) === 0)
                                <p class="text-xs text-slate-300">—</p>
                            @else
                                <ul class="space-y-1">
                                    @foreach($t['online'] as $u)
                                        <li class="flex items-center justify-between text-xs">
                                            <span class="truncate text-slate-700">{{ $u['name'] }}</span>
                                            <span class="ml-2 shrink-0 text-slate-400">{{ $u['ago'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        {{-- 选题中 --}}
                        <div class="bg-white p-3">
                            <div class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-sky-600">
                                <span class="h-2 w-2 rounded-full bg-sky-500"></span>智能选题
                            </div>
                            @if(count($t['topic']) === 0)
                                <p class="text-xs text-slate-300">—</p>
                            @else
                                <div class="flex flex-wrap gap-1">
                                    @foreach($t['topic'] as $name)
                                        <span class="rounded-md bg-sky-50 px-2 py-0.5 text-xs text-sky-700 ring-1 ring-sky-100">{{ $name }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        {{-- 二创中 --}}
                        <div class="bg-white p-3">
                            <div class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-violet-600">
                                <span class="h-2 w-2 rounded-full bg-violet-500"></span>智能二创
                            </div>
                            @if(count($t['rewrite']) === 0)
                                <p class="text-xs text-slate-300">—</p>
                            @else
                                <div class="flex flex-wrap gap-1">
                                    @foreach($t['rewrite'] as $name)
                                        <span class="rounded-md bg-violet-50 px-2 py-0.5 text-xs text-violet-700 ring-1 ring-violet-100">{{ $name }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        {{-- 出片中 --}}
                        <div class="bg-white p-3">
                            <div class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-amber-600">
                                <span class="h-2 w-2 rounded-full bg-amber-500"></span>出片进度
                            </div>
                            @if(count($t['videos']) === 0)
                                <p class="text-xs text-slate-300">—</p>
                            @else
                                <ul class="space-y-1.5">
                                    @foreach($t['videos'] as $v)
                                        <li class="rounded-md bg-amber-50/60 px-2 py-1.5 ring-1 ring-amber-100">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="truncate text-xs font-medium text-slate-700">{{ $v['title'] }}</span>
                                                <span class="shrink-0 text-[10px] text-amber-600">{{ $v['mode'] }}</span>
                                            </div>
                                            <div class="mt-0.5 flex items-center justify-between text-[10px] text-slate-400">
                                                <span>{{ $v['user'] }}</span>
                                                <span>已等待 {{ $v['elapsed'] }}</span>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
