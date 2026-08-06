<x-app-layout>
<x-workspace-layout title="批量外发">
<div class="mx-auto max-w-6xl p-6">

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    @if(isset($isTrial) && $isTrial)
        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-4">
            <div class="text-sm font-semibold text-amber-800">免费试用版暂不支持批量外发</div>
            <div class="mt-1 text-sm text-amber-700">升级到专业版 / 企业版后，即可一键分发视频到抖音、视频号、小红书等平台。</div>
            <a href="{{ route('admin.billing') }}" class="mt-3 inline-block rounded-lg bg-amber-500 px-3 py-1.5 text-sm font-medium text-white shadow-sm transition hover:bg-amber-600">前往升级 →</a>
        </div>
    @elseif($videos->isEmpty())
        <div class="luxury-glass p-10 text-center text-slate-400">暂无待发布视频。请先在「人工审核」中通过视频。</div>
    @else
        <form method="POST" action="{{ route('studio.publish.do') }}" class="space-y-5">
            @csrf

            {{-- 平台选择 --}}
            <div class="luxury-glass p-5">
                <div class="mb-3 text-sm font-semibold text-slate-700">选择分发平台</div>
                <div class="flex flex-col gap-3">
                    @foreach($platforms as $key => $label)
                        @php $authorized = $authStatus[$key] ?? false; @endphp
                        <div class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2">
                            <label class="flex cursor-pointer items-center gap-2">
                                <input type="checkbox" name="platforms[]" value="{{ $key }}" class="accent-brand-600" {{ $authorized ? 'checked' : '' }}>
                                <span class="font-medium text-slate-700">{{ $label }}</span>
                            </label>
                            @if($authorized)
                                <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] text-emerald-700">已授权</span>
                            @else
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-400">未授权</span>
                            @endif
                            @if($key === 'douyin' || $key === 'xiaohongshu')
                                <button type="button"
                                        class="oauth-btn ml-auto rounded-md bg-brand-600 px-2.5 py-1 text-[11px] font-medium text-white hover:bg-brand-700"
                                        data-auth-url="{{ $publicBase }}/oauth/authorize/{{ $key }}">点此授权{{ $label }}</button>
                            @elseif($key === 'wechat')
                                <span class="ml-auto text-[11px] text-slate-400">client_credential 模式：填 WECHAT_APPID/SECRET 即生效，无需授权</span>
                            @endif
                        </div>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-slate-400">抖音 / 小红书需点「授权」在弹窗内完成平台授权（授权后本页自动刷新为「已授权」）。视频号为 client_credential 模式，在 model_keys.env 填好密钥即生效。</p>
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
                <button class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-700">批量发布</button>
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
                            <th class="px-4 py-2.5">平台</th>
                            <th class="px-4 py-2.5">状态</th>
                            <th class="px-4 py-2.5">发布时间</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($records as $rec)
                            <tr>
                                <td class="px-4 py-2.5 text-slate-700">{{ $rec->videoJob?->title ?: ('#'.$rec->video_job_id) }}</td>
                                <td class="px-4 py-2.5 text-slate-600">{{ $platforms[$rec->platform] ?? $rec->platform }}</td>
                                <td class="px-4 py-2.5">
                                    @if($rec->isSuccess())
                                        <span class="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">成功</span>
                                    @else
                                        <span class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-700">失败</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-slate-500">{{ $rec->published_at?->format('m-d H:i') ?? '-' }}</td>
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
    document.querySelectorAll('.oauth-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var url = btn.getAttribute('data-auth-url');
            var w = 640, h = 720;
            var left = (window.screen.width - w) / 2, top = (window.screen.height - h) / 2;
            window.open(url, 'oauth_popup', 'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top);
        });
    });
    // 8500 回调成功页 postMessage 通知父窗 → 刷新本页更新「已授权」徽章
    window.addEventListener('message', function (e) {
        var d = e.data || {};
        if (d.type === 'oauth_authorized') {
            location.reload();
        }
    });
</script>
@endpush
</x-workspace-layout>
</x-app-layout>
