<x-app-layout>
<x-workspace-layout title="选题二创">
    <div class="mx-auto max-w-5xl p-6">

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- ===== 左侧：选题改写输入区 ===== -->
        <section class="luxury-glass p-5">
            <!-- 无选题来源时的提示 -->
            <div id="noSourceBox" class="hidden rounded-lg border border-amber-200 bg-amber-50 px-4 py-5 text-center">
                <p class="text-sm font-medium text-amber-800">未检测到选题来源</p>
                <p class="mt-1 text-xs text-amber-700">选题二创需从「智能选题」选择选题后进入。如需改写自有稿件，请使用「原始稿二创」。</p>
                <div class="mt-3 flex justify-center gap-2">
                    <a href="/studio/topic" class="inline-flex items-center rounded-md bg-brand-500 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-brand-600">去智能选题 →</a>
                    <a href="/studio/rewrite-original" class="inline-flex items-center rounded-md bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm ring-1 ring-slate-200 hover:bg-slate-50">原始稿二创</a>
                </div>
            </div>

            <!-- 来源模式指示条 -->
            <div id="sourceBanner" class="mb-4 hidden rounded-lg border border-brand-200 bg-gradient-to-r from-brand-50 to-white px-4 py-3">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-xs font-medium text-brand-700" id="sourceTitle">基于选题二创</p>
                        <p class="text-[11px] text-slate-500" id="sourceDesc">已从「智能选题」带入 <span id="sourceCount">0</span> 条选题</p>
                    </div>
                    <a href="/studio/topic" class="shrink-0 rounded-md bg-white px-2 py-1 text-xs font-medium text-slate-600 shadow-sm ring-1 ring-slate-200 transition hover:bg-slate-50">返回选题</a>
                </div>
            </div>

            <!-- 批量选题面板（从"全部去二创"跳转时显示） -->
            <div id="batchPanel" class="mb-4 hidden rounded-lg border border-brand-200 bg-brand-50/50 p-3">
                <div class="mb-2 flex items-center justify-between">
                    <h4 class="text-xs font-semibold text-brand-700">待改写选题（点击单条选用，或批量改写全部）</h4>
                    <button type="button" id="toggleBatchPanel" class="text-xs text-slate-500 hover:text-slate-700">收起 ▲</button>
                </div>
                <div id="batchTopicList" class="max-h-60 space-y-1.5 overflow-y-auto"></div>
                <div id="batchActions" class="mt-2 flex items-center justify-between border-t border-brand-200 pt-2">
                    <span class="text-[11px] text-slate-500">按每条选题自带的呈现形式串行改写</span>
                    <button type="button" id="batchRewriteAllBtn" class="inline-flex items-center gap-1 rounded-md bg-brand-500 px-3 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-brand-600 disabled:opacity-50 disabled:cursor-not-allowed">
                        全部二创
                    </button>
                </div>
            </div>

            <form id="rwForm" class="space-y-4">
                <!-- 改写模式 -->
                <div>
                    <label id="modeLabel" class="mb-1 block text-sm font-medium text-slate-600">改写模式</label>
                    <select id="mode" name="mode"
                        class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option value="avatar">单人数字人出镜</option>
                        <option value="scroll_male">男声幕后音·动态画面</option>
                        <option value="scroll_female">女声幕后音·动态画面</option>
                        <option value="scroll_dual">男女对话幕后音·动态画面</option>
                        <option value="scroll">📋 幕后音·滚动字幕</option>
                        <option value="manga">📖 AI 漫剧</option>
                        <option value="whiteboard">✍️ AI 白板图解</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-400">模式已按选题页所选「呈现形式」自动匹配，可手动微调。</p>
                    <p id="modeHint" class="mt-1 text-xs text-brand-600"></p>
                    <label id="forceUnifiedWrap" class="mt-2 hidden flex cursor-pointer items-center gap-2 text-xs text-slate-500">
                        <input type="checkbox" id="forceUnified" class="accent-brand-500 rounded">
                        强制统一形式（忽略选题自带的呈现形式，全部用上方所选）
                    </label>
                </div>

                <!-- 角色与声音分配 -->
                <div class="rounded-lg studio-card studio-card-sm">
                    <label class="mb-1.5 block text-sm font-medium text-slate-600">
                        角色与声音
                        <span class="font-normal text-slate-400">（按呈现形式自动分声线）</span>
                    </label>
                    <select id="roleMode" name="role_mode"
                        class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option value="auto">自动分配（由 AI 根据内容判断男声/女声/对话）</option>
                        <option value="single_male">单人口播（男声）</option>
                        <option value="single_female">单人口播（女声）</option>
                        <option value="dual_female_lead">男女对话（女声开头）</option>
                        <option value="dual_male_lead">男女对话（男声开头）</option>
                        <option value="narrator_male">男声幕后音（解说口吻）</option>
                        <option value="narrator_female">女声幕后音（解说口吻）</option>
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

                <!-- 选题原始稿 -->
                <div id="textWrap">
                    <div class="mb-1 flex items-center justify-between">
                        <label class="text-sm font-medium text-slate-600" id="textLabel">选题原始稿</label>
                        <span id="charCounter" class="text-xs text-slate-400">0 字 · 预计约 0 秒</span>
                    </div>
                    <textarea id="text" name="text" rows="10" required
                        class="w-full rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                        placeholder="请先从上方选题列表选择一条选题，或从「智能选题」重新进入…"></textarea>
                    <p id="textHint" class="mt-1 hidden text-[11px] text-slate-400">来源：智能选题 — 可直接点击「智能改写」生成口播稿</p>
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
                    <p class="text-sm text-slate-400">改写后的稿子与违禁词标记将显示在这里<br><span class="text-xs text-slate-300">支持从选题页一键带入标题</span></p>
                </div>
            </div>

            <!-- 批量进度恢复提示 -->
            <div id="batchResumeBanner" class="hidden mb-3 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-700"></div>
            <!-- 单条改写恢复提示 -->
            <div id="singleRestoreNote" class="hidden mb-3 rounded-lg border border-brand-200 bg-brand-50 px-4 py-2 text-sm text-brand-700">已恢复上次的改写内容，可继续编辑或直接改写。</div>

            <!-- 出片引导：批量出片功能已移除，逐条用卡片内「带稿去出片」 -->
            <p class="mb-3 rounded-lg border border-brand-200 bg-brand-50 px-4 py-2 text-xs text-brand-700">改写完成后，在下面每条结果卡片里点「带稿去出片」即可逐条生成视频。</p>

            <!-- 批量结果列表 -->
            <div id="batchResult" class="hidden space-y-3"></div>

            <!-- 结果内容 -->
            <div id="result" class="hidden space-y-3"></div>

            <!-- 元数据条 -->
            <div id="metaBar" class="mt-3 hidden rounded-lg studio-card studio-card-sm">
                <div class="grid grid-cols-2 gap-2 text-xs sm:grid-cols-4" id="metaGrid"></div>
            </div>

            <!-- 操作按钮组 -->
            <div id="actionBar" class="mt-4 hidden rounded-xl border border-brand-200 bg-gradient-to-r from-brand-50 to-white p-4">
                <p class="mb-3 text-sm font-semibold text-brand-700">改写完成 — 下一步</p>
                <div class="flex flex-col gap-2.5">
                    <button type="button" id="btnGoScroll" class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-brand-600 active:scale-[0.98]">
                        带稿去出片 <span aria-hidden="true">→</span>
                    </button>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex flex-wrap gap-2">
                            <button type="button" id="btnCopy" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-sm font-medium text-slate-600 shadow-sm transition hover:bg-slate-50 active:scale-[0.98]">
                                复制清洗稿
                            </button>
                            <button type="button" id="btnGoQc" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-sm font-medium text-slate-600 shadow-sm transition hover:bg-slate-50 active:scale-[0.98]">
                                跑质检 <span aria-hidden="true">→</span>
                            </button>
                        </div>
                        <button type="button" id="btnRegen" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 active:scale-[0.98]">
                            重新改写
                        </button>
                    </div>
                </div>
            </div>

            <div id="errorBox" class="mt-3 hidden rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600"></div>

            <!-- 批量改写确认面板 -->
            <div id="batchRewriteModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl">
                    <h3 class="text-base font-semibold text-slate-800">确认批量改写</h3>
                    <p class="mt-1 text-xs text-slate-500">以下配置将应用于全部 <span id="brwCount" class="font-medium text-brand-600">0</span> 条选题，确认后再开始改写。</p>
                    <dl id="brwSummary" class="mt-3 space-y-1.5 text-xs text-slate-600"></dl>
                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" id="brwCancel" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600">取消</button>
                        <button type="button" id="brwStart" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white">开始批量改写</button>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- 出片进度记录弹窗（纯 inline 样式，避开 Tailwind 扫描静默失效） -->
