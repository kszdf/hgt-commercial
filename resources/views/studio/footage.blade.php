<x-app-layout>
<x-workspace-layout title="真人素材精剪">
<div class="mx-auto max-w-4xl p-6">

    @include('components.flash')

    <div class="luxury-glass p-5">
        <h3 class="text-sm font-semibold text-slate-700">真人出镜素材 → 自动精剪成片</h3>
        <p class="mt-1 text-xs leading-relaxed text-slate-500">
            上传你手机/相机拍的真人口播原片（竖屏 9:16 最佳），系统自动：
            <strong>去气口、去长停顿、去重复句</strong> → 拼接 → <strong>烧录字幕</strong> → 抽帧<strong>封面</strong>，
            输出可直接发布的成熟短视频。支持 mp4/mov 等，≤500MB，建议单条 ≤10 分钟。
        </p>

        <form method="POST" action="{{ route('studio.footage.edit') }}" enctype="multipart/form-data" class="mt-4 space-y-3" id="footageForm">
            @csrf
            <div class="flex flex-wrap items-center gap-3">
                <input type="file" name="file" accept="video/*" required
                       class="block w-full max-w-md rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-500 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-white hover:file:bg-brand-600">
                <select name="language" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600">
                    <option value="zh">中文普通话</option>
                    <option value="auto">自动识别</option>
                </select>
                <button type="submit" id="footageBtn"
                        class="rounded-lg bg-brand-500 px-5 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-brand-600 disabled:opacity-50">
                    开始精剪
                </button>
            </div>
            <p id="footageHint" class="hidden text-xs text-brand-600">⏳ 正在自动精剪（转写 → 去气口/停顿/重复 → 拼接 → 字幕 → 封面），一般需 1-3 分钟，请勿关闭页面…</p>
        </form>
    </div>

    @if (!empty($result) && $result['ok'] ?? false)
        <div class="mt-5 luxury-glass p-5" id="footageResult">
            <div class="mb-3 flex items-center justify-between">
                <h4 class="text-sm font-semibold text-slate-700">精剪成品</h4>
                <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-600">
                    时长 {{ $result['duration_before'] }}s → {{ $result['duration_after'] }}s（剪掉 {{ round($result['duration_before'] - $result['duration_after'], 1) }}s）
                </span>
            </div>

            <video controls playsinline class="w-full rounded-xl border border-slate-200 bg-black"
                   poster="{{ $result['cover_name'] ? route('studio.footage.play', $result['cover_name']) : '' }}"
                   src="{{ route('studio.footage.play', $result['out_name']) }}"></video>

            <div class="mt-4 grid gap-3 md:grid-cols-2">
                @if(!empty($result['silences_removed']))
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                        <p class="text-xs font-semibold text-amber-700">已剪停顿 / 气口（{{ count($result['silences_removed']) }} 处）</p>
                        <ul class="mt-1 space-y-0.5 text-xs text-amber-600">
                            @foreach(array_slice($result['silences_removed'], 0, 8) as $s)
                                <li>· {{ $s['from_sec'] }}s → {{ $s['to_sec'] }}s（剪掉 {{ $s['cut_sec'] }}s）</li>
                            @endforeach
                            @if(count($result['silences_removed']) > 8)<li>… 共 {{ count($result['silences_removed']) }} 处</li>@endif
                        </ul>
                    </div>
                @endif
                @if(!empty($result['dups_removed']))
                    <div class="rounded-lg border border-rose-200 bg-rose-50 p-3">
                        <p class="text-xs font-semibold text-rose-700">已去重复句（{{ count($result['dups_removed']) }} 处）</p>
                        <ul class="mt-1 space-y-0.5 text-xs text-rose-600">
                            @foreach(array_slice($result['dups_removed'], 0, 5) as $d)
                                <li>· {{ mb_substr($d['dup_of'], 0, 30) }} …（重复已合并）</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            @if(!empty($result['transcript']))
                <div class="mt-3 rounded-lg border border-slate-200 bg-white p-3">
                    <p class="text-xs font-semibold text-slate-600">成片文案（字幕内容）</p>
                    <p class="mt-1 text-sm leading-relaxed text-slate-700">{{ $result['transcript'] }}</p>
                </div>
            @endif

            <div class="mt-4 flex flex-wrap gap-2">
                <a href="{{ route('studio.footage.play', $result['out_name']) }}"
                   class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600">⬇ 下载成片</a>
                <button type="button" id="packBtn"
                        class="rounded-lg bg-amber-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-amber-600">
                    ✨ 生成发布标题 + 封面
                </button>
                <a href="{{ route('studio.rewrite-original') }}"
                   class="rounded-lg bg-slate-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800">去二创改写文案</a>
                <a href="{{ route('studio.publish') }}"
                   class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700">去发布</a>
            </div>

            {{-- 发布包装结果（标题/副标题/封面） --}}
            <div id="packBox" class="mt-4 hidden rounded-xl border border-amber-200 bg-amber-50/60 p-4">
                <p class="mb-2 text-xs font-semibold text-amber-700">发布包装（对标主流财税IP · 高级感）</p>
                <div class="flex flex-wrap gap-4">
                    <img id="packCover" alt="封面" class="h-72 rounded-lg border border-slate-200 shadow-sm">
                    <div class="min-w-56 flex-1 space-y-2">
                        <div>
                            <p class="text-xs text-slate-400">主标题</p>
                            <p id="packTitle" class="text-lg font-bold text-slate-800"></p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400">副标题</p>
                            <p id="packSubtitle" class="text-sm text-slate-600"></p>
                        </div>
                        <div class="flex gap-2 pt-1">
                            <button type="button" onclick="copyPack()"
                                    class="rounded-md bg-white px-3 py-1.5 text-xs font-medium text-slate-600 border border-slate-200 hover:border-amber-300">复制文案</button>
                            <a id="packCoverLink" href="#" download
                               class="rounded-md bg-white px-3 py-1.5 text-xs font-medium text-slate-600 border border-slate-200 hover:border-amber-300">下载封面</a>
                        </div>
                        <p class="text-[11px] text-amber-600/80">提示：封面由成片智能选帧 + 人脸构图生成，标题/副标题已按财税垂类规则过滤。</p>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

