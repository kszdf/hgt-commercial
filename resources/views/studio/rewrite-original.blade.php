<x-app-layout>
<x-workspace-layout title="原始稿二创">
<div class="mx-auto max-w-5xl p-6">

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- ===== 左侧：原始稿输入区 ===== -->
        <section class="luxury-glass p-5">
            <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                <p class="text-sm font-medium text-slate-700">自由原创改写</p>
                <p class="text-xs text-slate-500">粘贴你自己的口播稿、文案或逐字稿，AI 按所选模式改写为可直接配音的清洗稿。</p>
            </div>

            <form id="rwForm" class="space-y-4">
                <!-- 改写模式 -->
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">呈现形式</label>
                    <select id="mode" name="mode"
                        class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option value="avatar">单人数字人出镜</option>
                        <option value="motion">🎬 幕后音·动态画面</option>
                        <option value="scroll">📋 幕后音·滚动字幕</option>
                        <option value="manga">📖 AI 漫剧</option>
                        <option value="whiteboard">✍️ AI 白板图解</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-400">选择与后续出片一致的声音/出镜形式，避免前后矛盾。</p>
                    <p id="modeHint" class="mt-1 text-xs text-brand-600"></p>
                </div>

                <!-- 角色与声音分配 -->
                <div class="rounded-lg studio-card studio-card-sm">
                    <label class="mb-1.5 block text-sm font-medium text-slate-600">
                        角色与声音<span class="hint" data-tip="声线最终在出片页确认；此处决定改写稿的角色分配方式。">?</span>
                    </label>
                    <select id="roleMode" name="role_mode"
                        class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option value="auto">自动分配（默认男声独白，对话稿自动双声）</option>
                        <option value="single_male">单人口播（男声）</option>
                        <option value="single_female">单人口播（女声）</option>
                        <option value="dual_male_lead">男女对话（男声解答）</option>
                        <option value="custom">自由角色分配（按下方说明切换）</option>
                    </select>
                    <textarea id="roleNote" name="role_note" rows="2"
                        class="mt-2 hidden w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                        placeholder="例如：开头由女声提问，第3段起男声解答，结尾女声留钩子"></textarea>
                    <label class="mt-2 flex cursor-pointer items-center gap-2 text-xs text-slate-500">
                        <input type="checkbox" id="keepManualRoles" name="keep_manual_roles" value="1" class="accent-brand-500 rounded">
                        保留原稿中已有的「男：/女：」对话标注
                    </label>
                    <p class="mt-1.5 text-xs text-slate-400">
                        <span class="text-brand-600">提示：</span>「女：/男：」前缀用于区分声线；单人形式无需前缀；数字人出镜为单人独白，前缀自动忽略。
                    </p>
                </div>

                <!-- 重点方向 -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-600">
                        重点方向 <span class="font-normal text-slate-400">（可选，引导 AI 侧重方向）</span>
                    </label>
                    <div id="focusChips" class="flex flex-wrap gap-1.5">
                        <button type="button" data-focus="痛点警示" class="focus-chip rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 transition hover:border-brand-300 hover:text-brand-600">痛点警示</button>
                        <button type="button" data-focus="政策解读" class="focus-chip rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 transition hover:border-brand-300 hover:text-brand-600">政策解读</button>
                        <button type="button" data-focus="实操指南" class="focus-chip rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 transition hover:border-brand-300 hover:text-brand-600">实操指南</button>
                        <button type="button" data-focus="案例故事" class="focus-chip rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 transition hover:border-brand-300 hover:text-brand-600">案例故事</button>
                        <button type="button" data-focus="避坑指南" class="focus-chip rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 transition hover:border-brand-300 hover:text-brand-600">避坑指南</button>
                        <button type="button" data-focus="留资转化" class="focus-chip rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 transition hover:border-brand-300 hover:text-brand-600">留资转化</button>
                    </div>
                    <input type="hidden" id="focus" name="focus" value="">
                    <p id="focusDisplay" class="mt-1 hidden text-xs text-brand-600">已选：<span id="focusTags"></span> <button type="button" id="clearFocus" class="text-slate-400 hover:text-red-500">清除</button></p>
                </div>

                <!-- 原始稿 -->
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <label class="text-sm font-medium text-slate-600">原始稿</label>
                        <span id="charCounter" class="text-xs text-slate-400">0 字 · 预计约 0 秒</span>
                    </div>
                    <textarea id="text" name="text" rows="10" required
                        class="w-full rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                        placeholder="粘贴你的口播稿 / 文案 / 逐字稿…"></textarea>
                </div>

                <!-- 目标时长 -->
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">
                        目标时长 <span class="font-normal text-slate-400">（控制改写稿长度）</span>
                    </label>
                    <div class="flex items-center gap-2">
                        <select id="durationPreset" name="duration_preset"
                            class="shrink-0 rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                            <option value="">不限（按原文自然长度）</option>
                            <option value="30">30 秒（短视频/朋友圈）</option>
                            <option value="60" selected>1 分钟（标准口播·推荐）</option>
                            <option value="120">2 分钟（深度讲解）</option>
                            <option value="180">3 分钟（案例拆解）</option>
                            <option value="300">5 分钟（长视频）</option>
                            <option value="custom">自定义…</option>
                        </select>
                        <input type="number" id="durationCustom" name="duration_custom" min="10" max="600"
                            placeholder="秒数，如 90"
                            class="hidden w-28 rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <span id="durationHint" class="text-xs text-slate-400">约 130–160 字/分钟（预估按 145 字/分钟）</span>
                    </div>
                    <input type="hidden" id="targetDuration" name="target_duration" value="">
                </div>

                <!-- 保留要素 -->
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">
                        保留要素 <span class="font-normal text-slate-400">（可选，改写时必须保留的关键内容）</span>
                    </label>
                    <textarea id="preserve" name="preserve" rows="2"
                        class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none placeholder:text-slate-300 focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                        placeholder="例如：&#10;• 必须保留「核心卖点」这个关键词&#10;• 保留具体数据「5000+ 用户」「2026年」&#10;• 保留品牌名《XX 产品》&#10;留空则 AI 自由发挥"></textarea>
                    <p class="mt-0.5 text-[11px] text-slate-400">每行一条，AI 改写时会确保这些内容不被删改或替换</p>
                </div>

                <!-- 提交按钮 -->
                <button type="submit" id="genBtn"
                    class="w-full rounded-lg bg-brand-500 px-4 py-2.5 font-medium text-white shadow-sm transition hover:bg-brand-600 disabled:opacity-50 disabled:cursor-not-allowed">
                    智能改写
                </button>
                <p id="formMsg" class="text-sm text-red-500"></p>
            </form>
        </section>

        <!-- ===== 右侧：结果区 ===== -->
        <section class="luxury-glass p-5">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-700">改写结果</h3>
                <span id="statusBadge" class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-500">待生成</span>
            </div>

            <!-- 空状态 -->
            <div id="emptyState" class="space-y-3">
                <div class="rounded-lg studio-card text-center">
                    <div class="mx-auto mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-slate-200">
                        <svg class="h-5 w-5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </div>
                    <p class="text-sm text-slate-400">改写后的稿子与违禁词标记将显示在这里</p>
                </div>
            </div>

            <!-- 结果内容 -->
            <div id="result" class="hidden space-y-3"></div>

            <!-- 元数据条 -->
            <div id="metaBar" class="mt-3 hidden rounded-lg studio-card studio-card-sm">
                <div class="grid grid-cols-2 gap-2 text-xs sm:grid-cols-4" id="metaGrid"></div>
            </div>

            <!-- 操作按钮组 -->
            <div id="actionBar" class="mt-4 hidden rounded-lg border border-brand-200 bg-gradient-to-r from-brand-50 to-white p-3">
                <p class="mb-2 text-xs font-medium text-brand-700">改写完成 — 下一步</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" id="btnCopy" class="inline-flex items-center gap-1 rounded-lg bg-white px-3 py-2 text-xs font-medium text-slate-700 shadow-sm ring-1 ring-slate-200 transition hover:bg-slate-50">
                        复制清洗稿
                    </button>
                    <button type="button" id="btnGoScroll" class="inline-flex items-center gap-1 rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white shadow-sm transition hover:bg-brand-600">
                        带稿去出片 →
                    </button>
                    <button type="button" id="btnGoQc" class="inline-flex items-center gap-1 rounded-lg bg-white px-3 py-2 text-xs font-medium text-slate-700 shadow-sm ring-1 ring-slate-200 transition hover:bg-slate-50">
                        跑质检 →
                    </button>
                    <button type="button" id="btnRegen" class="inline-flex items-center gap-1 rounded-lg bg-white px-3 py-2 text-xs font-medium text-slate-400 shadow-sm ring-1 ring-slate-200 transition hover:bg-slate-50">
                        重新改写
                    </button>
                </div>
            </div>

            <div id="errorBox" class="mt-3 hidden rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600"></div>
        </section>
    </div>
