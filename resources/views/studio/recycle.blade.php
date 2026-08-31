<x-app-layout>
<x-workspace-layout title="回收站" :breadcrumbs="[['label' => '工作台总览', 'url' => '/dashboard'], ['label' => '回收站']]">
<div class="mx-auto max-w-5xl p-6">

    <div class="mb-4 flex items-center justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-800">回收站</h2>
            <p class="mt-0.5 text-sm text-slate-400">已删除的视频会暂存在这里（共 {{ $jobs->count() }} 条），可恢复或彻底删除。</p>
        </div>
        <a href="/studio/videos" class="rounded-lg studio-card studio-card-sm text-sm font-medium text-slate-600 hover:bg-slate-50">← 返回视频列表</a>
    </div>

    @if($jobs->isEmpty())
        <div class="luxury-glass p-10 text-center text-sm text-slate-400">回收站为空，没有已删除的视频。</div>
    @else
        <div class="space-y-4">
        @foreach($jobs as $job)
            <article class="luxury-glass overflow-hidden">
                <div class="flex gap-4 p-4">
                    <div class="relative w-24 shrink-0">
                        @if($job->coverAsset)
                            <img src="{{ route('studio.covers.preview', $job->coverAsset) }}" alt="" class="h-32 w-24 rounded-lg object-cover">
                        @else
                            <div class="flex h-32 w-24 items-center justify-center rounded-lg bg-slate-100 text-xs text-slate-300">无封面</div>
                        @endif
                    </div>
                    <div class="flex flex-1 flex-col">
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-slate-800">{{ $job->title ?: '未命名视频' }}</span>
                            <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">{{ $job->modeLabel() }}</span>
                        </div>
                        <div class="mt-1 text-xs text-slate-400">删除于 {{ $job->deleted_at->format('Y-m-d H:i') }}</div>

                        <div class="mt-auto flex items-end gap-2 pt-3">
                            <form action="{{ route('studio.recycle.restore', $job) }}" method="POST">
                                @csrf
                                <button type="submit" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-700">恢复</button>
                            </form>
                            <form action="{{ route('studio.recycle.force', $job) }}" method="POST" class="ml-auto">
                                @csrf @method('DELETE')
                                <button type="button" onclick="hgtDel(this)" data-msg="彻底删除后不可恢复，确定要永久删除该视频？" class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs text-red-600 hover:bg-red-50">彻底删除</button>
                            </form>
                        </div>
                    </div>
                </div>
            </article>
        @endforeach
        </div>
    @endif
</div>
</x-workspace-layout>
</x-app-layout>

