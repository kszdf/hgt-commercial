<x-app-layout>
<x-workspace-layout title="发布助手">
<div class="mx-auto max-w-6xl p-6">

    @include('components.flash')

    <!-- 模式说明（常显） -->
    <div class="mb-5 rounded-xl border border-slate-200 bg-white px-4 py-3">
        <div class="text-sm font-semibold text-slate-700">发布助手 · 导出素材 + 各平台手动发布</div>
        <ul class="mt-1 space-y-1 text-sm text-slate-500">
            <li>· 本系统负责<strong>产出</strong>视频成片与小红书图文素材；正式发布请在各平台 App 内手动操作。</li>
            <li>· 自动发布接口（OAuth 授权 / 一键群发）已停用：多数平台（尤其小红书）的自动发布需企业资质且基本不对外开放，手动发布最稳、最合规。</li>
            <li>· 下方每个成片都可直接<strong>下载</strong>，并按平台展开「手动发布步骤」照着发即可。</li>
        </ul>
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
                    <div class="luxury-glass overflow-hidden">
                        <div class="flex flex-wrap items-center gap-4 p-4">
                            @if($job->coverAsset)
                                <img src="{{ route('studio.covers.preview', $job->coverAsset) }}" class="h-16 w-16 flex-none rounded-lg object-cover" alt="">
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-slate-800">{{ $job->title ?: '未命名视频' }}</span>
                                    <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">{{ $job->mode === 'avatar' ? '数字人出镜' : '滚动字幕卡' }}</span>
                                </div>
                                <p class="mt-0.5 text-xs text-slate-400">渲染完成 · 可下载发布</p>
                            </div>
                            <a href="/studio/scroll/download/{{ $job->id }}"
                                class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-brand-700">下载成片</a>
                        </div>

                        <details class="border-t border-slate-100 px-4 py-3">
                            <summary class="cursor-pointer text-sm font-medium text-slate-600">手动发布步骤（展开按平台照发）</summary>
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
                                    <div class="mb-1 font-semibold text-slate-700">YouTube</div>
                                    <ol class="list-decimal space-y-1 pl-4">
                                        <li>登录 YouTube Studio →「创建」→「上传视频」。</li>
                                        <li>选择成片，填标题/描述/标签，设置「公开」后发布。</li>
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