</div>

<script>
let lastResult = null;

function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function highlight(text, hits){
    let h = escapeHtml(text);
    (hits||[]).forEach(it => {
        const w = escapeHtml(it.word || '');
        if (w) h = h.split(w).join('<mark class="bg-red-100 text-red-700 rounded px-0.5">' + w + '</mark>');
    });
    return h;
}
function updateCharCount() {
    const txt = document.getElementById('text').value.replace(/\s/g, '');
    const chars = txt.length;
    const sec = Math.max(1, Math.round(chars / 2.4));   // 2026-08-28 语速口径统一 2.4字/秒(145字/分)
    const fmt = sec >= 60 ? Math.floor(sec/60)+'分'+(sec%60)+'秒' : '约'+sec+'秒';
    document.getElementById('charCounter').textContent = chars + ' 字 · 预计 ' + fmt;
}

// 重点方向标签选择
const selectedFocus = [];
document.querySelectorAll('.focus-chip').forEach(chip => {
    chip.addEventListener('click', function () {
        const f = this.dataset.focus;
        const idx = selectedFocus.indexOf(f);
        if (idx >= 0) {
            selectedFocus.splice(idx, 1);
            this.classList.remove('bg-brand-100', 'border-brand-400', 'text-brand-700', 'ring-1', 'ring-brand-200');
            this.classList.add('bg-white', 'border-slate-200', 'text-slate-600');
        } else {
            selectedFocus.push(f);
            this.classList.add('bg-brand-100', 'border-brand-400', 'text-brand-700', 'ring-1', 'ring-brand-200');
            this.classList.remove('bg-white', 'border-slate-200', 'text-slate-600');
        }
        document.getElementById('focus').value = selectedFocus.join('、');
        const fd = document.getElementById('focusDisplay');
        if (selectedFocus.length) {
            fd.classList.remove('hidden');
            document.getElementById('focusTags').textContent = selectedFocus.join('、');
        } else {
            fd.classList.add('hidden');
        }
    });
});
document.getElementById('clearFocus')?.addEventListener('click', function () {
    selectedFocus.length = 0;
    document.getElementById('focus').value = '';
    document.getElementById('focusDisplay').classList.add('hidden');
    document.querySelectorAll('.focus-chip').forEach(c => {
        c.classList.remove('bg-brand-100','border-brand-400','text-brand-700','ring-1','ring-brand-200');
        c.classList.add('bg-white','border-slate-200','text-slate-600');
    });
});

