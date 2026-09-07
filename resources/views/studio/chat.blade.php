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
    /* ===== chat 页：以对话为绝对中心，仿 WorkBuddy 视觉（更舒展、更轻） ===== */
    .chat-shell {
        display: flex;
        flex-direction: row;
        height: 100%;
        min-height: 0;
        overflow: hidden;
    }
    /* 左侧常驻会话列（三栏中间一栏；workspace 侧栏为最左功能菜单） */
    .chat-rail {
        flex: 0 0 auto;
        width: 264px;
        min-width: 264px;
        display: flex;
        flex-direction: column;
        background: var(--color-background-secondary, #f8fafc);
        border-right: 1px solid var(--surface-card-border, #e2e8f0);
        transition: width .18s ease, min-width .18s ease;
    }
    .chat-rail.collapsed { width: 0; min-width: 0; border-right: none; overflow: hidden; }
    .chat-main {
        flex: 1 1 auto;
        min-width: 0;
        display: flex;
        flex-direction: column;
    }
    /* 顶格条（当前空间名 + 要素 + 删除）粘性常驻，对话滚动时不动 */
    .chat-meta {
        flex: 0 0 auto;
        position: sticky;
        top: 0;
        z-index: 20;
        background: rgba(255,255,255,0.96);
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
        box-shadow: 0 1px 3px rgba(15,23,42,0.05);
    }
    .chat-scroll {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        padding: 1.5rem 1.5rem 1.5rem;  /* 上下都多留点呼吸 */
    }
    .chat-input { flex: 0 0 auto; }
    .chat-bubble-wrap { max-width: 820px; margin: 0 auto; }
    .chat-bubble { max-width: 92%; }
    /* 关键：对话气泡内文字一律可选可复制（默认就是 text，但显式声明防被任何父级 user-select 继承影响） */
    .chat-bubble, .chat-bubble * {
        -webkit-user-select: text;
        user-select: text;
        -webkit-touch-callout: default;
    }
    /* 输入框文字也可选（防止后续在某些主题里被屏蔽） */
    textarea, .chat-input { -webkit-user-select: text; user-select: text; }
    /* 会话列内元素 */
    .rail-item {
        display: flex; align-items: flex-start; gap: 6px;
        cursor: pointer; border-radius: 8px; padding: 7px 8px;
        color: #334155; transition: background .12s;
    }
    .rail-item:hover { background: #eef2ff; }
    .rail-item.active { background: #e0e7ff; }
    .rail-item .rail-name { flex: 1; min-width: 0; font-size: 12.5px; font-weight: 500; }
    .rail-item.active .rail-name { color: #4338ca; }
    .rail-item .rail-sub { font-size: 11px; color: #94a3b8; }
    .rail-item.active .rail-sub { color: #6366f1; }
    .rail-item .rail-ops { display: none; gap: 2px; }
    .rail-item:hover .rail-ops { display: inline-flex; }
    /* ===== 对话页专用：保留完整 6 菜单侧栏（图标+文字），不再收成图标条，避免"素材与账户"组入口丢失 ===== */
</style>
<script>
    // 标记本页面是对话工作台主界面（保留完整 6 菜单侧栏，chat 页内部自带会话列）
</script>

<div class="chat-shell">

    {{-- 左：会话/空间常驻列（三栏第二栏；最左 workspace 侧栏为功能菜单） --}}
    <aside id="sessRail" class="chat-rail">
        <div class="flex h-12 shrink-0 items-center justify-between border-b border-slate-200/70 px-3">
            <span class="text-sm font-semibold text-slate-700">对话</span>
            <button id="railToggleBtn" type="button" title="收起/展开会话列"
                class="rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg>
            </button>
        </div>
        <div class="shrink-0 space-y-1.5 border-b border-slate-200/70 p-2.5">
            <button id="newSpaceBtn" type="button"
                class="flex w-full items-center gap-2 rounded-lg bg-indigo-600 px-3 py-2 text-xs font-medium text-white transition hover:bg-indigo-700">
                ＋ 开聊
            </button>
            <button id="newNamedBtn" type="button"
                class="flex w-full items-center gap-2 rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-2 text-xs font-medium text-indigo-700 transition hover:bg-indigo-100">
                📁 新建空间
            </button>
        </div>
        <div id="sessionList" class="flex-1 space-y-0.5 overflow-y-auto p-2">
            <p class="px-2 py-3 text-center text-xs text-slate-400">加载中…</p>
        </div>
        <div class="shrink-0 border-t border-slate-200/70 px-3 py-2 text-[11px] text-slate-400">
            空间＝长期任务存档 · 开聊＝随手聊
        </div>
    </aside>

    {{-- 右：对话主区（元信息条 + 消息 + 输入） --}}
    <div class="chat-main">
        {{-- ① 元信息条：当前空间名 + 要素 + 操作 --}}
        <div class="chat-meta flex h-12 shrink-0 items-center gap-x-3 border-b border-slate-200 bg-white/80 px-4 backdrop-blur-sm">
            <div class="flex min-w-0 items-center gap-2">
                <button id="railUncollapseBtn" type="button" title="展开会话列"
                    class="hidden rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 5l7 7-7 7M5 5l7 7-7 7"/></svg>
                </button>
                <span id="spaceIcon" class="text-sm">💬</span>
                <span id="spaceTitle" class="max-w-[180px] truncate text-sm font-semibold text-slate-800">新对话</span>
                <button id="renameBtn" type="button" title="起名＝存入空间，长期保留"
                    class="ml-1 hidden items-center gap-1 rounded-md border border-slate-200 bg-white px-2 py-1 text-[11px] text-slate-500 transition hover:border-indigo-300 hover:text-indigo-600 sm:inline-flex">
                    ✎ 存为空间
                </button>
            </div>
            <div class="flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5">主题：<b id="chipTopic" class="text-slate-700">未定</b></span>
                <span class="hidden rounded-full bg-slate-100 px-2.5 py-0.5 md:inline">受众：<b id="chipAud" class="text-slate-700">未定</b></span>
                <span class="hidden rounded-full bg-slate-100 px-2.5 py-0.5 lg:inline">数量：<b id="chipCount" class="text-slate-700">—</b></span>
            </div>
            <div class="ml-auto flex items-center gap-1.5">
                <button id="delSpaceBtn" type="button" title="删除当前对话"
                    class="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs text-slate-500 transition hover:border-red-300 hover:text-red-600">
                    🗑 删除
                </button>
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
                    对话都会自动留着，下次回来接着聊 · 起个名就存入左侧「空间」 · 改主意随时说"全写 / 写第N条 / 做成片 / 配音"
                </p>
            </div>
        </div>
    </div>

</div>

<script>
(function () {
    // ---- 全局错误浮层：任何前端异常/网络失败都可见，绝不"静默无回复" ----
    function globalErr(msg, detail) {
        try {
            let box = document.getElementById('hgtGlobalErr');
            if (!box) {
                box = document.createElement('div');
                box.id = 'hgtGlobalErr';
                box.style.cssText = 'position:fixed;top:72px;right:16px;z-index:9999;max-width:420px;'
                    + 'background:#FEF2F2;border:1px solid #FCA5A5;border-radius:12px;padding:10px 14px;'
                    + 'font-size:12.5px;color:#B91C1C;box-shadow:0 8px 24px rgba(0,0,0,.12);';
                document.body.appendChild(box);
            }
            box.innerHTML = '<b>⚠️ 页面遇到问题：</b>' + String(msg) + (detail ? '<div style="margin-top:4px;color:#7F1D1D;font-size:11px;word-break:break-all;">' + String(detail).slice(0, 300) + '</div>' : '')
                + '<button onclick="this.parentNode.remove()" style="float:right;border:0;background:transparent;color:#B91C1C;cursor:pointer;font-size:14px;">×</button>';
        } catch (_) { /* ignore */ }
    }
    window.addEventListener('error', function (e) {
        if (e && e.message && e.message.indexOf('ResizeObserver') === -1) globalErr(e.message, (e.filename || '') + ':' + (e.lineno || ''));
    });
    window.addEventListener('unhandledrejection', function (e) {
        const r = e && e.reason;
        globalErr((r && r.message) || '异步操作失败', r && r.stack ? r.stack.split('\n')[1] : '');
    });
    const SID_KEY = 'chat_sid_' + '{{ $tenantSlug }}';
    const chatBox = document.getElementById('chatBox');
    const input = document.getElementById('userInput');
    const sendBtn = document.getElementById('sendBtn');
    const listBox = document.getElementById('sessionList');
    const sessRail = document.getElementById('sessRail');
    const railToggleBtn = document.getElementById('railToggleBtn');
    const railUncollapseBtn = document.getElementById('railUncollapseBtn');
    const spaceIconEl = document.getElementById('spaceIcon');
    const spaceTitleEl = document.getElementById('spaceTitle');
    let sid = localStorage.getItem(SID_KEY) || '';
    let lastWritten = null;   // 最近一次 written 成稿（供整批导出）
    let busy = false;
    let lastMsg = '';
    let sessions = [];
    let pendingAsk = [];
    let showAllTemps = false; // "开聊"是否展开全部

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

    // 渲染"下一步建议"卡片：点一下自动发送短指令（走老 _detect_pipeline 或新能力调度）
    function nextCardHtml(list, prefix) {
        if (!list || !list.length) return prefix || '';
        let h = (prefix || '<p class="mt-2 text-xs text-slate-500">下一步：</p>')
              + '<div class="mt-1 flex flex-wrap gap-2">';
        list.forEach(n => {
            const msg = n.cmd || ('用' + (n.name || ''));
            h += '<button type="button" data-msg="' + esc(msg)
                + '" class="act-msg rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 transition hover:bg-indigo-100">'
                + esc(n.icon || '▶️') + ' ' + esc(n.name || msg) + '</button>';
        });
        h += '</div>';
        return h;
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
        const samples = [
            { icon: '🎯', txt: '给准备注册公司的小老板，拆 3 个「注册资本该写多少」的角度' },
            { icon: '📕', txt: '把「个人卡收货款被查」这个话题，写一条能发小红书的图文正文' },
            { icon: '🔍', txt: '拆解这条爆款为什么火：<随便一条财税口播稿贴进来>' },
            { icon: '📦', txt: '帮我把上面刚写好的 3 篇口播稿，各存成 Word 和 PDF' },
        ];
        let cards = '';
        samples.forEach((s, i) => {
            // 用 div + role=button 而非原生 <button>，避免 button 默认拦截鼠标拖选/双击选词
            cards += '<div role="button" tabindex="0" data-sample="' + i + '" class="sample-card block w-full cursor-pointer rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-left text-sm text-slate-700 transition hover:border-indigo-300 hover:bg-indigo-50/50">'
                + '<span class="mr-1.5">' + s.icon + '</span>' + esc(s.txt) + '</div>';
        });
        appendMsg('ai',
            '<p class="font-medium text-slate-800">你好，我是你的出稿助手 ✦</p>'
            + '<p class="mt-1 text-sm text-slate-500">直接说想做什么，或点下面任意一张卡片照着干：</p>'
            + '<div class="mt-3 grid gap-2">' + cards + '</div>'
            + '<p class="mt-3 rounded-lg bg-indigo-50/60 px-3 py-2 text-[13px] text-slate-600">'
            + '例：「我想做一批创业开公司的口播，给准备注册的小老板看，5 条，讲人话别堆术语，要能挂留资钩子」</p>'
            + '<p class="mt-2 text-xs text-slate-400">我会先和你把<strong>主题、受众、关键要求</strong>对齐 → 拆角度方案 → 你认可后出稿 → 改稿 → 配音 → 出片。聊到一半起个名，这段对话就存入左侧「空间」，下次接着聊不会忘。</p>'
        );
        // 点示例卡 = 自动填入并发送（div+role=button：点击 ≠ 选词，本卡不会拦截拖选/双击选词）
        chatBox.querySelectorAll('.sample-card').forEach((card, i) => {
            card.addEventListener('click', () => {
                const s = samples[i];
                if (s.txt.indexOf('贴进来') > -1) { input.value = ''; input.placeholder = '把爆款文案或链接贴进来，我来拆…'; input.focus(); return; }
                sendUserText(s.txt);
            });
            card.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); card.click(); } });
        });
    }

    async function sendUserText(text) {
        input.value = text;
        await doSend();
    }

    // 兜底渲染：resultBlock 抛错时退化到 JSON 原文，AI 永不沉默
    function safeRender(bubble, data) {
        try {
            bubble.innerHTML = resultBlock(data);
        } catch (err) {
            console.error('resultBlock error:', err, data);
            const json = JSON.stringify(data, null, 0);
            bubble.innerHTML = '<div class="space-y-2"><div class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-[13px] text-amber-700">'
                + '回复内容渲染异常（已退化到原文）：</div>'
                + '<pre class="whitespace-pre-wrap break-words text-[12.5px] text-slate-700">' + esc(json) + '</pre></div>';
        }
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

    /* ===== 成稿导出（网站侧即时生成 docx/pdf/xlsx/md/txt，不依赖 8500） ===== */
    const EXPORT_LABELS = { docx: 'Word', pdf: 'PDF', xlsx: 'Excel', md: 'MD', txt: 'TXT' };
    function exportBar(defaultTitle, pieces, mode, idx) {
        const fmtIcon = { docx: '🅆', pdf: '📄', xlsx: '📊', md: '📝', txt: '📃' };
        const btns = Object.keys(EXPORT_LABELS).map(f => {
            return '<button type="button" data-fmt="' + f + '" data-title="' + esc(defaultTitle || '')
                + '" data-mode="' + mode + '" data-idx="' + (idx === undefined ? '' : idx)
                + '" class="exp-btn rounded border border-slate-300 bg-white px-2 py-1 text-[11px] text-slate-600 transition hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700">'
                + (fmtIcon[f] || '') + ' ' + EXPORT_LABELS[f] + '</button>';
        }).join('');
        return '<div class="flex flex-wrap items-center gap-1.5">'
            + '<span class="text-[11px] text-slate-400">存成文件：</span>' + btns + '</div>';
    }

    async function doExport(fmt, title, pieces, btn) {
        const payload = { format: fmt, title: title || '', pieces: pieces.map(p => ({ title: p.title, script: p.script })) };
        try {
            const old = btn.innerHTML; btn.disabled = true; btn.innerHTML = '生成中…';
            const resp = await fetch('/studio/chat/export', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                body: JSON.stringify(payload)
            });
            if (!resp.ok) {
                let msg = '导出失败';
                try { const j = await resp.json(); msg = j.error || msg; } catch (e) {}
                alert(msg); return;
            }
            const ct = resp.headers.get('Content-Type') || '';
            if (ct.includes('application/json')) {
                // md/txt：后端返回内容，前端存 blob 下载
                const j = await resp.json();
                if (!j.ok) { alert(j.error || '导出失败'); return; }
                const blob = new Blob([j.content], { type: j.format === 'md' ? 'text/markdown;charset=utf-8' : 'text/plain;charset=utf-8' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = j.filename || ('口播稿.' + j.format);
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(() => URL.revokeObjectURL(a.href), 2000);
            } else {
                // 二进制(docx/pdf/xlsx)：直接触发浏览器下载
                const blob = await resp.blob();
                const disp = resp.headers.get('Content-Disposition') || '';
                const m = disp.match(/filename\*?=(?:UTF-8'')?"?([^";]+)/i);
                const fn = m ? decodeURIComponent(m[1]) : ((title || '口播稿') + '.' + fmt);
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = fn;
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(() => URL.revokeObjectURL(a.href), 2000);
            }
        } catch (e) {
            alert('导出失败：' + e.message);
        } finally {
            btn.disabled = false; btn.innerHTML = old;
        }
    }
    function csrfToken() {
        const m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }
    document.addEventListener('click', function (e) {
        const b = e.target.closest('.exp-btn');
        if (!b) return;
        const fmt = b.dataset.fmt, title = b.dataset.title || '';
        let pieces = [];
        if (b.dataset.mode === 'single' && lastWritten && lastWritten.length) {
            // 单篇：用生成时写入的 data-idx 直接定位
            const idx = b.dataset.idx !== '' ? parseInt(b.dataset.idx, 10) : -1;
            if (idx >= 0 && idx < lastWritten.length) {
                pieces = [lastWritten[idx]];
                if (!title) title = lastWritten[idx].title;
            }
        } else if (b.dataset.mode === 'batch' && lastWritten && lastWritten.length) {
            pieces = lastWritten;
        }
        if (!pieces.length) { alert('未找到要导出的内容'); return; }
        doExport(fmt, title, pieces, b);
    });

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
            h.push('</div>');
            h.push(nextCardHtml(r.next, '<p class="mt-2 text-xs text-slate-500">认可就说"就按这个全写"，或指定写某条（如"写第2条"）。点下面的卡片一键走：</p>'));
            return h.join('');
        }
        if (r.stage === 'written') {
            const all = (r.results || []).map((w, i) => ({
                title: w.title || ('口播稿' + (i + 1)), script: w.script || ''
            }));
            lastWritten = all;   // 供整批导出按钮取数
            const h = ['<p class="font-medium text-slate-800">' + (r.revised ? '✅ 已按要求重写' : '✅ 成稿如下（每段为可直接配音的口播稿）') + '：</p><div class="mt-2 space-y-3">'];
            (r.results || []).forEach((w, i) => {
                h.push('<div class="rounded-lg border border-slate-200 bg-white p-3">'
                    + '<div class="flex items-center justify-between gap-2">'
                    + '<p class="font-semibold text-slate-800">' + esc(w.title || ('口播稿' + (i + 1))) + '</p>'
                    + '<button type="button" data-rev="' + i + '" class="revise-btn shrink-0 rounded border border-slate-300 px-2 py-0.5 text-[11px] text-slate-500 transition hover:bg-slate-100">✎ 改这篇</button>'
                    + '</div>'
                    + '<p class="mt-2 whitespace-pre-wrap text-slate-700">' + esc(w.script || '') + '</p>'
                    + '<div class="mt-2 border-t border-slate-100 pt-2">' + exportBar(w.title || ('口播稿' + (i + 1)), [w], 'single', i) + '</div></div>');
            });
            h.push('</div>');
            h.push('<div class="mt-3 rounded-xl border border-indigo-100 bg-indigo-50/50 p-3">'
                + '<div class="flex items-center gap-2"><span class="text-xs font-medium text-indigo-800">📦 整批导出</span>'
                + '<span class="text-[11px] text-slate-500">' + all.length + ' 篇一次存成文件</span></div>'
                + '<div class="mt-2">' + exportBar('', all, 'batch') + '</div></div>');
            h.push(nextCardHtml(r.next, '<p class="mt-2 text-xs text-slate-500">要调整某篇就点「改这篇」；稿子满意了，直接点下面卡片走下一步：</p>'));
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

    // ---------- 左侧会话列（常驻）：空间(存档) / 开聊(随手) ----------
    function renderSessions() {
        if (!sessions.length) {
            listBox.innerHTML = '<p class="px-3 py-6 text-center text-xs text-slate-400">还没有对话<br>点上面「＋ 开聊」开始第一段</p>';
            return;
        }
        // 后端已排序：置顶 → 空间 → 最近更新。这里按「开聊」与「空间」分两段呈现
        const spaces = sessions.filter(x => x.kind === 'space' || x.title);
        const temps  = sessions.filter(x => !(x.kind === 'space' || x.title));
        let html = '';
        function item(x) {
            const active = x.session_id === sid;
            const name = x.title || x.topic || (x.kind === 'space' ? '未命名空间' : '开聊');
            const meta = [];
            if (x.written_count) meta.push(x.written_count + ' 篇稿');
            else if (x.angle_count) meta.push(x.angle_count + ' 个角度');
            meta.push((x.msg_count || 0) + ' 条');
            const ops = '<div class="rail-ops">'
                + '<button type="button" data-act="rename" data-sid="' + esc(x.session_id) + '" title="改名 / 存为空间" class="rounded px-1 text-[11px] text-slate-400 hover:text-indigo-600">✎</button>'
                + '<button type="button" data-act="del" data-sid="' + esc(x.session_id) + '" title="删除" class="rounded px-1 text-[11px] text-slate-400 hover:text-red-500">🗑</button>'
                + '</div>';
            return '<div data-sid="' + esc(x.session_id) + '" class="rail-item ' + (active ? 'active' : '') + '">'
                + '<span class="mt-0.5 shrink-0 text-xs leading-none">' + (x.title ? '📁' : '💬') + '</span>'
                + '<div class="min-w-0 flex-1">'
                + '<p class="rail-name truncate">' + esc(name) + '</p>'
                + '<p class="rail-sub mt-0.5 truncate">' + esc(meta.join(' · ')) + ' · ' + esc(fmtTime(x.updated_at)) + '</p>'
                + '</div>' + ops + '</div>';
        }
        if (spaces.length) {
            html += '<p class="rail-group px-2 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wider text-slate-400">📁 空间</p>'
                  + spaces.map(item).join('');
        }
        if (temps.length) {
            const visible = showAllTemps ? temps : temps.slice(0, 5);
            html += '<p class="rail-group px-2 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-wider text-slate-400">💬 开聊</p>'
                  + visible.map(item).join('');
            if (temps.length > 5) {
                html += '<button id="moreTempsBtn" type="button" class="mt-1 w-full rounded-md px-2 py-1 text-center text-[11px] text-slate-400 transition hover:bg-slate-100 hover:text-indigo-600">'
                      + (showAllTemps ? '收起开聊' : '查看全部 ' + temps.length + ' 段开聊') + '</button>';
            }
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
        spaceTitleEl.textContent = title || '新对话';
        spaceIconEl.textContent = title ? '📁' : '💬';
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
        if (!sid) { alert('先发一条消息，或点「＋ 开聊」开始对话。'); return; }
        const cur = spaceTitleEl.textContent;
        const t = prompt('给这段对话起个名字，存入左侧「空间」长期保留：', cur === '新对话' ? '' : cur);
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
        const target = sessions.find(x => x.session_id === id);
        const isSpace = !!(target && (target.title || target.kind === 'space'));
        const name = (target && (target.title || target.topic)) || '这段对话';
        const msg = isSpace
            ? '确定删除「' + name + '」这个空间？\n\n空间里的全部对话记录会一并删除，且不可恢复。\n（已生成的视频/图片/文件不受影响，仍在素材库中）'
            : '确定删除「' + name + '」？\n\n这段开聊的对话记录会删除，且不可恢复。';
        if (!confirm(msg)) return;
        try {
            await api('/studio/chat/session/delete', {
                method: 'POST', body: JSON.stringify({ session_id: id }),
            });
            if (id === sid) { setActive('', ''); showIntro(); }
            await loadSessions();
        } catch (e) { alert('删除失败：' + e.message); }
    }

    // 会话列 折叠/展开（常驻列，可收起让对话更宽）
    function setRailCollapsed(collapsed) {
        sessRail.classList.toggle('collapsed', collapsed);
        if (railUncollapseBtn) railUncollapseBtn.style.display = collapsed ? 'inline-flex' : 'none';
        try { localStorage.setItem('chat_rail_collapsed', collapsed ? '1' : '0'); } catch (_) {}
    }
    if (railToggleBtn) railToggleBtn.addEventListener('click', () => setRailCollapsed(!sessRail.classList.contains('collapsed')));
    if (railUncollapseBtn) railUncollapseBtn.addEventListener('click', () => setRailCollapsed(false));
    // 初始状态：用户没手动设过时，按屏宽自适应（≥1536px 默认展开；否则收起，靠对话上方的展开按钮唤出）
    let railCollapsedByUser = null;
    try { railCollapsedByUser = localStorage.getItem('chat_rail_collapsed'); } catch (_) {}
    if (railCollapsedByUser === '1') setRailCollapsed(true);
    else if (railCollapsedByUser === '0') setRailCollapsed(false);
    else setRailCollapsed(window.innerWidth < 1536);
    // 会话列里的事件（行点击 / 改名 / 删除 / 查看全部开聊）
    listBox.addEventListener('click', (e) => {
        if (e.target.closest?.('#moreTempsBtn')) { showAllTemps = !showAllTemps; renderSessions(); return; }
        const btn = e.target.closest?.('[data-act]');
        if (btn) {
            e.stopPropagation();
            if (btn.dataset.act === 'rename') {
                const target = sessions.find(x => x.session_id === btn.dataset.sid);
                const t = prompt('给这段对话起个名字（起名即存入左侧「空间」，长期保留）：', (target && (target.title || target.topic)) || '');
                if (t === null) return;
                api('/studio/chat/session/update', {
                    method: 'POST', body: JSON.stringify({ session_id: btn.dataset.sid, title: t.trim() }),
                }).then(() => { loadSessions(); if (btn.dataset.sid === sid) setActive(sid, t.trim()); });
            } else if (btn.dataset.act === 'del') {
                deleteSession(btn.dataset.sid);
            }
            return;
        }
        const it = e.target.closest?.('.rail-item');
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
            spaceTitleEl.textContent = r.title;
            spaceIconEl.textContent = '📁';
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
        // 秒数计时：长任务（如"全写"5 篇）要 1~4 分钟，让用户知道"还在跑 + 已跑多久"，区分卡死
        let _t0 = Date.now();
        const _tm = setInterval(() => {
            const sec = Math.round((Date.now() - _t0) / 1000);
            thinking.innerHTML = '<span class="text-slate-400">⏳ 正在处理，已 ' + sec + ' 秒'
                + (sec > 25 ? '（长任务可能需 1~4 分钟，请勿关闭页面）' : '') + '…</span>';
        }, 1000);
        // fetch 兜底超时 330s：到点仍未返回视为"服务无响应"，提示用户判断而非一直白转
        const ctl = new AbortController();
        const _tt = setTimeout(() => ctl.abort(), 330000);
        try {
            const data = await api('/studio/chat/send', {
                method: 'POST', body: JSON.stringify({ session_id: sid, message: msg }),
                signal: ctl.signal,
            });
            clearInterval(_tm); clearTimeout(_tt);
            if (data.error) throw new Error(data.error);
            // 异步长任务（B 版）：8500 已返回 async，后台线程在跑，前端轮询 /chat/status
            if (data.stage === 'async') {
                await pollAsyncJob(data.job_id || sid, thinking, msg);
                thinking.remove();
                return;   // finally 会复位 busy
            }
            thinking.remove();
            const reply = appendMsg('ai', '');
            safeRender(reply, data);
            updateMeta(data);
            loadSessions();
            if (data.stage === 'ask' && data.missing && data.missing.length) {
                showQuick(['受众是中小企业老板', '给会计看', '要 5 条', '讲人话别堆术语']);
            } else {
                document.getElementById('quickReplies').classList.remove('flex');
                document.getElementById('quickReplies').classList.add('hidden');
            }
        } catch (e) {
            clearInterval(_tm); clearTimeout(_tt);
            thinking.remove();
            const isAbort = (e && e.name === 'AbortError');
            const errWrap = document.createElement('div');
            if (isAbort) {
                // 330s 超时：分不清"还在跑"还是"卡死"，明说并给 2 个动作，不让用户瞎猜
                errWrap.innerHTML = '<span class="text-amber-600 font-medium">⚠️ 服务超过 5 分钟未返回。</span> '
                    + '这种情况通常是任务仍在后台处理（长出稿/检索会很久），不是一定坏了。'
                    + '<button type="button" id="chatRetryBtn" class="ml-1 mt-1 inline-block rounded border border-indigo-300 bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-700 transition hover:bg-indigo-100">↻ 我再等等重试</button>'
                    + '<span class="text-slate-400">或刷新后到左侧空间点进本会话查看是否已写完。</span>';
            } else {
                errWrap.innerHTML = '<span class="text-red-500">出错了：' + esc(e.message) + '。</span> '
                    + '<button type="button" id="chatRetryBtn" class="ml-1 mt-1 inline-block rounded border border-indigo-300 bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-700 transition hover:bg-indigo-100">↻ 重试</button>';
            }
            const bubble = appendMsg('ai', '');
            bubble.appendChild(errWrap);
            const rb = document.getElementById('chatRetryBtn');
            if (rb) rb.onclick = () => { if (lastMsg) { input.value = lastMsg; doSend(); } };
        } finally {
            clearInterval(_tm); clearTimeout(_tt);
            busy = false; sendBtn.disabled = false; input.focus();
        }
    }

    // 异步长任务轮询（B 版）：8500 后台跑出稿/检索/审查，这里每 3s 查进度，实时显示"正在写第 N/M 篇"。
    function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
    async function pollAsyncJob(jobId, bubble, originMsg) {
        const t0 = Date.now();
        let keep = true;
        while (keep) {
            const sec = Math.round((Date.now() - t0) / 1000);
            let st = null;
            try {
                st = await api('/studio/chat/status/' + encodeURIComponent(jobId));
            } catch (_) {
                if (sec > 300) { keep = false; break; }   // 连查失败 5 分钟才放弃
                await sleep(3000); continue;
            }
            if (st.stage === 'pending') {
                const msg = (st.progress && st.progress.msg) || '正在处理…';
                bubble.innerHTML = '<span class="text-slate-400">⏳ ' + esc(msg) + '　已 ' + sec + ' 秒'
                    + '</span><div class="mt-2 flex gap-1"><div class="h-1 w-12 animate-pulse rounded bg-indigo-400"></div>'
                    + '<div class="h-1 w-8 animate-pulse rounded bg-indigo-300"></div><div class="h-1 w-5 animate-pulse rounded bg-indigo-200"></div></div>';
            } else if (st.stage && ['done', 'written', 'propose', 'search', 'review', 'ask'].includes(st.stage)) {
                safeRender(bubble, st);   // 后台跑完，完整结果渲染（resultBlock 出错时降级为原文 JSON）
                updateMeta(st); loadSessions();
                keep = false; break;
            } else if (st.error || st.stage === 'error') {
                bubble.innerHTML = '<span class="text-red-500">出错了：' + esc(st.error || '任务失败') + '。</span> '
                    + '<button type="button" id="chatRetryBtn" class="ml-1 mt-1 inline-block rounded border border-indigo-300 bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-700 transition hover:bg-indigo-100">↻ 重试</button>';
                const rb = bubble.querySelector('#chatRetryBtn');
                if (rb) rb.onclick = () => { if (lastMsg) { input.value = lastMsg; doSend(); } };
                keep = false; break;
            } else {
                // idle / 未知：尝试从消息流兜底渲染最新 AI 回复
                try {
                    const ms = await api('/studio/chat/messages?session_id=' + encodeURIComponent(jobId));
                    const arr = ms.messages || [];
                    if (arr.length) {
                        const last = arr[arr.length - 1];
                        bubble.innerHTML = resultBlock(last.data && last.data.stage ? last.data : last);
                        loadSessions();
                        keep = false; break;
                    }
                } catch (_) { /* 兜底失败继续轮询 */ }
                if (sec > 330) { keep = false; break; }   // 总超时 5.5 分钟
            }
            await sleep(3000);
        }
        if (keep === false) {
            bubble.innerHTML = '<span class="text-amber-600">长时间未返回结果，可能仍在后台处理。</span> '
                + '<span class="text-slate-400">可刷新页面，到左侧空间点进本会话查看最新状态。</span>';
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
    document.getElementById('newSpaceBtn').onclick = () => { createSession(''); };
    document.getElementById('newNamedBtn').onclick = () => {
        const t = prompt('给这个空间起个名字（如「注册公司引流系列」）：', '');
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
