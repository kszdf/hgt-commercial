<x-app-layout>
<div class="mx-auto max-w-5xl p-6">
    <header class="mb-5">
        <h2 class="text-xl font-semibold text-slate-800">我的数字人模特</h2>
        <p class="mt-0.5 text-sm text-slate-400">上传不同场景的专属数字人驱动视频。系统自动转码静音化 + 竖屏/时长质检，通过后方可用于出片。</p>
        <p class="mt-2"><a href="/studio/covers" class="text-sm text-brand-600 hover:underline">管理封面素材 →</a></p>
    </header>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-2.5 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <section class="luxury-glass p-5">
        <h3 class="mb-3 text-sm font-semibold text-slate-700">上传新模特</h3>
        <form action="{{ route('studio.models') }}" method="POST" enctype="multipart/form-data" class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            @csrf
            <div class="lg:col-span-2">
                <label class="mb-1 block text-sm font-medium text-slate-600">模特视频（mp4/mov/webm，≤200MB，建议竖屏 9:16、5–30s）</label>
                <input type="file" name="file" accept="video/*" required
                    class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
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
</x-app-layout>