// 目标时长预设切换
const durPreset = document.getElementById('durationPreset');
const durCustom = document.getElementById('durationCustom');
const durHidden = document.getElementById('targetDuration');
const durHint = document.getElementById('durationHint');

durPreset.addEventListener('change', function () {
    if (this.value === 'custom') {
        durCustom.classList.remove('hidden');
        durCustom.focus();
        durHint.textContent = '手动输入秒数';
        durHidden.value = '';
    } else {
        durCustom.classList.add('hidden');
        durHidden.value = this.value || '';
        const secs = parseInt(this.value) || 0;
        if (secs >= 60) {
            const min = Math.floor(secs / 60);
            const remain = secs % 60;
            const low = Math.round(min * 130 + (remain / 60) * 130);
            const high = Math.round(min * 160 + (remain / 60) * 160);
            durHint.textContent = '约 ' + low + '–' + high + ' 字';
        } else if (secs > 0) {
            durHint.textContent = '约 ' + Math.round(secs * 130 / 60) + '–' + Math.round(secs * 160 / 60) + ' 字';
        } else {
            durHint.textContent = '按原文自然长度';
        }
    }
});
durCustom.addEventListener('input', function () {
    durHidden.value = this.value || '';
});

// 角色与声音：custom 时显示说明框
const roleModeO = document.getElementById('roleMode');
const roleNoteO = document.getElementById('roleNote');
if (roleModeO && roleNoteO) {
    roleModeO.addEventListener('change', function () {
        if (this.value === 'custom') { roleNoteO.classList.remove('hidden'); roleNoteO.focus(); }
        else { roleNoteO.classList.add('hidden'); }
    });
}
// 呈现形式 → 角色与声音 联动（与选题二创页一致）
const modeSelO = document.getElementById('mode');
if (modeSelO) {
    modeSelO.addEventListener('change', function () {
        const rm = document.getElementById('roleMode');
        if (!rm) return;
        const d = this.value;
        if (d === 'manga' || d === 'whiteboard') {
            // 2026-08-31: 漫剧/白板走「内容规整」，无角色声线分配
            rm.value = 'single_male';
            const hint = document.getElementById('modeHint');
            if (hint) hint.textContent = 'AI 漫剧 / 白板图解：改写为「内容规整稿」（保留事件/数据/要点），供分镜或提炼直接使用';
        } else {
            const hint = document.getElementById('modeHint');
            if (hint) hint.textContent = '';
            // 动态画面/滚动字幕合并后：声线在出片页统一选，二创默认男声
            rm.value = 'single_male';
        }
        rm.dispatchEvent(new Event('change'));
    });
}

