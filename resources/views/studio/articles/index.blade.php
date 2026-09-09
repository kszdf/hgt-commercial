<x-app-layout>
<x-workspace-layout title="公众号文章" :breadcrumbs="[['label' => '工作台总览', 'url' => '/dashboard'], ['label' => '公众号文章']]">
<div class="mx-auto max-w-6xl p-6">
    <header class="mb-5">
        <h1 class="text-xl font-bold text-slate-800">公众号文章</h1>
        <p class="mt-1 text-sm text-slate-500">AI 出稿 → SEO 优化 → 送公众号草稿箱 → 人工审核 → 群发。群发前必须审核通过（微信公众平台运营规范 3.27，禁止非真人自动化创作与脚本批量连续发布），订阅号单号每日限群发 1 篇。</p>
    </header>

    @include('components.flash')

    {{-- 未配置公众号凭据：黄色警告条（此时送草稿箱/群发均为模拟发送） --}}
    @if(!$wechatConfigured)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
            ⚠ 尚未配置公众号凭据（AppID / AppSecret）。请到「平台账号」中添加并填写公众号账号信息，否则送草稿箱与群发均为<strong>模拟发送</strong>，不会真正写入你的公众号。
        </div>
    @endif

    {{-- 状态筛选 + 搜索 --}}
    <section class="mb-4 flex flex-wrap items-center gap-2">
        <a href="{{ route('studio.articles') }}"
           class="rounded-full border px-3.5 py-1.5 text-sm transition {{ $status === '' ? 'border-brand-400 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-600 hover:border-brand-300' }}">全部</a>
        <a href="{{ route('studio.articles', ['status' => 'draft']) }}"
           class="rounded-full border px-3.5 py-1.5 text-sm transition {{ $status === 'draft' ? 'border-brand-400 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-600 hover:border-brand-300' }}">待审核</a>
        <a href="{{ route('studio.articles', ['status' => 'reviewed']) }}"
           class="rounded-full border px-3.5 py-1.5 text-sm transition {{ $status === 'reviewed' ? 'border-brand-400 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-600 hover:border-brand-300' }}">已审核</a>
        <a href="{{ route('studio.articles', ['status' => 'published']) }}"
           class="rounded-full border px-3.5 py-1.5 text-sm transition {{ $status === 'published' ? 'border-brand-400 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-600 hover:border-brand-300' }}">已发表</a>

        @if($accounts->count() > 0)
            <select id="accountId" title="选择目标公众号"
                class="rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                @foreach($accounts as $acc)
                    <option value="{{ $acc->id }}">公众号：{{ $acc->account_name }}</option>
                @endforeach
            </select>
        @endif

        <form method="GET" action="{{ route('studio.articles') }}" class="ml-auto flex items-center gap-2">
            @if($status !== '')<input type="hidden" name="status" value="{{ $status }}">@endif
            <input type="text" name="q" value="{{ $keyword }}" placeholder="搜索标题 / 正文关键词"
                class="w-64 rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
            <button type="submit" class="rounded-lg bg-slate-700 px-4 py-2 text-sm font-medium text-white shadow hover:bg-slate-800 transition-colors">搜索</button>
        </form>
    </section>

    {{-- 文章卡片列表 --}}
    @if($articles->isEmpty())
        <p class="rounded-lg studio-card studio-card-sm text-sm text-slate-400">暂无文章。可在「对话出稿 / 智能选题」成稿后来这里做 SEO 优化并送公众号草稿箱。</p>
    @else
        <div class="space-y-3">
            @foreach($articles as $a)
                @php
                    $seoClass = ['high' => 'bg-green-100 text-green-700', 'mid' => 'bg-amber-100 text-amber-700', 'low' => 'bg-red-100 text-red-700'][$a->seoLevel()];
                    $statusClass = [
                        'draft'     => 'bg-slate-100 text-slate-600',
                        'reviewed'  => 'bg-brand-100 text-brand-700',
                        'published' => 'bg-green-100 text-green-700',
                        'failed'    => 'bg-red-100 text-red-700',
                    ][$a->status] ?? 'bg-slate-100 text-slate-600';
                @endphp
                <div class="luxury-glass p-4" data-article="{{ $a->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="truncate text-sm font-semibold text-slate-800" title="{{ $a->title }}">{{ $a->title }}</h3>
                                <span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $statusClass }}">{{ $a->statusLabel() }}</span>
                                <span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $seoClass }}">SEO {{ $a->seo_score }}</span>
                                @if($a->hit_count > 0)
                                    <span class="rounded bg-red-50 px-1.5 py-0.5 text-xs text-red-600">违禁词 {{ $a->hit_count }}</span>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-slate-400">{{ $a->excerpt(80) }}</p>
                            <p class="mt-1 text-xs text-slate-400">
                                {{ number_format($a->word_count) }} 字
                                @if($a->region) · {{ $a->region }} @endif
                                @if($a->kw_main) · 主词：{{ $a->kw_main }} @endif
                                · {{ $a->created_at ? $a->created_at->format('Y-m-d H:i') : '-' }}
                                @if($a->published_at) · 已群发 {{ $a->published_at->format('Y-m-d H:i') }} @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <button type="button" onclick='viewArticle({{ $a->id }})'
                                class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-600 hover:bg-slate-50">查看</button>

                            <button type="button" onclick='pushDraft({{ $a->id }}, this)'
                                class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-600 hover:bg-slate-50">送草稿箱</button>

                            @if($a->status !== 'published')
                                <button type="button" onclick='approve({{ $a->id }}, this)'
                                    class="rounded-lg border border-brand-200 bg-white px-2.5 py-1.5 text-xs text-brand-700 hover:bg-brand-50">审核通过</button>
                            @endif

                            @if($a->canPublish())
                                <button type="button" onclick='publish({{ $a->id }}, this)'
                                    class="rounded-lg bg-brand-600 px-2.5 py-1.5 text-xs font-medium text-white shadow hover:bg-brand-700">发表</button>
                            @else
                                <button type="button" disabled title="需先审核"
                                    class="cursor-not-allowed rounded-lg bg-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-400">发表</button>
                            @endif

                            <button type="button" onclick='delArticle({{ $a->id }}, this)'
                                class="rounded-lg border border-red-200 bg-white px-2.5 py-1.5 text-xs text-red-600 hover:bg-red-50">删除</button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-5">{{ $articles->links() }}</div>
    @endif
