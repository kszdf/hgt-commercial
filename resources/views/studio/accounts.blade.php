<x-app-layout>
<x-workspace-layout title="平台账号">
<div class="mx-auto max-w-5xl p-6">

    @include('components.flash')

    {{-- 说明 --}}
    <div class="mb-5 rounded-xl border border-slate-200 bg-white px-4 py-3">
        <div class="text-sm font-semibold text-slate-700">多账号矩阵发布</div>
        <ul class="mt-1 space-y-1 text-sm text-slate-500">
            <li>· 抖音、小红书等平台可以添加 <strong>多个账号</strong>：既可以一条视频同时发到多个号（打矩阵），也可以不同视频指定不同账号发布。</li>
            <li>· 每个账号可设置「内容定位标签」和「每日发布上限」，防止同一内容过多账号同质化发布被平台风控。</li>
            <li>· 授权：点击「去授权」在弹窗中完成平台授权；完成后点击「标记为已授权」即可用于发布。</li>
        </ul>
    </div>

    {{-- 新增账号 --}}
    <div class="luxury-glass mb-5 p-5">
        <h3 class="mb-3 text-sm font-semibold text-slate-700">添加新账号</h3>
        <form method="POST" action="{{ route('studio.accounts') }}" class="grid grid-cols-2 gap-3 text-sm md:grid-cols-3">
            @csrf
            <div>
                <label class="mb-1 block text-slate-500">平台</label>
                <select name="platform" required class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
                    @foreach($platformKeys as $k)
                        <option value="{{ $k }}">{{ \App\Models\PlatformAccount::PLATFORM_LABELS[$k] ?? $k }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-slate-500">账号名称（如：慧根堂主号）</label>
                <input type="text" name="account_name" required maxlength="60" placeholder="例如：风险警示号 / 政策解读号"
                    class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
            </div>
            <div>
                <label class="mb-1 block text-slate-500">每日发布上限（条/天）</label>
                <input type="number" name="daily_limit" min="1" max="20" value="3"
                    class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
            </div>
            <div class="col-span-2">
                <label class="mb-1 block text-slate-500">内容定位标签（可多选，用于按内容类型分发到对应账号）</label>
                <div class="flex flex-wrap gap-2">
                    @foreach($tags as $t)
                        <label class="flex cursor-pointer items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1 text-xs text-slate-600">
                            <input type="checkbox" name="content_tags[]" value="{{ $t }}" class="accent-brand-500">{{ $t }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="col-span-2">
                <label class="mb-1 block text-slate-500">备注（选填）</label>
                <input type="text" name="remark" maxlength="120" placeholder="例如：主推留资转化、粉丝 1.2w"
                    class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
            </div>
            <div class="col-span-2 md:col-span-3">
                <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">添加账号</button>
            </div>
        </form>
    </div>

    {{-- 账号列表 --}}
    <div class="luxury-glass overflow-hidden">
        <div class="px-5 py-4 text-sm font-semibold text-slate-700">我的账号（{{ $accounts->count() }}）</div>
        @if($accounts->isEmpty())
            <div class="px-5 pb-6 text-center text-sm text-slate-400">还没有账号，先在上方添加一个。</div>
        @else
            <div class="divide-y divide-slate-100">
                @foreach($accounts as $a)
                    <div class="flex flex-wrap items-center gap-3 px-5 py-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-slate-800">{{ $a->account_name ?: $a->platformLabel() }}</span>
                                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">{{ $a->platformLabel() }}</span>
                                @if($a->isAuthorized())
                                    <span class="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">已授权</span>
                                @else
                                    <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-400">未授权</span>
                                @endif
                                <span class="text-xs text-slate-400">今日余量 {{ $a->remainingToday() }}/{{ $a->daily_limit }} 条</span>
                            </div>
                            @if($a->remark)
                                <p class="mt-0.5 truncate text-xs text-slate-400">{{ $a->remark }}</p>
                            @endif
                            @if(!empty($a->content_tags))
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @foreach($a->content_tags as $t)
                                        <span class="rounded bg-brand-50 px-1.5 py-0.5 text-[10px] text-brand-600">#{{ $t }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @php $publicBase = env('PYTHON_PIPELINE_PUBLIC_URL', 'http://127.0.0.1:8500'); @endphp
                            <button type="button" class="oauth-btn rounded-md bg-brand-600 px-2.5 py-1 text-[11px] font-medium text-white hover:bg-brand-700"
                                    data-auth-url="{{ $publicBase }}/oauth/authorize/{{ $a->platform }}?account_id={{ $a->id }}"
                                    data-account-id="{{ $a->id }}">去授权</button>
                            @if(!$a->isAuthorized())
                                <form method="POST" action="{{ route('studio.accounts.authorized', $a) }}">
                                    @csrf
                                    <button class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[11px] text-slate-600 hover:bg-slate-50">标记为已授权</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('studio.accounts.unauthorized', $a) }}">
                                    @csrf
                                    <button class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[11px] text-slate-400 hover:bg-slate-50">标记未授权</button>
                                </form>
                            @endif
                            <details class="relative">
                                <summary class="cursor-pointer rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[11px] text-slate-600">编辑</summary>
                                <form method="POST" action="{{ route('studio.accounts.update', $a) }}"
                                      class="absolute right-0 top-8 z-10 w-72 space-y-2 rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                                    @csrf
                                    <input type="text" name="account_name" value="{{ $a->account_name }}" maxlength="60"
                                           placeholder="账号名称" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-xs">
                                    <input type="text" name="remark" value="{{ $a->remark }}" maxlength="120"
                                           placeholder="备注" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-xs">
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach($tags as $t)
                                            <label class="flex cursor-pointer items-center gap-1 text-[11px] text-slate-600">
                                                <input type="checkbox" name="content_tags[]" value="{{ $t }}" class="accent-brand-500"
                                                    {{ in_array($t, $a->content_tags ?? [], true) ? 'checked' : '' }}>{{ $t }}
                                            </label>
                                        @endforeach
                                    </div>
                                    <input type="number" name="daily_limit" min="1" max="20" value="{{ $a->daily_limit }}"
                                           class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-xs" title="每日发布上限">
                                    <button class="w-full rounded-lg bg-brand-600 py-1.5 text-xs font-medium text-white">保存</button>
                                </form>
                            </details>
                            <form method="POST" action="{{ route('studio.accounts.destroy', $a) }}" onsubmit="return confirm('确认删除该账号？删除后不可恢复。')">
                                @csrf
                                @method('DELETE')
                                <button class="rounded-md border border-red-100 bg-red-50 px-2.5 py-1 text-[11px] text-red-600 hover:bg-red-100">删除</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
    // 平台授权弹窗 + 回调（8500 授权成功后 postMessage 回传 account_id → 自动标记已授权）
    document.querySelectorAll('.oauth-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var w = 640, h = 720;
            var left = (window.screen.width - w) / 2, top = (window.screen.height - h) / 2;
            window.open(btn.getAttribute('data-auth-url'), 'oauth_popup',
                'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top);
        });
    });
    window.addEventListener('message', function (e) {
        var d = e.data || {};
        if (d.type === 'oauth_authorized') {
            // 8500 已按 (platform, account_id) 缓存 token；这里把 Laravel 账号标记为已授权
            var aid = d.account_id;
            if (!aid) { location.reload(); return; }
            var token = document.querySelector('meta[name="csrf-token"]')?.content || '';
            fetch('/studio/accounts/' + aid + '/authorized', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams({})
            }).then(function () { location.reload(); });
        }
    });
</script>
@endpush
</x-workspace-layout>
</x-app-layout>