// 实时字数统计
document.getElementById('text').addEventListener('input', updateCharCount);

// ========== 状态持久化（返回原始稿二创时不丢数据） ==========
function saveRewriteOriginalState() {
    try {
        sessionStorage.setItem('hgt_rewrite_original_state', JSON.stringify({
            text: document.getElementById('text').value,
            mode: document.getElementById('mode') ? document.getElementById('mode').value : '',
            focus: document.getElementById('focus') ? document.getElementById('focus').value : '',
            targetDuration: document.getElementById('targetDuration') ? document.getElementById('targetDuration').value : '',
            preserve: document.getElementById('preserve') ? document.getElementById('preserve').value : '',
        }));
    } catch(e) {}
}
// 返回时恢复工作区
(function () {
    try {
        const raw = sessionStorage.getItem('hgt_rewrite_original_state');
        if (!raw) return;
        const s = JSON.parse(raw);
        if (s.text) document.getElementById('text').value = s.text;
        if (s.mode) document.getElementById('mode').value = s.mode;
        if (s.focus) {
            document.getElementById('focus').value = s.focus;
            const fd = document.getElementById('focusDisplay');
            if (fd) { fd.classList.remove('hidden'); document.getElementById('focusTags').textContent = s.focus; }
        }
        if (s.targetDuration) document.getElementById('targetDuration').value = s.targetDuration;
        if (s.preserve) document.getElementById('preserve').value = s.preserve;
        updateCharCount();
    } catch(e) {}
})();
window.addEventListener('beforeunload', saveRewriteOriginalState);

// 提交改写
document.getElementById('rwForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    await runSingleRewrite();
});

// 呈现形式 → 后端改写模式（后端只认 single/dual/script/content）
function mapDisplayModeToRewriteMode(displayMode) {
    // 2026-08-31: AI 漫剧/白板图解走「内容规整」模式（产出内容稿供下游分镜/提炼）
    if (displayMode === 'manga' || displayMode === 'whiteboard') return 'content';
    return 'single'; // avatar / motion / scroll / scroll_male / scroll_female / scroll_dual / script 都按单人稿改写
}

async function callRewrite({mode, text, focus, target_duration, preserve, role_mode, role_note, keep_manual_roles, signal}) {
    const resp = await fetch('/studio/rewrite/generate', {
        method: 'POST',
        signal,
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({
            mode: mapDisplayModeToRewriteMode(mode),
            text: text,
            focus: focus || undefined,
            target_duration: target_duration || undefined,
            preserve: preserve || undefined,
            role_mode: role_mode || undefined,
            role_note: role_note || undefined,
            keep_manual_roles: keep_manual_roles ? true : undefined,
        })
    });
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || '提交失败');
    if (!data.ok) throw new Error(data.error || '生成失败');
    return data;
}