<script>
document.getElementById('footageForm')?.addEventListener('submit', function () {
    const btn = document.getElementById('footageBtn');
    const hint = document.getElementById('footageHint');
    if (btn) { btn.disabled = true; btn.textContent = '精剪中…'; }
    if (hint) hint.classList.remove('hidden');
});

// 发布包装：标题 + 副标题 + 高级感封面
document.getElementById('packBtn')?.addEventListener('click', async function () {
    const btn = this;
    const box = document.getElementById('packBox');
    btn.disabled = true; btn.textContent = '生成中…';
    try {
        const uuid = @json($result['uuid'] ?? '');
        const text = @json($result['transcript'] ?? '');
        const resp = await fetch('/studio/publish-pack', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({ uuid: uuid, text: text, industry: '财税' })
        });
        const data = await resp.json();
        if (!resp.ok || data.error) throw new Error(data.error || ('HTTP ' + resp.status));
        document.getElementById('packTitle').textContent = data.title;
        document.getElementById('packSubtitle').textContent = data.subtitle;
        const coverUrl = '/studio/publish-pack/cover/' + encodeURIComponent(data.cover_name);
        document.getElementById('packCover').src = coverUrl;
        document.getElementById('packCoverLink').href = coverUrl;
        box.classList.remove('hidden');
        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (e) {
        hgtToast('error', '生成失败：' + (e.message || '未知错误'));
    } finally {
        btn.disabled = false; btn.textContent = '✨ 生成发布标题 + 封面';
    }
});

function copyPack() {
    const title = document.getElementById('packTitle').textContent;
    const subtitle = document.getElementById('packSubtitle').textContent;
    navigator.clipboard?.writeText('标题：' + title + '\n副标题：' + subtitle).then(() => hgtToast('info', '已复制标题/副标题'));
}
</script>
</x-workspace-layout>
</x-app-layout>