<div id="jobLogModal" style="position:fixed;inset:0;z-index:50;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.45)">
  <div style="width:100%;max-width:28rem;margin:0 1rem;background:#fff;border-radius:.75rem;box-shadow:0 20px 25px -5px rgba(0,0,0,.15);overflow:hidden">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.75rem 1rem;border-bottom:1px solid #f1f5f9">
      <h4 style="font-size:.875rem;font-weight:600;color:#334155">出片进度记录</h4>
      <button type="button" onclick="closeJobLog()" style="border:none;background:#f8fafc;border-radius:.375rem;padding:.25rem .55rem;color:#94a3b8;cursor:pointer;font-size:.875rem">✕</button>
    </div>
    <div id="jobLogBody" style="max-height:60vh;overflow-y:auto;padding:1rem;font-size:.75rem;color:#475569">加载中…</div>
  </div>
</div>

<script>
// ========== 0. 状态与工具函数 ==========
let lastResult = null;
let batchResults = [];
let currentTopics = [];

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
    // 2026-08-28 语速口径统一: 4.5字/秒(270字/分)修正为 2.4字/秒(145字/分), 与出片页/后端一致
    const sec = Math.max(1, Math.round(chars / 2.4));
    const fmt = sec >= 60 ? Math.floor(sec/60)+'分'+(sec%60)+'秒' : '约'+sec+'秒';
    document.getElementById('charCounter').textContent = chars + ' 字 · 预计 ' + fmt;
}
function mapTopicFormToMode(form) {
    // 选题页 form 值 → 二创页 mode 值（统一 7 种呈现形式，2026-08-31 补 scroll 滚动字幕）
    if (!form) return 'avatar';
    const f = String(form).trim();
    // 7 值直接透传
    if (['avatar','scroll_male','scroll_female','scroll_dual','scroll','manga','whiteboard'].includes(f)) return f;
    // 兼容旧值/Topic API 返回值（2026-08-28 修复：幕后音口播_单人 曾误映射为数字人 avatar）
    if (f === '单声口播' || f === '单人口播' || f === 'script') return 'avatar';
    if (f === '幕后音口播_单人') return 'scroll_male';
    if (f === '幕后音口播_双人' || f === '双声对话' || f === '双人口播') return 'scroll_dual';
    return 'avatar';
}
function setModeSelect(value) {
    const sel = document.getElementById('mode');
    if (sel && ['avatar','scroll_male','scroll_female','scroll_dual','scroll','manga','whiteboard'].includes(value)) sel.value = value;
}
function showSourceBanner(type, count, sourceUrl) {
    const banner = document.getElementById('sourceBanner');
    const title = document.getElementById('sourceTitle');
    const desc = document.getElementById('sourceDesc');
    const cnt = document.getElementById('sourceCount');
    if (!banner) return;
    banner.classList.remove('hidden');
    if (type === 'topic') {
        title.textContent = '基于单条选题二创';
        desc.innerHTML = '已从「智能选题」带入 1 条选题，可直接改写';
    } else if (type === 'hotspot') {
        title.textContent = '来自全网财税热点';
        let d = '已从「全网财税热点」带入 1 条热点选题与创作角度，可直接改写';
        if (sourceUrl) d += ' · <a href="' + escapeHtml(sourceUrl) + '" target="_blank" rel="noopener" class="text-brand-600 underline">查看原文 ↗</a>';
        desc.innerHTML = d;
    } else if (type === 'dissect') {
        title.textContent = '来自爆款拆解';
        desc.innerHTML = '已从「爆款拆解」带入完整文案与可复刻结构，可直接改写（请替换为你的行业案例与数字人）';
    } else if (type === 'hotspot-all') {
        title.textContent = '来自全网财税热点（批量）';
        cnt.textContent = count || 0;
    } else {
        title.textContent = '基于批量选题二创';
        cnt.textContent = count || 0;
    }
}
function hideSourceBanner() {
    document.getElementById('sourceBanner')?.classList.add('hidden');
}
function setTextFromTopic(title, hook, mode, angle) {
    const ta = document.getElementById('text');
    let body = title || '';
    if (angle) body += '\n\n' + angle;
    ta.value = body + (hook ? '\n\n（钩子方向：' + hook + '）' : '');
    updateCharCount();
    document.getElementById('textLabel').textContent = '选题原始稿';
    document.getElementById('textHint').classList.remove('hidden');
    if (mode) setModeSelect(mode);
}
function getFormLabel(form) {
    const map = {
        'avatar': '数字人出镜',
        'scroll_male': '男声幕后音·动态画面',
        'scroll_female': '女声幕后音·动态画面',
        'scroll_dual': '男女对话幕后音·动态画面',
        'scroll': '幕后音·滚动字幕',
        'manga': 'AI 漫剧',
        'whiteboard': 'AI 白板图解',
        '单声口播': '单声',
        '幕后音口播_单人': '单声',
        '幕后音口播_双人': '双声'
    };
    return map[form] || '默认';
}

