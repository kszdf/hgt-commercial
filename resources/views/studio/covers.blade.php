<x-app-layout>
<div class="mx-auto max-w-5xl p-6">
    <header class="mb-5">
        <h2 class="text-xl font-semibold text-slate-800">封面素材库</h2>
        <p class="mt-0.5 text-sm text-slate-400">上传视频封面图（jpg/png/webp，≤10MB）。发布到视频号 / 抖音 / 小红书 时可指定封面。系统自动记录尺寸与大小，租户隔离。</p>
        <p class="mt-2"><a href="/studio/models" class="text-sm text-brand-600 hover:underline">管理我的模特 →</a></p>
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
</x-app-layout>
