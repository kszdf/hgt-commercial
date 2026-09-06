<x-app-layout>
<x-workspace-layout title="对话出稿">
<div class="mx-auto max-w-4xl p-6">

    <!-- ===== 顶部引导条 ===== -->
    <section class="mb-4 rounded-xl border border-slate-200 bg-white/70 p-4 backdrop-blur-sm">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-lg font-semibold text-slate-800">对话出稿工作台</h1>
                <p class="mt-1 text-sm text-slate-500">
                    用一句话说清你要什么，AI 会先跟你对齐
                    <span class="text-slate-700">主题 / 受众 / 关键要求 / 数量</span>，
                    再按你的写稿规范拆角度方案 → 等你确认 → 逐条成稿。
                </p>
            </div>
            <button id="newChatBtn" type="button"
                class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-100">
                ＋ 新对话
            </button>
        </div>
        <!-- 要素状态 -->
        <div id="metaChips" class="mt-3 flex flex-wrap gap-2 text-xs">
            <span class="rounded-full bg-slate-100 px-3 py-1 text-slate-500">主题：<b id="chipTopic" class="text-slate-700">未定</b></span>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-slate-500">受众：<b id="chipAud" class="text-slate-700">未定</b></span>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-slate-500">数量：<b id="chipCount" class="text-slate-700">—</b></span>
        </div>
    </section>

    <!-- ===== 对话消息区 ===== -->
    <section id="chatBox" class="mb-4 min-h-[46vh] space-y-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <!-- 开场引导由 JS 注入 -->
        <div class="flex items-start gap-3">
            <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-sm">✦</div>
            <div class="max-w-[80%] rounded-2xl rounded-tl-sm bg-slate-100 px-4 py-3 text-sm leading-relaxed text-slate-700">
                你好，我是你的出稿助手。想做一批财税口播稿的话，直接跟我说，比如：<br>
                「我想做创业开公司的口播，给准备开公司的小老板看，5 条，讲人话别堆术语，要能挂留资钩子。」<br>
                我会先跟你把主题、受众、关键要求对齐，再帮你拆角度方案。
            </div>
        </div>
    </section>

    <!-- 快速建议 -->
    <div id="quickReplies" class="mb-4 hidden flex-wrap gap-2"></div>

    <!-- ===== 输入区 ===== -->
    <section class="flex items-end gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
        <textarea id="userInput" rows="1" placeholder="输入你的出稿需求…（Enter 发送，Shift+Enter 换行）"
            class="max-h-40 flex-1 resize-none rounded-lg border-0 bg-transparent px-2 py-2 text-sm text-slate-700 outline-none placeholder:text-slate-400"></textarea>
        <button id="sendBtn" type="button"
            class="shrink-0 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:opacity-50">
            发送
        </button>
    </section>

</div>

