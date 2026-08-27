<x-app-layout>
<x-workspace-layout title="发布排期">
<div class="mx-auto max-w-6xl p-6">

    @include('components.flash')

    {{-- 顶部说明 + 月份切换 --}}
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
        <div class="text-sm text-slate-500">
            <span class="font-semibold text-slate-700">内容日历 · 发布排期</span>
            <span class="ml-2 text-xs">给视频排好发布时间：勾选「自动发布」到点自动分发到账号；不勾选则到点提醒你手动发（视频号等）。</span>
        </div>
        <div class="flex items-center gap-2 text-sm">
            <a href="{{ route('studio.schedule', ['month' => $ref->copy()->subMonth()->format('Y-m')]) }}" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-slate-600 hover:bg-slate-50">← 上月</a>
            <span class="font-medium text-slate-700">{{ $ref->format('Y年m月') }}</span>
            <a href="{{ route('studio.schedule', ['month' => $ref->copy()->addMonth()->format('Y-m')]) }}" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-slate-600 hover:bg-slate-50">下月 →</a>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        {{-- 左：日历 --}}
        <div class="luxury-glass p-5 lg:col-span-2">
            @php
                $first = $ref->copy()->startOfMonth();
                $daysInMonth = $ref->daysInMonth;
                $leading = $first->dayOfWeek; // 0=周日
                $today = now()->toDateString();
            @endphp
            <div class="grid grid-cols-7 gap-1 text-center text-xs">
                @foreach(['日','一','二','三','四','五','六'] as $w)
                    <div class="py-1 font-medium text-slate-400">{{ $w }}</div>
                @endforeach
                @for($i = 0; $i < $leading; $i++)
                    <div class="min-h-16"></div>
                @endfor
                @for($d = 1; $d <= $daysInMonth; $d++)
                    @php
                        $date = $ref->copy()->day($d)->toDateString();
                        $cnt = $byDay[$date] ?? 0;
                        $isToday = $date === $today;
                    @endphp
                    <a href="{{ route('studio.schedule', ['month' => $ref->format('Y-m')]) }}#day-{{ $date }}"
                       class="flex min-h-16 flex-col rounded-lg border p-1.5 transition hover:border-brand-300 {{ $isToday ? 'border-brand-400 bg-brand-50' : 'border-slate-100 bg-white' }}">
                        <span class="text-xs {{ $isToday ? 'font-bold text-brand-600' : 'text-slate-500' }}">{{ $d }}</span>
                        @if($cnt > 0)
                            <span class="mt-auto self-end rounded bg-brand-500 px-1.5 py-0.5 text-[10px] font-medium text-white">{{ $cnt }} 条</span>
                        @endif
                    </a>
                @endfor
            </div>
        </div>

        {{-- 右：今日待发 + 新建排期 --}}
        <div class="space-y-4">
            <div class="luxury-glass p-5">
                <h3 class="mb-3 text-sm font-semibold text-slate-700">今日待发</h3>
                @if($todayDue->isEmpty())
                    <p class="py-4 text-center text-sm text-slate-400">今日暂无到期排期。</p>
                @else
                    <div class="max-h-64 space-y-2 overflow-y-auto">
                        @foreach($todayDue as $s)
                            <div class="rounded-lg border border-amber-100 bg-amber-50/60 px-3 py-2">
                                <div class="truncate text-xs font-medium text-slate-700">{{ $s->videoJob?->title ?: ('#' . $s->video_job_id) }}</div>
                                <div class="mt-0.5 flex items-center justify-between text-[11px] text-slate-500">
                                    <span>{{ $s->schedule_at->format('m-d H:i') }} · {{ $s->account?->platformLabel() ?? '任意账号' }}{{ $s->account ? '·' . ($s->account->account_name ?: '') : '' }}</span>
                                    @if($s->auto_publish)
                                        <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-emerald-700">自动</span>
                                    @else
                                        <span class="rounded bg-amber-100 px-1.5 py-0.5 text-amber-700">待手动</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="luxury-glass p-5">
                <h3 class="mb-3 text-sm font-semibold text-slate-700">新建排期</h3>
                <form method="POST" action="{{ route('studio.schedule') }}" class="space-y-3 text-sm">
                    @csrf
                    <div>
                        <label class="mb-1 block text-slate-500">选择视频（仅已通过审核）</label>
                        <select name="video_job_id" required class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
                            @foreach($videos as $v)
                                <option value="{{ $v->id }}">{{ $v->title ?: $v->job_id }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-slate-500">发布账号（不选=任意已授权账号）</label>
                        <select name="platform_account_id" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
                            <option value="">（任意）</option>
                            @foreach($accounts as $a)
                                <option value="{{ $a->id }}">{{ $a->platformLabel() }} · {{ $a->account_name ?: '未命名' }}（{{ $a->isAuthorized() ? '已授权' : '未授权' }}）</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-slate-500">日期</label>
                            <input type="date" name="schedule_date" required value="{{ now()->toDateString() }}"
                                class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        </div>
                        <div>
                            <label class="mb-1 block text-slate-500">时间</label>
                            <input type="time" name="schedule_time" required value="09:00"
                                class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        </div>
                    </div>
                    <label class="flex cursor-pointer items-center gap-2 text-slate-600">
                        <input type="checkbox" name="auto_publish" value="1" class="accent-brand-500">
                        到点自动发布（需账号已授权；未授权会标记失败）
                    </label>
                    <div>
                        <input type="text" name="note" maxlength="120" placeholder="备注（选填）"
                            class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
                    </div>
                    <button class="w-full rounded-lg bg-brand-600 py-2 text-sm font-medium text-white hover:bg-brand-700">创建排期</button>
                </form>
            </div>
        </div>
    </div>

    {{-- 排期列表 --}}
    <section class="mt-5 luxury-glass overflow-hidden">
        <div class="px-5 py-4 text-sm font-semibold text-slate-700">{{ $ref->format('Y年m月') }} 排期（{{ $schedules->count() }}）</div>
        @if($schedules->isEmpty())
            <div class="px-5 pb-6 text-center text-sm text-slate-400">本月暂无排期。</div>
        @else
            <div class="divide-y divide-slate-100">
                @foreach($schedules as $s)
                    <div id="day-{{ $s->schedule_at->toDateString() }}" class="flex flex-wrap items-center gap-3 px-5 py-3">
                        <div class="w-24 text-sm font-medium text-slate-700">{{ $s->schedule_at->format('m-d H:i') }}</div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm text-slate-800">{{ $s->videoJob?->title ?: ('#' . $s->video_job_id) }}</div>
                            <div class="text-xs text-slate-400">
                                {{ $s->account?->platformLabel() ?? '任意账号' }}{{ $s->account ? ' · ' . ($s->account->account_name ?: '') : '' }}
                                @if($s->note)<span class="ml-1 text-slate-300">· {{ $s->note }}</span>@endif
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="rounded px-2 py-0.5 text-xs
                                @if($s->status === 'published') bg-emerald-100 text-emerald-700
                                @elseif($s->status === 'failed' || $s->status === 'skipped') bg-red-100 text-red-700
                                @elseif($s->status === 'due') bg-amber-100 text-amber-700
                                @else bg-slate-100 text-slate-500 @endif">
                                {{ $s->statusLabel() }}
                            </span>
                            @if($s->auto_publish)<span class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] text-emerald-600">自动</span>@endif
                        </div>
                        <div class="flex items-center gap-1.5">
                            @if($s->isRunnable())
                                <form method="POST" action="{{ route('studio.schedule.run', $s) }}">
                                    @csrf
                                    <button class="rounded-md bg-brand-600 px-2.5 py-1 text-[11px] font-medium text-white hover:bg-brand-700">立即发布</button>
                                </form>
                                <form method="POST" action="{{ route('studio.schedule.auto', $s) }}">
                                    @csrf
                                    <button class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[11px] text-slate-600 hover:bg-slate-50">
                                        {{ $s->auto_publish ? '取消自动' : '开启自动' }}
                                    </button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('studio.schedule.destroy', $s) }}" onsubmit="return confirm('确认删除该排期？')">
                                @csrf
                                @method('DELETE')
                                <button class="rounded-md border border-red-100 bg-red-50 px-2.5 py-1 text-[11px] text-red-600 hover:bg-red-100">删除</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>
</x-workspace-layout>
</x-app-layout>