// ========== 1. 从选题页跳转自动填入 ==========
(function () {
    const params = new URLSearchParams(window.location.search);
    const fromTopic = params.get('from');

    // 1a. 单条选题跳转（从选题卡片"去二创"过来）
    if (fromTopic === 'topic') {
        const title = sessionStorage.getItem('hgt_topic_title') || '';
        const hook = sessionStorage.getItem('hgt_topic_hook') || '';
        const form = sessionStorage.getItem('hgt_topic_form') || '';
        const angle = sessionStorage.getItem('hgt_topic_angle') || '';
        const potential = sessionStorage.getItem('hgt_topic_potential') || '';
        window.__topicIndustry = sessionStorage.getItem('hgt_topic_industry') || '';
        if (title) {
            setTextFromTopic(title, hook, mapTopicFormToMode(form), angle + (potential ? '\n\n（爆款潜力：' + potential + '）' : ''));
            showSourceBanner('topic', 1);
            sessionStorage.removeItem('hgt_topic_title');
            sessionStorage.removeItem('hgt_topic_hook');
            sessionStorage.removeItem('hgt_topic_form');
            sessionStorage.removeItem('hgt_topic_industry');
            sessionStorage.removeItem('hgt_topic_angle');
            sessionStorage.removeItem('hgt_topic_potential');
        } else {
            document.getElementById('noSourceBox')?.classList.remove('hidden');
        }
        return;
    }

    // 1b. 批量选题跳转（从"全部去二创"过来）
    if (fromTopic === 'topic-all') {
        const raw = sessionStorage.getItem('hgt_batch_topics');
        let topics = [];
        try { topics = raw ? JSON.parse(raw) : []; } catch(e) { topics = []; }
        // 从出片/质检返回时 hgt_batch_topics 已被消费，改从已保存的批量状态恢复选题
        if (!topics.length) {
            const saved0 = loadBatchRewriteState();
            if (saved0 && saved0.topics && saved0.topics.length) topics = saved0.topics;
        }
        if (topics.length) {
            currentTopics = topics;
            showSourceBanner('topic-all', topics.length);
            // 2026-08-31 修复：批量链路恢复行业上下文（topic 页已存 hgt_batch_industry）
            if (!window.__topicIndustry) {
                window.__topicIndustry = sessionStorage.getItem('hgt_batch_industry') || '';
            }
            // 批量模式：隐藏单条专属字段，避免「单文本框承载多选题」的语义矛盾
            document.body.classList.add('entry-batch');
            document.getElementById('textWrap')?.classList.add('hidden');
            document.getElementById('genBtn')?.classList.add('hidden');
            const ml = document.getElementById('modeLabel');
            if (ml) ml.textContent = '批量统一呈现形式';
            document.getElementById('forceUnifiedWrap')?.classList.remove('hidden');
            renderBatchPanel(topics);
            // 自动选中第一条，让模式下拉立即反映选题形式
            selectBatchTopic(0);
            // 恢复上次批量进度（同一批选题已有结果时）
            const saved = loadBatchRewriteState();
            if (saved && Array.isArray(saved.results) && saved.results.length) {
                batchResults = saved.results;
                renderBatchProgress();
                renderBatchResumeBanner(saved.results);
            }
        } else {
            document.getElementById('noSourceBox')?.classList.remove('hidden');
        }
        sessionStorage.removeItem('hgt_batch_topics');
        return;
    }

    // 1c. 热点选题跳转（从「全网财税热点」卡片"去二创"过来）
    if (fromTopic === 'hotspot') {
        const title = sessionStorage.getItem('hgt_topic_title') || '';
        const summary = sessionStorage.getItem('hgt_topic_summary') || '';
        const angle = sessionStorage.getItem('hgt_topic_angle') || '';
        const hook = sessionStorage.getItem('hgt_topic_hook') || '';
        const form = sessionStorage.getItem('hgt_topic_form') || '';
        const sourceUrl = sessionStorage.getItem('hgt_topic_source_url') || '';
        if (title || angle) {
            setTextFromTopic(title, hook, mapTopicFormToMode(form), angle || summary);
            showSourceBanner('hotspot', 1, sourceUrl);
            sessionStorage.removeItem('hgt_topic_title');
            sessionStorage.removeItem('hgt_topic_summary');
            sessionStorage.removeItem('hgt_topic_angle');
            sessionStorage.removeItem('hgt_topic_hook');
            sessionStorage.removeItem('hgt_topic_form');
            sessionStorage.removeItem('hgt_topic_source_url');
            sessionStorage.removeItem('hgt_topic_matched_sub');
            sessionStorage.removeItem('hgt_topic_from');
        } else {
            document.getElementById('noSourceBox')?.classList.remove('hidden');
        }
        return;
    }

    // 1c2. 热点批量跳转（从「全部去二创」过来）
    if (fromTopic === 'hotspot-all') {
        const raw = sessionStorage.getItem('hgt_batch_hotspots');
        let topics = [];
        try { topics = raw ? JSON.parse(raw) : []; } catch(e) { topics = []; }
        if (!topics.length) {
            const saved0 = loadBatchRewriteState();
            if (saved0 && saved0.topics && saved0.topics.length) topics = saved0.topics;
        }
        if (topics.length) {
            currentTopics = topics;
            showSourceBanner('hotspot-all', topics.length);
            document.body.classList.add('entry-batch');
            document.getElementById('textWrap')?.classList.add('hidden');
            document.getElementById('genBtn')?.classList.add('hidden');
            const ml = document.getElementById('modeLabel');
            if (ml) ml.textContent = '批量统一呈现形式';
            document.getElementById('forceUnifiedWrap')?.classList.remove('hidden');
            renderBatchPanel(topics);
            selectBatchTopic(0);
            const saved = loadBatchRewriteState();
            if (saved && Array.isArray(saved.results) && saved.results.length) {
                batchResults = saved.results;
                renderBatchProgress();
                renderBatchResumeBanner(saved.results);
            }
        } else {
            document.getElementById('noSourceBox')?.classList.remove('hidden');
        }
        sessionStorage.removeItem('hgt_batch_hotspots');
        return;
    }

    // 1d. 爆款拆解跳转（从「爆款拆解」页"去二创"过来）
    if (fromTopic === 'dissect') {
        const title = sessionStorage.getItem('hgt_dissect_title') || '';
        const text = sessionStorage.getItem('hgt_dissect_text') || '';
        const hook = sessionStorage.getItem('hgt_dissect_hook') || '';
        const form = sessionStorage.getItem('hgt_dissect_form') || '';
        const angle = sessionStorage.getItem('hgt_dissect_angle') || '';
        if (text || title || angle) {
            // 完整文案作正文（setTextFromTopic 的 title 参数即 textarea 主体），标题/角度作补充
            const body = (title ? '【原视频】' + title + '\n' : '') + (angle || '');
            setTextFromTopic(text || title, hook, mapTopicFormToMode(form), body);
            showSourceBanner('dissect', 1);
            sessionStorage.removeItem('hgt_dissect_title');
            sessionStorage.removeItem('hgt_dissect_text');
            sessionStorage.removeItem('hgt_dissect_hook');
            sessionStorage.removeItem('hgt_dissect_form');
            sessionStorage.removeItem('hgt_dissect_angle');
        } else {
            document.getElementById('noSourceBox')?.classList.remove('hidden');
        }
        return;
    }

    // 无来源参数：提示用户
    document.getElementById('noSourceBox')?.classList.remove('hidden');
})();

