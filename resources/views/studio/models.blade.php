<x-app-layout>
<div class="mx-auto max-w-5xl p-6">
    <header class="mb-5">
        <h2 class="text-xl font-semibold text-slate-800">我的数字人模特</h2>
        <p class="mt-0.5 text-sm text-slate-400">上传不同场景的专属数字人驱动视频。系统自动转码静音化 + 竖屏/时长质检，通过后方可用于出片。</p>
        <p class="mt-2 flex flex-wrap gap-3">
            <a href="/studio/voices" class="text-sm text-brand-600 hover:underline">声音库 →</a>
            <a href="/studio/covers" class="text-sm text-brand-600 hover:underline">管理封面素材 →</a>
        </p>
    </header>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-2.5 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <!-- 拍摄注意事项（克隆质量的关键） -->
    <section class="mb-4 rounded-xl border border-amber-200 bg-amber-50/70 p-5">
        <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold text-amber-800">
            <svg class="h-4 w-4 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            拍摄注意事项（决定克隆效果，上传前务必浏览）
        </h3>
        <ul class="grid grid-cols-1 gap-x-6 gap-y-1.5 text-xs leading-relaxed text-amber-900 sm:grid-cols-2">
            <li>· <b>时长</b>：建议 5–30 秒，过短唇形学习不足，过长渲染更慢</li>
            <li>· <b>画幅</b>：优先竖屏 9:16（1080×1920），横屏出片会被裁切</li>
            <li>· <b>光线</b>：正面均匀打光，避免阴阳脸、强逆光、灯光频闪</li>
            <li>· <b>角度</b>：正脸平视镜头，头部小幅自然转动可接受</li>
            <li>· <b>人物</b>：单人出镜，画面中只有一位主角，背景尽量简洁</li>
            <li>· <b>稳定</b>：固定机位或用支架，避免大幅晃动、快速走动</li>
            <li>· <b>遮挡</b>：避免口罩、墨镜、手部长时间遮挡面部</li>
            <li>· <b>表情</b>：自然放松，避免夸张肢体动作与快速转头</li>
            <li>· <b>原声</b>：原片需含人声（用于唇形参考），出片时会替换为克隆配音</li>
            <li>· <b>格式</b>：mp4 / mov / webm，单文件 ≤ 200MB</li>
        </ul>
    </section>

    <section class="luxury-glass p-5">
        <h3 class="mb-3 text-sm font-semibold text-slate-700">上传新模特</h3>
        <form action="{{ route('studio.models') }}" method="POST" enctype="multipart/form-data" class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            @csrf
            <div class="lg:col-span-2">
                <label class="mb-1 block text-sm font-medium text-slate-600">模特视频（mp4/mov/webm，≤200MB，建议竖屏 9:16、5–30s）</label>
                <input type="file" name="file" accept="video/*" required
                    class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                <p id="fileHint" class="mt-1.5 text-xs"></p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-600">素材名称</label>
                <input type="text" name="name" maxlength="60" placeholder="如：会议室主讲"
                    class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-600">场景标签</label>
                <input type="text" name="scene" maxlength="40" placeholder="如：会议室 / 户外"
                    class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
            </div>
            <div class="lg:col-span-2">
                <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-brand-600">上传并自动质检</button>
            </div>
        </form>
    </section>

    <section class="mt-4">
        <h3 class="mb-3 text-sm font-semibold text-slate-700">已上传素材（{{ $assets->count() }}）</h3>
        @if($assets->isEmpty())
            <p class="rounded-lg bg-slate-50 p-4 text-sm text-slate-400">还没有上传任何模特，先上传一个试试。</p>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($assets as $a)
                    <div class="luxury-glass flex flex-col p-4">
                        <div class="mb-2 overflow-hidden rounded-lg bg-black/5">
                            <video class="h-40 w-full object-cover" src="{{ route('studio.models.preview', $a) }}" controls preload="metadata"></video>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="truncate text-sm font-medium text-slate-700">{{ $a->name }}</span>
                            @if($a->status === 'ready')
                                <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700">就绪</span>
                            @else
                                <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">质检未过</span>
                            @endif
                        </div>
                        <div class="mt-1 text-xs text-slate-400">
                            {{ $a->scene ? $a->scene.' · ' : '' }}{{ $a->resolution ?? '-' }} · {{ $a->duration ? number_format($a->duration,1).'s' : '-' }}
                        </div>
                        <div class="mt-3 flex gap-2">
                            <form action="{{ route('studio.models.reupload', $a) }}" method="POST" enctype="multipart/form-data" class="flex-1">
                                @csrf
                                <input type="file" name="file" accept="video/*" class="hidden" onchange="this.form.submit()">
                                <button type="button" onclick="this.previousElementSibling.click()" class="w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs text-slate-600 hover:bg-slate-50">重新上传</button>
                            </form>
                            <form action="{{ route('studio.models.destroy', $a) }}" method="POST" onsubmit="return confirm('确定删除该素材？');" class="flex-1">
                                @csrf @method('DELETE')
                                <button type="submit" class="w-full rounded-lg border border-red-200 bg-white px-2 py-1.5 text-xs text-red-600 hover:bg-red-50">删除</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>
</div>

<script>
// 选文件实时合规自检：类型 + 大小即时反馈（深度质检在上传后由服务端完成）
(function () {
    const input = document.querySelector('input[name="file"]');
    const hint = document.getElementById('fileHint');
    if (!input || !hint) return;
    const MAX = 200 * 1024 * 1024;
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
            hint.textContent = '文件过大（' + (f.size / 1024 / 1024).toFixed(1) + 'MB），请控制在 200MB 以内';
            this.value = '';
            return;
        }
        hint.className = 'mt-1.5 text-xs text-green-600';
        hint.textContent = '已选择：' + f.name + '（' + (f.size / 1024 / 1024).toFixed(1) + 'MB）· 类型与大小合规，点击「上传并自动质检」后将进行静音化 / 竖屏 / 时长深度质检';
    });
})();
</script>
</x-app-layout>