</div>

{{-- 查看详情弹窗 --}}
<div id="articleModal" class="fixed inset-0 z-50 hidden bg-black/60 p-6" onclick="closeModal()">
    <div class="mx-auto max-h-[85vh] max-w-3xl overflow-y-auto rounded-xl bg-white p-6 shadow-2xl" onclick="event.stopPropagation()">
        <div class="mb-3 flex items-start justify-between gap-4">
            <h3 id="mTitle" class="text-base font-bold text-slate-800"></h3>
            <button type="button" onclick="closeModal()" class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-sm text-slate-600 hover:bg-slate-200">关闭 ✕</button>
        </div>
        <div id="mMeta" class="mb-3 flex flex-wrap gap-2 text-xs text-slate-500"></div>
        <div id="mBody" class="whitespace-pre-wrap text-sm leading-7 text-slate-700"></div>
    </div>
</div>

{{-- 全局操作提示 --}}
<div id="articleToast" class="fixed bottom-6 left-1/2 z-50 hidden -translate-x-1/2 rounded-lg px-4 py-2.5 text-sm shadow-lg"></div>
</x-workspace-layout>
</x-app-layout>

<script>
function csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; }

function toast(msg, type) {
    const el = document.getElementById('articleToast');
    el.className = 'fixed bottom-6 left-1/2 z-50 -translate-x-1/2 rounded-lg px-4 py-2.5 text-sm shadow-lg ' +
        (type === 'ok' ? 'bg-green-600 text-white'
            : type === 'warn' ? 'bg-amber-500 text-white'
            : 'bg-red-600 text-white');
    el.textContent = msg;
    el.classList.remove('hidden');
    clearTimeout(window._articleToastTimer);
    window._articleToastTimer = setTimeout(() => el.classList.add('hidden'), 4000);
}