function selectBatchTopic(index) {
    const list = document.getElementById('batchTopicList');
    const topic = currentTopics[index];
    if (!topic) return;
    setTextFromTopic(topic.title, topic.hook || '', mapTopicFormToMode(topic.form), topic.angle || topic.summary || '');
    list.querySelectorAll('[data-index]').forEach(d => d.classList.remove('border-brand-400','bg-brand-50'));
    const active = list.querySelector('[data-index="' + index + '"]');
    if (active) active.classList.add('border-brand-400','bg-brand-50');
}

function renderBatchPanel(topics) {
    const panel = document.getElementById('batchPanel');
    const list = document.getElementById('batchTopicList');
    if (!panel || !list) return;
    panel.classList.remove('hidden');
    list.innerHTML = '';

    topics.forEach((t, i) => {
        const el = document.createElement('div');
        el.className = 'group rounded-md border border-white bg-white px-3 py-2 text-xs transition hover:border-brand-300 hover:shadow-sm';
        el.dataset.index = i;
        const formLabel = getFormLabel(t.form);
        el.innerHTML =
            '<div class="flex items-start justify-between gap-2">' +
                '<div class="min-w-0 flex-1 cursor-pointer" data-select>' +
                    '<span class="rounded bg-slate-100 px-1 py-0.5 text-[10px] text-slate-500">' + (i+1) + '</span> ' +
                    '<span class="rounded bg-brand-50 px-1 py-0.5 text-[10px] text-brand-600">' + formLabel + '</span> ' +
                    '<span class="font-medium text-slate-700">' + escapeHtml(t.title) + '</span>' +
                    (t.hook ? '<p class="mt-0.5 text-slate-400 truncate">钩子：' + escapeHtml(t.hook) + '</p>' : '') +
                '</div>' +
                '<button type="button" data-rewrite class="shrink-0 rounded-md bg-brand-500 px-2 py-1 text-[11px] font-medium text-white opacity-0 transition hover:bg-brand-600 group-hover:opacity-100">二创</button>' +
            '</div>';

        el.querySelector('[data-select]').addEventListener('click', function() {
            selectBatchTopic(i);
        });

        el.querySelector('[data-rewrite]').addEventListener('click', function(e) {
            e.stopPropagation();
            selectBatchTopic(i);
            runSingleRewrite();
        });

        list.appendChild(el);
    });

    document.getElementById('toggleBatchPanel').addEventListener('click', function() {
        const listEl = document.getElementById('batchTopicList');
        const actionsEl = document.getElementById('batchActions');
        const isHidden = listEl.style.display === 'none';
        listEl.style.display = isHidden ? '' : 'none';
        actionsEl.style.display = isHidden ? 'flex' : 'none';
        this.textContent = isHidden ? '收起 ▲' : '展开 ' + topics.length + ' 条 ▼';
    });

    document.getElementById('batchRewriteAllBtn').addEventListener('click', openBatchRewriteModal);
}

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

