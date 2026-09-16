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
        overflow-x: hidden;
        padding: 1.5rem 1rem 1.5rem;  /* 左右 1rem 与底部输入区 px-4 对齐，上下边缘严格对齐 */
    }
    .chat-input { flex: 0 0 auto; }
    /* 对话流居中、限宽，与 WorkBuddy 对话观感一致 */
    .chat-bubble-wrap { max-width: 768px; margin: 0 auto; width: 100%; }
    .chat-bubble { max-width: 100%; }
    @media (max-width: 640px)  { .chat-bubble-wrap { max-width: 100%; } }

    /* 每条 AI 消息上方的操作工具栏（复制/赞/踩/朗读/重新生成/分享） */
    .msg-actions { display: flex; gap: 1px; margin-bottom: 5px; opacity: 1; }
    .msg-actions button {
        display: inline-flex; align-items: center; justify-content: center; gap: 3px;
        height: 26px; padding: 0 7px; border: 0; border-radius: 7px; background: transparent;
        color: #94a3b8; cursor: pointer; font-size: 12px; line-height: 1;
        transition: background .12s, color .12s;
    }
    .msg-actions button:hover { background: #f1f5f9; color: #6366f1; }
    .msg-actions button.active { color: #6366f1; background: #eef2ff; }
    .msg-actions svg { height: 15px; width: 15px; }
    .msg-actions .lbl { font-size: 11.5px; }
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
    /* ===== 右侧「产物」面板：对话产出的成稿/视频/图文自动汇聚于此，可随时收起 =====
       字号与配色沿用本页既有档位（12.5px 主名 / 11px 副名 / slate+indigo），保持全站一致 */
    .chat-artifacts {
        flex: 0 0 auto;
        width: 340px;
        min-width: 340px;
        display: flex;
        flex-direction: column;
        background: #ffffff;
        border-left: 1px solid var(--surface-card-border, #e2e8f0);
        transition: width .18s ease, min-width .18s ease;
    }
    .chat-artifacts.collapsed { width: 0; min-width: 0; border-left: none; overflow: hidden; }
    .af-item {
        display: flex; align-items: flex-start; gap: 8px;
        cursor: pointer; border-radius: 8px; padding: 7px 8px;
        border: 1px solid transparent; transition: background .12s, border-color .12s;
    }
    .af-item:hover { background: #f8fafc; }
    .af-item.active { background: #eef2ff; border-color: #c7d2fe; }
    .af-item .af-name { flex: 1; min-width: 0; font-size: 12.5px; font-weight: 500; color: #334155; }
    .af-item.active .af-name { color: #4338ca; }
    .af-item .af-sub { font-size: 11px; color: #94a3b8; }
    .af-item.active .af-sub { color: #6366f1; }
    .af-dot {
        position: absolute; top: -3px; right: -3px;
        height: 7px; width: 7px; border-radius: 9999px; background: #ef4444;
    }
    @media (max-width: 1440px) { .chat-artifacts { width: 300px; min-width: 300px; } }
    @media (max-width: 1180px) { .chat-artifacts { display: none; } }
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
            <button id="newChatBtn" type="button"
                class="flex w-full items-center gap-2 rounded-lg bg-indigo-600 px-3 py-2 text-xs font-medium text-white transition hover:bg-indigo-700">
                💬 新建开聊
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
        {{-- ① 顶部标题条：WorkBuddy 风格，只保留空间名 + 删除，信息 chips 收入对话内 --}}
        <div class="chat-meta flex h-12 shrink-0 items-center justify-between border-b border-slate-200 bg-white/90 px-4 backdrop-blur-sm">
            <div class="flex min-w-0 items-center gap-2">
                <button id="railUncollapseBtn" type="button" title="展开会话列"
                    class="hidden rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 5l7 7-7 7M5 5l7 7-7 7"/></svg>
                </button>
                <span id="spaceIcon" class="text-sm">💬</span>
                <span id="spaceTitle" class="max-w-[260px] truncate text-sm font-semibold text-slate-800">新对话</span>
                <button id="renameBtn" type="button" title="起名＝存入空间，长期保留"
                    class="ml-1 hidden items-center gap-1 rounded-md border border-slate-200 bg-white px-2 py-1 text-[11px] text-slate-500 transition hover:border-indigo-300 hover:text-indigo-600 sm:inline-flex">
                    ✎ 改名
                </button>
            </div>
            <div class="flex items-center gap-1.5">
                <button id="afOpenBtn" type="button" title="显示产物面板"
                    class="relative rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-indigo-600">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5.5A1.5 1.5 0 015.5 4h13A1.5 1.5 0 0120 5.5v13a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 014 18.5v-13zM9.5 4v16"/></svg>
                    <span id="afDot" class="af-dot hidden"></span>
                </button>
                <button id="delSpaceBtn" type="button" title="删除当前对话"
                    class="rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-red-600">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
            </div>
        </div>

        {{-- ② 对话滚动区 --}}
        <div id="chatBox" class="chat-scroll space-y-4 bg-[var(--surface-page)]">
        </div>

        {{-- ③ 输入区：始终可见，固定底部 --}}
        <div class="chat-input border-t border-slate-200 bg-white px-4 py-3">
            <div class="chat-bubble-wrap">
                {{-- 固定操作栏：复制/赞/踩/朗读/重新生成/分享，对最新一条 AI 回复生效 --}}
                <div id="msgToolbar" class="mb-1.5 flex items-center gap-1 pl-1"></div>
                <div id="quickReplies" class="mb-2 hidden flex-wrap gap-1.5"></div>
                {{-- 输入框：WorkBuddy 同款高个圆角框——文字区在上占满，按钮在框内右下角 --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm focus-within:border-indigo-300">
                    <textarea id="userInput" rows="3" placeholder="说出你想做什么——AI 帮你拆角度 → 出稿 → 改稿 → 配音 → 出片，一句话驱动整条生产线。"
                        class="block w-full resize-none rounded-lg border-0 bg-transparent px-1.5 py-1 text-sm leading-relaxed text-slate-700 outline-none placeholder:text-slate-400"
                        style="min-height:84px;max-height:220px"></textarea>
                    <div class="mt-1 flex items-center justify-between gap-2">
                        <button id="micBtn" type="button" title="语音输入：点一下开始，说完再点一次结束"
                            class="shrink-0 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50">
                            🎤 语音
                        </button>
                        <div class="flex items-center gap-2">
                            <button id="planWeekBtn" type="button"
                                class="shrink-0 rounded-lg border border-indigo-300 bg-white px-3 py-2 text-sm font-medium text-indigo-700 transition hover:bg-indigo-50">
                                📅 规划
                            </button>
                            <button id="sendBtn" type="button"
                                class="shrink-0 rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:opacity-50">
                                发送
                            </button>
                        </div>
                    </div>
                    <span id="micStatus" class="mt-1 hidden text-[11px] text-rose-500"></span>
                </div>
                <p class="mt-1.5 text-center text-[11px] text-slate-400">
                    内容由 AI 生成，请核实重要信息
                </p>
            </div>
        </div>
    </div>

    {{-- 右：产物面板（成稿/视频/图文自动汇聚，可随时收起；首次有产物时自动展开） --}}
    <aside id="artifactRail" class="chat-artifacts collapsed">
        <div class="flex h-12 shrink-0 items-center justify-between border-b border-slate-200/70 px-3">
            <div class="flex min-w-0 items-center gap-1.5">
                <span class="text-sm font-semibold text-slate-700">产物</span>
                <span id="afCount" class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-500">0</span>
            </div>
            <button id="afCloseBtn" type="button" title="收起产物面板"
                class="rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 5l7 7-7 7M5 5l7 7-7 7"/></svg>
            </button>
        </div>
        <div id="afList" class="flex-1 space-y-0.5 overflow-y-auto p-2">
            <p class="px-3 py-8 text-center text-xs leading-relaxed text-slate-400">这次对话产出的成稿、视频、图文会自动出现在这里。</p>
        </div>
        <div id="afPreview" class="hidden max-h-[46%] shrink-0 overflow-y-auto border-t border-slate-200/70 bg-slate-50/60 p-3"></div>
    </aside>

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

    // 输入框随内容撑高（最高 220px）
    function autoGrow(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 220) + 'px';
    }

    // 语音输入：点麦克风开始 → 实时上屏 → 再点结束 → 自动整理表述
    function setupMic() {
        const micBtn = document.getElementById('micBtn');
        const micStatus = document.getElementById('micStatus');
        const input = document.getElementById('userInput');
        const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) {
            micBtn.disabled = true;
            micBtn.title = '当前浏览器不支持语音输入，请用 Chrome / Edge 桌面版';
            micBtn.classList.add('opacity-50', 'cursor-not-allowed');
            return;
        }
        const rec = new SR();
        rec.lang = 'zh-CN';
        rec.interimResults = true;
        rec.continuous = true;
        let finalText = '';
        let manualStop = false;  // 只有手动点麦克风才算结束；浏览器静音自动断开时自动续录

        rec.onresult = (e) => {
            let interim = '';
            for (let i = e.resultIndex; i < e.results.length; i++) {
                const t = e.results[i][0].transcript;
                if (e.results[i].isFinal) finalText += t;
                else interim += t;
            }
            input.value = (finalText + interim).trim();
            autoGrow(input);
        };
        rec.onerror = (e) => {
            if (e.error === 'not-allowed') {
                manualStop = true;  // 权限拒绝：终止会话
                micStatus.classList.add('hidden');
                micBtn.classList.remove('bg-rose-50', 'border-rose-300', 'text-rose-600');
                micBtn.dataset.recording = '';
                alert('麦克风权限被拒绝，请在浏览器地址栏允许麦克风后重试。');
            }
            // no-speech / network / aborted 等瞬时错误：不收尾，由 onend 自动续录
        };
        rec.onend = () => {
            if (!micBtn.dataset.recording) return;  // 已收尾
            if (manualStop) { finishVoice(); return; }
            // 浏览器因停顿/静音自动断开：立即无缝续录，已识别文字保留
            try { rec.start(); }
            catch (_) { setTimeout(() => { try { rec.start(); } catch (_2) { finishVoice(); } }, 400); }
        };
        function finishVoice() {
            micBtn.dataset.recording = '';
            micBtn.classList.remove('bg-rose-50', 'border-rose-300', 'text-rose-600');
            micStatus.textContent = '● 正在整理表述…';
            const raw = input.value.trim();
            if (!raw) { micStatus.classList.add('hidden'); return; }
            micStatus.classList.remove('hidden');
            polishAndFill(raw);
        }

        micBtn.addEventListener('click', () => {
            if (micBtn.dataset.recording) {
                manualStop = true;
                try { rec.stop(); } catch (_) { finishVoice(); }  // 手动结束 → 触发 onend 收尾整理
            } else {
                manualStop = false;
                finalText = input.value.trim() ? input.value.trim() + ' ' : '';
                micBtn.dataset.recording = '1';
                micBtn.classList.add('bg-rose-50', 'border-rose-300', 'text-rose-600');
                micStatus.textContent = '● 正在聆听…（中间停顿不断句，点麦克风结束）';
                micStatus.classList.remove('hidden');
                try { rec.start(); } catch (_) { /* 已在录音则忽略 */ }
            }
        });

        async function polishAndFill(raw) {
            try {
                const d = await api('/studio/chat/polish', { text: raw });
                if (d && d.polished) {
                    input.value = d.polished;
                    lastPolishResearch = (d.research || '').trim();
                    if (lastPolishResearch) {
                        micStatus.textContent = '🔎 已联网核对参考，可直接发送（发送时一并参考）';
                        micStatus.classList.remove('hidden');
                        return;  // 保持提示可见，等用户发送
                    }
                }
            } catch (err) {
                /* 整理失败：保留原话，用户可手动改 */
            } finally {
                autoGrow(input);
                if (!lastPolishResearch) micStatus.classList.add('hidden');
            }
        }
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
    let _bulkRender = false;   // 批量回放标志：期间不逐条贴底，最后一次性贴
    // 智能滚动：新消息/状态更新时自动贴到最新（底部）。用户主动上翻历史(距底>120px)则不打扰；回到底附近自动恢复跟随。
    function stickToBottom(force) {
        const nearBottom = chatBox.scrollHeight - chatBox.scrollTop - chatBox.clientHeight < 120;
        if (force && !_bulkRender || nearBottom && !_bulkRender) {
            chatBox.scrollTop = chatBox.scrollHeight;
        }
    }
    chatBox.addEventListener('scroll', function () {
        // 用户手动回到底部附近时，若此刻有"正在生成"标记则贴底（避免被卡在半空）
        if (chatBox.scrollHeight - chatBox.scrollTop - chatBox.clientHeight < 30) {
            chatBox.scrollTop = chatBox.scrollHeight;
        }
    }, { passive: true });

    // 最新一条可操作的 AI 回复气泡（输入框上方的固定工具栏对它生效）
    let _lastAiBubble = null;
    let lastPolishResearch = '';  // 联网参考（/polish 返回），供 doSend 清理提示用，须挂顶层作用域

    function appendMsg(role, html, opts) {
        opts = opts || {};
        const wrap = document.createElement('div');
        const col = document.createElement('div');
        const av = document.createElement('div');
        const bubble = document.createElement('div');
        bubble.className = 'chat-bubble rounded-2xl px-4 py-3 text-sm leading-relaxed whitespace-pre-wrap ';
        if (role === 'user') {
            // 用户消息：头像在右、气泡靠右自动宽（与输入框右边缘对齐）
            wrap.className = 'chat-bubble-wrap flex items-start gap-3 flex-row-reverse';
            av.className = 'mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-medium bg-indigo-100 text-indigo-700';
            av.textContent = '我';
            col.className = 'bubble-col flex min-w-0 flex-1 flex-col items-end';
            bubble.className += 'rounded-tr-sm bg-slate-100 text-slate-800 border border-slate-200';
        } else {
            // AI 消息：头像内联在消息头部行，气泡占满整列——与底部输入框同宽、左右边缘对齐
            wrap.className = 'chat-bubble-wrap';
            av.className = 'flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold bg-white text-indigo-600 ring-2 ring-indigo-100';
            av.textContent = '阿';
            const head = document.createElement('div');
            head.className = 'mb-1.5 flex items-center gap-2';
            head.appendChild(av);
            col.className = 'bubble-col flex min-w-0 flex-col';
            col.appendChild(head);
            bubble.className += 'w-full bg-white text-slate-800 border border-slate-200 shadow-sm';
        }
        bubble.innerHTML = html;
        col.appendChild(bubble);
        wrap.appendChild(col);
        if (role === 'user') wrap.appendChild(av);
        chatBox.appendChild(wrap);
        // 记录最新一条可操作的 AI 回复（固定工具栏在输入框上方，对它生效）
        if (role === 'ai' && !opts.noTools) _lastAiBubble = bubble;
        stickToBottom(true);
        return bubble;
    }

    // ==================== 每条 AI 消息操作栏（复制/赞/踩/朗读/重新生成/分享） ====================
    const MSG_ICON = {
        copy: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 012-2h10"/></svg>',
        like: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 11v9H4a1 1 0 01-1-1v-7a1 1 0 011-1h3zm0 0l4-7a2 2 0 013.7 1.3L13.6 10H19a2 2 0 012 2v1a4 4 0 01-4 4h-5l-3 3v-3H7"/></svg>',
        dislike: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 13V4h3a1 1 0 011 1v7a1 1 0 01-1 1h-3zm0 0l-4 7a2 2 0 01-3.7-1.3L10.4 14H5a2 2 0 01-2-2v-1a4 4 0 014-4h5l3-3v3h0z" transform="rotate(180 12 12)"/></svg>',
        read: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 5L6 9H2v6h4l5 4V5z"/><path d="M15.5 8.5a5 5 0 010 7M18.5 5.5a9 9 0 010 13"/></svg>',
        regen: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 12a9 9 0 0115.5-6.2L21 8M21 3v5h-5M21 12a9 9 0 01-15.5 6.2L3 16M3 21v-5h5"/></svg>',
        share: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>'
    };
    function buildMsgActions() {
        const acts = document.createElement('div');
        acts.className = 'msg-actions';
        const items = [
            { a: 'copy', t: '复制', show: MSG_ICON.copy },
            { a: 'like', t: '赞', show: MSG_ICON.like },
            { a: 'dislike', t: '踩', show: MSG_ICON.dislike },
            { a: 'read', t: '朗读', show: MSG_ICON.read },
            { a: 'regen', t: '重新生成', show: MSG_ICON.regen },
            { a: 'share', t: '分享', show: MSG_ICON.share }
        ];
        items.forEach(function (it) {
            const b = document.createElement('button');
            b.type = 'button'; b.title = it.t; b.dataset.action = it.a;
            b.innerHTML = it.show + '<span class="lbl">' + it.t + '</span>';
            b.addEventListener('click', function () { onMsgAction(it.a, b); });
            acts.appendChild(b);
        });
        return acts;
    }
    // 固定工具栏：常驻在输入对话框上方，始终可见，对最新一条 AI 回复生效
    (function mountToolbar() {
        const tb = document.getElementById('msgToolbar');
        if (tb) tb.appendChild(buildMsgActions());
    })();
    function onMsgAction(action, btn) {
        // 工具栏固定在输入框上方：作用于最新一条 AI 回复
        const wrapEl = btn.closest('.chat-bubble-wrap');
        const inMsg = wrapEl ? wrapEl.querySelector('.chat-bubble') : null;
        const bubble = inMsg || _lastAiBubble;
        if (!bubble) { toast('还没有可操作的 AI 回复'); return; }
        const text = bubble.innerText || '';
        if (action === 'copy') {
            copyText(text);
        } else if (action === 'like') {
            btn.classList.toggle('active');
            const sib = btn.parentElement.querySelector('[data-action="dislike"]');
            if (sib) sib.classList.remove('active');
            toast(btn.classList.contains('active') ? '已点赞' : '已取消赞');
        } else if (action === 'dislike') {
            btn.classList.toggle('active');
            const sib = btn.parentElement.querySelector('[data-action="like"]');
            if (sib) sib.classList.remove('active');
            toast(btn.classList.contains('active') ? '已点踩' : '已取消踩');
        } else if (action === 'read') {
            toggleRead(text, btn);
        } else if (action === 'regen') {
            const u = findPrevUserText(wrapEl);
            if (u) { input.value = u; toast('正在重新生成…'); doSend(); }
            else toast('没找到上一句输入，无法重新生成');
        } else if (action === 'share') {
            if (navigator.share) {
                navigator.share({ title: '慧根堂出稿助手', text: text }).catch(function () {});
            } else {
                copyText(text);
                toast('已复制内容，可粘贴分享');
            }
        }
    }
    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { toast('已复制'); })
                .catch(function () { fallbackCopy(text); });
        } else { fallbackCopy(text); }
    }
    function fallbackCopy(text) {
        try {
            const ta = document.createElement('textarea');
            ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            document.execCommand('copy'); document.body.removeChild(ta);
            toast('已复制');
        } catch (e) { toast('复制失败，请手动选择'); }
    }
    function findPrevUserText(fromWrap) {
        const wraps = Array.prototype.slice.call(chatBox.querySelectorAll('.chat-bubble-wrap'));
        for (let i = wraps.length - 1; i >= 0; i--) {
            const w = wraps[i];
            if (w === fromWrap) continue;
            if (w.className.indexOf('flex-row-reverse') === -1) continue; // 只认用户消息
            const ub = w.querySelector('.chat-bubble');
            const t = ub ? (ub.innerText || '').trim() : '';
            if (t) return t;
        }
        return '';
    }
    let _readingBtn = null;
    function toggleRead(text, btn) {
        if (!('speechSynthesis' in window)) { toast('当前浏览器不支持朗读'); return; }
        if (_readingBtn === btn) {
            window.speechSynthesis.cancel();
            btn.classList.remove('active'); _readingBtn = null; return;
        }
        window.speechSynthesis.cancel();
        const u = new SpeechSynthesisUtterance(text);
        u.lang = 'zh-CN'; u.rate = 1; u.pitch = 1;
        const voices = window.speechSynthesis.getVoices() || [];
        const zh = voices.find(function (v) { return /zh|chinese/i.test((v.lang || '') + (v.name || '')); });
        if (zh) u.voice = zh;
        u.onend = function () { btn.classList.remove('active'); _readingBtn = null; };
        u.onerror = function () { btn.classList.remove('active'); _readingBtn = null; };
        window.speechSynthesis.speak(u);
        _readingBtn = btn; btn.classList.add('active');
        toast('开始朗读');
    }
    function toast(msg) {
        let t = document.getElementById('hgtToast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'hgtToast';
            t.style.cssText = 'position:fixed;left:50%;bottom:92px;transform:translateX(-50%);z-index:99999;'
                + 'background:rgba(15,23,42,.92);color:#fff;font-size:12.5px;padding:7px 14px;border-radius:999px;'
                + 'box-shadow:0 6px 20px rgba(0,0,0,.22);transition:opacity .2s;pointer-events:none;';
            document.body.appendChild(t);
        }
        t.textContent = msg; t.style.opacity = '1';
        clearTimeout(t._timer);
        t._timer = setTimeout(function () { t.style.opacity = '0'; }, 1600);
    }

    // ==================== 右侧「产物」面板 ====================
    // 对话产出的成稿/视频/图文统一在此汇聚：结果一出就自动显示在右侧，也能人工收起。
    // 实时对话与回看历史（openSession 回放 resultBlock）走同一处登记 → 保证"随时都在"。
    let ARTIFACTS = [];          // {key,type,title,sub,status,url,text}
    let afActive = null;         // 当前预览的产物 key
    let afClosedByUser = false;  // 用户手动收起过 → 不再自动弹开，只在按钮上点红点

    function setArtifactsOpen(open) {
        const rail = document.getElementById('artifactRail');
        if (!rail) return;
        rail.classList.toggle('collapsed', !open);
        const b = document.getElementById('afOpenBtn');
        if (b) b.classList.toggle('hidden', open);
        const dot = document.getElementById('afDot');
        if (dot && open) dot.classList.add('hidden');
    }

    // 登记/更新一条产物（同 key 覆盖，避免重复）
    function pushArtifact(a) {
        if (!a || !a.key) return;
        const i = ARTIFACTS.findIndex(x => x.key === a.key);
        if (i >= 0) {
            ARTIFACTS[i] = Object.assign({}, ARTIFACTS[i], a);
        } else {
            a.ts = a.ts || Date.now();
            ARTIFACTS.unshift(a);
            if (!afClosedByUser) setArtifactsOpen(true);
            else {
                const dot = document.getElementById('afDot');
                if (dot) dot.classList.remove('hidden');
            }
        }
        if (!afActive) afActive = a.key;
        renderArtifacts();
    }

    const _AF_ICON = { script: '📄', video: '🎬', image: '📕', audio: '🎧', file: '📎' };
    const _AF_LABEL = { script: '口播稿', video: '视频', image: '图文', audio: '配音', file: '文件' };

    function renderArtifacts() {
        const list = document.getElementById('afList');
        const cnt = document.getElementById('afCount');
        if (cnt) cnt.textContent = String(ARTIFACTS.length);
        if (!list) return;
        if (!ARTIFACTS.length) {
            list.innerHTML = '<p class="px-3 py-8 text-center text-xs leading-relaxed text-slate-400">这次对话产出的成稿、视频、图文会自动出现在这里。</p>';
            return;
        }
        list.innerHTML = ARTIFACTS.map(a => {
            const active = a.key === afActive ? ' active' : '';
            return '<div class="af-item' + active + '" data-af="' + esc(a.key) + '" title="' + esc(a.title || '') + '">'
                + '<span class="mt-0.5 text-sm leading-none">' + (_AF_ICON[a.type] || '📎') + '</span>'
                + '<div class="min-w-0 flex-1">'
                +   '<p class="af-name truncate">' + esc(a.title || '未命名') + '</p>'
                +   '<p class="af-sub truncate">' + esc(a.sub || _AF_LABEL[a.type] || '') + '</p>'
                + '</div></div>';
        }).join('');
        list.querySelectorAll('.af-item').forEach(el => {
            el.addEventListener('click', () => { afActive = el.dataset.af; renderArtifacts(); renderAfPreview(); });
        });
        renderAfPreview();
    }

    function renderAfPreview() {
        const box = document.getElementById('afPreview');
        if (!box) return;
        const a = ARTIFACTS.find(x => x.key === afActive);
        if (!a) { box.classList.add('hidden'); box.innerHTML = ''; return; }
        box.classList.remove('hidden');
        let h = '<div class="mb-2 flex items-center justify-between gap-2">'
            + '<p class="truncate text-[12.5px] font-medium text-slate-700">' + esc(a.title || '') + '</p>'
            + '<button type="button" id="afPvClose" title="收起预览" class="shrink-0 rounded p-0.5 text-slate-400 transition hover:bg-slate-200 hover:text-slate-600">'
            + '<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button></div>';
        if (a.type === 'video' && a.url) {
            h += '<div class="overflow-hidden rounded-lg border border-slate-200 bg-black">'
               + '<video controls preload="metadata" class="block max-h-[300px] w-full" src="' + esc(a.url) + '"></video></div>'
               + '<div class="mt-2 flex flex-wrap gap-2">'
               + '<a href="' + esc(a.url) + '" download="' + esc(a.title || 'video') + '.mp4" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-medium text-slate-700 transition hover:bg-slate-50">⬇ 下载</a>'
               + '</div>';
        } else if (a.type === 'script' || a.type === 'file') {
            h += '<div class="max-h-[260px] overflow-y-auto whitespace-pre-wrap rounded-lg border border-slate-200 bg-white p-2.5 text-[12.5px] leading-relaxed text-slate-700">' + esc(a.text || '') + '</div>';
        } else if (a.type === 'image' && a.url) {
            h += '<img src="' + esc(a.url) + '" alt="' + esc(a.title || '') + '" class="w-full rounded-lg border border-slate-200">';
        } else {
            h += '<p class="text-xs text-slate-400">这类产物暂不支持预览，可直接在对话里操作。</p>';
        }
        box.innerHTML = h;
        const c = document.getElementById('afPvClose');
        if (c) c.addEventListener('click', () => { afActive = null; renderArtifacts(); renderAfPreview(); });
    }

    // 从编排器返回数据里抽取产物（resultBlock 每渲染一条 AI 消息都会经过）
    function collectArtifacts(r) {
        if (!r || typeof r !== 'object') return;
        if (r.stage === 'written' && Array.isArray(r.results)) {
            r.results.forEach((w, i) => {
                if (!w || !w.script) return;
                const t = w.title || ('口播稿' + (i + 1));
                pushArtifact({
                    key: 'script:' + t + ':' + String(w.script).slice(0, 24),
                    type: 'script', title: t,
                    sub: '口播稿 · ' + String(w.script).length + ' 字',
                    text: String(w.script),
                });
            });
        }
        const jid = (r.data && r.data.job_id) || r.job_id;
        if (jid) {
            const st = String((r.data && r.data.status) || r.status || '').toLowerCase();
            // 历史回放时后端未必带状态：能存档说明任务早已结束，按完成处理
            // （否则回看旧会话时成片永远卡在"渲染中"，拿不到播放地址）
            const done = (st === 'done' || st === '');
            pushArtifact({
                key: 'job:' + jid, type: 'video',
                title: (r.title || '成片') + '（' + String(jid).slice(0, 6) + '）',
                sub: done ? '视频 · 渲染完成' : '视频 · 渲染中…',
                url: done ? ('/studio/scroll/download/' + encodeURIComponent(jid)) : null,
                status: st || 'running',
            });
        }
    }

    // 这些能力参数齐了就直接跑，不让用户多点一次「开始执行」
    const _AUTO_CAPS = ['topic', 'rewrite', 'xhs', 'hotspot'];

    // 每次新开聊随机展示一套引导语和示例，避免固定话术疲劳
    const _INTRO_VARIANTS = [
        {
            title: '你好，我是你的出稿助手 ✦',
            subtitle: '直接说想做什么，或点下面任意一张卡片照着干：',
            samples: [
                { icon: '🎯', txt: '给准备注册公司的小老板，拆 3 个「注册资本该写多少」的角度' },
                { icon: '📕', txt: '把「个人卡收货款被查」这个话题，写一条能发小红书的图文正文' },
                { icon: '🔍', txt: '拆解这条爆款为什么火：<随便一条财税口播稿贴进来>' },
                { icon: '📦', txt: '帮我把上面刚写好的 3 篇口播稿，各存成 Word 和 PDF' },
            ],
            hint: '例：「我想做一批创业开公司的口播，给准备注册的小老板看，5 条，讲人话别堆术语，要能挂留资钩子」',
            footer: '我会先和你把<strong>主题、受众、关键要求</strong>对齐 → 拆角度方案 → 你认可后出稿 → 改稿 → 配音 → 出片。聊到一半起个名，这段对话就存入左侧「空间」，下次接着聊不会忘。',
        },
        {
            title: '今天想写什么？一句话就能开干。',
            subtitle: '给我主题和受众，我帮你拆角度、出稿、改稿、配音一条龙：',
            samples: [
                { icon: '💰', txt: '写 5 条「公转私风险」口播稿，给昆山的小老板看，开头要勾人' },
                { icon: '🏗️', txt: '给建筑行业老板拆 4 个「挂靠与分包」的财税痛点角度' },
                { icon: '📊', txt: '把这份稽查案例改成 1500 字的公众号长文，结尾留资' },
                { icon: '🎙️', txt: '为上面写好的口播稿生成配音音频，并出一版带字幕的视频' },
            ],
            hint: '例：「帮我写 3 条关于暂估成本的口播，面向年营收 500 万左右的制造业老板，口语化，每条 300 字左右」',
            footer: '流程是：对齐需求 → 拆角度 → 出稿 → 改写避违禁词 → 可选配音/出片。随时说「换个角度」我会重拆。',
        },
        {
            title: '又来搞内容了？先抓钩子再成稿。',
            subtitle: '选一个你想试的方向，或者直接把选题甩给我：',
            samples: [
                { icon: '🚨', txt: '用「滞纳金改成迟纳金」这个热点，拆 3 个涉税风险角度' },
                { icon: '🧾', txt: '把「虚开发票」这个选题写成 3 条不同开头的爆款口播' },
                { icon: '📱', txt: '给「个人卡流水过大」写一版适合抖音的 30 秒口播稿' },
                { icon: '📚', txt: '把刚才聊好的选题直接生成 Word 文档，我审完再出音频' },
            ],
            hint: '例：「我想做财税顾问引流内容，给有历史遗留账务问题的老板看，先拆 5 个钩子角度」',
            footer: '爆款秘诀：第一句埋冲突或悬念。我会先给你角度方案，你点头再写正文，避免返工。',
        },
        {
            title: '想好选题了吗？没想就一起碰。',
            subtitle: '下面卡片只是例子，点一下就能改着用：',
            samples: [
                { icon: '🧩', txt: '帮我规划本周 7 条朋友圈/公众号内容，面向中小企业老板' },
                { icon: '⚠️', txt: '围绕「暂估成本跨年」写 4 条口播，风格冷静讲人话' },
                { icon: '🔄', txt: '把这条爆款财税文案改写成老张的口吻，再生成配图文案' },
                { icon: '🎬', txt: '以上稿子确认后，直接选模特、出数字人口播视频' },
            ],
            hint: '例：「给我规划下周老张讲财税的 7 条选题，要覆盖注册公司、发票、公转私、顾问引流」',
            footer: '我可以帮你做选题规划、拆角度、写稿、改写、存文档、配音、出片，全程一句一句推进。',
        },
        {
            title: '出稿助手已上线，今天拍什么？',
            subtitle: '点卡片快速开始，或直接输入你的选题：',
            samples: [
                { icon: '🎯', txt: '给「注册资本实缴」这个话题拆 5 个老板听得懂的切入角度' },
                { icon: '✍️', txt: '写一条小红书图文，讲「为什么你的个人卡会被税务盯上」' },
                { icon: '📈', txt: '拆解这条爆款口播为什么火：<把文案或链接贴进来>' },
                { icon: '🎁', txt: '把上面确认好的 3 篇稿子，一次性生成 Word、PDF 和音频' },
            ],
            hint: '例：「我要做财税顾问的引流视频，面向年产值 1000 万左右的老板，讲风险点，结尾留咨询钩子」',
            footer: '从选题到出片一个对话完成。中途想换方向、改字数、换语气，直接说就行。',
        },
        {
            title: 'Ready？把选题丢给我。',
            subtitle: '我可以拆角度、写稿、改稿、配音、出片，也可以先帮你规划一周选题：',
            samples: [
                { icon: '📅', txt: '帮我做本周公众号选题规划，每天 1 篇，面向中小企业主' },
                { icon: '🚨', txt: '以「税务稽查 6 个高频点」写 3 条口播，语气严肃但有料' },
                { icon: '🔥', txt: '把这条爆款改成我的风格：<贴任意财税口播文案进来>' },
                { icon: '📝', txt: '把「公转私」这个选题写成公众号长文，1500 字左右' },
            ],
            hint: '例：「给建筑号排 7 天选题，每天围绕挂靠、分包、甲供材、异地预缴、农民工社保中的一个」',
            footer: '记住：爆款钩子落在正文开口第一句。我会先给角度方案，确认后再写全文。',
        },
    ];

    function showIntro() {
        chatBox.innerHTML = '';
        const variant = _INTRO_VARIANTS[Math.floor(Math.random() * _INTRO_VARIANTS.length)];
        const samples = variant.samples;
        let cards = '';
        samples.forEach((s, i) => {
            // 用 div + role=button 而非原生 <button>，避免 button 默认拦截鼠标拖选/双击选词
            cards += '<div role="button" tabindex="0" data-sample="' + i + '" class="sample-card block w-full cursor-pointer rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-left text-sm text-slate-700 transition hover:border-indigo-300 hover:bg-indigo-50/50">'
                + '<span class="mr-1.5">' + s.icon + '</span>' + esc(s.txt) + '</div>';
        });
        appendMsg('ai',
            '<p class="font-medium text-slate-800">' + esc(variant.title) + '</p>'
            + '<p class="mt-1 text-sm text-slate-500">' + esc(variant.subtitle) + '</p>'
            + '<div class="mt-3 grid gap-2">' + cards + '</div>'
            + '<p class="mt-3 rounded-lg bg-indigo-50/60 px-3 py-2 text-[13px] text-slate-600">'
            + esc(variant.hint) + '</p>'
            + '<p class="mt-2 text-xs text-slate-400">' + variant.footer + '</p>'
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
        // 顺手登记产物 → 右侧面板随时显示；失败不影响正文渲染
        try { collectArtifacts(r); } catch (_) { /* noop */ }
        if (r.stage === 'busy') {
            return '<p class="text-amber-600">⏳ ' + esc(r.message || '上一条消息还在处理中，请稍候，完成后会自动出现。') + '</p>';
        }
        if (r.stage === 'answer') {
            // 顾问式正面回答（不写稿、不追问受众）
            const h = ['<div class="space-y-2">'];
            if (r.sources && r.sources.length) {
                h.push('<div class="flex flex-wrap gap-1.5">'
                    + r.sources.slice(0, 5).map(x =>
                        '<a href="' + esc(x.url) + '" target="_blank" rel="noopener" '
                        + 'class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[11px] text-indigo-600 transition hover:bg-indigo-50">🔗 '
                        + esc(x.title || x.url).slice(0, 40) + '</a>').join('')
                    + '</div>');
            }
            h.push('<div class="prose prose-sm prose-slate max-w-none whitespace-pre-wrap text-slate-800 leading-relaxed">'
                + esc(r.message || '') + '</div></div>');
            h.push(nextCardHtml(
                [{ id: 'rewrite', name: '把这条做成口播稿', icon: '✍️', cmd: '把上面这条整理成口播稿' },
                 { id: 'xhs', name: '做成小红书图文', icon: '📕', cmd: '把上面这条做成小红书图文' }],
                '<p class="mt-3 text-xs text-slate-400">—— 想把它变成内容，点一下或直接说。</p>'
            ));
            return h.join('');
        }
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
            let h = ['<p>' + esc(r.message || '') + '</p>'];
            if (r.options && r.options.length) {
                h.push('<div class="mt-2 flex flex-wrap gap-2">');
                r.options.forEach(o => {
                    h.push('<button type="button" data-msg="' + esc(o) + '" class="act-msg rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 transition hover:bg-indigo-100">'
                        + esc(String(o)) + '</button>');
                });
                h.push('</div>');
            }
            return h.join('');
        }
        if (r.stage === 'plan') {
            const days = (r.plan && r.plan.days) || [];
            const h = ['<p class="font-medium text-slate-800">📅 我帮你排的下周内容（点任一天直接开写）：</p>',
                       '<div class="mt-2 grid gap-2 sm:grid-cols-2">'];
            days.forEach(d => {
                const topic = (d.topic || '').replace(/\n/g, ' ');
                const msg = '【规划选题】' + topic + '　受众：已注册、正在经营的中小老板';
                h.push('<button type="button" data-msg="' + esc(msg).replace(/<br>/g, ' ')
                    + '" class="act-msg text-left rounded-lg border border-indigo-200 bg-white p-3 transition hover:border-indigo-400 hover:bg-indigo-50">'
                    + '<div class="flex items-center justify-between gap-2"><span class="text-[11px] font-semibold text-indigo-700">'
                    + esc(d.day || '') + ' · ' + esc(d.pillar || '') + '</span>'
                    + '<span class="text-[10px] text-slate-400">' + esc(d.form || '') + '</span></div>'
                    + '<p class="mt-1 text-sm font-medium text-slate-800">' + esc(topic) + '</p>'
                    + (d.why ? '<p class="mt-0.5 text-[11px] text-slate-500">' + esc((d.why || '').replace(/\n/g, ' ')) + '</p>' : '')
                    + '</button>');
            });
            h.push('</div>');
            h.push(nextCardHtml(r.next, '<p class="mt-2 text-xs text-slate-500">也可以直接说"全写这一周"（后续批量出稿），或挑某天细化。</p>'));
            return h.join('');
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
            const all = (r.results || []).filter(w => w.script).map((w, i) => ({
                title: w.title || ('口播稿' + (i + 1)), script: w.script || ''
            }));
            lastWritten = all;   // 供整批导出按钮取数
            const h = ['<p class="font-medium text-slate-800">' + (r.message ? '✅ ' + esc(r.message) : (r.revised ? '✅ 已按要求重写：' : '✅ 成稿如下（每段为可直接配音的口播稿）：')) + '</p><div class="mt-2 space-y-3">'];
            (r.results || []).forEach((w, i) => {
                // widx：批量出稿用后端真实下标（有失败篇时不至于错位）；
                // 单条写稿/历史数据没有 widx 时，只要本篇有成稿就用本地下标兜底，空稿则不渲染按钮。
                const ridx = (w.widx === undefined || w.widx === null) ? (w.script ? i : null) : w.widx;
                const revBtn = (ridx === null) ? ''
                    : '<button type="button" data-rev="' + ridx + '" class="revise-btn shrink-0 rounded border border-slate-300 px-2 py-0.5 text-[11px] text-slate-500 transition hover:bg-slate-100">✎ 改这篇</button>';
                h.push('<div class="rounded-lg border border-slate-200 bg-white p-3">'
                    + '<div class="flex items-center justify-between gap-2">'
                    + '<p class="font-semibold text-slate-800">' + (w.day ? '<span class="mr-1.5 rounded bg-indigo-100 px-1.5 py-0.5 text-[11px] font-normal text-indigo-700">' + esc(w.day) + (w.pillar ? ' · ' + esc(w.pillar) : '') + '</span>' : '') + esc(w.title || ('口播稿' + (i + 1))) + '</p>'
                    + revBtn
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
            // 字段标签表（与 action_ready 共用，避免重复定义）
            const _ASK_LABELS = { dialogue: '口播稿', text: '原文', topic: '主题', industry: '行业', keywords: '关键词',
                count: '数量', platform: '平台', hook: '钩子', mode: '视频形式', title: '主标题',
                subtitle: '副标题', voice_form: '配音形式', focus: '侧重点', job_id: '视频任务',
                url: '链接', audio_path: '音频路径' };
            const h = [
                // 1. 能力标题
                '<p class="font-medium text-slate-800">' + esc(c.icon || '▶️') + ' ' + esc(r.message || ('我来帮你' + (c.name || ''))) + '</p>'
            ];
            // 2. ✅ 已定方案摘要（从 r.vals 渲染，让用户清楚"已经定了什么"）
            const decided = Object.keys(r.vals || {});
            if (decided.length) {
                h.push('<div class="mt-2 rounded-lg border border-emerald-200 bg-emerald-50/50 p-2.5">'
                    + '<p class="text-[11px] font-semibold text-emerald-700">✅ 已定方案</p>'
                    + '<div class="mt-1 space-y-0.5 text-[12.5px]">');
                decided.forEach(k => {
                    let v = String(r.vals[k] ?? '');
                    if (v.length > 50) v = v.slice(0, 50) + '…';
                    h.push('<div class="flex gap-2"><span class="shrink-0 text-slate-500">' + esc(_ASK_LABELS[k] || k) + '</span>'
                        + '<span class="text-slate-800 break-all">' + esc(v) + '</span></div>');
                });
                h.push('</div></div>');
            }
            // 3. 👉 下一步（当前问题 + 进度）
            h.push('<div class="mt-2 rounded-lg border border-indigo-200 bg-indigo-50/40 p-2.5">'
                + '<p class="text-[11px] font-semibold text-indigo-700">👉 下一步' + (r.progress ? ' · 参数收集 ' + esc(r.progress) : '') + '</p>'
                + '<p class="mt-1 text-[13px] text-slate-800">' + esc(p.label || '请补充信息')
                + (p.hint ? '<span class="text-slate-400">（' + esc(p.hint) + '）</span>' : '') + '</p>');
            // 4. 选项按钮（加序号 + 简标，便于"对比着选"）
            if ((p.options || []).length) {
                h.push('<div class="mt-2 space-y-1.5">');
                p.options.forEach((o, i) => {
                    const raw = String(o);
                    const colonIdx = raw.indexOf(':');
                    const val = colonIdx >= 0 ? raw.slice(colonIdx + 1) : raw;
                    h.push('<button type="button" data-msg="' + esc(raw) + '" class="act-msg flex w-full items-start gap-2 rounded-lg border border-indigo-300 bg-white px-3 py-1.5 text-left text-xs text-indigo-700 transition hover:border-indigo-500 hover:bg-indigo-50">'
                        + '<span class="shrink-0 rounded bg-indigo-100 px-1.5 py-0.5 text-[11px] font-semibold text-indigo-700">' + (i + 1) + '</span>'
                        + '<span class="flex-1">' + esc(val) + '</span></button>');
                });
                h.push('</div>');
            }
            h.push('</div>');
            // 5. 💡 提示
            h.push('<p class="mt-2 text-[11px] text-slate-400">💡 ' + esc(r.tip || '直接在下面填，或点上面的选项。') + ' 不想做了就说「取消」。</p>');
            return h.join('');
        }
        if (r.stage === 'action_ready') {
            const c = r.cap || {};
            const payload = encodeURIComponent(JSON.stringify({ cap: c.id, vals: r.vals || {}, next: r.next || [] }));
            const isAuto = _AUTO_CAPS.includes(c.id);
            let _modePicker = '';
            let _vfPicker = '';
            if (c.id === 'video_render') {
                const _modes = [
                    { m: 'scroll', icon: '📜', name: '滚动字幕' },
                    { m: 'avatar', icon: '🧑', name: '数字人出镜' },
                    { m: 'motion', icon: '🎞️', name: '动态图文' },
                    { m: 'manga', icon: '📚', name: '漫剧' },
                    { m: 'whiteboard', icon: '🖊️', name: '白板手绘' },
                    { m: 'card', icon: '🧩', name: '图解版' }
                ];
                const _curM = (r.vals && r.vals.mode) || 'scroll';
                _modePicker = '<div class="mt-2"><p class="text-[11px] font-medium text-slate-500">选择视频形式</p>'
                    + '<div class="mt-1 flex flex-wrap gap-2" data-mode-group>'
                    + _modes.map(x => '<button type="button" data-mode="' + x.m + '" class="mode-opt rounded-lg border px-2.5 py-1 text-xs ' + (x.m === _curM ? 'border-indigo-500 bg-indigo-50 text-indigo-700 font-medium' : 'border-slate-200 text-slate-600 hover:border-indigo-300') + '">' + x.icon + ' ' + x.name + '</button>').join('')
                    + '</div></div>';
                const _vfs = [
                    { v: 'dialogue', name: '双声对话（女问男答）' },
                    { v: 'male_mono', name: '男声独白（单声）' },
                    { v: 'female_mono', name: '女声独白（单声）' }
                ];
                const _forceMono = (_curM === 'avatar' || _curM === 'card');
                const _curV = (r.vals && r.vals.voice_form) || 'male_mono';
                const _vfHint = _forceMono
                    ? '<p class="mt-1 text-[10px] text-amber-500">⚠️ ' + (_curM === 'card' ? '图解版为单人解说' : '数字人出镜为单人出镜') + '，仅支持单声独白，已为你禁用双声对话</p>'
                    : '';
                _vfPicker = '<div class="mt-2"><p class="text-[11px] font-medium text-slate-500">配音形式</p>'
                    + '<div class="mt-1 flex flex-wrap gap-2" data-vf-group>'
                    + _vfs.map(x => {
                        const _disabled = (_forceMono && x.v === 'dialogue');
                        const _cls = _disabled
                            ? 'vf-opt rounded-lg border px-2.5 py-1 text-xs border-slate-200 text-slate-300 cursor-not-allowed line-through'
                            : 'vf-opt rounded-lg border px-2.5 py-1 text-xs ' + (x.v === _curV ? 'border-indigo-500 bg-indigo-50 text-indigo-700 font-medium' : 'border-slate-200 text-slate-600 hover:border-indigo-300');
                        return '<button type="button" data-vf="' + x.v + '" class="' + _cls + '"' + (_disabled ? ' disabled' : '') + '>' + x.name + '</button>';
                    }).join('')
                    + '</div>' + _vfHint + '</div>';
            }
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
            h.push(_modePicker + _vfPicker);
            let _runBtn;
            if (c.id === 'video_render' && !isAuto) {
                _runBtn = '<button type="button" data-cap="' + payload + '" data-video-run="1" class="cap-run rounded-lg px-4 py-1.5 text-xs font-medium bg-indigo-600 text-white transition hover:bg-indigo-700">▶ 开始执行</button>';
            } else {
                _runBtn = '<button type="button" data-cap="' + payload + '" data-autorun="' + (isAuto ? '1' : '0') + '" class="cap-run rounded-lg px-4 py-1.5 text-xs font-medium transition ' + (isAuto ? 'bg-slate-400 text-white cursor-not-allowed' : 'bg-indigo-600 text-white hover:bg-indigo-700') + '" ' + (isAuto ? 'disabled' : '') + '>' + (isAuto ? '⏳ 自动执行中…' : '▶ 开始执行') + '</button>';
            }
            h.push('<div class="mt-2 flex flex-wrap gap-2">' + _runBtn + '</div>');
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
            return '<p>' + esc(r.summary || r.message || '本批已全部写完。') + '</p>';
        }
        if (r.stage === 'reset') {
            return '<p class="text-slate-500">已重置本次会话。</p>';
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
        // 切会话 → 产物流水清空；随后由历史回放（resultBlock）自动重建本会话的产出
        ARTIFACTS = []; afActive = null; afClosedByUser = false;
        try { renderArtifacts(); setArtifactsOpen(false); } catch (_) { /* 初始化早于面板挂载时忽略 */ }
        renderSessions();
    }

    async function openSession(id) {
        try {
            const d = await api('/studio/chat/messages?session_id=' + encodeURIComponent(id));
            setActive(d.session_id, d.title || d.topic || '');
            const msgs = d.messages || [];
            if (!msgs.length) { showIntro(); }
            else {
                chatBox.innerHTML = '';
                _bulkRender = true;          // 批量回放：先不逐条跳动
                msgs.forEach(m => {
                    const p = m.payload || {};
                    if (m.role === 'user') appendMsg('user', esc(p.content || ''));
                    else appendMsg('ai', resultBlock(p));
                });
                _bulkRender = false;
                stickToBottom(true);         // 全部渲染完一次性贴到最新
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
        if (!sid) { alert('先发一条消息，或点「💬 新建开聊」开始对话。'); return; }
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
        // 顶部标题条保持简洁，主题/受众/数量等元信息已收入对话气泡内
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
        if (!msg) return;
        if (busy) {
            appendMsg('ai', '<span class="text-amber-600">⏳ 上一条消息还在处理，请稍候，完成后会自动出现。</span>', { noTools: true });
            return;
        }
        lastMsg = msg;
        busy = true; sendBtn.disabled = true; input.value = '';
        if (lastPolishResearch) { lastPolishResearch = ''; micStatus.classList.add('hidden'); }
        appendMsg('user', esc(msg));
        const thinking = appendMsg('ai', '<span class="text-slate-400">…正在理解并处理</span>', { noTools: true });
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
            // 简单能力参数齐了自动执行，不让用户多点一次"开始执行"
            if (data.stage === 'action_ready' && autoRunCap(data)) {
                // 已触发自动执行，当前确认卡片保留，后续结果会接着滚出来
            }
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
                const prog = st.progress || {};
                const phase = prog.phase || 'working';
                const msg = prog.msg || '正在处理…';
                const sec = Math.round((Date.now() - t0) / 1000);
                // 阶段步骤条：让"还在跑"这件事一眼可见，区分卡死
                const steps = [
                    {k: 'thinking', t: '理解意图'},
                    {k: 'searching', t: '检索政策'},
                    {k: 'reviewing', t: '协作审查'},
                    {k: 'proposing', t: '拆角度'},
                    {k: 'writing', t: '成稿'},
                ];
                const phaseMap = {thinking: 0, searching: 1, reviewing: 2, proposing: 3, writing: 4};
                const cur = (phase in phaseMap) ? phaseMap[phase] : 0;
                let bar = '<div class="mt-1 flex flex-wrap items-center gap-x-1 gap-y-0.5 text-xs">';
                steps.forEach((s, i) => {
                    const on = i <= cur;
                    bar += '<span class="' + (on ? 'text-indigo-600 font-medium' : 'text-slate-400') + '">'
                        + (i === cur ? '▶ ' : '') + s.t + (i < steps.length - 1 ? ' →' : '') + '</span>';
                });
                bar += '</div>';
                // 仅长任务（>3s）显示取消按钮，避免一闪而过
                const cancelBtn = sec > 3
                    ? '<button type="button" id="chatCancelBtn" class="mt-2 inline-block rounded border border-slate-300 bg-white px-3 py-1 text-xs text-slate-500 transition hover:bg-slate-50">✕ 取消等待</button>'
                    : '';
                bubble.innerHTML = '<div class="text-slate-500">⏳ ' + esc(msg) + '　已 ' + sec + ' 秒'
                    + (sec > 25 ? '（长任务可能需 1~4 分钟）' : '') + '</div>' + bar + cancelBtn;
                const cb = bubble.querySelector('#chatCancelBtn');
                if (cb) cb.onclick = () => {
                    keep = false;
                    bubble.innerHTML = '<span class="text-slate-400">已取消当前等待。后台可能仍在生成，刷新页面或点进左侧本会话即可查看最新结果。</span>';
                };
            } else if (st.stage && ['done', 'written', 'propose', 'search', 'review', 'ask', 'answer'].includes(st.stage)) {
                safeRender(bubble, st);   // 后台跑完，完整结果渲染（resultBlock 出错时降级为原文 JSON）
                updateMeta(st); loadSessions();
                stickToBottom(true);      // 结果替换完成后贴到最新
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
        // 只有当循环是因为总超时（>330s）自然退出且仍未拿到结果时，才显示兜底提示。
        // 注意：循环内 break 会设置 keep=false，但 break 前已经 safeRender 或显示 error，
        // 所以这里只处理"函数执行完仍未 break"的极少数超时情况，避免覆盖正常结果。
        if (keep !== false) {
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
            // 出片卡片：先注入用户在卡片上选的视频形式/配音形式，再执行（避免双重触发）
            if (capBtn.dataset.videoRun) { runVideoRender(capBtn); return; }
            runCapAction(capBtn, payload);
        }
    });

    // 出片卡片：用户选形式/配音后构造 payload 再执行
    function runVideoRender(btn) {
        try {
            const card = btn.closest('.chat-bubble') || btn.parentElement;
            const mg = card.querySelector('[data-mode-group]');
            const msel = mg ? mg.querySelector('.mode-opt.border-indigo-500') : null;
            const modeVal = msel ? msel.dataset.mode : 'scroll';
            const vg = card.querySelector('[data-vf-group]');
            const vsel = vg ? vg.querySelector('.vf-opt.border-indigo-500') : null;
            const vfVal = vsel ? vsel.dataset.vf : 'male_mono';
            const raw = JSON.parse(decodeURIComponent(btn.dataset.cap));
            raw.vals = raw.vals || {};
            raw.vals.mode = modeVal;
            raw.vals.voice_form = vfVal;
            runCapAction(btn, raw);
        } catch (e) {
            console.error('runVideoRender failed', e);
        }
    }
    // 形式/配音选择高亮切换（事件委托）
    if (typeof chatBox !== 'undefined' && chatBox) {
        chatBox.addEventListener('click', function (e) {
            const mo = e.target.closest('.mode-opt');
            if (mo) {
                mo.parentElement.querySelectorAll('.mode-opt').forEach(b => b.classList.remove('border-indigo-500', 'bg-indigo-50', 'text-indigo-700', 'font-medium'));
                mo.classList.add('border-indigo-500', 'bg-indigo-50', 'text-indigo-700', 'font-medium');
                const isAvatar = (mo.dataset.mode === 'avatar' || mo.dataset.mode === 'card');
                // 切到数字人/图解版：禁用「双声对话」并强制改选单声；切走其它形式：恢复双声可点
                document.querySelectorAll('[data-vf-group] .vf-opt').forEach(btn => {
                    const isDialogue = (btn.dataset.vf === 'dialogue');
                    if (isAvatar && isDialogue) {
                        btn.classList.add('border-slate-200', 'text-slate-300', 'cursor-not-allowed', 'line-through');
                        btn.classList.remove('border-indigo-500', 'bg-indigo-50', 'text-indigo-700', 'font-medium');
                        btn.disabled = true;
                        if (btn.classList.contains('border-indigo-500')) {
                            btn.classList.remove('border-indigo-500', 'bg-indigo-50', 'text-indigo-700', 'font-medium');
                            const male = document.querySelector('[data-vf-group] .vf-opt[data-vf="male_mono"]');
                            if (male) male.classList.add('border-indigo-500', 'bg-indigo-50', 'text-indigo-700', 'font-medium');
                        }
                    } else if (!isAvatar && isDialogue) {
                        btn.classList.remove('border-slate-200', 'text-slate-300', 'cursor-not-allowed', 'line-through');
                        btn.disabled = false;
                    }
                });
                return;
            }
            const vo = e.target.closest('.vf-opt');
            if (vo) {
                vo.parentElement.querySelectorAll('.vf-opt').forEach(b => b.classList.remove('border-indigo-500', 'bg-indigo-50', 'text-indigo-700', 'font-medium'));
                vo.classList.add('border-indigo-500', 'bg-indigo-50', 'text-indigo-700', 'font-medium');
            }
        });
    }

    // ---------- 能力执行（对话里点「开始执行」，或被自动触发）----------
    async function runCapAction(btn, payload) {
        const hasBtn = btn && btn.tagName;
        const silent = !hasBtn;   // 自动触发时不展示原始 JSON，等 AI 总结统一输出
        if (hasBtn) { btn.disabled = true; btn.textContent = '⏳ 正在执行…'; }
        appendMsg('ai', '<p class="text-slate-500">⏳ 正在跑「' + esc((payload.cap || '')) + '」，请稍候…</p>', { noTools: true });
        try {
            const res = await api('/studio/chat/action', {
                method: 'POST',
                body: JSON.stringify({ cap: payload.cap, vals: payload.vals || {} })
            });
            if (res && res.ok === false) throw new Error(res.error || '后端返回失败');
            const data = (res && res.data) || {};

            // 长任务（出片）：给出任务号并轮询进度
            if (data.job_id) {
                appendMsg('ai', '<p class="font-medium text-slate-800">🎬 已提交渲染，任务号 <code class="text-[11px]">' + esc(data.job_id) + '</code></p>', { noTools: true }
                    + '<p class="mt-1 text-slate-600">视频要渲染几分钟，我会一直盯着进度，完成后告诉你。</p>');
                pollJob(data.job_id, payload);
            } else if (silent) {
                // 自动执行：直接交给 AI 总结，不在中间暴露原始 JSON
                await sendActionResult(payload.cap, true, data, payload.next || []);
            } else {
                appendMsg('ai', '<p class="font-medium text-slate-800">✅ ' + esc(payload.cap || '') + ' 跑完了</p>', { noTools: true }
                    + '<pre class="mt-1 max-h-60 overflow-auto whitespace-pre-wrap rounded bg-slate-50 p-2 text-[11px] text-slate-600">'
                    + esc(JSON.stringify(data, null, 1).slice(0, 1500)) + '</pre>');
                // 把结果交给 AI 总结 + 引导下一步（AI 回复里自带下一步卡片）
                await sendActionResult(payload.cap, true, data, payload.next || []);
            }
        } catch (err) {
            appendMsg('ai', '<p class="text-rose-600">❌ 没跑通：' + esc(err.message || '未知错误') + '</p>', { noTools: true }
                + '<p class="mt-1 text-xs text-slate-500">可以改一下参数再来，或跟我说你要做什么，我换个方式帮你。</p>');
            await sendActionResult(payload.cap, false, { error: String(err.message || '') });
        } finally {
            if (hasBtn) { btn.disabled = false; btn.textContent = '↻ 再执行一次'; }
        }
    }

    // 自动执行：用户说"给我10条选题"这类话，参数齐了就直接跑，不再等点按钮
    function autoRunCap(data) {
        try {
            if (!data || data.stage !== 'action_ready' || !data.cap || !data.cap.id) return false;
            if (!_AUTO_CAPS.includes(data.cap.id)) return false;
            const payload = { cap: data.cap.id, vals: data.vals || {}, next: data.next || [] };
            // 把当前消息里的按钮改成「正在自动执行…」状态，给用户明确反馈
            const lastAi = [...chatBox.querySelectorAll('.chat-bubble')].pop();
            if (lastAi) {
                const btn = lastAi.querySelector('button[data-autorun="1"]');
                if (btn) { btn.disabled = true; btn.textContent = '⏳ 已自动触发，正在跑…'; }
            }
            setTimeout(() => runCapAction(null, payload), 80);
            return true;
        } catch (err) {
            // 自动执行失败不阻塞用户，保留开始执行按钮可手动点
            console.error('autoRunCap failed:', err);
            return false;
        }
    }

    // 长任务轮询：每 8 秒查一次，完成后回灌 AI 并给下一步卡片
    async function pollJob(jobId, payload) {
        let tries = 0;
        const timer = setInterval(async () => {
            tries++;
            try {
                const r = await fetch('/studio/video/status/' + encodeURIComponent(jobId), { headers: { 'Accept': 'application/json' } });
                const j = await r.json();
                const st = j.status || j.data?.status || '';
                if (st === 'done' || st === 'failed' || tries > 90) {
                    clearInterval(timer);
                    const ok = st === 'done';
                    if (ok) {
                        // 视频内嵌预览：直接用 Laravel 现有 inline 端点（带 cookie 鉴权）
                        const videoUrl = '/studio/scroll/download/' + encodeURIComponent(jobId);
                        // 右侧产物面板：同一条任务从「渲染中」翻成「已完成」，可直接播放/下载
                        pushArtifact({ key: 'job:' + jobId, type: 'video',
                            title: '成片（' + String(jobId).slice(0, 6) + '）',
                            sub: '视频 · 渲染完成', url: videoUrl, status: 'done' });
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
                        pushArtifact({ key: 'job:' + jobId, type: 'video',
                            sub: '视频 · ' + (st === 'failed' ? '渲染失败' : '渲染超时'), status: st || 'failed' });
                        appendMsg('ai', '<p class="text-rose-600">⚠️ 渲染' + (st === 'failed' ? '失败' : '超时（已盯了 12 分钟）') + '</p>', { noTools: true }
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
    const planWeekBtn = document.getElementById('planWeekBtn');
    if (planWeekBtn) planWeekBtn.onclick = () => doSend('帮我规划本周财税内容');
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSend(); }
        autoGrow(e.target);  // 自动撑高
    });
    // 语音输入（Web Speech API）+ 自动整理
    setupMic();
    document.getElementById('newChatBtn').onclick = () => { createSession(''); };
    document.getElementById('newNamedBtn').onclick = () => {
        const t = prompt('给这个空间起个名字（如「注册公司引流系列」）：', '');
        if (t === null) return;
        createSession(t.trim());
    };
    document.getElementById('renameBtn').onclick = renameCurrent;
    document.getElementById('delSpaceBtn').onclick = () => { if (sid) deleteSession(sid); };

    // 产物面板：顶栏按钮展开 / 面板内按钮收起（与会话列同一套交互习惯）
    const afOpenBtnEl = document.getElementById('afOpenBtn');
    if (afOpenBtnEl) afOpenBtnEl.onclick = () => { afClosedByUser = false; setArtifactsOpen(true); };
    const afCloseBtnEl = document.getElementById('afCloseBtn');
    if (afCloseBtnEl) afCloseBtnEl.onclick = () => { afClosedByUser = true; setArtifactsOpen(false); };
    renderArtifacts();
    setArtifactsOpen(false);

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
