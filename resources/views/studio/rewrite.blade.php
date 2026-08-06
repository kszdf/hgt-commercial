<x-app-layout>
<x-workspace-layout title="智能二创">
<div class="mx-auto max-w-5xl p-6">

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- ===== 左侧：输入区（增强版） ===== -->
        <section class="luxury-glass p-5">
            <!-- 批量选题面板（从"全部去二创"跳转时显示） -->
            <div id="batchPanel" class="mb-4 hidden rounded-lg border border-brand-200 bg-brand-50/50 p-3">
                <div class="mb-2 flex items-center justify-between">
                    <h4 class="text-xs font-semibold text-brand-700">待改写选题（点击选用）</h4>
                    <button type="button" id="toggleBatchPanel" class="text-xs text-slate-500 hover:text-slate-700">收起 ▲</button>
                </div>
                <div id="batchTopicList" class="max-h-48 space-y-1.5 overflow-y-auto"></div>
            </div>

            <form id="rwForm" class="space-y-4">
                <!-- 改写模式 -->
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">改写模式</label>
                    <select id="mode" name="mode"
                        class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option value="dual">双声对话（女问男答·推荐）</option>
                        <option value="single">单人口播（男声张老师）</option>
                        <option value="script">专业口播稿（保留术语）</option>
                    </select>
                </div>

                <!-- 重点方向（新增：标签式多选，传给 AI 引导改写策略） -->
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

                <!-- 原始稿（带实时字数统计） -->
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <label class="text-sm font-medium text-slate-600">原始稿</label>
                        <span id="charCounter" class="text-xs text-slate-400">0 字 · 预计约 0 秒</span>
                    </div>
                    <textarea id="text" name="text" rows="10" required
                        class="w-full rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                        placeholder="粘贴你的口播稿 / 文案 / 选题标题…&#10;&#10;提示：从「智能选题」点「选用」过来的标题会自动填入此处"></textarea>
                </div>

                <!-- 目标时长（控制改写稿长度匹配视频时长） -->
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">
                        目标时长 <span class="font-normal text-slate-400">（控制改写稿长度）</span>
                    </label>
                    <div class="flex items-center gap-2">
                        <select id="durationPreset" name="duration_preset"
                            class="shrink-0 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
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
                            class="hidden w-28 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <span id="durationHint" class="text-xs text-slate-400">约 130–160 字/分钟</span>
                    </div>
                    <input type="hidden" id="targetDuration" name="target_duration" value="">
                </div>

                <!-- 保留要素（用户指定改写时必须保留的内容） -->
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">
                        保留要素 <span class="font-normal text-slate-400">（可选，改写时必须保留的关键内容）</span>
                    </label>
                    <textarea id="preserve" name="preserve" rows="2"
                        class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none placeholder:text-slate-300 focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
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

        <!-- ===== 右侧：结果区（增强版） ===== -->
        <section class="luxury-glass p-5">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-700">改写结果</h3>
                <span id="statusBadge" class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-500">待生成</span>
            </div>

            <!-- 空状态 -->
            <div id="emptyState" class="space-y-3">
                <div class="rounded-lg bg-slate-50 p-6 text-center">
                    <div class="mx-auto mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-slate-200">
                        <svg class="h-5 w-5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </div>
                    <p class="text-sm text-slate-400">改写后的稿子与违禁词标记将显示在这里<br><span class="text-xs text-slate-300">支持从选题页一键带入标题</span></p>
                </div>
            </div>

            <!-- 结果内容（动态填充） -->
            <div id="result" class="hidden space-y-3"></div>

            <!-- 元数据条（字数/时长/违禁词统计） -->
            <div id="metaBar" class="mt-3 hidden rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                <div class="grid grid-cols-2 gap-2 text-xs sm:grid-cols-4" id="metaGrid"></div>
            </div>

            <!-- 操作按钮组（改写完成后显示） -->
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
// ========== 1. 从选题页跳转自动填入 ==========
(function () {
    const params = new URLSearchParams(window.location.search);
    const fromTopic = params.get('from');

    // 1a. 单条选题跳转（从选题卡片"去二创"过来）
    if (fromTopic === 'topic') {
        const title = sessionStorage.getItem('hgt_topic_title') || '';
        const hook = sessionStorage.getItem('hgt_topic_hook') || '';
        const ta = document.getElementById('text');
        if (title && ta) {
            ta.value = title + (hook ? '\n\n（钩子方向：' + hook + '）' : '');
            sessionStorage.removeItem('hgt_topic_title');
            sessionStorage.removeItem('hgt_topic_hook');
            updateCharCount();
            const hint = document.createElement('div');
            hint.className = 'mb-4 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-700';
            hint.innerHTML = '已从「智能选题」带入选题标题，可直接点击「智能改写」开始二创。 <a href="/studio/topic" class="font-medium underline hover:text-brand-900">← 返回选题</a>';
            document.querySelector('header').after(hint);
        }
    }

    // 1b. 批量选题跳转（从"全部去二创"过来）
    if (fromTopic === 'topic-all') {
        const raw = sessionStorage.getItem('hgt_batch_topics');
        let topics = [];
        try { topics = raw ? JSON.parse(raw) : []; } catch(e) { topics = []; }
        if (topics.length) {
            const panel = document.getElementById('batchPanel');
            const list = document.getElementById('batchTopicList');
            panel.classList.remove('hidden');

            topics.forEach((t, i) => {
                const el = document.createElement('div');
                el.className = 'cursor-pointer rounded-md border border-white bg-white px-3 py-2 text-xs transition hover:border-brand-300 hover:shadow-sm';
                el.innerHTML =
                    '<div class="flex items-start justify-between gap-2">' +
                        '<div class="min-w-0 flex-1">' +
                            '<span class="rounded bg-slate-100 px-1 py-0.5 text-[10px] text-slate-500">' + (i+1) + '</span> ' +
                            '<span class="font-medium text-slate-700">' + escapeHtml(t.title) + '</span>' +
                            (t.hook ? '<p class="mt-0.5 text-slate-400 truncate">钩子：' + escapeHtml(t.hook) + '</p>' : '') +
                        '</div>' +
                    '</div>';
                el.addEventListener('click', function() {
                    const ta = document.getElementById('text');
                    ta.value = t.title + (t.hook ? '\n\n（钩子方向：' + t.hook + '）' : '');
                    updateCharCount();
                    // 高亮当前选中
                    list.querySelectorAll('div').forEach(d => d.classList.remove('border-brand-400','bg-brand-50'));
                    this.classList.add('border-brand-400','bg-brand-50');
                });
                list.appendChild(el);
            });

            // 收起/展开切换
            document.getElementById('toggleBatchPanel').addEventListener('click', function() {
                const listEl = document.getElementById('batchTopicList');
                const isHidden = listEl.style.display === 'none';
                listEl.style.display = isHidden ? '' : 'none';
                this.textContent = isHidden ? '收起 ▲' : '展开 ' + topics.length + ' 条 ▼';
            });
        }
        sessionStorage.removeItem('hgt_batch_topics'); // 用完即清
    }
})();