// ========== 2.5 角色与声音分配面板交互 ==========
const roleMode = document.getElementById('roleMode');
const roleNote = document.getElementById('roleNote');
    if (roleMode && roleNote) {
        roleMode.addEventListener('change', function () {
            if (this.value === 'custom') {
                roleNote.classList.remove('hidden');
                roleNote.focus();
            } else {
                roleNote.classList.add('hidden');
            }
        });
    }

    // 呈现形式（mode）变化 → 自动联动「角色与声音」，根治「选了女声幕后音却以男：开头」
    // 仅响应用户手动操作（selectBatchTopic 用代码赋值不触发 change），不覆盖用户已手动设的角色
    const modeSel = document.getElementById('mode');
    if (modeSel) {
        modeSel.addEventListener('change', function () {
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
                if (d === 'scroll_female') rm.value = 'single_female';
                else if (d === 'scroll_male' || d === 'scroll') rm.value = 'single_male';
                else if (d === 'scroll_dual') rm.value = 'dual_male_lead';
                else rm.value = 'single_male'; // avatar 默认男声独白
            }
            rm.dispatchEvent(new Event('change'));
        });
    }

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
document.getElementById('text').addEventListener('input', updateCharCount);

// ========== 4.5 状态持久化（返回二创时不丢数据） ==========
function saveRewriteState() {
    try {
        sessionStorage.setItem('hgt_rewrite_state', JSON.stringify({
            text: document.getElementById('text').value,
            mode: document.getElementById('mode') ? document.getElementById('mode').value : '',
            focus: document.getElementById('focus') ? document.getElementById('focus').value : '',
            targetDuration: document.getElementById('targetDuration') ? document.getElementById('targetDuration').value : '',
            preserve: document.getElementById('preserve') ? document.getElementById('preserve').value : '',
            roleMode: document.getElementById('roleMode') ? document.getElementById('roleMode').value : '',
            roleNote: document.getElementById('roleNote') ? document.getElementById('roleNote').value : '',
            keepManualRoles: document.getElementById('keepManualRoles') ? document.getElementById('keepManualRoles').checked : false,
        }));
    } catch(e) {}
}
// 返回二创时恢复（有选题来源时由上方来源逻辑填充，这里不重复覆盖）
(function () {
    if (new URLSearchParams(window.location.search).get('from')) return;
    try {
        const raw = sessionStorage.getItem('hgt_rewrite_state');
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
        if (s.roleMode) document.getElementById('roleMode').value = s.roleMode;
        if (s.roleNote) { document.getElementById('roleNote').value = s.roleNote; document.getElementById('roleNote').classList.remove('hidden'); }
        if (s.keepManualRoles) document.getElementById('keepManualRoles').checked = true;
        updateCharCount();
        document.getElementById('singleRestoreNote')?.classList.remove('hidden');
    } catch(e) {}
})();
window.addEventListener('beforeunload', function () {
    saveRewriteState();
    if (currentTopics.length) saveBatchRewriteState();
});

// ========== 5. 提交改写 ==========
document.getElementById('rwForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    await runSingleRewrite();
});

