<x-app-layout>
<x-workspace-layout title="对话出稿">

@php
    $currentTitle = $currentTitle ?? '';
    $currentTopic = $currentTopic ?? '';
    $currentAudience = $currentAudience ?? '';
    $currentCount = $currentCount ?? 0;
    $tenantSlug = $tenantSlug ?? 'x';
@endphp

<style>
    /* ===== chat 页：以对话为绝对中心，禁掉任何可能挤压的列布局 ===== */
    .chat-shell {
        display: flex;
        flex-direction: column;
        height: calc(100vh - 4rem);   /* 顶栏 64px */
        overflow: hidden;
    }
    .chat-meta { flex: 0 0 auto; }
    .chat-scroll {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        padding: 1.25rem 1rem 1.5rem;
    }
    .chat-input { flex: 0 0 auto; }
    .chat-bubble-wrap { max-width: 768px; margin: 0 auto; }
    .chat-bubble { max-width: 88%; }
    .sess-menu {
        position: absolute;
        right: 0; top: calc(100% + 6px);
        width: 320px; max-height: 70vh; overflow-y: auto;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 12px 32px rgba(15,23,42,.14);
        z-index: 50;
    }
    .sess-item-row.active { background: #eef2ff; }
    .sess-item-row:hover { background: #f1f5f9; }
    .sess-item-row.active:hover { background: #e0e7ff; }
    /* ===== 对话页专用：把侧边栏自动收成图标条，给对话让路 ===== */
    body.workspace-chat #workspaceSidebar {
        width: 3.5rem !important;
    }
    body.workspace-chat #workspaceSidebar .ws-nav-text,
    body.workspace-chat #workspaceSidebar .ws-group-toggle > span:not(.ws-group-chev),
    body.workspace-chat #workspaceSidebar .ws-brand-text { display: none; }
    body.workspace-chat #workspaceSidebar .ws-nav-brand { justify-content: center; padding-left: 0.5rem; padding-right: 0.5rem; }
    body.workspace-chat #sidebarToggleIcon { display: inline-flex; }
</style>
<script>
    // 标记本页面是 chat：侧边栏自动收成图标条
    document.body.classList.add('workspace-chat');
</script>

<div class="chat-shell">

    {{-- ① 元信息条：空间名 + 切换下拉 + 主题/受众/数量 --}}
    <div class="chat-meta border-b border-slate-200 bg-white/80 px-4 py-2.5 backdrop-blur-sm">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
            {{-- 空间切换器 --}}
            <div class="relative" id="sessMenuRoot">
                <button id="sessMenuBtn" type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:border-indigo-300 hover:bg-indigo-50/40">
                    <span id="spaceIcon">💬</span>
                    <span id="spaceTitle" class="max-w-[200px] truncate">新对话</span>
                    <svg class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
                {{-- 下拉 --}}
                <div id="sessMenu" class="sess-menu hidden p-1.5">
                    <div class="flex items-center gap-1.5 px-2 py-1.5">
                        <button id="newSpaceBtn" type="button"
                            class="flex-1 rounded-md bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white transition hover:bg-indigo-700">
                            ＋ 新建临时会话
                        </button>
                        <button id="newNamedBtn" type="button"
                            class="flex-1 rounded-md border border-indigo-300 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 transition hover:bg-indigo-100">
                            📁 新建主题空间
                        </button>
                    </div>
                    <div class="my-1 border-t border-slate-100"></div>
                    <div id="sessionList" class="space-y-0.5">
                        <p class="px-2 py-3 text-center text-xs text-slate-400">加载中…</p>
                    </div>
                </div>
            </div>

            {{-- 要素 chips --}}
            <div class="flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5">主题：<b id="chipTopic" class="text-slate-700">未定</b></span>
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5">受众：<b id="chipAud" class="text-slate-700">未定</b></span>
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5">数量：<b id="chipCount" class="text-slate-700">—</b></span>
            </div>

            <div class="ml-auto flex items-center gap-1.5">
                <button id="renameBtn" type="button" title="重命名 / 存为空间"
                    class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-500 transition hover:border-indigo-300 hover:text-indigo-600">
                    ✎ 改名
                </button>
                <button id="delSpaceBtn" type="button" title="删除当前会话"
                    class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-500 transition hover:border-red-300 hover:text-red-600">
                    🗑 删除
                </button>
            </div>
        </div>
    </div>

    {{-- ② 对话滚动区 --}}
    <div id="chatBox" class="chat-scroll space-y-4 bg-[var(--surface-page)]">
    </div>

    {{-- ③ 输入区：始终可见，固定底部 --}}
    <div class="chat-input border-t border-slate-200 bg-white px-4 py-3">
        <div class="chat-bubble-wrap">
            <div id="quickReplies" class="mb-2 hidden flex-wrap gap-1.5"></div>
            <div class="flex items-end gap-2 rounded-xl border border-slate-200 bg-white p-2 shadow-sm focus-within:border-indigo-300">
                <textarea id="userInput" rows="1" placeholder="说出你想做什么——AI 帮你拆角度 → 出稿 → 改稿 → 配音 → 出片，一句话驱动整条生产线。"
                    class="max-h-40 flex-1 resize-none rounded-lg border-0 bg-transparent px-2 py-1.5 text-sm text-slate-700 outline-none placeholder:text-slate-400"></textarea>
                <button id="sendBtn" type="button"
                    class="shrink-0 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:opacity-50">
                    发送
                </button>
            </div>
            <p class="mt-1.5 text-center text-[11px] text-slate-400">
                历史对话都会留在这个空间，下次回来直接接着聊，AI 不会忘 ·<span class="mx-1">·</span> 改主意随时说"全写 / 写第N条 / 改第N条 / 做成片 / 配音"
            </p>
        </div>
    </div>

</div>

<script>
(function () {
    const SID_KEY = 'chat_sid_' + '{{ $tenantSlug }}';
    const chatBox = document.getElementById('chatBox');
    const input = document.getElementById('userInput');
    const sendBtn = document.getElementById('sendBtn');
    const listBox = document.getElementById('sessionList');
    const sessMenu = document.getElementById('sessMenu');
    const sessMenuBtn = document.getElementById('sessMenuBtn');
    const sessMenuRoot = document.getElementById('sessMenuRoot');
    let sid = localStorage.getItem(SID_KEY) || '';
    let busy = false;
    let lastMsg = '';
    let sessions = [];
    let pendingAsk = [];

    function csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; }

    async function api(url, options) {
        const resp = await fetch(url, Object.assign({
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
        }, options || {}));
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        return await resp.json();
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/\n/g, '<br>');
    }

    function fmtTime(ts) {
        if (!ts) return '';
        const diff = (Date.now() - ts * 1000) / 1000;
        if (diff < 60) return '刚刚';
        if (diff < 3600) return Math.floor(diff / 60) + ' 分钟前';
        if (diff < 86400) return Math.floor(diff / 3600) + ' 小时前';
        if (diff < 604800) return Math.floor(diff / 86400) + ' 天前';
        const d = new Date(ts * 1000);
        return (d.getMonth() + 1) + '/' + d.getDate();
    }

    // ---------- 消息渲染 ----------
    function appendMsg(role, html) {
        const wrap = document.createElement('div');
        wrap.className = 'chat-bubble-wrap flex items-start gap-3 ' + (role === 'user' ? 'flex-row-reverse' : '');
        const av = document.createElement('div');
        av.className = 'mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm ' +
            (role === 'user' ? 'bg-indigo-100 order-2' : 'bg-slate-200 order-1');
        av.textContent = role === 'user' ? '我' : '✦';
        const bubble = document.createElement('div');
        bubble.className = 'chat-bubble rounded-2xl px-4 py-3 text-sm leading-relaxed whitespace-pre-wrap ' +
            (role === 'user'
                ? 'rounded-tr-sm bg-indigo-600 text-white order-1'
                : 'rounded-tl-sm bg-white text-slate-700 order-2 border border-slate-200 shadow-sm');
        bubble.innerHTML = html;
        wrap.appendChild(av); wrap.appendChild(bubble);
        chatBox.appendChild(wrap);
        chatBox.scrollTop = chatBox.scrollHeight;
        return bubble;
    }

    function showIntro() {
        chatBox.innerHTML = '';
        appendMsg('ai',
            '<p class="font-medium text-slate-800">你好，我是你的出稿助手 ✦</p>'
            + '<p class="mt-2">直接说你要做什么，比如：</p>'
            + '<p class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-slate-600">'
            + '「我想做一批创业开公司的口播，给准备注册的小老板看，5 条，讲人话别堆术语，要能挂留资钩子」</p>'
            + '<p class="mt-2">我会先和你把<strong>主题、受众、关键要求</strong>对齐，再按你的写稿规范拆角度方案 → 你认可后出稿 → 改稿 → 配音 → 出片，一气呵成。</p>'
            + '<p class="mt-2 text-xs text-slate-400">这个空间里的对话会一直留着，下次回来直接接着聊，AI 不会忘。</p>'
        );
    }

    function angleCard(a, idx) {
        return '<div class="rounded-lg border border-indigo-200 bg-indigo-50/60 p-3">'
            + '<div class="flex items-start justify-between gap-2">'
            + '<p class="font-medium text-slate-800">' + esc(a.title || ('角度' + (idx + 1))) + '</p>'
            + '<span class="shrink-0 rounded bg-indigo-100 px-1.5 py-0.5 text-[11px] text-indigo-700">#' + (idx + 1) + '</span></div>'
            + (a.angle ? '<p class="mt-1 text-xs text-slate-600">切入：' + esc(a.angle) + '</p>' : '')
            + (a.form ? '<p class="mt-1 text-[11px] text-slate-400">建议形式：' + esc(a.form) + '</p>' : '')
            + '</div>';
    }

    function resultBlock(r) {
        if (r.stage === 'search') {
            const h = ['<p class="font-medium text-slate-800">🔍 全网检索结果：</p>',
                       '<p class="mt-1">' + esc(r.message || '') + '</p>'];
            if (r.sources && r.sources.length) {
                h.push('<div class="mt-2 space-y-1 border-t border-slate-200 pt-2">');
                r.sources.forEach(x => {
                    h.push('<a href="' + esc(x.url) + '" target="_blank" rel="noopener" '
                        + 'class="block text-[11px] text-indigo-600 transition hover:underline">🔗 '
                        + esc(x.title || x.url) + '</a>');
                });
                h.push('</div>');
            }
            h.push('<p class="mt-2 text-xs text-slate-500">以上为全网真实检索结果。要我基于这些参考帮你出角度方案或成稿，直接说。</p>');
            return h.join('');
        }
        if (r.stage === 'review') {
            const meta = ['<span class="rounded bg-amber-100 px-2 py-0.5 text-[11px] text-amber-700">协作审查</span>',
                '<span class="text-[11px] text-slate-400">基于 '
                + (r.ref_count || 0) + ' 条检索证据 + ' + (r.angle_count || 0) + ' 个角度 + '
                + (r.written_count || 0) + ' 篇稿件</span>'].join(' ');
            let html = '<div class="space-y-2">'
                + '<div class="flex items-center gap-2">' + meta + '</div>'
                + '<div class="rounded-lg border border-amber-200 bg-amber-50/60 p-3">'
                + '<div class="prose prose-sm prose-slate max-w-none whitespace-pre-wrap text-slate-800 leading-relaxed">'
                + esc(r.message || '') + '</div></div>';
            if (r.tip) html += '<p class="text-xs text-slate-500">' + esc(r.tip) + '</p>';
            if (r.ask_back && r.ask_back.length) {
                pendingAsk = r.ask_back;
                html += '<div class="mt-2 rounded-lg border-l-4 border-indigo-400 bg-indigo-50/70 p-3">'
                    + '<p class="mb-1 flex items-center gap-1 text-[11px] font-medium text-indigo-700">'
                    + '🤔 反过来我想问你' + (r.answered ? '' : '（答完我接着往下推）') + '</p><ul class="space-y-1">';
                r.ask_back.forEach((q, i) => {
                    html += '<li class="flex gap-2 text-sm leading-relaxed text-slate-800">'
                        + '<span class="mt-0.5 shrink-0 text-indigo-500">' + (i + 1) + '.</span>'
                        + '<span>' + esc(q) + '</span></li>';
                });
                html += '</ul></div>';
                input.placeholder = '回答上面的问题…（Enter 发送）';
            } else {
                pendingAsk = [];
                input.placeholder = '说出你想做什么——AI 帮你拆角度 → 出稿 → 改稿 → 配音 → 出片，一句话驱动整条生产线。';
            }
            html += '</div>';
            return html;
        }
        if (r.stage === 'ask') {
            return '<p>' + esc(r.message || '') + '</p>';
        }
        if (r.stage === 'propose') {
            const h = ['<p class="font-medium text-slate-800">💡 我已按你的主题和写稿规范拆出角度方案，你看看：</p>',
                       '<div class="mt-2 grid gap-2">'];
            (r.angles || []).forEach((a, i) => h.push(angleCard(a, i)));
            h.push('</div><p class="mt-2 text-xs text-slate-500">认可就说"就按这个全写"，或指定某条（如"写第2条"）。</p>');
            return h.join('');
        }
        if (r.stage === 'written') {
            const h = ['<p class="font-medium text-slate-800">' + (r.revised ? '✅ 已按要求重写' : '✅ 成稿如下（每段为可直接配音的口播稿）') + '：</p><div class="mt-2 space-y-3">'];
            (r.results || []).forEach((w, i) => {
                h.push('<div class="rounded-lg border border-slate-200 bg-white p-3">'
                    + '<div class="flex items-center justify-between gap-2">'
                    + '<p class="font-semibold text-slate-800">' + esc(w.title || ('口播稿' + (i + 1))) + '</p>'
                    + '<button type="button" data-rev="' + i + '" class="revise-btn shrink-0 rounded border border-slate-300 px-2 py-0.5 text-[11px] text-slate-500 transition hover:bg-slate-100">✎ 改这篇</button>'
                    + '</div>'
                    + '<p class="mt-2 whitespace-pre-wrap text-slate-700">' + esc(w.script || '') + '</p></div>');
            });
            h.push('</div><p class="mt-2 text-xs text-slate-500">要调整某篇就点「改这篇」或直接说（如"第2篇太长了，口吻再简洁些"）；稿子满意了，跟我说"做成片"或"配音"。</p>');
            return h.join('');
        }
        if (r.stage === 'action_ask') {
            const c = r.cap || {}, p = r.param || {};
            const h = [
                '<p class="font-medium text-slate-800">' + esc(c.icon || '▶️') + ' ' + esc(r.message || ('我来帮你' + (c.name || ''))) + '</p>',
                '<p class="mt-1 text-xs text-slate-400">参数收集 ' + esc(r.progress || '') + '</p>',
                '<p class="mt-2 text-slate-700">' + esc(p.label || '请补充信息') + (p.hint ? '<span class="text-slate-400">（' + esc(p.hint) + '）</span>' : '') + '</p>'
            ];
            if ((p.options || []).length) {
                h.push('<div class="mt-2 flex flex-wrap gap-2">');
                p.options.forEach(o => {
                    h.push('<button type="button" data-msg="' + esc(o) + '" class="act-msg rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 transition hover:bg-indigo-100">' + esc(String(o).split(':').pop()) + '</button>');
                });
                h.push('</div>');
            }
            h.push('<p class="mt-2 text-xs text-slate-500">' + esc(r.tip || '直接在下面填，或点上面的选项。') + ' 不想做了就说「取消」。</p>');
            return h.join('');
        }
        if (r.stage === 'action_ready') {
            const c = r.cap || {};
            const payload = encodeURIComponent(JSON.stringify({ cap: c.id, vals: r.vals || {}, next: r.next || [] }));
            const h = [
                '<p class="font-medium text-slate-800">' + esc(c.icon || '▶️') + ' ' + esc(r.message || ('准备好了，可以开始' + (c.name || ''))) + '</p>',
                '<div class="mt-2 rounded-lg border border-slate-200 bg-slate-50 p-2 text-[11px] text-slate-500"><table class="w-full">'
            ];
            const labels = { dialogue: '口播稿', text: '原文', topic: '主题', industry: '行业', keywords: '关键词',
                             count: '数量', platform: '平台', hook: '钩子', mode: '视频形式', title: '主标题',
                             subtitle: '副标题', voice_form: '配音形式', focus: '侧重点', job_id: '视频任务',
                             url: '链接', audio_path: '音频路径' };
            Object.keys(r.vals || {}).forEach(k => {
                let v = String(r.vals[k] ?? '');
                if (v.length > 60) v = v.slice(0, 60) + '…';
                h.push('<tr><td class="pr-2 py-0.5 text-slate-400 whitespace-nowrap">' + esc(labels[k] || k)
                    + '</td><td class="py-0.5 text-slate-700">' + esc(v) + '</td></tr>');
            });
            h.push('</table></div>');
            h.push('<div class="mt-2 flex flex-wrap gap-2">'
                + '<button type="button" data-cap="' + payload + '" class="cap-run rounded-lg bg-indigo-600 px-4 py-1.5 text-xs font-medium text-white transition hover:bg-indigo-700">▶ 开始执行</button>'
                + '</div>');
            h.push('<p class="mt-2 text-xs text-slate-500">' + esc(r.tip || '') + '</p>');
            return h.join('');
        }
        if (r.stage === 'action_done') {
            const c = r.cap || {};
            const h = ['<p class="font-medium text-slate-800">' + esc(c.icon || '▶️') + ' ' + esc(c.name || '') + (r.ok ? ' 已完成' : ' 没跑通') + '</p>',
                       '<p class="mt-1">' + esc(r.message || '') + '</p>'];
            if (r.data && r.data.job_id) {
                h.push('<p class="mt-1 text-[11px] text-slate-400">任务号：' + esc(r.data.job_id) + '</p>');
            }
            if ((r.next || []).length) {
                h.push('<p class="mt-2 text-xs font-medium text-slate-500">下一步，你可以：</p><div class="mt-1 flex flex-wrap gap-2">');
                r.next.forEach(n => {
                    h.push('<button type="button" data-msg="' + esc('用' + n.name) + '" class="act-msg rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 transition hover:bg-indigo-100">'
                        + esc(n.icon || '▶️') + ' ' + esc(n.name) + '</button>');
                });
                h.push('</div>');
            }
            return h.join('');
        }
        if (r.stage === 'act_result') {
            const h = ['<p>' + esc(r.message || '执行完成') + '</p>'];
            if ((r.next || []).length) {
                h.push('<p class="mt-2 text-xs font-medium text-slate-500">下一步，你可以：</p><div class="mt-1 flex flex-wrap gap-2">');
                r.next.forEach(n => {
                    h.push('<button type="button" data-msg="' + esc('用' + n.name) + '" class="act-msg rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 transition hover:bg-indigo-100">'
                        + esc(n.icon || '▶️') + ' ' + esc(n.name) + '</button>');
                });
                h.push('</div>');
            }
            return h.join('');
        }
        if (r.stage === 'pipeline') {
            const h = ['<p>' + esc(r.message || '') + '</p>', '<div class="mt-2 flex flex-wrap gap-2">'];
            (r.actions || []).forEach(a => {
                if (a.type === 'goto' && a.url) {
                    h.push('<a href="' + esc(a.url) + '" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-indigo-700"'
                        + (a.hint ? ' title="' + esc(a.hint) + '"' : '') + '>' + esc(a.label) + '</a>');
                } else {
                    h.push('<button type="button" data-msg="' + esc(a.label.replace(/去|对已写稿做|（.*）|\[.*\]/g, '')) + '" class="act-msg rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 transition hover:bg-indigo-100">' + esc(a.label) + '</button>');
                }
            });
            h.push('</div><p class="mt-2 text-xs text-slate-500">也可以直接输入指令，AI 会接着引导你。</p>');
            return h.join('');
        }
        if (r.stage === 'done') {
            return '<p>' + esc(r.message || '本批已全部写完。') + '</p>';
        }
        return '<p>' + esc(r.error || r.message || '（已完成）') + '</p>';
    }

    // ---------- 顶栏下拉里的会话列表 ----------
    function renderSessions() {
        if (!sessions.length) {
            listBox.innerHTML = '<p class="px-2 py-3 text-center text-xs text-slate-400">还没有会话，点上面「＋ 新建临时会话」开始。</p>';
            return;
        }
        const spaces = sessions.filter(x => x.kind === 'space' || x.title);
        const temps = sessions.filter(x => !(x.kind === 'space' || x.title));
        let html = '';
        function item(x) {
            const active = x.session_id === sid;
            const name = x.title || x.topic || '未命名会话';
            const meta = [];
            if (x.written_count) meta.push(x.written_count + ' 篇稿');
            else if (x.angle_count) meta.push(x.angle_count + ' 个角度');
            meta.push((x.msg_count || 0) + ' 条对话');
            return '<div data-sid="' + esc(x.session_id) + '" class="sess-item-row group cursor-pointer rounded-md px-2 py-1.5 ' + (active ? 'active' : '') + '">'
                + '<div class="flex items-start gap-1.5">'
                + '<span class="mt-0.5 shrink-0 text-[11px]">' + (x.title ? '📁' : '💬') + '</span>'
                + '<div class="min-w-0 flex-1">'
                + '<p class="truncate text-xs font-medium ' + (active ? 'text-indigo-700' : 'text-slate-700') + '">' + esc(name) + '</p>'
                + '<p class="mt-0.5 truncate text-[11px] text-slate-400">' + esc(meta.join(' · ')) + ' · ' + esc(fmtTime(x.updated_at)) + '</p>'
                + '</div>'
                + '<div class="hidden shrink-0 gap-0.5 group-hover:flex">'
                + '<button type="button" data-act="rename" data-sid="' + esc(x.session_id) + '" title="重命名" class="rounded px-1 text-[11px] text-slate-400 hover:bg-white hover:text-indigo-600">✎</button>'
                + '<button type="button" data-act="del" data-sid="' + esc(x.session_id) + '" title="删除" class="rounded px-1 text-[11px] text-slate-400 hover:bg-white hover:text-red-600">🗑</button>'
                + '</div></div></div>';
        }
        if (spaces.length) {
            html += '<p class="px-2 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wider text-slate-400">📁 主题空间</p>' + spaces.map(item).join('');
        }
        if (temps.length) {
            html += '<p class="px-2 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-wider text-slate-400">💬 临时会话</p>' + temps.map(item).join('');
        }
        listBox.innerHTML = html;
    }

    async function loadSessions() {
        try {
            const d = await api('/studio/chat/sessions');
            sessions = d.sessions || [];
            renderSessions();
        } catch (e) {
            listBox.innerHTML = '<p class="px-2 py-3 text-center text-xs text-slate-400">会话列表加载失败（8500 旧代码？）</p>';
        }
    }

    function setActive(sidVal, title) {
        sid = sidVal || '';
        pendingAsk = [];
        input.placeholder = '说出你想做什么——AI 帮你拆角度 → 出稿 → 改稿 → 配音 → 出片，一句话驱动整条生产线。';
        localStorage.setItem(SID_KEY, sid);
        document.getElementById('spaceTitle').textContent = title || '新对话';
        document.getElementById('spaceIcon').textContent = title ? '📁' : '💬';
        renderSessions();
    }

    async function openSession(id) {
        try {
            const d = await api('/studio/chat/messages?session_id=' + encodeURIComponent(id));
            setActive(d.session_id, d.title || d.topic || '');
            document.getElementById('chipTopic').textContent = d.topic || '未定';
            document.getElementById('chipAud').textContent = d.audience || '未定';
            document.getElementById('chipCount').textContent = d.count ? (d.count + ' 条') : '—';
            const msgs = d.messages || [];
            sessMenu.classList.add('hidden');   // 切完关闭下拉
            if (!msgs.length) { showIntro(); }
            else {
                chatBox.innerHTML = '';
                msgs.forEach(m => {
                    const p = m.payload || {};
                    if (m.role === 'user') appendMsg('user', esc(p.content || ''));
                    else appendMsg('ai', resultBlock(p));
                });
            }
        } catch (e) {
            showIntro();
        }
    }

    async function createSession(title) {
        try {
            const d = await api('/studio/chat/session/create', {
                method: 'POST', body: JSON.stringify({ title: title || '' }),
            });
            await loadSessions();
            await openSession(d.session_id);
            input.focus();
        } catch (e) {
            alert('新建失败：' + e.message);
        }
    }

    async function renameCurrent() {
        if (!sid) { alert('先发一条消息，或点「＋ 新建」开个会话。'); return; }
        const cur = document.getElementById('spaceTitle').textContent;
        const t = prompt('给这个会话起个名字（起名后即成为「主题空间」，长期保留）：', cur === '新对话' ? '' : cur);
        if (t === null) return;
        try {
            await api('/studio/chat/session/update', {
                method: 'POST', body: JSON.stringify({ session_id: sid, title: t.trim() }),
            });
            await loadSessions();
            setActive(sid, t.trim());
        } catch (e) { alert('重命名失败：' + e.message); }
    }

    async function deleteSession(id) {
        if (!confirm('删除这个会话？历史对话会一起删掉，不可恢复。')) return;
        try {
            await api('/studio/chat/session/delete', {
                method: 'POST', body: JSON.stringify({ session_id: id }),
            });
            if (id === sid) { setActive('', ''); showIntro(); }
            await loadSessions();
        } catch (e) { alert('删除失败：' + e.message); }
    }

    // 下拉开关
    sessMenuBtn.onclick = (e) => { e.stopPropagation(); sessMenu.classList.toggle('hidden'); };
    document.addEventListener('click', (e) => {
        if (!sessMenuRoot.contains(e.target)) sessMenu.classList.add('hidden');
    });
    // 下拉里的事件
    listBox.addEventListener('click', (e) => {
        const btn = e.target.closest?.('[data-act]');
        if (btn) {
            e.stopPropagation();
            if (btn.dataset.act === 'rename') {
                const target = sessions.find(x => x.session_id === btn.dataset.sid);
                const t = prompt('会话名称（留空则退回临时会话）：', (target && (target.title || target.topic)) || '');
                if (t === null) return;
                api('/studio/chat/session/update', {
                    method: 'POST', body: JSON.stringify({ session_id: btn.dataset.sid, title: t.trim() }),
                }).then(() => { loadSessions(); if (btn.dataset.sid === sid) setActive(sid, t.trim()); });
            } else if (btn.dataset.act === 'del') {
                deleteSession(btn.dataset.sid);
            }
            return;
        }
        const it = e.target.closest?.('.sess-item-row');
        if (it && it.dataset.sid) openSession(it.dataset.sid);
    });

    // ---------- 发送 ----------
    function updateMeta(r) {
        if (r.topic) document.getElementById('chipTopic').textContent = r.topic;
        if (r.audience) document.getElementById('chipAud').textContent = r.audience;
        if (r.count) document.getElementById('chipCount').textContent = r.count + ' 条';
        if (r.session_id) sid = r.session_id;
        localStorage.setItem(SID_KEY, sid);
        if (r.title) {
            document.getElementById('spaceTitle').textContent = r.title;
            document.getElementById('spaceIcon').textContent = '📁';
        }
    }

    function showQuick(msgs) {
        const q = document.getElementById('quickReplies');
        q.innerHTML = ''; q.classList.add('flex');
        (msgs || []).forEach(m => {
            const b = document.createElement('button');
            b.className = 'rounded-full border border-slate-300 bg-white px-3 py-1 text-xs text-slate-600 transition hover:bg-slate-50';
            b.textContent = m;
            b.onclick = () => { input.value = m; doSend(); };
            q.appendChild(b);
        });
    }

    async function doSend() {
        const msg = input.value.trim();
        if (!msg || busy) return;
        lastMsg = msg;
        busy = true; sendBtn.disabled = true; input.value = '';
        appendMsg('user', esc(msg));
        const thinking = appendMsg('ai', '<span class="text-slate-400">…正在理解并处理</span>');
        try {
            const data = await api('/studio/chat/send', {
                method: 'POST', body: JSON.stringify({ session_id: sid, message: msg }),
            });
            if (data.error) throw new Error(data.error);
            thinking.remove();
            appendMsg('ai', resultBlock(data));
            updateMeta(data);
            loadSessions();
            if (data.stage === 'ask' && data.missing && data.missing.length) {
                showQuick(['受众是中小企业老板', '给会计看', '要 5 条', '讲人话别堆术语']);
            } else {
                document.getElementById('quickReplies').classList.remove('flex');
                document.getElementById('quickReplies').classList.add('hidden');
            }
        } catch (e) {
            thinking.remove();
            const errWrap = document.createElement('div');
            errWrap.innerHTML = '<span class="text-red-500">出错了：' + esc(e.message) + '。</span> '
                + '<button type="button" id="chatRetryBtn" class="ml-1 mt-1 inline-block rounded border border-indigo-300 bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-700 transition hover:bg-indigo-100">↻ 重试</button>';
            const bubble = appendMsg('ai', '');
            bubble.appendChild(errWrap);
            const rb = document.getElementById('chatRetryBtn');
            if (rb) rb.onclick = () => { if (lastMsg) { input.value = lastMsg; doSend(); } };
        } finally {
            busy = false; sendBtn.disabled = false; input.focus();
        }
    }

    chatBox.addEventListener('click', (e) => {
        const rev = e.target.closest?.('.revise-btn');
        if (rev) {
            const idx = parseInt(rev.dataset.rev || '0', 10) + 1;
            input.value = '改第' + idx + '篇：';
            input.focus();
            return;
        }
        const act = e.target.closest?.('.act-msg');
        if (act && act.dataset.msg) {
            input.value = act.dataset.msg;
            doSend();
            return;
        }
        const capBtn = e.target.closest?.('.cap-run');
        if (capBtn && capBtn.dataset.cap && !capBtn.disabled) {
            let payload = null;
            try { payload = JSON.parse(decodeURIComponent(capBtn.dataset.cap)); } catch (_) { return; }
            runCapAction(capBtn, payload);
        }
    });

    // ---------- 能力执行（对话里点「开始执行」）----------
    async function runCapAction(btn, payload) {
        btn.disabled = true;
        btn.textContent = '⏳ 正在执行…';
        appendMsg('ai', '<p class="text-slate-500">⏳ 正在跑「' + esc((payload.cap || '')) + '」，请稍候…</p>');
        try {
            const res = await api('/studio/chat/action', {
                method: 'POST',
                body: JSON.stringify({ cap: payload.cap, vals: payload.vals || {} })
            });
            if (res && res.ok === false) throw new Error(res.error || '后端返回失败');
            const data = (res && res.data) || {};

            // 长任务（出片）：给出任务号并轮询进度
            if (data.job_id) {
                appendMsg('ai', '<p class="font-medium text-slate-800">🎬 已提交渲染，任务号 <code class="text-[11px]">' + esc(data.job_id) + '</code></p>'
                    + '<p class="mt-1 text-slate-600">视频要渲染几分钟，我会一直盯着进度，完成后告诉你。</p>');
                pollJob(data.job_id, payload);
            } else {
                appendMsg('ai', '<p class="font-medium text-slate-800">✅ ' + esc(payload.cap || '') + ' 跑完了</p>'
                    + '<pre class="mt-1 max-h-60 overflow-auto whitespace-pre-wrap rounded bg-slate-50 p-2 text-[11px] text-slate-600">'
                    + esc(JSON.stringify(data, null, 1).slice(0, 1500)) + '</pre>');
                // 把结果交给 AI 总结 + 引导下一步（AI 回复里自带下一步卡片）
                await sendActionResult(payload.cap, true, data, payload.next || []);
            }
        } catch (err) {
            appendMsg('ai', '<p class="text-rose-600">❌ 没跑通：' + esc(err.message || '未知错误') + '</p>'
                + '<p class="mt-1 text-xs text-slate-500">可以改一下参数再来，或跟我说你要做什么，我换个方式帮你。</p>');
            await sendActionResult(payload.cap, false, { error: String(err.message || '') });
        } finally {
            btn.disabled = false;
            btn.textContent = '↻ 再执行一次';
        }
    }

    // 长任务轮询：每 8 秒查一次，完成后回灌 AI 并给下一步卡片
    async function pollJob(jobId, payload) {
        let tries = 0;
        const timer = setInterval(async () => {
            tries++;
            try {
                const r = await fetch('/studio/scroll/status/' + encodeURIComponent(jobId), { headers: { 'Accept': 'application/json' } });
                const j = await r.json();
                const st = j.status || j.data?.status || '';
                if (st === 'done' || st === 'failed' || tries > 90) {
                    clearInterval(timer);
                    const ok = st === 'done';
                    if (ok) {
                        // 视频内嵌预览：直接用 Laravel 现有 inline 端点（带 cookie 鉴权）
                        const videoUrl = '/studio/scroll/download/' + encodeURIComponent(jobId);
                        appendMsg('ai',
                            '<p class="font-medium text-slate-800">🎉 视频渲染完成</p>'
                            + '<div class="mt-2 overflow-hidden rounded-xl border border-slate-200 bg-black">'
                            +   '<video controls preload="metadata" class="block max-h-[420px] w-full" src="' + esc(videoUrl) + '"></video>'
                            + '</div>'
                            + '<div class="mt-2 flex flex-wrap gap-2">'
                            +   '<a href="' + esc(videoUrl) + '" download="' + esc(jobId) + '.mp4" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50">⬇ 下载 mp4</a>'
                            +   '<button type="button" data-msg="对刚成片做质检" class="act-msg rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 transition hover:bg-indigo-100">🛡️ 成片质检</button>'
                            +   '<button type="button" data-msg="打成发布包" class="act-msg rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 transition hover:bg-indigo-100">📦 打发布包</button>'
                            +   '<button type="button" data-msg="改成小红书图文" class="act-msg rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 transition hover:bg-indigo-100">📕 一鱼多吃·小红书</button>'
                            + '</div>');
                    } else {
                        appendMsg('ai', '<p class="text-rose-600">⚠️ 渲染' + (st === 'failed' ? '失败' : '超时（已盯了 12 分钟）') + '</p>'
                            + '<p class="mt-1 text-xs text-slate-500">可以重新执行一次，或跟我说要改什么。</p>');
                    }
                    await sendActionResult(payload.cap, ok, { job_id: jobId, status: st }, payload.next || []);
                }
            } catch (_) { /* 网络抖动，下一轮继续 */ }
        }, 8000);
    }

    // 把执行结果送回编排器，让 AI 总结 + 纠偏 + 引导下一步
    async function sendActionResult(cap, ok, data, next) {
        try {
            const res = await api('/studio/chat/send', {
                method: 'POST',
                body: JSON.stringify({ session_id: sid, message: '', action: { cap: cap, ok: ok, data: data } })
            });
            if (res && res.stage === 'action_done') {
                appendMsg('ai', resultBlock(res));
                return;
            }
        } catch (_) { /* 总结失败不阻断主流程 */ }
        // 兜底：AI 没回出引导时，也把下一步卡片给用户（不能让他卡住不知道干嘛）
        renderNext(next || []);
    }

    function renderNext(list) {
        if (!list || !list.length) return;
        let h = '<p class="mt-2 text-xs font-medium text-slate-500">下一步，你可以：</p><div class="mt-1 flex flex-wrap gap-2">';
        list.forEach(n => {
            h += '<button type="button" data-msg="' + esc('用' + n.name) + '" class="act-msg rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 transition hover:bg-indigo-100">'
                + esc(n.icon || '▶️') + ' ' + esc(n.name) + '</button>';
        });
        h += '</div>';
        appendMsg('ai', h);
    }

    sendBtn.onclick = doSend;
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSend(); }
        // 自动撑高
        e.target.style.height = 'auto';
        e.target.style.height = Math.min(e.target.scrollHeight, 160) + 'px';
    });
    document.getElementById('newSpaceBtn').onclick = () => { sessMenu.classList.add('hidden'); createSession(''); };
    document.getElementById('newNamedBtn').onclick = () => {
        sessMenu.classList.add('hidden');
        const t = prompt('给这个主题空间起个名字（如「注册公司引流系列」）：', '');
        if (t === null) return;
        createSession(t.trim());
    };
    document.getElementById('renameBtn').onclick = renameCurrent;
    document.getElementById('delSpaceBtn').onclick = () => { if (sid) deleteSession(sid); };

    // ---------- 启动 ----------
    (async function init() {
        await loadSessions();
        const known = sessions.some(x => x.session_id === sid);
        if (sid && (known || sessions.length === 0)) {
            await openSession(sid);
        } else if (sessions.length) {
            await openSession(sessions[0].session_id);
        } else {
            setActive('', '');
            showIntro();
        }
        input.focus();
    })();
})();
</script>

</x-workspace-layout>
</x-app-layout>
