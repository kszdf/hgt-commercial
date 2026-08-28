<x-app-layout>
<x-workspace-layout title="发布助手">
<div class="mx-auto max-w-6xl p-6">

    @include('components.flash')

    {{-- 发布状态提示（状态驱动，替代长篇模式说明） --}}
    <div class="mb-5 rounded-xl border border-slate-200 bg-white px-4 py-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="text-sm font-semibold text-slate-700">发布助手</div>
            <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-500">已审核通过的成片可外发</span>
        </div>
        @if($accountCount === 0)
            <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                尚未添加发布渠道：请先到「发布渠道」添加账号并完成授权（抖音/小红书走 OAuth），
                否则发布会进入「模拟记录」或「待人工发布」清单，不会真正发出。
                <a href="/studio/accounts" class="font-medium underline hover:text-amber-800">去配置 →</a>
            </div>
        @elseif($authorizedCount === 0)
            <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                已添加 {{ $accountCount }} 个渠道，但尚未完成授权：抖音/小红书需 OAuth 授权后才能自动发布；
                未授权平台的发布将记为「模拟」或存入「待人工发布」清单。
                <a href="/studio/accounts" class="font-medium underline hover:text-amber-800">去授权 →</a>
            </div>
        @endif
        <p class="mt-2 text-xs text-slate-400">自动发布：抖音/小红书（授权后）；人工发布：视频号等无开放接口平台，下载成片到 App 手动发。</p>
    </div>

    {{-- 视频成片 --}}
    <section class="mb-8">
        <h3 class="mb-3 text-sm font-semibold text-slate-700">视频成片（{{ $videos->count() }}）</h3>

        @if($videos->isEmpty())
            <div class="luxury-glass p-10 text-center">
                <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                </div>
                <div class="text-sm font-medium text-slate-600">还没有已完成的成片</div>
                <p class="mt-1 text-sm text-slate-400">先到「视频出片」生成并渲染完成，再来这里下载与发布。</p>
                <a href="/studio/scroll" class="mt-4 inline-block rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-brand-700">前往视频出片 →</a>
            </div>
        @else
            <div class="space-y-4">
                @foreach($videos as $job)
                    @php
                        $statusLabel = $job->publish_status === 'approved' ? '已通过'
                            : ($job->publish_status === 'published' ? '已发布'
                            : ($job->publish_status === 'reviewing' ? '审核中'
                            : ($job->publish_status === 'rejected' ? '已驳回' : '待审核')));
                        $jobRecords = $publishRecords[$job->id] ?? collect();
                    @endphp
                    <div class="luxury-glass overflow-hidden">
                        <div class="flex flex-wrap items-center gap-4 p-4">
                            @if($job->coverAsset)
                                <img src="{{ route('studio.covers.preview', $job->coverAsset) }}" class="h-16 w-16 flex-none rounded-lg object-cover" alt="">
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-slate-800">{{ $job->title ?: '未命名视频' }}</span>
                                    <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">{{ $job->mode === 'avatar' ? '数字人出镜' : '幕后音·动态画面' }}</span>
                                    <span class="rounded px-2 py-0.5 text-xs
                                        @if(in_array($job->publish_status, ['approved', 'published'])) bg-emerald-50 text-emerald-600
                                        @elseif($job->publish_status === 'rejected') bg-red-50 text-red-600
                                        @else bg-amber-50 text-amber-600 @endif">
                                        审核：{{ $statusLabel }}
                                    </span>
                                </div>
                                <p class="mt-0.5 text-xs text-slate-400">渲染完成 · 可下载发布</p>
                            </div>
                            <a href="/studio/scroll/download/{{ $job->id }}"
                                class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-brand-700">下载成片</a>
                            <a href="/studio/videos?pack={{ $job->job_id }}"
                                class="rounded-lg border border-brand-300 bg-brand-50 px-4 py-2 text-sm font-medium text-brand-700 transition hover:bg-brand-100"
                                title="生成行业化标题 + 封面 + 形象照">✨ 发布包装</a>
                        </div>

                        {{-- 一键发布区 --}}
                        <div class="border-t border-slate-100 px-4 py-3">
                            @if(! $job->canPublish())
                                <div class="flex flex-wrap items-center gap-2 text-sm text-slate-500">
                                    <span>该视频尚未审核通过（当前：{{ $statusLabel }}），不能外发。</span>
                                    <a href="/studio/review" class="text-brand-600 hover:underline">去人工审核 →</a>
                                </div>
                            @elseif($accounts->isEmpty())
                                <div class="text-sm text-slate-500">
                                    还没有可用账号。先去 <a href="/studio/accounts" class="text-brand-600 hover:underline">发布渠道</a> 添加并授权账号。
                                </div>
                            @else
                                <div class="mb-2 text-xs font-semibold text-slate-600">一键发布到账号：</div>
                                <div class="flex flex-wrap gap-2">
                                    @foreach($accounts as $acc)
                                        <form method="POST" action="{{ route('studio.publish.send', $job) }}" onsubmit="return confirm('确认发布到 {{ $acc->platformLabel() }} · {{ $acc->account_name }}？')">
                                            @csrf
                                            <input type="hidden" name="platform_account_id" value="{{ $acc->id }}">
                                            <button class="rounded-lg border px-3 py-1.5 text-xs font-medium transition
                                                {{ $acc->isManualPlatform()
                                                    ? 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                                    : 'border-brand-200 bg-brand-50 text-brand-700 hover:bg-brand-100' }}">
                                                {{ $acc->platformLabel() }} · {{ $acc->account_name }}
                                                @if($acc->isManualPlatform())
                                                    <span class="ml-1 text-slate-400">（人工）</span>
                                                @endif
                                            </button>
                                        </form>
                                    @endforeach
                                </div>
                            @endif

                            {{-- 发布记录 --}}
                            @if($jobRecords->isNotEmpty())
                                <div class="mt-3 space-y-1">
                                    @foreach($jobRecords as $rec)
                                        <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                            <span class="rounded px-1.5 py-0.5
                                                @if($rec->status === 'success') bg-emerald-100 text-emerald-700
                                                @elseif($rec->status === 'manual') bg-blue-100 text-blue-700
                                                @else bg-red-100 text-red-700 @endif">
                                                {{ $rec->statusLabel() }}
                                            </span>
                                            <span>{{ $rec->platformLabel() ?? ($rec->platform) }} · {{ $rec->account_name_snapshot }}</span>
                                            <span class="text-slate-400">{{ $rec->published_at?->format('m-d H:i') }}</span>
                                            @if($rec->post_url)
                                                <a href="{{ $rec->post_url }}" target="_blank" class="text-brand-600 hover:underline">查看作品 ↗</a>
                                            @endif
                                            @if($rec->error)
                                                <span class="text-slate-400">{{ $rec->error }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <details class="border-t border-slate-100 px-4 py-3">
                            <summary class="cursor-pointer text-sm font-medium text-slate-600">手动发布步骤（展开按平台照发，兜底）</summary>
                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                <div class="rounded-lg border border-slate-100 bg-slate-50 p-3 text-xs text-slate-600">
                                    <div class="mb-1 font-semibold text-slate-700">抖音</div>
                                    <ol class="list-decimal space-y-1 pl-4">
                                        <li>打开抖音 App → 右下「我」→ 右上「≡」→「创作者服务中心」→「发布作品」。</li>
                                        <li>从相册选择本地下载的成片，添加标题与话题（如 #税务风险 #老板必看）。</li>
                                        <li>勾选「声明原创」（财税内容需本人出镜或授权），点击发布。</li>
                                    </ol>
                                </div>
                                <div class="rounded-lg border border-slate-100 bg-slate-50 p-3 text-xs text-slate-600">
                                    <div class="mb-1 font-semibold text-slate-700">小红书（图文/视频）</div>
                                    <ol class="list-decimal space-y-1 pl-4">
                                        <li>打开小红书 App → 底部「+」→ 选择成片或图片。</li>
                                        <li>写标题 + 正文，加话题标签；封面选清晰一帧。</li>
                                        <li>点击「发布」。若做图文笔记，可先在 <a href="/studio/xhs" class="text-brand-600 hover:underline">小红书图文</a> 生成素材包再发。</li>
                                    </ol>
                                </div>
                                <div class="rounded-lg border border-slate-100 bg-slate-50 p-3 text-xs text-slate-600">
                                    <div class="mb-1 font-semibold text-slate-700">视频号（微信）</div>
                                    <ol class="list-decimal space-y-1 pl-4">
                                        <li>打开微信 →「发现」→「视频号」→ 右上「人形」→「发表视频」。</li>
                                        <li>从相册选择成片，填写描述后发布。</li>
                                    </ol>
                                </div>
                                <div class="rounded-lg border border-slate-100 bg-slate-50 p-3 text-xs text-slate-600">
                                    <div class="mb-1 font-semibold text-slate-700">快手 / B站 / YouTube</div>
                                    <ol class="list-decimal space-y-1 pl-4">
                                        <li>快手创作者平台 / B站创作者中心 / YouTube Studio → 上传视频。</li>
                                        <li>选择成片，填标题/描述/标签后发布。</li>
                                    </ol>
                                </div>
                            </div>
                        </details>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- 小红书图文 --}}
    <section>
        <h3 class="mb-3 text-sm font-semibold text-slate-700">小红书图文素材</h3>
        <div class="luxury-glass p-5">
            <p class="text-sm text-slate-500">小红书图文在专用页面生成封面 + 内文配图，可一键打包下载后，到小红书 App 手动发布。</p>
            <a href="/studio/xhs" class="mt-3 inline-block rounded-lg bg-rose-500 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-rose-600">前往小红书图文 →</a>
        </div>
    </section>
</div>
</x-workspace-layout>
</x-app-layout>