// 呈现形式 → 后端改写模式（后端只认 single/dual/script/content）
function mapDisplayModeToRewriteMode(displayMode) {
    if (displayMode === 'scroll_dual') return 'dual';
    // 2026-08-31: AI 漫剧/白板图解走「内容规整」模式（产出内容稿供下游分镜/提炼），
    // 而非口播稿（口播稿喂给漫剧会被 classify 判为法条类拒单或语义错配）
    if (displayMode === 'manga' || displayMode === 'whiteboard') return 'content';
    return 'single'; // avatar / scroll_male / scroll_female / scroll / script 都按单人稿改写
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
            industry: window.__topicIndustry || undefined,
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
    const batchResult = document.getElementById('batchResult');
    const emptyState = document.getElementById('emptyState');
    const errBox = document.getElementById('errorBox');
    const metaBar = document.getElementById('metaBar');
    const actionBar = document.getElementById('actionBar');

    const text = document.getElementById('text').value.trim();
    if (!text) {
        msg.textContent = '请先从上方选题列表选择一条选题';
        return;
    }

    msg.textContent = ''; errBox.classList.add('hidden');
    metaBar.classList.add('hidden'); actionBar.classList.add('hidden');
    batchResult.classList.add('hidden'); batchResult.innerHTML = '';
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
            role_mode: document.getElementById('roleMode').value,
            role_note: document.getElementById('roleNote').value.trim(),
            keep_manual_roles: document.getElementById('keepManualRoles').checked,
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
    const batchResult = document.getElementById('batchResult');

    emptyState.classList.add('hidden');
    batchResult.classList.add('hidden');
    result.classList.remove('hidden');

    const hits = (data.hits || []);
    const meta = data.meta || {};

    let html = '';
    html += '<div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">';
    html += '<div class="mb-1 text-xs text-slate-500">改写稿（<span class="text-red-500 font-medium">标红</span>为违禁词/高风险词，已自动替换为下方清洗稿中的安全表达）</div>';
    html += '<div class="mb-1.5 flex items-center justify-between text-xs text-slate-400"><span>' + (meta.clean_chars || 0) + '字</span></div>';
    html += '<div class="whitespace-pre-wrap text-sm text-slate-700 leading-relaxed max-h-80 overflow-y-auto">' + highlight(data.rewritten, hits) + '</div></div>';

    if (hits.length) {
        const high = hits.filter(h => h.level === 'high');
        html += '<div class="rounded-xl ' + (high.length ? 'border-red-200 bg-red-50' : 'border-amber-200 bg-amber-50') + ' p-3 text-xs ' + (high.length ? 'text-red-600' : 'text-amber-700') + '">';
        html += '<div class="rounded-lg bg-amber-50 p-2.5 text-xs text-amber-700">命中<strong>' + hits.length + '</strong>处' + (high.length ? '（<strong>' + high.length + '处高风险</strong>）' : '') + '违禁词：';
        html += hits.map(h => escapeHtml(h.word || '')).join('、') + '</div>';
    }

    html += '<div class="rounded-xl border border-slate-200 bg-white p-4">';
    html += '<div class="mb-1.5 flex items-center justify-between text-xs font-medium text-slate-500">';
    html += '<span>🧹 清洗后可配音稿 <span class="rounded bg-green-50 px-1 py-0.5 text-[10px] font-normal text-green-600">用于配音/出片</span></span>';
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

// ========== 6.0 批量改写：持久化 / 重试 / 间隔 工具 ==========
const BATCH_STATE_KEY = 'hgt_batch_rewrite_state';
function sleep(ms){ return new Promise(r => setTimeout(r, ms)); }

function saveBatchRewriteState() {
    try {
        localStorage.setItem(BATCH_STATE_KEY, JSON.stringify({
            topics: currentTopics,
            results: batchResults,
            focus: document.getElementById('focus') ? document.getElementById('focus').value : '',
            target_duration: document.getElementById('targetDuration') ? document.getElementById('targetDuration').value : '',
            preserve: document.getElementById('preserve') ? document.getElementById('preserve').value : '',
            roleMode: document.getElementById('roleMode') ? document.getElementById('roleMode').value : '',
            roleNote: document.getElementById('roleNote') ? document.getElementById('roleNote').value : '',
            keepManualRoles: document.getElementById('keepManualRoles') ? document.getElementById('keepManualRoles').checked : false,
            ts: Date.now(),
        }));
    } catch(e) {}
}
function loadBatchRewriteState() {
    try {
        const raw = localStorage.getItem(BATCH_STATE_KEY);
        if (!raw) return null;
        const s = JSON.parse(raw);
        if (!s || !Array.isArray(s.topics)) return null;
        return s;
    } catch(e) { return null; }
}
function clearBatchRewriteState() {
    try { localStorage.removeItem(BATCH_STATE_KEY); } catch(e) {}
}
// 带退避重试的改写调用（失败项最多重试 2 次，间隔 600ms / 1200ms）
async function callRewriteWithRetry(payload, maxRetry = 2) {
    let lastErr;
    for (let attempt = 0; attempt <= maxRetry; attempt++) {
        try {
            return await callRewrite(payload);
        } catch (err) {
            if (err.name === 'AbortError') throw err;  // 中止立即上抛，不重试
            lastErr = err;
            if (attempt < maxRetry) await sleep(600 * Math.pow(2, attempt));
        }
    }
    throw lastErr;
}

// ========== 5.5 批量改写确认面板 ==========
function openBatchRewriteModal() {
    if (!currentTopics.length) return;
    const cnt = document.getElementById('brwCount');
    if (cnt) cnt.textContent = currentTopics.length;
    const unified = document.getElementById('forceUnified') && document.getElementById('forceUnified').checked;
    const modeSel = document.getElementById('mode');
    const roleSel = document.getElementById('roleMode');
    const modeLabel = unified ? (modeSel ? modeSel.options[modeSel.selectedIndex].text : '—') : '按各选题自带的呈现形式';
    const roleLabel = roleSel ? roleSel.options[roleSel.selectedIndex].text : '—';
    const focus = document.getElementById('focus').value || '无';
    const durRaw = document.getElementById('targetDuration').value;
    const dur = durRaw ? durRaw + ' 秒' : '不限';
    const preserve = document.getElementById('preserve').value.trim() || '无';
    const rows = [
        ['批量统一形式', modeLabel],
        ['角色与声音', roleLabel],
        ['重点方向', focus],
        ['目标时长', dur],
        ['保留要素', preserve],
        ['待改写数量', currentTopics.length + ' 条'],
    ];
    const dl = document.getElementById('brwSummary');
    if (dl) dl.innerHTML = rows.map(r =>
        '<div class="flex justify-between gap-3 border-b border-slate-100 pb-1.5">' +
        '<dt class="text-slate-400 shrink-0">' + r[0] + '</dt>' +
        '<dd class="text-right font-medium text-slate-700 break-all">' + escapeHtml(String(r[1])) + '</dd></div>'
    ).join('');
    document.getElementById('batchRewriteModal')?.classList.remove('hidden');
}
(function bindBatchRewriteModal() {
    document.getElementById('brwStart')?.addEventListener('click', function () {
        document.getElementById('batchRewriteModal')?.classList.add('hidden');
        runBatchRewrite();
    });
    document.getElementById('brwCancel')?.addEventListener('click', function () {
        document.getElementById('batchRewriteModal')?.classList.add('hidden');
    });
    const m = document.getElementById('batchRewriteModal');
    if (m) m.addEventListener('click', function (e) { if (e.target === m) m.classList.add('hidden'); });
})();

// ========== 6. 批量改写全部选题 ==========
async function runBatchRewrite() {
    if (!currentTopics.length) return;
    const btn = document.getElementById('batchRewriteAllBtn');
    const genBtn = document.getElementById('genBtn');
    const badge = document.getElementById('statusBadge');
    const msg = document.getElementById('formMsg');
    const errBox = document.getElementById('errorBox');
    const result = document.getElementById('result');
    const batchResult = document.getElementById('batchResult');
    const emptyState = document.getElementById('emptyState');
    const metaBar = document.getElementById('metaBar');
    const actionBar = document.getElementById('actionBar');

    msg.textContent = ''; errBox.classList.add('hidden');
    metaBar.classList.add('hidden'); actionBar.classList.add('hidden');
    result.classList.add('hidden'); result.innerHTML = '';
    batchResult.innerHTML = '';
    batchResult.classList.remove('hidden');
    emptyState.classList.add('hidden');

    btn.disabled = true; btn.classList.add('zw-btn-loading');
    btn.textContent = '批量改写中 0/' + currentTopics.length;
    genBtn.disabled = true;
    badge.textContent = '批量改写中';
    badge.className = 'rounded-full bg-brand-100 px-3 py-1 text-xs text-brand-600';

    const signal = HGTAbort.begin('中止：批量改写中…');
    batchResults = [];
    document.getElementById('batchResumeBanner')?.classList.add('hidden');
    const focus = document.getElementById('focus').value;
    const target_duration = document.getElementById('targetDuration').value;
    const preserve = document.getElementById('preserve').value.trim();

    for (let i = 0; i < currentTopics.length; i++) {
        if (!HGTAbort.isActive()) { hgtToast('warn', '已中止批量改写'); break; }
        const topic = currentTopics[i];
        const unified = document.getElementById('forceUnified') && document.getElementById('forceUnified').checked;
        const mode = unified ? document.getElementById('mode').value : mapTopicFormToMode(topic.form);
        // 2026-08-31 修复：批量提交文本与单条预览对齐（补 angle/summary/potential），
        // 此前批量只发 title+hook，丢失切入角度导致批量质量系统性低于单条
        const text = topic.title
            + (topic.hook ? '\n\n（钩子方向：' + topic.hook + '）' : '')
            + (topic.angle ? '\n\n（切入角度：' + topic.angle + '）' : '')
            + (topic.summary ? '\n\n（内容概要：' + topic.summary + '）' : '')
            + (topic.potential ? '\n\n（爆款潜力：' + topic.potential + '）' : '');
        try {
            const data = await callRewriteWithRetry({
            mode, text, focus, target_duration, preserve,
            role_mode: document.getElementById('roleMode').value,
            role_note: document.getElementById('roleNote').value.trim(),
            keep_manual_roles: document.getElementById('keepManualRoles').checked,
            signal,
        });
            batchResults.push({index: i, title: topic.title, ok: true, mode: mode, data});
        } catch (err) {
            if (err.name === 'AbortError') { hgtToast('warn', '已中止批量改写'); break; }
            batchResults.push({index: i, title: topic.title, ok: false, mode: mode, error: err.message});
        }
        btn.textContent = '批量改写中 ' + (i + 1) + '/' + currentTopics.length;
        renderBatchProgress();
        saveBatchRewriteState(); // 每完成一条即落盘，关页/刷新均可续跑
        // 条目间 400ms 间隔：平抑长尾抖动，稳稳压在限流线以下
        if (i < currentTopics.length - 1) await sleep(400);
    }

    HGTAbort.end();
    btn.classList.remove('zw-btn-loading'); btn.disabled = false;
    btn.textContent = '全部二创';
    genBtn.disabled = false;
    badge.textContent = '完成 ' + batchResults.filter(r => r.ok).length + '/' + currentTopics.length;
    badge.className = 'rounded-full bg-green-100 px-3 py-1 text-xs text-green-700';
}

function renderBatchProgress() {
    const container = document.getElementById('batchResult');
    let html = '';
    batchResults.forEach(r => {
        if (!r.ok) {
            html += '<div class="rounded-xl border border-red-200 bg-red-50 p-3">';
            html += '<p class="text-xs font-medium text-red-700">' + (r.index + 1) + '. ' + escapeHtml(r.title) + '</p>';
            html += '<p class="text-xs text-red-600">失败：' + escapeHtml(r.error || '') + '</p>';
            html += '</div>';
            return;
        }
        const data = r.data;
        const hits = data.hits || [];
        const meta = data.meta || {};
        html += '<div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">';
        html += '<div class="mb-2 flex items-center justify-between">';
        html += '<div><span class="rounded bg-brand-50 px-1.5 py-0.5 text-[10px] font-medium text-brand-600">' + (r.index + 1) + '</span> <span class="text-sm font-semibold text-slate-800">' + escapeHtml(r.title) + '</span></div>';
        html += '<span class="text-xs ' + (meta.high_risk_count ? 'text-red-600' : 'text-slate-400') + '">' + (meta.clean_chars || 0) + '字 ' + (hits.length ? '· ' + hits.length + '处标红' : '') + '</span>';
        html += '</div>';
        html += '<div class="whitespace-pre-wrap text-sm text-slate-700 leading-relaxed max-h-48 overflow-y-auto">' + highlight(data.rewritten, hits) + '</div>';
        html += '<div class="mt-2 flex flex-wrap gap-2">';
        html += '<button type="button" onclick="copyBatchCleaned(' + r.index + ')" class="rounded-md bg-white px-2 py-1 text-xs font-medium text-slate-600 shadow-sm ring-1 ring-slate-200 hover:bg-slate-50">复制清洗稿</button>';
        html += '<button type="button" onclick="toggleBatchCleaned(' + r.index + ')" class="rounded-md bg-white px-2 py-1 text-xs font-medium text-slate-500 shadow-sm ring-1 ring-slate-200 hover:bg-slate-50">展开清洗稿</button>';
        html += '<button type="button" onclick="goScrollFromBatch(' + r.index + ')" class="rounded-md bg-brand-500 px-2 py-1 text-xs font-medium text-white shadow-sm hover:bg-brand-600">带稿去出片</button>';
        html += '</div>';
        html += '<div id="batchCleaned_' + r.index + '" class="hidden mt-2 whitespace-pre-wrap rounded-md bg-slate-50 p-2 text-xs text-slate-600 leading-relaxed">' + escapeHtml(data.cleaned || '') + '</div>';
        html += '</div>';
    });
    container.innerHTML = html;
}

function renderBatchResumeBanner(results) {
    const banner = document.getElementById('batchResumeBanner');
    if (!banner) return;
    const done = results.filter(r => r.ok).length;
    const failed = results.filter(r => !r.ok).length;
    banner.innerHTML =
        '<div class="flex items-center justify-between gap-2 flex-wrap">' +
            '<span>已恢复上次批量进度：成功 <strong>' + done + '</strong> / ' + results.length +
            (failed ? '，失败 <strong>' + failed + '</strong>' : '') +
            ' 条。可编辑后重新「全部二创」，或直接带稿去出片。</span>' +
            '<button type="button" id="batchClearBtn" class="rounded-md bg-white px-2.5 py-1 text-xs font-medium text-slate-600 shadow-sm ring-1 ring-slate-200 hover:bg-slate-50">清除进度重来</button>' +
        '</div>';
    banner.classList.remove('hidden');
    const clearBtn = document.getElementById('batchClearBtn');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            clearBatchRewriteState();
            batchResults = [];
            const br = document.getElementById('batchResult');
            if (br) { br.innerHTML = ''; br.classList.add('hidden'); }
            banner.classList.add('hidden');
        });
    }
}