async function runSingleRewrite() {
    const btn = document.getElementById('genBtn');
    const msg = document.getElementById('formMsg');
    const badge = document.getElementById('statusBadge');
    const result = document.getElementById('result');
    const emptyState = document.getElementById('emptyState');
    const errBox = document.getElementById('errorBox');
    const metaBar = document.getElementById('metaBar');
    const actionBar = document.getElementById('actionBar');

    const text = document.getElementById('text').value.trim();
    if (!text) {
        msg.textContent = '请输入原始稿';
        return;
    }

    msg.textContent = ''; errBox.classList.add('hidden');
    metaBar.classList.add('hidden'); actionBar.classList.add('hidden');
    result.classList.add('hidden'); result.innerHTML = '';
    zwSetLoading(btn, {loading: true, text: 'AI 改写中…'});
    badge.textContent = '改写中'; badge.className = 'rounded-full bg-brand-100 px-3 py-1 text-xs text-brand-600';

    const signal = HGTAbort.begin('中止：AI 改写中…');
    try {
        const data = await callRewrite({
            mode: document.getElementById('mode').value,
            text: document.getElementById('text').value,
            focus: document.getElementById('focus').value,
            target_duration: document.getElementById('targetDuration').value,
            preserve: document.getElementById('preserve').value.trim(),
            role_mode: document.getElementById('roleMode') ? document.getElementById('roleMode').value : undefined,
            role_note: document.getElementById('roleNote') ? document.getElementById('roleNote').value.trim() : undefined,
            keep_manual_roles: document.getElementById('keepManualRoles') ? document.getElementById('keepManualRoles').checked : false,
            signal: signal,
        });

        lastResult = data;
        renderSingleResult(data);
        badge.textContent = '完成';
        badge.className = 'rounded-full bg-green-100 px-3 py-1 text-xs text-green-700';
        zwSetLoading(btn, {loading: false});
    } catch (err) {
        if (err.name === 'AbortError') {
            zwSetLoading(btn, {loading: false});
            badge.textContent = '已中止'; badge.className = 'rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-500';
            errBox.textContent = '⏹ 已中止改写。'; errBox.classList.remove('hidden');
            hgtToast('warn', '已中止改写');
            return;
        }
        zwSetLoading(btn, {loading: false});
        badge.textContent = '失败'; badge.className = 'rounded-full bg-red-100 px-3 py-1 text-xs text-red-600';
        errBox.textContent = err.message; errBox.classList.remove('hidden');
    } finally {
        HGTAbort.end();
    }
}

