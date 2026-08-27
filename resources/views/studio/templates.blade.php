<x-app-layout>
<x-workspace-layout title="话术模板">
<div class="mx-auto max-w-5xl p-6">

    @include('components.flash')

    {{-- 顶部说明 --}}
    <div class="mb-5 rounded-xl border border-slate-200 bg-white px-4 py-3">
        <div class="text-sm font-semibold text-slate-700">财税垂类话术库</div>
        <ul class="mt-1 space-y-1 text-sm text-slate-500">
            <li>· 留资钩子 / 爆款开头 / 避坑清单 / 结尾引导 / 选题角度——可直接引用至选题、二创或发布文案。</li>
            <li>· 模板均按合规标准编写（不承诺避税、不诱导虚开、不用绝对化用语）。</li>
            <li>· 喜欢哪条点「收藏」，就会存到你的「我的模板」里，可自由编辑成自己的风格。</li>
        </ul>
    </div>

    {{-- 分类 Tab --}}
    <div class="mb-4 flex flex-wrap gap-2">
        <a href="{{ route('studio.templates') }}"
           class="rounded-full px-3 py-1.5 text-xs font-medium transition {{ is_null($currentType) ? 'bg-brand-500 text-white' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50' }}">全部</a>
        @foreach($types as $key => $label)
            <a href="{{ route('studio.templates', ['type' => $key]) }}"
               class="rounded-full px-3 py-1.5 text-xs font-medium transition {{ $currentType === $key ? 'bg-brand-500 text-white' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50' }}">{{ $label }}</a>
        @endforeach
    </div>

    {{-- 添加模板（折叠） --}}
    <details class="mb-5 luxury-glass p-4">
        <summary class="cursor-pointer text-sm font-semibold text-slate-700">{{ $isAdmin ? '新增模板（平台级，所有租户可见）' : '添加我的模板' }}</summary>
        <form method="POST" action="{{ route('studio.templates') }}" class="mt-3 grid grid-cols-1 gap-3 text-sm md:grid-cols-3">
            @csrf
            <div>
                <label class="mb-1 block text-slate-500">类型</label>
                <select name="type" required class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
                    @foreach($types as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-slate-500">标题</label>
                <input type="text" name="title" required maxlength="60" placeholder="如：风险自测清单"
                    class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
            </div>
            <div>
                <label class="mb-1 block text-slate-500">标签（逗号分隔，选填）</label>
                <input type="text" name="tags_text" placeholder="如：留资,转化"
                    class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
            </div>
            <div class="md:col-span-3">
                <label class="mb-1 block text-slate-500">话术内容</label>
                <textarea name="content" required maxlength="1000" rows="3"
                    placeholder="按合规标准编写：不承诺避税、不诱导虚开、不用绝对化用语…"
                    class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700"></textarea>
            </div>
            <div class="md:col-span-3">
                <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">保存模板</button>
            </div>
        </form>
    </details>

    {{-- 模板列表 --}}
    @if($templates->isEmpty())
        <div class="luxury-glass p-10 text-center text-sm text-slate-400">当前分类暂无模板。</div>
    @else
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            @foreach($templates as $t)
                <div class="luxury-glass flex flex-col p-4">
                    <div class="mb-1.5 flex items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="rounded bg-brand-50 px-1.5 py-0.5 text-[10px] font-medium text-brand-600">{{ $t->typeLabel() }}</span>
                            @if($t->isPlatform())
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">平台</span>
                            @else
                                <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] text-emerald-600">我的</span>
                            @endif
                            @foreach(($t->tags ?? []) as $tag)
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">#{{ $tag }}</span>
                            @endforeach
                        </div>
                        <span class="shrink-0 text-[10px] text-slate-400">使用 {{ $t->use_count }}</span>
                    </div>
                    <div class="text-sm font-semibold text-slate-800">{{ $t->title }}</div>
                    <p class="mt-1 flex-1 whitespace-pre-wrap text-xs leading-relaxed text-slate-600">{{ $t->content }}</p>
                    <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2.5">
                        <button type="button" class="tpl-copy rounded-md bg-brand-600 px-2.5 py-1 text-[11px] font-medium text-white hover:bg-brand-700" data-text="{{ e($t->content) }}">复制</button>
                        @if($t->type === 'angle')
                            <a href="{{ route('studio.topic', ['kw' => $t->title]) }}" class="rounded-md border border-brand-200 bg-brand-50 px-2.5 py-1 text-[11px] font-medium text-brand-600 hover:bg-brand-100">去选题 →</a>
                        @else
                            <a href="{{ route('studio.rewrite-original', ['tpl' => $t->content]) }}" class="rounded-md border border-brand-200 bg-brand-50 px-2.5 py-1 text-[11px] font-medium text-brand-600 hover:bg-brand-100">去二创 →</a>
                        @endif
                        @if(! $t->isPlatform() || $isAdmin)
                            <details class="relative">
                                <summary class="cursor-pointer rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[11px] text-slate-600">编辑</summary>
                                <form method="POST" action="{{ route('studio.templates.update', $t) }}"
                                      class="absolute right-0 top-8 z-10 w-80 space-y-2 rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                                    @csrf
                                    <input type="text" name="title" value="{{ $t->title }}" maxlength="60"
                                           class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-xs">
                                    <textarea name="content" rows="3" maxlength="1000"
                                              class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-xs">{{ $t->content }}</textarea>
                                    <button class="w-full rounded-lg bg-brand-600 py-1.5 text-xs font-medium text-white">保存</button>
                                </form>
                            </details>
                        @endif
                        <form method="POST" action="{{ route('studio.templates.copy', $t) }}">
                            @csrf
                            <button class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[11px] text-slate-600 hover:bg-slate-50">收藏到我的</button>
                        </form>
                        @if(! $t->isPlatform() || $isAdmin)
                            <form method="POST" action="{{ route('studio.templates.destroy', $t) }}" onsubmit="return confirm('确认删除该模板？')">
                                @csrf
                                @method('DELETE')
                                <button class="ml-auto rounded-md border border-red-100 bg-red-50 px-2.5 py-1 text-[11px] text-red-600 hover:bg-red-100">删除</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

@push('scripts')
<script>
    // 复制话术
    document.querySelectorAll('.tpl-copy').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            try {
                await navigator.clipboard.writeText(btn.getAttribute('data-text') || '');
                hgtToast('success', '话术已复制，去粘贴使用吧');
            } catch (e) {
                hgtToast('error', '复制失败，请手动选择复制');
            }
        });
    });
</script>
@endpush
</x-workspace-layout>
</x-app-layout>