// ========== 7. 操作按钮动作 ==========
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

function copyBatchCleaned(idx) {
    const r = batchResults.find(x => x.index === idx);
    const t = r?.data?.cleaned || '';
    if (navigator.clipboard && t) {
        navigator.clipboard.writeText(t).then(() => {
            const container = document.getElementById('batchResult').children[idx];
            const btn = container.querySelector('button[onclick^="copyBatchCleaned"]');
            if (btn) {
                const orig = btn.textContent;
                btn.textContent = '已复制';
                setTimeout(() => btn.textContent = orig, 1500);
            }
        });
    }
}

function toggleBatchCleaned(idx) {
    const el = document.getElementById('batchCleaned_' + idx);
    if (el) el.classList.toggle('hidden');
}

function goScrollFromBatch(idx) {
    const r = batchResults.find(x => x.index === idx);
    if (!r || !r.ok || !r.data.cleaned) return;
    sessionStorage.setItem('hgt_rewrite_cleaned', r.data.cleaned);
    sessionStorage.setItem('hgt_rewrite_industry', window.__topicIndustry || '');
    // 2026-08-31 修复批量模式错配：改用该条结果实际使用的 mode（r.mode），
    // 而非全局下拉值（混合形式批量时下拉只反映最后一次点击的选题）
    const displayMode = r.mode || document.getElementById('mode').value;
    sessionStorage.setItem('hgt_rewrite_mode', displayMode);
    // 声线交付出片页选择（去掉硬编码 zhang/jiang，避免绕过租户默认声线）
    window.location.href = '/studio/scroll?from=rewrite&src=topic&batch=1&mode=' + encodeURIComponent(displayMode);
}

