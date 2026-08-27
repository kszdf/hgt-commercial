<x-app-layout>
<x-workspace-layout title="视频生成列表" :breadcrumbs="[['label' => '工作台总览', 'url' => '/dashboard'], ['label' => '视频生成列表']]">
<div class="mx-auto max-w-5xl p-6">

    @include('components.flash')

    <div class="mb-4 flex items-center justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-800">视频生成列表</h2>
            <p class="mt-0.5 text-sm text-slate-400">本账号全部出片记录（共 {{ $jobs->count() }} 条）。删除后进入回收站，可恢复。</p>
        </div>
        <div class="flex gap-2">
            <a href="/studio/recycle" class="rounded-lg studio-card studio-card-sm text-sm font-medium text-slate-600 hover:bg-slate-50">回收站</a>
        </div>
    </div>

    @if($jobs->isEmpty())
        <div class="luxury-glass p-10 text-center">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            </div>
            <div class="text-sm font-medium text-slate-600">还没有生成任何视频</div>
            <p class="mt-1 text-sm text-slate-400">前往视频出片，提交第一段配音稿即可生成真实短视频。</p>
            <a href="/studio/scroll" class="mt-4 inline-block rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-brand-700">前往出片 →</a>
        </div>
    @else
        <div class="space-y-4">
        @foreach($jobs as $job)
            <article class="luxury-glass overflow-hidden">
                <div class="flex gap-4 p-4">
                    <div class="relative w-24 shrink-0">
                        @if($job->coverAsset)
                            <img src="{{ route('studio.covers.preview', $job->coverAsset) }}" alt="" class="h-32 w-24 rounded-lg object-cover">
                        @elseif($job->isRendered())
                            <video src="/studio/scroll/download/{{ $job->job_id }}" muted preload="metadata" class="h-32 w-24 rounded-lg bg-black object-cover"></video>
                        @else
                            <div class="flex h-32 w-24 items-center justify-center rounded-lg bg-slate-100 text-xs text-slate-300">无封面</div>
                        @endif
                    </div>
                    <div class="flex flex-1 flex-col">
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-slate-800">{{ $job->title ?: '未命名视频' }}</span>
                            <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">{{ $job->mode === 'avatar' ? '数字人出镜' : '滚动字幕卡' }}</span>
                        </div>
                        <div class="mt-1 text-xs text-slate-400">{{ $job->created_at->format('Y-m-d H:i') }}</div>

                        <div class="mt-2 flex flex-wrap gap-2 text-xs">
                            <span class="rounded px-2 py-0.5
                                @if($job->status=='done') bg-emerald-100 text-emerald-700
                                @elseif($job->status=='failed') bg-red-100 text-red-700
                                @else bg-slate-100 text-slate-500 @endif">
                                渲染：{{ $job->status == 'done' ? '已完成' : ($job->status == 'failed' ? '失败' : '渲染中') }}
                            </span>
                            @if($job->status=='failed' && $job->failed_reason)
                                <span class="rounded px-2 py-0.5 bg-red-50 text-red-600"
                                    title="{{ $job->pipeline_error ?: '' }}">
                                    原因：{{ \App\Models\VideoJob::failedReasonLabel($job->failed_reason) }}
                                </span>
                            @endif
                            <span class="rounded px-2 py-0.5
                                @if($job->qc_status=='passed') bg-emerald-100 text-emerald-700
                                @elseif($job->qc_status=='warned') bg-amber-100 text-amber-700
                                @elseif($job->qc_status=='blocked') bg-red-100 text-red-700
                                @else bg-slate-100 text-slate-500 @endif">
                                质检：{{ $job->qc_status == 'passed' ? '通过' : ($job->qc_status == 'warned' ? '告警' : ($job->qc_status == 'blocked' ? '阻断' : '未检')) }}
                            </span>
                            <span class="rounded px-2 py-0.5
                                @if($job->publish_status=='approved' || $job->publish_status=='published') bg-emerald-100 text-emerald-700
                                @elseif($job->publish_status=='reviewing') bg-blue-100 text-blue-700
                                @elseif($job->publish_status=='rejected') bg-red-100 text-red-700
                                @else bg-slate-100 text-slate-500 @endif">
                                审核：{{ $job->publish_status == 'approved' ? '已通过' : ($job->publish_status == 'published' ? '已发布' : ($job->publish_status == 'reviewing' ? '审核中' : ($job->publish_status == 'rejected' ? '已驳回' : '待审核'))) }}
                            </span>
                        </div>

                        <div class="mt-auto flex items-end gap-2 pt-3">
                            @if($job->isRendered() && $job->isPendingReview())
                                <a href="/studio/review" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-700">去审核</a>
                            @endif
                            @if($job->canPublish())
                                <a href="/studio/publish" class="rounded-lg border border-brand-300 bg-white px-3 py-1.5 text-xs font-medium text-brand-700 hover:bg-brand-50">去发布</a>
                            @endif
                            @if(($job->isRendered() || $job->status === 'failed') && $job->dialogue)
                                <a href="/studio/scroll?src=clone&job_id={{ $job->id }}" title="复用此条的文稿与形式去出片"
                                   class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-50">{{ $job->status === 'failed' ? '↻ 重新出片' : '↻ 复刻' }}</a>
                                <button type="button" class="pack-btn rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs text-amber-600 hover:bg-amber-50"
                                        data-job="{{ $job->job_id }}" title="生成发布标题+副标题+高级感封面">✨ 包装</button>
                            @endif
                            <form action="{{ route('studio.videos.hit', $job) }}" method="POST">
                                @csrf
                                <button class="rounded-lg border px-3 py-1.5 text-xs transition {{ $job->is_hit ? 'border-amber-300 bg-amber-50 text-amber-600' : 'border-slate-200 bg-white text-slate-500 hover:bg-amber-50' }}"
                                        title="标记为爆款，方便后续一键复刻">{{ $job->is_hit ? '⭐ 已标记爆款' : '☆ 标记爆款' }}</button>
                            </form>
                            <form action="{{ route('studio.videos.destroy', $job) }}" method="POST" class="ml-auto">
                                @csrf @method('DELETE')
                                <button type="button" onclick="hgtDel(this)" data-msg="确定删除该视频？将移入回收站，可在回收站恢复。" class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs text-red-600 hover:bg-red-50">删除</button>
                            </form>
                        </div>
                    </div>
                </div>
            </article>
        @endforeach
        </div>
    @endif