async function hgtPost(url, method, body) {
    const resp = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(data.error || ('请求失败 ' + resp.status));
    if (data.ok === false) throw new Error(data.error || '操作失败');
    return data;
}

function accountId() {
    const sel = document.getElementById('accountId');
    return sel ? sel.value : '';
}

async function viewArticle(id) {
    try {
        const data = await fetch('/studio/articles/' + id, { headers: { 'Accept': 'application/json' } })
            .then(r => r.json());
        const a = data.article || {};
        document.getElementById('mTitle').textContent = a.title || '';
        document.getElementById('mMeta').innerHTML =
            '<span class="rounded bg-slate-100 px-1.5 py-0.5">' + (a.status_label || '') + '</span>' +
            '<span class="rounded bg-slate-100 px-1.5 py-0.5">SEO ' + (a.seo_score || 0) + '</span>' +
            '<span class="rounded bg-slate-100 px-1.5 py-0.5">' + (a.word_count || 0) + ' 字</span>' +
            (a.region ? '<span class="rounded bg-slate-100 px-1.5 py-0.5">' + a.region + '</span>' : '') +
            (a.wechat_article_url ? '<a class="text-brand-600 hover:underline" target="_blank" href="' + a.wechat_article_url + '">已群发文章链接</a>' : '');
        document.getElementById('mBody').textContent = a.content || '';
        document.getElementById('articleModal').classList.remove('hidden');
    } catch (e) {
        toast('❌ ' + e.message, 'err');
    }
}
function closeModal() { document.getElementById('articleModal').classList.add('hidden'); }

async function pushDraft(id, btn) {
    if (!confirm('确定将该文章送入公众号草稿箱？')) return;
    const old = btn.textContent; btn.disabled = true; btn.textContent = '推送中…';
    try {
        const data = await hgtPost('/studio/articles/' + id + '/push-draft', 'POST', { account_id: accountId() });
        if (data.simulated) {
            toast('⚠ ' + (data.warn || '未配置公众号凭据，本次为模拟发送'), 'warn');
        } else {
            toast('✅ 已送入公众号草稿箱', 'ok');
        }
        setTimeout(() => location.reload(), 1200);
    } catch (e) {
        toast('❌ ' + e.message, 'err');
        btn.disabled = false; btn.textContent = old;
    }
}

async function approve(id, btn) {
    if (!confirm('确认该文章由你人工审核通过？通过后方可群发。')) return;
    const old = btn.textContent; btn.disabled = true; btn.textContent = '提交中…';
    try {
        await hgtPost('/studio/articles/' + id + '/approve', 'POST', {});
        toast('✅ 已审核通过，现在可以群发', 'ok');
        setTimeout(() => location.reload(), 1000);
    } catch (e) {
        toast('❌ ' + e.message, 'err');
        btn.disabled = false; btn.textContent = old;
    }
}

async function publish(id, btn) {
    if (!confirm('确认群发到公众号？订阅号每日仅可群发 1 篇。')) return;
    const old = btn.textContent; btn.disabled = true; btn.textContent = '群发中…';
    try {
        const data = await hgtPost('/studio/articles/' + id + '/publish', 'POST', { account_id: accountId() });
        if (data.simulated) {
            toast('⚠ ' + (data.warn || '未配置公众号凭据，本次为模拟发送'), 'warn');
        } else {
            toast('✅ 已群发。' + (data.ai_declaration_reminder || ''), 'ok');
        }
        setTimeout(() => location.reload(), 1500);
    } catch (e) {
        toast('❌ ' + e.message, 'err');
        btn.disabled = false; btn.textContent = old;
    }
}

async function delArticle(id, btn) {
    if (!confirm('确定删除该文章？删除后进入回收站（软删除）。')) return;
    const old = btn.textContent; btn.disabled = true; btn.textContent = '删除中…';
    try {
        await hgtPost('/studio/articles/' + id, 'DELETE', null);
        toast('✅ 已删除', 'ok');
        setTimeout(() => location.reload(), 800);
    } catch (e) {
        toast('❌ ' + e.message, 'err');
        btn.disabled = false; btn.textContent = old;
    }
}
</script>