function renderSingleResult(data) {
    const result = document.getElementById('result');
    const emptyState = document.getElementById('emptyState');
    const metaBar = document.getElementById('metaBar');
    const actionBar = document.getElementById('actionBar');

    emptyState.classList.add('hidden');
    result.classList.remove('hidden');

    const hits = (data.hits || []);
    const meta = data.meta || {};

    let html = '';
    html += '<div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">';
    html += '<div class="mb-1.5 flex items-center justify-between text-xs font-medium">';
    html += '<span class="text-slate-500">改写稿（违禁词已<span class="text-red-500">标红</span>）</span>';
    html += '<span class="text-slate-400">' + (meta.clean_chars || 0) + '字</span></div>';
    html += '<div class="whitespace-pre-wrap text-sm text-slate-700 leading-relaxed max-h-80 overflow-y-auto">' + highlight(data.rewritten, hits) + '</div></div>';

    if (hits.length) {
        const high = hits.filter(h => h.level === 'high');
        html += '<div class="rounded-xl ' + (high.length ? 'border-red-200 bg-red-50' : 'border-amber-200 bg-amber-50') + ' p-3 text-xs ' + (high.length ? 'text-red-600' : 'text-amber-700') + '">';
        html += '<div class="rounded-lg bg-amber-50 p-2.5 text-xs text-amber-700">命中<strong>' + hits.length + '</strong>处' + (high.length ? '（<strong>' + high.length + '处高风险</strong>）' : '') + '违禁词：';
        html += hits.map(h => escapeHtml(h.word || '')).join('、') + '</div>';
    }

    html += '<div class="rounded-xl border border-slate-200 bg-white p-4">';
    html += '<div class="mb-1.5 flex items-center justify-between text-xs font-medium text-slate-500">';
    html += '<span>🧹 清洗后可配音稿</span>';
    html += '<button type="button" onclick="copyText()" class="text-brand-500 hover:underline font-medium">复制</button></div>';
    html += '<div id="cleaned" class="whitespace-pre-wrap text-sm text-slate-700 leading-relaxed max-h-60 overflow-y-auto">' + escapeHtml(data.cleaned || '') + '</div></div>';

    result.innerHTML = html;

    if (meta.clean_chars) {
        metaBar.classList.remove('hidden');
        const delta = meta.char_delta || 0;
        const deltaStr = delta > 0 ? '+' + delta : (delta < 0 ? String(delta) : '=');
        const deltaCls = delta > 0 ? 'text-green-600' : (delta < 0 ? 'text-red-500' : 'text-slate-500');
        document.getElementById('metaGrid').innerHTML =
            '<div class="rounded-md bg-white p-2 text-center"><div class="text-lg font-semibold text-slate-800">' + meta.orig_chars + '</div><div class="text-[10px] text-slate-400">原文字数</div></div>' +
            '<div class="rounded-md bg-white p-2 text-center"><div class="text-lg font-semibold text-slate-800">' + meta.clean_chars + '</div><div class="text-[10px] text-slate-400">改写后 <span class="' + deltaCls + '">(' + deltaStr + ')</span></div></div>' +
            '<div class="rounded-md bg-white p-2 text-center"><div class="text-lg font-semibold text-brand-600">' + (meta.duration_fmt || '-') + '</div><div class="text-[10px] text-slate-400">预估配音时长</div></div>' +
            '<div class="rounded-md bg-' + (meta.high_risk_count ? 'red' : meta.hit_count ? 'amber' : 'slate') + '-50 p-2 text-center"><div class="text-lg font-semibold text-' + (meta.high_risk_count ? 'red-600' : meta.hit_count ? 'amber-600' : 'slate-600') + '">' + (meta.hit_count || 0) + '</div><div class="text-[10px] text-slate-400">违禁词</div></div>';
    }

    actionBar.classList.remove('hidden');
}

function copyText() {
    const t = document.getElementById('cleaned')?.innerText || '';
    if (navigator.clipboard && t) {
        navigator.clipboard.writeText(t).then(() => {
            const btn = document.getElementById('btnCopy');
            const orig = btn.innerHTML;
            btn.innerHTML = '已复制';
            setTimeout(() => btn.innerHTML = orig, 1500);
        });
    }
}

document.getElementById('btnGoScroll')?.addEventListener('click', function () {
    if (!lastResult || !lastResult.cleaned) return;
    saveRewriteOriginalState();
    const displayMode = document.getElementById('mode').value;
    sessionStorage.setItem('hgt_rewrite_cleaned', lastResult.cleaned);
    sessionStorage.setItem('hgt_rewrite_mode', displayMode);
    window.location.href = '/studio/scroll?from=rewrite&src=original&mode=' + encodeURIComponent(displayMode);
});

document.getElementById('btnGoQc')?.addEventListener('click', function () {
    if (!lastResult || !lastResult.cleaned) return;
    saveRewriteOriginalState();
    sessionStorage.setItem('hgt_qc_text', lastResult.cleaned);
    window.location.href = '/studio/qc?from=rewrite&src=original&text=' + encodeURIComponent(lastResult.cleaned);
});

document.getElementById('btnRegen')?.addEventListener('click', function () {
    document.getElementById('rwForm').scrollIntoView({ behavior: 'smooth' });
});

// 初始化
updateCharCount();

// 话术模板联动：从「话术模板」带入 ?tpl= 到原始稿输入框
(function () {
    try {
        var p = new URLSearchParams(window.location.search);
        var tpl = p.get('tpl');
        if (tpl) {
            var ta = document.getElementById('text');
            if (ta) ta.value = tpl;
            updateCharCount();
            hgtToast('info', '已带入模板话术，可直接改写');
        }
    } catch (e) {}
})();
</script>
</x-workspace-layout>
</x-app-layout>

