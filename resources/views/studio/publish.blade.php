<x-app-layout>
<x-workspace-layout title="多平台发布">
<div class="mx-auto max-w-6xl p-6">

    @include('components.flash')

    <!-- 发布渠道说明（常显） -->
    <div class="mb-5 rounded-xl border border-slate-200 bg-white px-4 py-3">
        <div class="text-sm font-semibold text-slate-700">多账号矩阵发布</div>
        <ul class="mt-1 space-y-1 text-sm text-slate-500">
            <li>· 选好视频，再勾选要发到的<strong>账号</strong>（可多选）：一条视频发多个号 = 打矩阵；不同视频指定不同账号 = 分号运营。</li>
            <li>· 每个账号每天有发布上限（见「平台账号」设置），超出会自动跳过并记录。</li>
            <li>· <strong>演示模式</strong>：未完成平台授权的账号不会真实发布，系统会明确标注「模拟」，不会假装成功。</li>
            <li>· 视频号暂无开放发布接口，请在「视频号助手」App 手动上传。</li>
            <li><a href="{{ route('studio.accounts') }}" class="text-brand-600 hover:underline">管理平台账号 →</a></li>
        </ul>
    </div>

    @if(isset($isTrial) && $isTrial)
        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-4">
            <div class="text-sm font-semibold text-amber-800">免费试用版暂不支持批量外发</div>
            <div class="mt-1 text-sm text-amber-700">升级到专业版 / 企业版后，即可一键把视频分发到多个平台账号。</div>
            <a href="{{ route('admin.billing') }}" class="mt-3 inline-block rounded-lg bg-amber-500 px-3 py-1.5 text-sm font-medium text-white shadow-sm transition hover:bg-amber-600">前往升级 →</a>
        </div>
    @elseif($videos->isEmpty())
        <div class="luxury-glass p-10 text-center">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
            </div>
            <div class="text-sm font-medium text-slate-600">暂无待发布视频</div>
            <p class="mt-1 text-sm text-slate-400">批量外发仅针对已通过「人工审核」的视频。请先在人工审核中通过出片，再来发布。</p>
            <a href="/studio/review" class="mt-4 inline-block rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-brand-700">前往人工审核 →</a>
        </div>
    @else
        <form method="POST" action="{{ route('studio.publish.do') }}" class="space-y-5">
            @csrf

            {{-- 账号选择（按平台分组） --}}
            <div class="luxury-glass p-5">
                <div class="mb-3 text-sm font-semibold text-slate-700">选择发布账号</div>
                @php
                    $groups = $accounts->groupBy('platform');
                @endphp
                @if($groups->isEmpty())
                    <p class="py-3 text-center text-sm text-slate-400">
                        还没有平台账号，<a href="{{ route('studio.accounts') }}" class="text-brand-600 hover:underline">先去添加并授权 →</a>
                    </p>
                @else
                    <div class="flex flex-col gap-4">
                        @foreach($groups as $plat => $platAccounts)
                            <div>
                                <div class="mb-1.5 text-xs font-medium text-slate-500">
                                    {{ \App\Models\PlatformAccount::PLATFORM_LABELS[$plat] ?? $plat }}
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    @foreach($platAccounts as $a)
                                        @php $authorized = $a->isAuthorized(); @endphp
                                        <div class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm
                                            {{ $authorized ? 'border-slate-200 bg-white' : 'border-slate-100 bg-slate-50 opacity-60' }}">
                                            <label class="flex cursor-pointer items-center gap-2">
                                                <input type="checkbox" name="accounts[]" value="{{ $a->id }}" class="accent-brand-600"
                                                    {{ $authorized && $authorizedIds->contains($a->id) ? 'checked' : '' }}
                                                    {{ $authorized ? '' : 'disabled' }}>
                                                <span class="font-medium text-slate-700">{{ $a->account_name ?: $a->platformLabel() }}</span>
                                            </label>
                                            @if($authorized)
                                                <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] text-emerald-700">已授权 · 今日余 {{ $a->remainingToday() }}</span>
                                            @else
                                                @php $publicBase = env('PYTHON_PIPELINE_PUBLIC_URL', 'http://127.0.0.1:8500'); @endphp
                                                <button type="button" class="oauth-btn rounded bg-brand-50 px-1.5 py-0.5 text-[10px] text-brand-600 hover:bg-brand-100"
                                                        data-auth-url="{{ $publicBase }}/oauth/authorize/{{ $a->platform }}?account_id={{ $a->id }}"
                                                        data-account-id="{{ $a->id }}">去授权</button>
                                            @endif
                                            @if(!empty($a->content_tags))
                                                <span class="text-[10px] text-slate-400">#{{ implode(' #', array_slice($a->content_tags, 0, 3)) }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
                <p class="mt-2 text-xs text-slate-400">未授权账号不可勾选；勾选多个账号 = 同一条视频打矩阵。账号每日上限见「平台账号」。</p>
            </div>

            {{-- 待发视频 --}}
            <div class="luxury-glass overflow-hidden p-5">
                <div class="mb-3 text-sm font-semibold text-slate-700">选择视频（{{ $videos->count() }} 个待发布）</div>
                <div class="space-y-2">
                    @foreach($videos as $job)
                        <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-100 bg-white px-3 py-2 hover:border-brand-400">
                            @if($job->coverAsset)
                                <img src="{{ route('studio.covers.preview', $job->coverAsset) }}" class="h-10 w-10 flex-none rounded object-cover" alt="">
                            @endif
                            <input type="checkbox" name="video_ids[]" value="{{ $job->id }}" class="accent-brand-600" checked>
                            <span class="font-medium text-slate-800">{{ $job->title ?: '未命名视频' }}</span>
                            <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">{{ $job->mode === 'avatar' ? '数字人出镜' : '滚动字幕卡' }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end">
                <button class="rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-brand-700">批量发布</button>
            </div>
        </form>
    @endif

    {{-- 发布历史 --}}
    <section class="mt-8">
        <h3 class="mb-3 text-sm font-semibold text-slate-700">发布历史</h3>
        @if($records->isEmpty())
            <div class="luxury-glass p-6 text-center text-sm text-slate-400">暂无发布记录。</div>
        @else
            <div class="luxury-glass overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5">视频</th>
                            <th class="px-4 py-2.5">平台 / 账号</th>
                            <th class="px-4 py-2.5">状态</th>
                            <th class="px-4 py-2.5">发布时间</th>
                            <th class="px-4 py-2.5">作品链接</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($records as $rec)
                            <tr>
                                <td class="px-4 py-2.5 text-slate-700">{{ $rec->videoJob?->title ?: ('#'.$rec->video_job_id) }}</td>
                                <td class="px-4 py-2.5 text-slate-600">
                                    {{ \App\Models\PlatformAccount::PLATFORM_LABELS[$rec->platform] ?? $rec->platform }}
                                    @if($rec->account_name_snapshot || $rec->account)
                                        <span class="text-slate-400">· {{ $rec->account_name_snapshot ?: $rec->account?->account_name }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">
                                    @if($rec->isSuccess() && $rec->simulated)
                                        <span class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-700" title="该发布为演示模式，未实际发出">模拟</span>
                                    @elseif($rec->isSuccess())
                                        <span class="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">成功</span>
                                    @else
                                        <span class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-700" title="{{ $rec->error }}">失败</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-slate-500">{{ $rec->published_at?->format('m-d H:i') ?? '-' }}</td>
                                <td class="px-4 py-2.5">
                                    @if($rec->post_url)
                                        <a href="{{ $rec->post_url }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline">查看 ↗</a>
                                    @else
                                        <span class="text-slate-300">-</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        @endif
    </section>
</div>

@push('scripts')
<script>
    // 平台授权弹窗（账号级）+ 回调标记已授权
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
            var aid = d.account_id;
            var token = document.querySelector('meta[name="csrf-token"]')?.content || '';
            if (!aid) { location.reload(); return; }
            fetch('/studio/accounts/' + aid + '/authorized', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams({})
            }).then(function () { location.reload(); });
        }
    });

    // 批量外发：拦截表单提交，改用 fetch 以支持「中止」按钮
    document.querySelectorAll('form[action$="publish.do"], form[action*="studio/publish"]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (HGTAbort.isActive()) return;
            // 至少勾选一个账号
            var checked = form.querySelectorAll('input[name="accounts[]"]:checked');
            if (!checked.length) { hgtToast('warn', '请至少勾选一个已授权的发布账号'); return; }
            const signal = HGTAbort.begin('中止：批量发布中…');
            const btn = form.querySelector('button[type="submit"]');
            if (btn) btn.disabled = true;
            const fd = new FormData(form);
            fetch(form.action, {
                method: 'POST',
                signal: signal,
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd
            })
            .then(function (r) {
                location.href = '/studio/publish';
            })
            .catch(function (err) {
                if (err.name === 'AbortError') { hgtToast('warn', '已中止发布'); return; }
                hgtToast('error', '发布请求异常：' + (err.message || '网络错误'));
                if (btn) btn.disabled = false;
            })
            .finally(function () { HGTAbort.end(); });
        });
    });
</script>
@endpush
</x-workspace-layout>
</x-app-layout>
