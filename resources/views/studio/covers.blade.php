<x-app-layout>
<div class="mx-auto max-w-5xl p-6">
    <header class="mb-5">
        <h2 class="text-xl font-semibold text-slate-800">封面素材库</h2>
        <p class="mt-0.5 text-sm text-slate-400">上传视频封面图（jpg/png/webp，≤10MB）。发布到视频号 / 抖音 / 小红书 时可指定封面。系统自动记录尺寸与大小，租户隔离。</p>
        <p class="mt-2 flex flex-wrap gap-3">
            <a href="/studio/voices" class="text-sm text-brand-600 hover:underline">声音库 →</a>
            <a href="/studio/models" class="text-sm text-brand-600 hover:underline">管理我的模特 →</a>
        </p>
    </header>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-2.5 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <section class="luxury-glass p-5">
        <h3 class="mb-3 text-sm font-semibold text-slate-700">上传新封面</h3>
        <form action="{{ route('studio.covers') }}" method="POST" enctype="multipart/form-data" class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            @csrf
            <div class="lg:col-span-2">
                <label class="mb-1 block text-sm font-medium text-slate-600">封面图片（jpg/png/webp，≤10MB，建议 1080×1920 竖版 或 1280×720 横版）</label>
                <input type="file" name="file" accept="image/*" required
                    class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-600">封面名称</label>
                <input type="text" name="name" maxlength="60" placeholder="如：金税四期钩子图"
                    class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-600">场景标签</label>
                <input type="text" name="scene" maxlength="40" placeholder="如：财税科普 / 政策解读"
                    class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
            </div>
            <div class="lg:col-span-2">
                <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-brand-600">上传封面</button>
            </div>
        </form>
    </section>

    <section class="mt-4">
        <h3 class="mb-2 text-sm font-semibold text-slate-700">平台预设封面库</h3>
        <p class="mb-3 text-sm text-slate-400">按行业分类的精美封面模板，出片页「平台预设」中可直接选用；点「收藏」可复制到我的封面自行改图（预设本身不可删改）。</p>

        <div class="mb-4 flex flex-wrap gap-2" id="presetTabs">
            @foreach($presetCategories as $cat)
                <button type="button" data-cat="{{ $cat['slug'] }}"
                    class="preset-tab rounded-full border px-3.5 py-1.5 text-sm transition
                    {{ $loop->first ? 'border-brand-400 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-600 hover:border-brand-300' }}">
                    {{ $cat['label'] }}
                </button>
            @endforeach
        </div>

        @foreach($presetCategories as $cat)
            <div class="preset-panel" data-cat="{{ $cat['slug'] }}" @if(!$loop->first) style="display:none" @endif>
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach($cat['covers'] as $c)
                        <div class="luxury-glass flex flex-col p-3">
                            <div class="relative mb-2 overflow-hidden rounded-lg bg-black/5">
                                <img src="{{ route('studio.covers.preview', $c) }}" alt="{{ $c->name }}"
                                    class="h-44 w-full object-cover">
                                @if(Str::endsWith($c->file_path, '.gif'))
                                    <span class="absolute left-2 top-2 rounded bg-black/60 px-1.5 py-0.5 text-[11px] text-white">动态</span>
                                @endif
                            </div>
                            <span class="truncate text-sm font-medium text-slate-700" title="{{ $c->name }}">{{ $c->name }}</span>
                            <span class="mt-1 text-xs text-slate-400">
                                {{ $c->width && $c->height ? $c->width.'×'.$c->height : '-' }}
                            </span>
                            <form action="{{ route('studio.covers.pick', $c) }}" method="POST" class="mt-2">
                                @csrf
                                <button type="submit" class="w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs text-slate-600 hover:bg-slate-50">收藏到我的封面</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </section>

    <section class="mt-4">
        <h3 class="mb-3 text-sm font-semibold text-slate-700">已上传封面（{{ $assets->count() }}）</h3>
        @if($assets->isEmpty())
            <p class="rounded-lg bg-slate-50 p-4 text-sm text-slate-400">还没有上传任何封面，先上传一张试试。</p>
        @else
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @foreach($assets as $a)
                    <div class="luxury-glass flex flex-col p-3">
                        <div class="mb-2 overflow-hidden rounded-lg bg-black/5">
                            <img src="{{ route('studio.covers.preview', $a) }}" alt="{{ $a->name }}"
                                class="h-36 w-full object-cover">
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="truncate text-sm font-medium text-slate-700">{{ $a->name }}</span>
                        </div>
                        <div class="mt-1 text-xs text-slate-400">
                            {{ $a->scene ? $a->scene.' · ' : '' }}{{ $a->width && $a->height ? $a->width.'×'.$a->height : '-' }} · {{ $a->size ? number_format($a->size/1024,0).'KB' : '-' }}
                        </div>
                        <div class="mt-3 flex gap-2">
                            <form action="{{ route('studio.covers.reupload', $a) }}" method="POST" enctype="multipart/form-data" class="flex-1">
                                @csrf
                                <input type="file" name="file" accept="image/*" class="hidden" onchange="this.form.submit()">
                                <button type="button" onclick="this.previousElementSibling.click()" class="w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs text-slate-600 hover:bg-slate-50">重传</button>
                            </form>
                            <form action="{{ route('studio.covers.destroy', $a) }}" method="POST" onsubmit="return confirm('确定删除该封面？');" class="flex-1">
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
<script>
    (function () {
        const tabs = document.querySelectorAll('#presetTabs .preset-tab');
        const panels = document.querySelectorAll('.preset-panel');
        tabs.forEach(btn => {
            btn.addEventListener('click', () => {
                const cat = btn.dataset.cat;
                tabs.forEach(t => {
                    const on = t === btn;
                    t.classList.toggle('border-brand-400', on);
                    t.classList.toggle('bg-brand-50', on);
                    t.classList.toggle('text-brand-700', on);
                    t.classList.toggle('border-slate-200', !on);
                    t.classList.toggle('bg-white', !on);
                    t.classList.toggle('text-slate-600', !on);
                });
                panels.forEach(p => { p.style.display = p.dataset.cat === cat ? '' : 'none'; });
            });
        });
    })();
</script>
</x-app-layout>