// 带稿去出片
document.getElementById('btnGoScroll')?.addEventListener('click', function () {
    if (!lastResult || !lastResult.cleaned) return;
    saveRewriteState();
    const displayMode = document.getElementById('mode').value;
    sessionStorage.setItem('hgt_rewrite_cleaned', lastResult.cleaned);
    sessionStorage.setItem('hgt_rewrite_industry', window.__topicIndustry || '');
    sessionStorage.setItem('hgt_rewrite_mode', displayMode);
    // 声线交付出片页选择（去掉硬编码 zhang/jiang，避免绕过租户默认声线）
    window.location.href = '/studio/scroll?from=rewrite&src=topic&mode=' + encodeURIComponent(displayMode);
});

// 跑质检
document.getElementById('btnGoQc')?.addEventListener('click', function () {
    if (!lastResult || !lastResult.cleaned) return;
    saveRewriteState();
    sessionStorage.setItem('hgt_qc_text', lastResult.cleaned);
    window.location.href = '/studio/qc?from=rewrite&src=topic&text=' + encodeURIComponent(lastResult.cleaned);
});

// 重新改写
document.getElementById('btnRegen')?.addEventListener('click', function () {
    document.getElementById('rwForm').scrollIntoView({ behavior: 'smooth' });
});

function csrf(){ return document.querySelector('meta[name="csrf-token"]')?.content || ''; }

// 初始化字数
updateCharCount();

// ===== 出片进度记录弹窗（读取后端 /studio/scroll/job-log/{jobId}，与单条出片共用接口） =====
function escHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function openJobLog(jobId) {
    const modal = document.getElementById('jobLogModal');
    const body = document.getElementById('jobLogBody');
    if (!modal || !body) return;
    if (!jobId) { body.innerHTML = '<div style="color:#94a3b8">暂无进度记录</div>'; modal.style.display = 'flex'; return; }
    body.innerHTML = '<div style="color:#94a3b8">加载中…</div>';
    fetch('/studio/scroll/job-log/' + jobId, {
        headers: { 'Accept':'application/json', 'X-CSRF-TOKEN': csrf() }
    })
    .then(r => r.json())
    .then(d => {
        if (!d.exists || !d.entries || !d.entries.length) {
            body.innerHTML = '<div style="color:#94a3b8">暂无进度记录（任务刚提交或已结束且无阶段切换）</div>';
            return;
        }
        let html = '';
        d.entries.forEach(e => {
            const st = e.status;
            const c = st === 'done' ? '#16a34a' : (st === 'failed' ? '#dc2626' : '#2563eb');
            html += '<div style="display:flex;gap:.5rem;padding:.4rem 0;border-bottom:1px solid #f1f5f9;align-items:baseline;flex-wrap:wrap">'
                + '<span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#64748b;flex-shrink:0">' + escHtml(e.time) + '</span>'
                + '<span style="color:' + c + ';font-weight:600;flex-shrink:0">' + escHtml(e.label) + '</span>'
                + '<span style="color:#94a3b8">' + (st === 'done' ? '已完成' : (st === 'failed' ? '出片失败' : '进行中')) + '</span>'
                + (typeof e.eta === 'number' && e.eta > 0 && st !== 'done' && st !== 'failed' ? '<span style="color:#94a3b8">预计剩余 ' + e.eta + 's</span>' : '')
                + '</div>';
        });
        body.innerHTML = html;
    })
    .catch(() => { body.innerHTML = '<div style="color:#dc2626">读取失败，请稍后重试</div>'; });
    modal.style.display = 'flex';
}
function closeJobLog() {
    const modal = document.getElementById('jobLogModal');
    if (modal) modal.style.display = 'none';
}
document.getElementById('jobLogModal')?.addEventListener('click', function(ev) {
    if (ev.target === this) closeJobLog();
});
</script>
</x-workspace-layout>
</x-app-layout>