@php $industryHint = $industryHint ?? ''; @endphp
<script>
(function () {
    const SID_KEY = 'chat_sid_' + '{{ $tenantSlug ?? 'x' }}';
    const chatBox = document.getElementById('chatBox');
    const input = document.getElementById('userInput');
    const sendBtn = document.getElementById('sendBtn');
    const newChatBtn = document.getElementById('newChatBtn');
    let sid = localStorage.getItem(SID_KEY) || '';
    let busy = false;

    function csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; }

    function appendMsg(role, html) {
        const wrap = document.createElement('div');
        wrap.className = 'flex items-start gap-3 ' + (role === 'user' ? 'flex-row-reverse' : '');
        const av = document.createElement('div');
        av.className = 'mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm ' +
            (role === 'user' ? 'bg-indigo-100 order-2' : 'bg-slate-200 order-1');
        av.textContent = role === 'user' ? '我' : '✦';
        const bubble = document.createElement('div');
        bubble.className = 'max-w-[80%] rounded-2xl px-4 py-3 text-sm leading-relaxed whitespace-pre-wrap ' +
            (role === 'user'
                ? 'rounded-tr-sm bg-indigo-600 text-white order-1'
                : 'rounded-tl-sm bg-slate-100 text-slate-700 order-2');
        bubble.innerHTML = html;
        wrap.appendChild(av); wrap.appendChild(bubble);
        chatBox.appendChild(wrap);
        chatBox.scrollTop = chatBox.scrollHeight;
        return bubble;
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/\n/g, '<br>');
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
        if (r.stage === 'ask') {
            return '<p>' + esc(r.message || '') + '</p>';
        }
        if (r.stage === 'propose') {
            const h = ['<p class="font-medium text-slate-800">我已按你的主题和写稿规范拆出角度方案，你看看：</p>',
                       '<div class="mt-2 grid gap-2">'];
            (r.angles || []).forEach((a, i) => h.push(angleCard(a, i)));
            h.push('</div><p class="mt-2 text-xs text-slate-500">认可就说"就按这个全写"，或指定某条（如"写第2条"）。</p>');
            return h.join('');
        }
        if (r.stage === 'written') {
            const h = ['<p class="font-medium text-slate-800">' + (r.revised ? '已按要求重写' : '成稿如下（每段为可直接配音的口播稿）') + '：</p><div class="mt-2 space-y-3">'];
            (r.results || []).forEach((w, i) => {
                h.push('<div class="rounded-lg border border-slate-200 bg-white p-3">'
                    + '<div class="flex items-center justify-between gap-2">'
                    + '<p class="font-semibold text-slate-800">' + esc(w.title || ('口播稿' + (i + 1))) + '</p>'
                    + '<button type="button" data-rev="' + i + '" class="revise-btn shrink-0 rounded border border-slate-300 px-2 py-0.5 text-[11px] text-slate-500 transition hover:bg-slate-100">✎ 改这篇</button>'
                    + '</div>'
                    + '<p class="mt-2 whitespace-pre-wrap text-slate-700">' + esc(w.script || '') + '</p></div>');
            });
            h.push('</div><p class="mt-2 text-xs text-slate-500">要调整某篇就点「改这篇」或直接说（如"第2篇太长了，口吻再简洁些"）；稿子满意了，跟我说"做成片"或"配音"，我帮你接下一步。</p>');
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

    function updateMeta(r) {
        if (r.topic) document.getElementById('chipTopic').textContent = r.topic;
        if (r.audience) document.getElementById('chipAud').textContent = r.audience;
        if (r.count) document.getElementById('chipCount').textContent = r.count + ' 条';
        if (r.session_id) { sid = r.session_id; localStorage.setItem(SID_KEY, sid); }
    }

    function showQuick(msgs) {
        const q = document.getElementById('quickReplies');
        q.innerHTML = ''; q.classList.add('flex');
        (msgs || []).forEach(m => {
            const b = document.createElement('button');
            b.className = 'rounded-full border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-600 transition hover:bg-slate-50';
            b.textContent = m;
            b.onclick = () => { input.value = m; doSend(); };
            q.appendChild(b);
        });
    }

    async function doSend() {
        const msg = input.value.trim();
        if (!msg || busy) return;
        busy = true; sendBtn.disabled = true; input.value = '';
        appendMsg('user', esc(msg));
        const thinking = appendMsg('ai', '<span class="text-slate-400">…正在理解并处理</span>');
        try {
            const resp = await fetch('/studio/chat/send', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify({ session_id: sid, message: msg }),
            });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const data = await resp.json();
            if (data.error) throw new Error(data.error);
            thinking.remove();
            appendMsg('ai', resultBlock(data));
            updateMeta(data);
            if (data.stage === 'ask' && data.missing && data.missing.length) {
                showQuick(['受众是中小企业老板', '给会计看', '要 5 条', '讲人话别堆术语']);
            } else {
                document.getElementById('quickReplies').classList.remove('flex');
                document.getElementById('quickReplies').classList.add('hidden');
            }
        } catch (e) {
            thinking.remove();
            appendMsg('ai', '出错了：' + esc(e.message) + '。请确认服务正常后重试。');
        } finally {
            busy = false; sendBtn.disabled = false; input.focus();
        }
    }

    // 动态按钮事件委托（成稿卡"改这篇" + pipeline 动作消息）
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
        }
    });

    sendBtn.onclick = doSend;
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSend(); }
    });
    newChatBtn.onclick = () => {
        sid = ''; localStorage.removeItem(SID_KEY);
        chatBox.innerHTML = '';
        appendMsg('ai', '好的，新对话。直接告诉我你想做什么主题、给谁看？');
        document.getElementById('chipTopic').textContent = '未定';
        document.getElementById('chipAud').textContent = '未定';
        document.getElementById('chipCount').textContent = '—';
        input.focus();
    };
})();
</script>
</x-workspace-layout>
</x-app-layout>
