<x-app-layout>
<x-workspace-layout title="我的数字人" :breadcrumbs="[['label' => '工作台', 'url' => '/dashboard'], ['label' => '我的数字人']]">
<div class="mx-auto max-w-5xl p-6">
    <header class="studio-pagehead">
        <p>上传一段你自己的视频，它就是你的专属数字人——之后所有片子都由"你"出镜讲，不用每次真人重拍。</p>
        <p class="mt-2 flex flex-wrap gap-3">
            <a href="/studio/voices" class="text-sm text-brand-600 hover:underline">配声音（声音库）→</a>
            <a href="/studio/covers" class="text-sm text-brand-600 hover:underline">管理封面素材 →</a>
        </p>
    </header>

    @include('components.flash')

    <!-- ===== 自建专属数字人：三步讲清楚（这是产品化最关键的一屏） ===== -->
    <section class="mt-4 rounded-xl border border-brand-100 bg-brand-50/40 p-5">
        <h3 class="text-sm font-semibold text-slate-700">三步拥有你自己的数字人</h3>
        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="rounded-lg bg-white p-4">
                <div class="mb-1.5 flex h-7 w-7 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">1</div>
                <div class="text-sm font-medium text-slate-700">拍一段你自己的视频</div>
                <p class="mt-1 text-xs leading-relaxed text-slate-400">坐着讲 30 秒左右，手上带点自然动作。手机竖着拍就行。<b class="text-slate-500">没有视频也可直接传一张照片。</b></p>
            </div>
            <div class="rounded-lg bg-white p-4">
                <div class="mb-1.5 flex h-7 w-7 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">2</div>
                <div class="text-sm font-medium text-slate-700">传上来，自动处理</div>
                <p class="mt-1 text-xs leading-relaxed text-slate-400">系统自动去掉原声、做竖屏和时长质检，通过就能出片。</p>
            </div>
            <div class="rounded-lg bg-white p-4">
                <div class="mb-1.5 flex h-7 w-7 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">3</div>
                <div class="text-sm font-medium text-slate-700">设为默认出镜</div>
                <p class="mt-1 text-xs leading-relaxed text-slate-400">设一次，以后每次出片自动用你这个形象。</p>
            </div>
        </div>
    </section>

    <!-- ===== 拍摄注意事项 ===== -->
    <section class="mt-4 rounded-xl border border-amber-200 bg-amber-50/70 p-5">
        <details class="rounded-lg border border-amber-200 bg-amber-50 p-3">
            <summary class="flex cursor-pointer items-center gap-2 text-sm font-semibold text-amber-800">
                <svg class="h-4 w-4 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                怎么拍效果最好（上传前务必看一眼）
            </summary>
            <div class="mt-3 rounded-lg bg-white/70 p-3">
                <p class="text-xs font-semibold text-amber-900">最重要的一条：手上要有动作</p>
                <p class="mt-1 text-xs leading-relaxed text-amber-900">
                    数字人的手势和身体动作，是从你这段视频里"继承"下来的——<b>你拍的时候手在动，成片里手就会动；你手不动，成片里手就不动</b>。
                    所以讲解时自然比划、数数、拿个东西展示，成片才不僵。<span class="hint" data-tip="系统只重画你的脸和嘴，身体和手是原样保留的。想要"边讲边比划"的效果，源头就得拍进去。">?</span>
                </p>
            </div>
            <ul class="mt-2 grid grid-cols-1 gap-x-6 gap-y-1.5 text-xs leading-relaxed text-amber-900 sm:grid-cols-2">
                <li>· <b>时长</b>：建议 15–60 秒，太短唇形学不足，太长上传慢</li>
                <li>· <b>画幅</b>：竖屏 9:16（手机竖着拍），横屏出片会被裁切</li>
                <li>· <b>光线</b>：正面均匀打光，别逆光、别阴阳脸</li>
                <li>· <b>角度</b>：正脸平视镜头，头小幅自然转动没问题</li>
                <li>· <b>人物</b>：画面里只有你一个人，背景干净些</li>
                <li>· <b>稳定</b>：手机支住或用支架，别边走边拍</li>
                <li>· <b>遮挡</b>：手别长时间挡住脸（挡脸唇形学不好）；戴口罩墨镜不行</li>
                <li>· <b>表情</b>：自然放松即可，别夸张地大幅挥手或快速甩头</li>
                <li>· <b>原声</b>：原片要有人声（给唇形做参考），出片时会换成你的克隆配音</li>
                <li>· <b>格式</b>：mp4 / mov / webm，单文件 ≤ 96MB</li>
            </ul>
        </details>
    </section>

    <section class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- A. 传视频（推荐：动作更自然） --}}
        <div class="luxury-glass p-5">
            <div class="mb-1 flex items-center gap-2">
                <h3 class="text-sm font-semibold text-slate-700">方式一：传一段视频</h3>
                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">推荐 · 动作自然</span>
            </div>
            <p class="mb-4 text-xs leading-relaxed text-slate-400">拍一段 30 秒左右的竖屏视频（手上带动作），成片里身体和手会跟着动，最自然。</p>
            <form action="{{ route('studio.models') }}" method="POST" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">你的视频（mp4/mov/webm，≤96MB）</label>
                    <input type="file" name="file" accept="video/*" required
                        class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                    <p id="fileHint" class="mt-1.5 text-xs"></p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-600">形象名称</label>
                        <input type="text" name="name" maxlength="60" placeholder="如：老张·办公室主讲"
                            class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-600">场景标签</label>
                        <input type="text" name="scene" maxlength="40" placeholder="如：办公室"
                            class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                    </div>
                </div>
                <button type="submit" class="w-full rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-brand-600">上传并自动质检</button>
            </form>
        </div>

        {{-- B. 传照片（单图口播：一张照片直出数字人） --}}
        <div class="luxury-glass p-5 ring-1 ring-brand-100">
            <div class="mb-1 flex items-center gap-2">
                <h3 class="text-sm font-semibold text-slate-700">方式二：传一张照片</h3>
                <span class="rounded-full bg-brand-100 px-2 py-0.5 text-xs font-medium text-brand-700">拍张照直出口播</span>
            </div>
            <p class="mb-4 text-xs leading-relaxed text-slate-400">
                没有现成视频？传一张正面清晰的照片，系统自动生成会说话的数字人。
                <b class="text-slate-500">说明：嘴型会动，身体保持静止</b>（照片没有动作可继承），适合快速出片。
            </p>
            <form action="{{ route('studio.models.photo') }}" method="POST" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">正面照片（jpg/png/webp，≤20MB）</label>
                    <input type="file" name="photo" accept="image/*" required
                        class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                    <p id="photoHint" class="mt-1.5 text-xs"></p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">形象名称</label>
                    <input type="text" name="name" maxlength="60" placeholder="如：老张·照片版"
                        class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                </div>
                <button type="submit" class="w-full rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-brand-600">生成照片数字人</button>
                <p class="text-center text-xs text-slate-400">约 10 秒生成，完成后出现在下方列表</p>
            </form>
        </div>
    </section>

    <section class="mt-4">
        <h3 class="mb-3 text-sm font-semibold text-slate-700">我的数字人（{{ $assets->count() }}）</h3>
        @if($assets->isEmpty())
            <div class="flex h-32 flex-col items-center justify-center rounded-xl border border-dashed border-slate-200 text-center">
                <p class="text-sm text-slate-400">还没有专属形象</p>
                <p class="mt-1 text-xs text-slate-300">按上面三步拍一段传上来，就是你的数字人</p>
            </div>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($assets as $a)
                    <div class="luxury-glass flex flex-col p-4 {{ $a->is_default ? 'ring-2 ring-brand-300' : '' }}">
                        <div class="mb-2 overflow-hidden rounded-lg bg-black/5">
                            <video class="h-40 w-full object-cover" src="{{ route('studio.models.preview', $a) }}" controls preload="metadata"></video>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-medium text-slate-700">{{ $a->name }}</span>
                            <div class="flex shrink-0 items-center gap-1">
                                @if($a->is_default)
                                    <span class="rounded-full bg-brand-100 px-2 py-0.5 text-xs font-medium text-brand-700">默认出镜</span>
                                @endif
                                @if($a->status === 'ready')
                                    <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700">可用</span>
                                @else
                                    <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">质检未过</span>
                                @endif
                            </div>
                        </div>
                        <div class="mt-1 text-xs text-slate-400">
                            @if($a->isPhoto())
                                <span class="mr-1 rounded bg-slate-100 px-1.5 py-0.5 text-slate-500">照片数字人</span>
                            @endif
                            {{ ($a->scene && !$a->isPhoto()) ? $a->scene.' · ' : '' }}{{ $a->resolution ?? '-' }} · {{ $a->duration ? number_format($a->duration,1).'s' : '-' }}
                        </div>
                        @if($a->status !== 'ready' && !empty($a->qc_result['reason']))
                            <div class="mt-1 text-xs text-red-500">未过原因：{{ $a->qc_result['reason'] }}</div>
                        @endif
                        <div class="mt-3 flex gap-2">
                            @if($a->status === 'ready' && ! $a->is_default)
                                <form action="{{ route('studio.models.default', $a) }}" method="POST" class="flex-1">
                                    @csrf
                                    <button type="submit" class="w-full rounded-lg border border-brand-200 bg-white px-2 py-1.5 text-xs font-medium text-brand-600 hover:bg-brand-50">设为默认</button>
                                </form>
                            @endif
                            <form action="{{ route('studio.models.reupload', $a) }}" method="POST" enctype="multipart/form-data" class="flex-1">
                                @csrf
                                <input type="file" name="file" accept="video/*" class="hidden" onchange="this.form.submit()">
                                <button type="button" onclick="this.previousElementSibling.click()" class="w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs text-slate-600 hover:bg-slate-50">重传</button>
                            </form>
                            <form action="{{ route('studio.models.destroy', $a) }}" method="POST" class="flex-1">
                                @csrf @method('DELETE')
                                <button type="button" onclick="hgtDel(this)" data-msg="确定删除该形象？删除后不可恢复。" class="w-full rounded-lg border border-red-200 bg-white px-2 py-1.5 text-xs text-red-600 hover:bg-red-50">删除</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>