// ========== 2. 重点方向标签选择 ==========
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

// ========== 3. 目标时长预设切换 ==========
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
        // 更新提示文字
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

// ========== 4. 实时字数统计 ==========
function updateCharCount() {
    const txt = document.getElementById('text').value.replace(/\s/g, '');
    const chars = txt.length;
    const sec = Math.max(1, Math.round(chars / 4.5));
    const fmt = sec >= 60 ? Math.floor(sec/60)+'分'+(sec%60)+'秒' : '约'+sec+'秒';
    document.getElementById('charCounter').textContent = chars + ' 字 · 预计 ' + fmt;
}
document.getElementById('text').addEventListener('input', updateCharCount);

// ========== 4. 提交改写 ==========
let lastResult = null; // 保存最近一次结果，供操作按钮使用

document.getElementById('rwForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('genBtn');
    const msg = document.getElementById('formMsg');
    const badge = document.getElementById('statusBadge');
    const result = document.getElementById('result');
    const emptyState = document.getElementById('emptyState');
    const errBox = document.getElementById('errorBox');
    const metaBar = document.getElementById('metaBar');
    const actionBar = document.getElementById('actionBar');

    msg.textContent = ''; errBox.classList.add('hidden');
    metaBar.classList.add('hidden'); actionBar.classList.add('hidden');
    btn.disabled = true; btn.textContent = '⏳ AI 改写中…';
    badge.textContent = '改写中'; badge.className = 'rounded-full bg-brand-100 px-3 py-1 text-xs text-brand-600';

    try {
        const resp = await fetch('/studio/rewrite/generate', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({
                mode: document.getElementById('mode').value,
                text: document.getElementById('text').value,
                focus: document.getElementById('focus').value || undefined,
                target_duration: document.getElementById('targetDuration').value || undefined,
                preserve: document.getElementById('preserve').value.trim() || undefined,
            })
        });
        const data = await resp.json();
        if (!resp.ok) throw new Error(data.error || '提交失败');
        if (!data.ok) throw new Error(data.error || '生成失败');

        lastResult = data;

        // 状态徽章
        const hits = (data.hits || []);
        const meta = data.meta || {};
        badge.textContent = '完成' + (meta.hit_count ? ' · ' + meta.hit_count + '处标红' : '');
        badge.className = 'rounded-full ' + (meta.high_risk_count ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700') + ' px-3 py-1 text-xs';

        // 隐藏空状态，显示结果
        emptyState.classList.add('hidden');
        result.classList.remove('hidden');

        // 构建结果 HTML
        let html = '';

        // 改写稿卡片
        html += '<div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">';
        html += '<div class="mb-1.5 flex items-center justify-between text-xs font-medium">';
        html += '<span class="text-slate-500">改写稿（违禁词已<span class="text-red-500">标红</span>）</span>';
        html += '<span class="text-slate-400">' + (meta.clean_chars || 0) + '字</span></div>';
        html += '<div class="whitespace-pre-wrap text-sm text-slate-700 leading-relaxed max-h-80 overflow-y-auto">' + highlight(data.rewritten, hits) + '</div></div>';

        // 违禁词警告
        if (hits.length) {
            const high = hits.filter(h => h.level === 'high');
            html += '<div class="rounded-xl ' + (high.length ? 'border-red-200 bg-red-50' : 'border-amber-200 bg-amber-50') + ' p-3 text-xs ' + (high.length ? 'text-red-600' : 'text-amber-700') + '">';
            html += '<div class="rounded-lg bg-amber-50 p-2.5 text-xs text-amber-700">命中<strong>' + hits.length + '</strong>处' + (high.length ? '（<strong>' + high.length + '处高风险</strong>）' : '') + '违禁词：';
            html += hits.map(h => escapeHtml(h.word || '')).join('、') + '</div>';
        }

        // 清洗后可配音稿
        html += '<div class="rounded-xl border border-slate-200 bg-white p-4">';
        html += '<div class="mb-1.5 flex items-center justify-between text-xs font-medium text-slate-500">';
        html += '<span>🧹 清洗后可配音稿</span>';
        html += '<button type="button" onclick="copyText()" class="text-brand-500 hover:underline font-medium">复制</button></div>';
        html += '<div id="cleaned" class="whitespace-pre-wrap text-sm text-slate-700 leading-relaxed max-h-60 overflow-y-auto">' + escapeHtml(data.cleaned || '') + '</div></div>';

        result.innerHTML = html;

        // 元数据条
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

        // 显示操作按钮组
        actionBar.classList.remove('hidden');

        btn.disabled = false; btn.textContent = '智能改写';
    } catch (err) {
        btn.disabled = false; btn.textContent = '智能改写';
        badge.textContent = '失败'; badge.className = 'rounded-full bg-red-100 px-3 py-1 text-xs text-red-600';
        errBox.textContent = err.message; errBox.classList.remove('hidden');
    }
});