</div>

{{-- 发布包装结果浮层 --}}
<div id="packModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">
    <div class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl">
        <div class="mb-3 flex items-center justify-between">
            <p class="text-sm font-semibold text-slate-700">✨ 发布包装（标题 + 封面）</p>
            <button type="button" onclick="document.getElementById('packModal').classList.add('hidden')" class="rounded-md px-2 py-1 text-slate-400 hover:bg-slate-100">✕</button>
        </div>
        <div id="packLoading" class="py-8 text-center text-sm text-slate-500">⏳ 正在生成标题与封面（智能选帧中）…</div>
        <div class="mb-3 flex flex-wrap items-center gap-2 rounded-lg bg-slate-50 px-3 py-2">
            <label class="flex cursor-pointer items-center gap-1.5 text-xs text-slate-600">
                <input type="checkbox" id="libUsePhoto" class="accent-amber-500"> 📷 用我的形象照做封面底图
            </label>
            <label class="cursor-pointer text-xs text-brand-600 hover:underline">
                <span id="libPhotoText">上传形象照</span>
                <input type="file" id="libPhotoFile" accept="image/jpeg,image/png,image/webp" class="hidden">
            </label>
        </div>
        <div id="packBody" class="hidden">
            <div class="flex flex-wrap gap-4">
                <img id="packCover" alt="封面" class="h-64 rounded-lg border border-slate-200 shadow-sm">
                <div class="min-w-52 flex-1 space-y-2">
                    <p id="packTitle" class="text-lg font-bold text-slate-800"></p>
                    <p id="packSubtitle" class="text-sm text-slate-600"></p>
                    <div class="flex gap-2 pt-1">
                        <button type="button" onclick="copyPackLib()" class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-600 hover:border-amber-300">复制文案</button>
                        <a id="packCoverLink" href="#" download class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-600 hover:border-amber-300">下载封面</a>
                    </div>
                </div>
            </div>
        </div>
        <div id="packError" class="hidden rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600"></div>
    </div>
</div>

<script>
document.querySelectorAll('.pack-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const jobId = btn.dataset.job;
        const modal = document.getElementById('packModal');
        const loading = document.getElementById('packLoading');
        const body = document.getElementById('packBody');
        const err = document.getElementById('packError');
        modal.classList.remove('hidden'); modal.classList.add('flex');
        loading.classList.remove('hidden'); body.classList.add('hidden'); err.classList.add('hidden');
        fetch('/studio/publish-pack', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({ job_id: jobId, use_photo: (document.getElementById('libUsePhoto')?.checked || false) })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) throw new Error(data.error);
            document.getElementById('packTitle').textContent = data.title;
            document.getElementById('packSubtitle').textContent = data.subtitle;
            const url = '/studio/publish-pack/cover/' + encodeURIComponent(data.cover_name);
            document.getElementById('packCover').src = url;
            document.getElementById('packCoverLink').href = url;
            loading.classList.add('hidden'); body.classList.remove('hidden');
        })
        .catch(function (e) {
            loading.classList.add('hidden');
            err.textContent = '生成失败：' + (e.message || '未知错误');
            err.classList.remove('hidden');
        });
    });
});
function copyPackLib() {
    const t = document.getElementById('packTitle').textContent;
    const s = document.getElementById('packSubtitle').textContent;
    navigator.clipboard?.writeText('标题：' + t + '\n副标题：' + s).then(() => hgtToast('info', '已复制'));
}
// 上传个人形象照（视频库浮层）
document.getElementById('libPhotoFile')?.addEventListener('change', async function () {
    const file = this.files && this.files[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('file', file);
    fd.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
    const label = document.getElementById('libPhotoText');
    label.textContent = '上传中…';
    try {
        const resp = await fetch('/studio/publish-pack/photo', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
        const data = await resp.json();
        if (!resp.ok || data.error) throw new Error(data.error || '上传失败');
        label.textContent = '✓ 已上传';
        document.getElementById('libUsePhoto').checked = true;
        hgtToast('success', '形象照已上传，生成封面时将使用它');
    } catch (e) {
        label.textContent = '上传形象照';
        hgtToast('error', '上传失败：' + (e.message || ''));
    }
});
</script>
</x-workspace-layout>
</x-app-layout>