<script>
// 选文件实时合规自检：类型 + 大小即时反馈（深度质检在上传后由服务端完成）
(function () {
    const input = document.querySelector('input[name="file"]');
    const hint = document.getElementById('fileHint');
    if (!input || !hint) return;
    // 上限 96MB：受中转链路带宽约束（国内服务器出网约 3Mbps，96MB 约需 5 分钟），非 CF 硬限。
    const MAX = 96 * 1024 * 1024;
    input.addEventListener('change', function () {
        const f = this.files && this.files[0];
        if (!f) { hint.textContent = ''; hint.className = 'mt-1.5 text-xs'; return; }
        if (!f.type.startsWith('video/')) {
            hint.className = 'mt-1.5 text-xs text-red-600';
            hint.textContent = '文件类型不支持：请选择视频文件（mp4 / mov / webm）';
            this.value = '';
            return;
        }
        if (f.size > MAX) {
            hint.className = 'mt-1.5 text-xs text-red-600';
            hint.textContent = '文件过大（' + (f.size / 1024 / 1024).toFixed(1) + 'MB），请控制在 96MB 以内';
            this.value = '';
            return;
        }
        hint.className = 'mt-1.5 text-xs text-green-600';
        hint.textContent = '已选择：' + f.name + '（' + (f.size / 1024 / 1024).toFixed(1) + 'MB）· 上传后自动去原声并做质检';
    });
})();

// 照片数字人：选照片自检（类型 + 大小）
(function () {
    const input = document.querySelector('input[name="photo"]');
    const hint = document.getElementById('photoHint');
    if (!input || !hint) return;
    const MAX = 20 * 1024 * 1024; // ≤20MB（照片）
    input.addEventListener('change', function () {
        const f = this.files && this.files[0];
        if (!f) { hint.textContent = ''; hint.className = 'mt-1.5 text-xs'; return; }
        if (!f.type.startsWith('image/')) {
            hint.className = 'mt-1.5 text-xs text-red-600';
            hint.textContent = '文件类型不支持：请选择图片（jpg / png / webp）';
            this.value = '';
            return;
        }
        if (f.size > MAX) {
            hint.className = 'mt-1.5 text-xs text-red-600';
            hint.textContent = '照片过大（' + (f.size / 1024 / 1024).toFixed(1) + 'MB），请控制在 20MB 以内';
            this.value = '';
            return;
        }
        hint.className = 'mt-1.5 text-xs text-green-600';
        hint.textContent = '已选择：' + f.name + '（' + (f.size / 1024 / 1024).toFixed(1) + 'MB）· 将自动生成会说话的数字人';
    });
})();
</script>
</x-workspace-layout>
</x-app-layout>
