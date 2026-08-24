<x-app-layout>
<x-workspace-layout title="公众号发文">
<div class="mx-auto max-w-4xl p-6">

    @include('components.flash')

    <div class="mb-5 rounded-xl border border-slate-200 bg-white px-4 py-3">
        <div class="text-sm font-semibold text-slate-700">公众号发文 · 图文文章</div>
        <ul class="mt-1 space-y-1 text-sm text-slate-500">
            <li>· 写好标题 + 正文 + 选一张封面，点发布，文章自动进公众号<strong>草稿箱</strong>。</li>
            <li>· 发布后到 <strong>mp.weixin.qq.com</strong> 后台检查、排版微调，再点「群发」。</li>
            <li>· 正文每空一行 = 一个段落，平台会自动排成文章段落。</li>
        </ul>
    </div>

    @if($wechatAccounts->isEmpty())
        <div class="luxury-glass p-10 text-center">
            <p class="text-sm text-slate-600">还没有已授权的公众号账号。</p>
            <p class="mt-1 text-sm text-slate-400">请先到「发布渠道」添加公众号并填好 AppID / AppSecret，再回来发文。</p>
            <a href="/studio/accounts" class="mt-4 inline-block rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">去添加公众号 →</a>
        </div>
    @else
        <form method="POST" action="{{ route('studio.article.send') }}" class="luxury-glass space-y-5 p-5 text-sm">
            @csrf

            <div>
                <label class="mb-1 block text-slate-600">发到哪个公众号 <span class="text-red-500">*</span></label>
                <select name="platform_account_id" required class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
                    @foreach($wechatAccounts as $a)
                        <option value="{{ $a->id }}">{{ $a->account_name ?: '公众号' }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1 block text-slate-600">标题 <span class="text-red-500">*</span></label>
                <input type="text" name="title" required maxlength="64" placeholder="例如：金税四期下，个人卡收营业款为什么最容易被盯上"
                    class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
            </div>

            <div>
                <label class="mb-1 block text-slate-600">正文 <span class="text-red-500">*</span></label>
                <textarea name="content" required rows="14" maxlength="20000" placeholder="空一行 = 一个段落。例如：&#10;&#10;很多老板以为，用个人卡收点营业款没人知道。&#10;&#10;其实税务系统的数据比对，最先盯上的就是这种说不清来源的流水……"
                    class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700 leading-relaxed"></textarea>
                <p class="mt-1 text-xs text-slate-400">正文空一行分一段，最多 2 万字。</p>
            </div>

            <div>
                <label class="mb-1 block text-slate-600">封面图 <span class="text-red-500">*</span>（点一张选中）</label>
                @if($covers->isEmpty())
                    <p class="rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-xs text-amber-700">
                        还没有封面图。请先到「封面素材」上传一张，或先到「视频出片」让系统自动生成封面。
                    </p>
                @else
                    <div class="grid max-h-72 grid-cols-4 gap-3 overflow-y-auto rounded-lg border border-slate-100 bg-slate-50 p-3 sm:grid-cols-6">
                        @foreach($covers as $cover)
                            <label class="group cursor-pointer">
                                <input type="radio" name="cover_asset_id" value="{{ $cover->id }}" class="peer sr-only"
                                    @if($loop->first) checked @endif>
                                <img src="{{ route('studio.covers.preview', $cover) }}" alt="{{ $cover->name }}"
                                    class="aspect-[3/4] w-full rounded-lg border-2 border-transparent object-cover peer-checked:border-brand-500">
                                <span class="mt-1 block truncate text-center text-[10px] text-slate-400">{{ $cover->name }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('studio.publish') }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">返回发布助手</a>
                <button class="rounded-lg bg-brand-600 px-5 py-2 text-sm font-medium text-white hover:bg-brand-700">发布到草稿箱</button>
            </div>
        </form>
    @endif
</div>
</x-workspace-layout>
</x-app-layout>
