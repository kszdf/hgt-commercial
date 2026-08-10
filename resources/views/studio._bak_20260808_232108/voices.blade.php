<x-app-layout>
<x-workspace-layout title="声音库" :breadcrumbs="[['label' => '工作台总览', 'url' => '/dashboard'], ['label' => '声音库']]">
<div class="mx-auto max-w-5xl p-6">
        <p class="mt-0.5 text-sm text-slate-400">上传参考音频克隆专属音色，支持多个男声 / 女声，出片时可自由选择。克隆后的音色仅你本租户可用，并会原样保留你录音里的口音 / 方言——无需单独选方言。</p>
        <p class="mt-2 flex flex-wrap gap-3">
            <a href="/studio/models" class="text-sm text-brand-600 hover:underline">管理我的模特 →</a>
            <a href="/studio/covers" class="text-sm text-brand-600 hover:underline">管理封面素材 →</a>
            <a href="/studio/scroll" class="text-sm text-brand-600 hover:underline">去出片 →</a>
        </p>
    </header>

    @include('components.flash')

    <!-- 录音注意事项 -->
    <section class="mb-4 rounded-xl border border-amber-200 bg-amber-50/70 p-5">
        <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold text-amber-800">
            <svg class="h-4 w-4 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            录音注意事项（决定克隆音色是否清晰逼真，上传前务必浏览）
        </h3>
        <ul class="grid grid-cols-1 gap-x-6 gap-y-1.5 text-xs leading-relaxed text-amber-900 sm:grid-cols-2">
            <li>· <b>环境</b>：安静无回声的室内，关闭空调/风扇等背景噪声</li>
            <li>· <b>无配乐</b>：纯人声，不要带背景音乐或伴奏</li>
            <li>· <b>单人</b>：画面/音轨中只有一位说话人，避免多人穿插</li>
            <li>· <b>时长</b>：建议 10–30 秒，过短音色学不准，过长无意义</li>
            <li>· <b>发音</b>：自然说话，咬字清晰，避免语速过快或含混</li>
            <li>· <b>语调</b>：用你期望克隆的常态语调，避免刻意拿腔或念稿</li>
            <li>· <b>授权</b>：仅克隆你本人或已获授权的声音，勿上传他人声音用于商用分发</li>
            <li>· <b>方言</b>：克隆音色会原样保留你录音里的口音 / 方言，出片即该方言，平台不另设方言选项</li>
            <li>· <b>格式</b>：wav / mp3 / m4a，单文件 ≤ 30MB</li>
        </ul>
    </section>

    <!-- 上传克隆 -->
    <section class="luxury-glass p-5">
        <h3 class="mb-3 text-sm font-semibold text-slate-700">克隆新声音</h3>
        <form action="{{ route('studio.voices') }}" method="POST" enctype="multipart/form-data" class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            @csrf
            <div class="lg:col-span-3">
                <label class="mb-1 block text-sm font-medium text-slate-600">参考音频（wav/mp3/m4a，≤30MB，建议 10–30s 清晰人声）</label>
                <input type="file" name="file" accept="audio/*" required
                    class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-600">声音名称</label>
                <input type="text" name="name" maxlength="60" placeholder="如：主播男声·沉稳 / 客服女声·亲切"
                    class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-600">声线性别</label>
                <select name="gender" class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                    <option value="male">男声</option>
                    <option value="female">女声</option>
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-brand-600">上传并克隆</button>
            </div>
        </form>
    </section>

    <!-- 声音列表 -->
    <section class="mt-4">
        <h3 class="mb-3 text-sm font-semibold text-slate-700">声音库（男声 {{ $maleCount }} · 女声 {{ $femaleCount }}）</h3>
        @if($voices->isEmpty())
            <p class="rounded-lg bg-slate-50 p-4 text-sm text-slate-400">还没有克隆任何声音，上传一段参考音频开始克隆吧。</p>
        @else
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($voices as $v)
                    <div class="luxury-glass flex flex-col p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-slate-700">{{ $v->name }}</div>
                                <div class="mt-1 flex items-center gap-1.5">
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">{{ $v->gender === 'male' ? '男声' : '女声' }}</span>
                                    @if($v->is_default)
                                        <span class="rounded-full bg-brand-100 px-2 py-0.5 text-[11px] text-brand-700">默认</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="mt-1 text-xs text-slate-400">已用 {{ $v->use_count }} 次</div>
                        <div class="mt-3 flex gap-2">
                            @if(!$v->is_default)
                                <form action="{{ route('studio.voices.default', $v) }}" method="POST" class="flex-1">
                                    @csrf
                                    <button type="submit" class="w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs text-slate-600 hover:bg-slate-50">设为默认</button>
                                </form>
                            @endif
                            <form action="{{ route('studio.voices.destroy', $v) }}" method="POST" class="flex-1">
                                @csrf @method('DELETE')
                                <button type="button" onclick="hgtDel(this)" data-msg="确定删除该声音？删除后不可恢复。" class="w-full rounded-lg border border-red-200 bg-white px-2 py-1.5 text-xs text-red-600 hover:bg-red-50">删除</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>
</x-workspace-layout>
</x-app-layout>
