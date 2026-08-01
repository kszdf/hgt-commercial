<x-app-layout>
<div class="mx-auto max-w-5xl p-6">
    <header class="mb-5">
        <h2 class="text-xl font-semibold text-slate-800">人工审核</h2>
        <p class="mt-0.5 text-sm text-slate-400">出片渲染完成后进入审核队列，逐条确认质量后通过或驳回。</p>
    </header>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    @if($jobs->isEmpty())
        <div class="luxury-glass p-10 text-center text-slate-400">暂无待审核视频。出片完成后会自动进入此处。</div>
    @else
        <div class="space-y-5">
        @foreach($jobs as $job)
            <article class="luxury-glass overflow-hidden">
                <div class="grid gap-4 p-5 lg:grid-cols-2">
                    <div>
                        <video src="/studio/scroll/download/{{ $job->job_id }}" controls
                               class="w-full rounded-lg bg-black aspect-[9/16] max-h-[420px]"></video>
                    </div>
                    <div class="flex flex-col">
                        <div class="mb-2 flex items-center gap-2">
                            <span class="text-base font-semibold text-slate-800">{{ $job->title ?: '未命名视频' }}</span>
                            <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">{{ $job->mode === 'avatar' ? '数字人出镜' : '滚动字幕卡' }}</span>
                        </div>

                        <div class="mb-3 flex flex-wrap gap-2 text-xs">
                            <span class="rounded px-2 py-0.5
                                @if($job->publish_status=='draft') bg-slate-100 text-slate-600
                                @elseif($job->publish_status=='reviewing') bg-blue-100 text-blue-700
                                @elseif($job->publish_status=='rejected') bg-red-100 text-red-700
                                @else bg-emerald-100 text-emerald-700 @endif">
                                审核：{{ $job->publish_status == 'draft' ? '待审核' : ($job->publish_status == 'reviewing' ? '审核中' : ($job->publish_status == 'rejected' ? '已驳回' : '已通过')) }}
                            </span>
                            <span class="rounded px-2 py-0.5
                                @if($job->qc_status=='passed') bg-emerald-100 text-emerald-700
                                @elseif($job->qc_status=='warned') bg-amber-100 text-amber-700
                                @elseif($job->qc_status=='blocked') bg-red-100 text-red-700
                                @else bg-slate-100 text-slate-500 @endif">
                                质检：{{ $job->qc_status == 'passed' ? '通过' : ($job->qc_status == 'warned' ? '告警' : ($job->qc_status == 'blocked' ? '阻断' : '未检')) }}
                            </span>
                        </div>

                        @if($job->qcReport)
                            <div class="mb-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                                <div class="mb-1 font-medium">机器质检结论（得分 {{ $job->qcReport->score ?? '-' }} · 等级 {{ $job->qcReport->level ?? '-' }}）</div>
                                @if($job->qcReport->issues)
                                    <ul class="list-disc space-y-0.5 pl-4">
                                    @foreach(json_decode($job->qcReport->issues, true) ?? [] as $iss)
                                        <li>{{ $iss['label'] ?? ($iss['type'] ?? '问题') }}：{{ $iss['detail'] ?? '' }}</li>
                                    @endforeach
                                    </ul>
                                @else
                                    <div class="text-slate-400">无明显问题</div>
                                @endif
                            </div>
                        @endif

                        @if($job->isRejected() && $job->review_note)
                            <div class="mb-3 rounded-lg border border-red-200 bg-red-50 p-2 text-xs text-red-700">驳回理由：{{ $job->review_note }}</div>
                        @endif

                        <div class="mt-auto flex items-end gap-2 pt-2">
                            <form method="POST" action="{{ route('studio.review.approve', $job) }}" onsubmit="return confirm('确认通过该视频并放入可外发队列？');">
                                @csrf
                                <button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">通过</button>
                            </form>
                            <form method="POST" action="{{ route('studio.review.reject', $job) }}" class="flex flex-1 items-end gap-2">
                                @csrf
                                <input name="reason" required maxlength="500" placeholder="驳回理由（必填）"
                                       class="flex-1 rounded-lg border border-slate-200 bg-white p-2 text-sm text-slate-700 outline-none focus:border-brand-400">
                                <button class="rounded-lg bg-red-500 px-4 py-2 text-sm font-medium text-white hover:bg-red-600">驳回</button>
                            </form>
                        </div>
                    </div>
                </div>
            </article>
        @endforeach
        </div>
    @endif
</div>
</x-app-layout>