// ========== 5. 操作按钮动作 ==========
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

// 带稿去出片 → 存 sessionStorage 跳 scroll 页
document.getElementById('btnGoScroll')?.addEventListener('click', function () {
    if (!lastResult || !lastResult.cleaned) return;
    sessionStorage.setItem('hgt_rewrite_cleaned', lastResult.cleaned);
    sessionStorage.setItem('hgt_rewrite_mode', document.getElementById('mode').value);
    window.location.href = '/studio/scroll?from=rewrite';
});

// 跑质检 → 存原文跳 QC 页
document.getElementById('btnGoQc')?.addEventListener('click', function () {
    if (!lastResult || !lastResult.cleaned) return;
    sessionStorage.setItem('hgt_qc_text', lastResult.cleaned);
    window.location.href = '/studio/qc?from=rewrite';
});

// 重新改写 → 滚动回表单
document.getElementById('btnRegen')?.addEventListener('click', function () {
    document.getElementById('rwForm').scrollIntoView({ behavior: 'smooth' });
});

// ========== 工具函数 ==========
function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function highlight(text, hits){
    let h = escapeHtml(text);
    (hits||[]).forEach(it => {
        const w = escapeHtml(it.word || '');
        if (w) h = h.split(w).join('<mark class="bg-red-100 text-red-700 rounded px-0.5">' + w + '</mark>');
    });
    return h;
}
</script>
</x-workspace-layout>
</x-app-layout>
