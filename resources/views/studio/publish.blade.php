<x-app-layout>
<div class="mx-auto max-w-6xl p-6">
    <header class="mb-5">
        <h2 class="text-xl font-semibold text-slate-800">批量外发</h2>
    </header>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    @if($videos->isEmpty())
        <div class="luxury-glass p-10 text-center text-slate-400">暂无待发布视频。请先在「人工审核」中通过视频。</div>
    @else
        <form method="POST" action="{{ route('studio.publish.do') }}" class="space-y-5">
            @csrf

            {{-- 平台选择 --}}
            <div class="luxury-glass p-5">
                <div class="mb-3 text-sm font-semibold text-slate-700">选择分发平台</div>
                <div class="flex flex-wrap gap-3">
                    @foreach($platforms as $key => $label)
                        @php $acc = $accounts->firstWhere('platform', $key); @endphp
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm hover:border-brand-400">
                            <input type="checkbox" name="platforms[]" value="{{ $key }}" class="accent-brand-600" checked>
                            <span class="font-medium text-slate-700">{{ $label }}</span>
                            @if($acc && $acc->isAuthorized())
                                <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] text-emerald-700">已授权</span>
                            @else
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-400">未授权</span>
                            @endif
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- 待发视频 --}}
            <div class="luxury-glass overflow-hidden p-5">
                <div class="mb-3 text-sm font-semibold text-slate-700">选择视频（{{ $videos->count() }} 个待发布）</div>
                <div class="space-y-2">
                    @foreach($videos as $job)
                        <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-100 bg-white px-3 py-2 hover:border-brand-400">
                            @if($job->coverAsset)
                                <img src="{{ route('studio.covers.preview', $job->coverAsset) }}" class="h-10 w-10 flex-none rounded object-cover" alt="">
                            @endif
                            <input type="checkbox" name="video_ids[]" value="{{ $job->id }}" class="accent-brand-600" checked>
                            <span class="font-medium text-slate-800">{{ $job->title ?: '未命名视频' }}</span>
                            <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">{{ $job->mode === 'avatar' ? '数字人出镜' : '滚动字幕卡' }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end">
                <button class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-700">批量发布</button>
            </div>
        </form>
    @endif

    {{-- 发布历史 --}}
    <section class="mt-8">
        <h3 class="mb-3 text-sm font-semibold text-slate-700">发布历史</h3>
        @if($records->isEmpty())
            <div class="luxury-glass p-6 text-center text-sm text-slate-400">暂无发布记录。</div>
        @else
            <div class="luxury-glass overflow-hidden">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5">视频</th>
                            <th class="px-4 py-2.5">平台</th>
                            <th class="px-4 py-2.5">状态</th>
                            <th class="px-4 py-2.5">发布时间</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($records as $rec)
                            <tr>
                                <td class="px-4 py-2.5 text-slate-700">{{ $rec->videoJob?->title ?: ('#'.$rec->video_job_id) }}</td>
                                <td class="px-4 py-2.5 text-slate-600">{{ $platforms[$rec->platform] ?? $rec->platform }}</td>
                                <td class="px-4 py-2.5">
                                    @if($rec->isSuccess())
                                        <span class="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">成功</span>
                                    @else
                                        <span class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-700">失败</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-slate-500">{{ $rec->published_at?->format('m-d H:i') ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
</x-app-layout>
