<x-app-layout>
<x-workspace-layout title="选题二创">
    <div class="mx-auto max-w-5xl p-6">

    <style>
        /* 批量出片进度看板：进度条与分步指示器（纯 CSS，避开 Tailwind 扫描） */
        .bv-bar { transition: width .5s ease; }
        .bv-step { transition: color .3s ease; }
        .bv-dot { transition: background-color .3s ease; }
    </style>

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
                        <option value="scroll_male">男声幕后音</option>
                        <option value="scroll_female">女声幕后音</option>
                        <option value="scroll_dual">男女对话幕后音</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-400">模式已按选题页所选「呈现形式」自动匹配，可手动微调。</p>
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
                        <span class="text-brand-600">提示：</span>「男声幕后音 / 女声幕后音 / 男女对话幕后音」由文稿「女：/男：」区分声线，单人形式无需前缀；「单人数字人出镜」为单人独白，对话前缀会自动忽略。出片时「女：」行用女声、「男：」行用男声。
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

            <!-- 批量出片入口 -->
            <button type="button" id="batchVideoBtn" class="hidden mb-3 inline-flex items-center gap-1.5 rounded-lg bg-gradient-to-r from-brand-500 to-brand-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:opacity-90 active:scale-[0.98]">🎬 批量出片（统一形式）</button>

            <!-- 批量结果列表 -->
            <div id="batchResult" class="hidden space-y-3"></div>

            <!-- 批量出片进度看板 -->
            <div id="batchVideoBoard" class="hidden"></div>

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

            <!-- 批量出片 · 统一形式选择器 -->
            <div id="batchVideoModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                <div id="batchVideoModalCard" class="w-full max-w-md cursor-move rounded-2xl bg-white p-5 shadow-xl">
                    <h3 class="text-base font-semibold text-slate-800">批量出片 · 统一形式</h3>
                    <p class="mt-1 text-xs text-slate-500">将用同一种呈现形式为 <span id="bvCount" class="font-medium text-brand-600">0</span> 条清洗稿生成视频。混合形式请改用逐条「带稿去出片」。</p>
                    <div class="mt-3 grid grid-cols-2 gap-2" id="bvFormGrid">
                        <button type="button" data-bv-form="scroll_male" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-700 transition">男声幕后音</button>
                        <button type="button" data-bv-form="scroll_female" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-700 transition">女声幕后音</button>
                        <button type="button" data-bv-form="scroll_dual" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-700 transition">男女对话</button>
                        <button type="button" data-bv-form="avatar" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-700 transition">单人数字人</button>
                    </div>
                    <input type="hidden" id="bvForm" value="scroll_male">
                    <div id="bvVoiceArea" class="mt-3 space-y-2">
                        <div id="bvSingleWrap" class="hidden">
                            <label class="text-xs text-slate-500">数字人声线</label>
                            <select id="bvSingleVoice" class="mt-1 w-full rounded-lg border border-slate-200 text-sm"></select>
                        </div>
                        <div id="bvMaleWrap">
                            <label class="text-xs text-slate-500">男声</label>
                            <select id="bvMaleVoice" class="mt-1 w-full rounded-lg border border-slate-200 text-sm"></select>
                        </div>
                        <div id="bvFemaleWrap">
                            <label class="text-xs text-slate-500">女声</label>
                            <select id="bvFemaleVoice" class="mt-1 w-full rounded-lg border border-slate-200 text-sm"></select>
                        </div>
                    </div>
                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" id="bvCancel" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600">取消</button>
                        <button type="button" id="bvStart" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white">开始批量生成</button>
                    </div>
                </div>
            </div>
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
    const sec = Math.max(1, Math.round(chars / 4.5));
    const fmt = sec >= 60 ? Math.floor(sec/60)+'分'+(sec%60)+'秒' : '约'+sec+'秒';
    document.getElementById('charCounter').textContent = chars + ' 字 · 预计 ' + fmt;
}
function mapTopicFormToMode(form) {
    // 选题页 form 值 → 二创页 mode 值（统一 4 种呈现形式）
    if (!form) return 'avatar';
    const f = String(form).trim();
    // 新的 4 值直接透传
    if (['avatar','scroll_male','scroll_female','scroll_dual'].includes(f)) return f;
    // 兼容旧值/Topic API 返回值
    if (f === '单声口播' || f === '幕后音口播_单人' || f === '单人口播' || f === 'script') return 'avatar';
    if (f === '幕后音口播_双人' || f === '双声对话' || f === '双人口播') return 'scroll_dual';
    return 'avatar';
}
function setModeSelect(value) {
    const sel = document.getElementById('mode');
    if (sel && ['avatar','scroll_male','scroll_female','scroll_dual'].includes(value)) sel.value = value;
}
function showSourceBanner(type, count) {
    const banner = document.getElementById('sourceBanner');
    const title = document.getElementById('sourceTitle');
    const desc = document.getElementById('sourceDesc');
    const cnt = document.getElementById('sourceCount');
    if (!banner) return;
    banner.classList.remove('hidden');
    if (type === 'topic') {
        title.textContent = '基于单条选题二创';
        desc.innerHTML = '已从「智能选题」带入 1 条选题，可直接改写';
    } else {
        title.textContent = '基于批量选题二创';
        cnt.textContent = count || 0;
    }
}
function hideSourceBanner() {
    document.getElementById('sourceBanner')?.classList.add('hidden');
}
function setTextFromTopic(title, hook, mode) {
    const ta = document.getElementById('text');
    ta.value = title + (hook ? '\n\n（钩子方向：' + hook + '）' : '');
    updateCharCount();
    document.getElementById('textLabel').textContent = '选题原始稿';
    document.getElementById('textHint').classList.remove('hidden');
    if (mode) setModeSelect(mode);
}
function getFormLabel(form) {
    const map = {
        'avatar': '数字人出镜',
        'scroll_male': '男声幕后音',
        'scroll_female': '女声幕后音',
        'scroll_dual': '男女对话幕后音',
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
        if (title) {
            setTextFromTopic(title, hook, mapTopicFormToMode(form));
            showSourceBanner('topic', 1);
            sessionStorage.removeItem('hgt_topic_title');
            sessionStorage.removeItem('hgt_topic_hook');
            sessionStorage.removeItem('hgt_topic_form');
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
            // 关页/返回后续接上次批量出片（DB 为权威源）
            const lbv = localStorage.getItem('hgt_last_batch_video');
            if (lbv) resumeBatchVideo(lbv);
        } else {
            document.getElementById('noSourceBox')?.classList.remove('hidden');
        }
        sessionStorage.removeItem('hgt_batch_topics');
        return;
    }

    // 无来源参数：提示用户
    document.getElementById('noSourceBox')?.classList.remove('hidden');
})();

function selectBatchTopic(index) {
    const list = document.getElementById('batchTopicList');
    const topic = currentTopics[index];
    if (!topic) return;
    setTextFromTopic(topic.title, topic.hook || '', mapTopicFormToMode(topic.form));
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
            if (d === 'scroll_female') rm.value = 'single_female';
            else if (d === 'scroll_male') rm.value = 'single_male';
            else if (d === 'scroll_dual') rm.value = 'dual_male_lead';
            else rm.value = 'single_male'; // avatar 默认男声独白
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

// 呈现形式 → 后端改写模式（后端只认 single/dual/script）
function mapDisplayModeToRewriteMode(displayMode) {
    if (displayMode === 'scroll_dual') return 'dual';
    return 'single'; // avatar / scroll_male / scroll_female / script 都按单人稿改写
}

async function callRewrite({mode, text, focus, target_duration, preserve, role_mode, role_note, keep_manual_roles}) {
    const resp = await fetch('/studio/rewrite/generate', {
        method: 'POST',
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
        });

        lastResult = data;
        renderSingleResult(data);
        badge.textContent = '完成';
        badge.className = 'rounded-full bg-green-100 px-3 py-1 text-xs text-green-700';
        zwSetLoading(btn, {loading: false});
    } catch (err) {
        zwSetLoading(btn, {loading: false});
        badge.textContent = '失败'; badge.className = 'rounded-full bg-red-100 px-3 py-1 text-xs text-red-600';
        errBox.textContent = err.message; errBox.classList.remove('hidden');
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

    batchResults = [];
    document.getElementById('batchResumeBanner')?.classList.add('hidden');
    const focus = document.getElementById('focus').value;
    const target_duration = document.getElementById('targetDuration').value;
    const preserve = document.getElementById('preserve').value.trim();

    for (let i = 0; i < currentTopics.length; i++) {
        const topic = currentTopics[i];
        const unified = document.getElementById('forceUnified') && document.getElementById('forceUnified').checked;
        const mode = unified ? document.getElementById('mode').value : mapTopicFormToMode(topic.form);
        const text = topic.title + (topic.hook ? '\n\n（钩子方向：' + topic.hook + '）' : '');
        try {
            const data = await callRewriteWithRetry({
            mode, text, focus, target_duration, preserve,
            role_mode: document.getElementById('roleMode').value,
            role_note: document.getElementById('roleNote').value.trim(),
            keep_manual_roles: document.getElementById('keepManualRoles').checked,
        });
            batchResults.push({index: i, title: topic.title, ok: true, mode: mode, data});
        } catch (err) {
            batchResults.push({index: i, title: topic.title, ok: false, mode: mode, error: err.message});
        }
        btn.textContent = '批量改写中 ' + (i + 1) + '/' + currentTopics.length;
        renderBatchProgress();
        saveBatchRewriteState(); // 每完成一条即落盘，关页/刷新均可续跑
        // 条目间 400ms 间隔：平抑长尾抖动，稳稳压在限流线以下
        if (i < currentTopics.length - 1) await sleep(400);
    }

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
    document.getElementById('batchVideoBtn')?.classList.toggle('hidden', !batchResults.some(r => r.ok && r.data && r.data.cleaned));
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
    const displayMode = document.getElementById('mode').value;
    sessionStorage.setItem('hgt_rewrite_mode', displayMode);
    window.location.href = '/studio/scroll?from=rewrite&src=topic&batch=1&mode=' + encodeURIComponent(displayMode) + '&dialogue=' + encodeURIComponent(r.data.cleaned);
}

// 带稿去出片
document.getElementById('btnGoScroll')?.addEventListener('click', function () {
    if (!lastResult || !lastResult.cleaned) return;
    saveRewriteState();
    const displayMode = document.getElementById('mode').value;
    sessionStorage.setItem('hgt_rewrite_cleaned', lastResult.cleaned);
    sessionStorage.setItem('hgt_rewrite_mode', displayMode);
    window.location.href = '/studio/scroll?from=rewrite&src=topic&mode=' + encodeURIComponent(displayMode) + '&dialogue=' + encodeURIComponent(lastResult.cleaned);
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

// ========== 8. 批量出片（统一形式一键生成） ==========
let batchVideoState = null;
function csrf(){ return document.querySelector('meta[name="csrf-token"]')?.content || ''; }

function fillVoiceSelect(selId, list) {
    const sel = document.getElementById(selId);
    if (!sel) return;
    sel.innerHTML = '<option value="">默认声线</option>' + (list||[]).map(v =>
        '<option value="'+escapeHtml(v.voice_id)+'">'+escapeHtml(v.name||v.voice_id)+(v.is_default?'（默认）':'')+'</option>'
    ).join('');
}
function selectBvForm(form) {
    document.querySelectorAll('[data-bv-form]').forEach(b => b.classList.remove('border-brand-400','bg-brand-50','ring-1','ring-brand-200'));
    const el = document.querySelector('[data-bv-form="'+form+'"]');
    if (el) el.classList.add('border-brand-400','bg-brand-50','ring-1','ring-brand-200');
    const f = document.getElementById('bvForm'); if (f) f.value = form;
    const single = document.getElementById('bvSingleWrap');
    const male = document.getElementById('bvMaleWrap');
    const female = document.getElementById('bvFemaleWrap');
    if (!single || !male || !female) return;
    if (form === 'avatar') { single.classList.remove('hidden'); male.classList.add('hidden'); female.classList.add('hidden'); }
    else if (form === 'scroll_male') { single.classList.add('hidden'); male.classList.remove('hidden'); female.classList.add('hidden'); }
    else if (form === 'scroll_female') { single.classList.add('hidden'); male.classList.add('hidden'); female.classList.remove('hidden'); }
    else { single.classList.add('hidden'); male.classList.remove('hidden'); female.classList.remove('hidden'); }
}

async function openBatchVideoModal() {
    const okItems = batchResults.filter(r => r.ok && r.data && r.data.cleaned);
    if (!okItems.length) return;
    const cnt = document.getElementById('bvCount'); if (cnt) cnt.textContent = okItems.length;
    try {
        const resp = await fetch('/studio/available-voices', { headers: { 'Accept':'application/json', 'X-CSRF-TOKEN': csrf() } });
        const data = await resp.json();
        fillVoiceSelect('bvMaleVoice', data.male);
        fillVoiceSelect('bvFemaleVoice', data.female);
        fillVoiceSelect('bvSingleVoice', (data.male||[]).concat(data.female||[]));
    } catch(e) {}
    selectBvForm('scroll_male');
    document.getElementById('batchVideoModal')?.classList.remove('hidden');
}

async function startBatchVideo() {
    const okItems = batchResults.filter(r => r.ok && r.data && r.data.cleaned);
    if (!okItems.length) return;
    const form = document.getElementById('bvForm').value;
    const config = {
        form: form,
        male_voice: document.getElementById('bvMaleVoice') ? document.getElementById('bvMaleVoice').value : '',
        female_voice: document.getElementById('bvFemaleVoice') ? document.getElementById('bvFemaleVoice').value : '',
        single_voice: document.getElementById('bvSingleVoice') ? document.getElementById('bvSingleVoice').value : '',
    };
    const scripts = okItems.map(r => ({ title: r.title, cleaned: r.data.cleaned }));
    document.getElementById('batchVideoModal')?.classList.add('hidden');
    let batchId;
    try {
        const resp = await fetch('/studio/batch-video/plan', {
            method:'POST', headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf()},
            body: JSON.stringify({ config, scripts })
        });
        const data = await resp.json();
        if (!resp.ok || !data.batch_id) throw new Error(data.error || '创建批量计划失败');
        batchId = data.batch_id;
    } catch(e) {
        alert('批量出片启动失败：' + e.message);
        return;
    }
    try { localStorage.setItem('hgt_last_batch_video', batchId); } catch(e) {}
    batchVideoState = { batchId, config, scripts };
    renderBatchVideoBoard(batchId, scripts);
    runBatchVideoOrchestrator(batchId, config, scripts, null);
}

function renderBatchVideoBoard(batchId, scripts) {
    const board = document.getElementById('batchVideoBoard');
    if (!board) return;
    board.classList.remove('hidden');
    document.getElementById('batchResult')?.classList.add('hidden');
    let html = '<div class="mb-2 flex items-center justify-between"><h4 class="text-sm font-semibold text-slate-700">批量出片进度</h4><span id="bvSummary" class="text-xs text-slate-500">0 / '+scripts.length+'</span></div><div id="bvCards" class="space-y-2">';
    scripts.forEach((s, i) => {
        html += '<div class="rounded-lg border border-slate-200 bg-white p-3 text-xs" data-bv="'+i+'">'
            + '<div class="flex items-center justify-between gap-2"><span class="font-medium text-slate-700 truncate">'+escapeHtml(s.title||('第'+(i+1)+'条'))+'</span>'
            + '<span class="bv-status shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">排队中</span></div>'
            // 进度条（动态宽度用 inline style，避开 Tailwind 扫描）
            + '<div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-slate-100"><div class="bv-bar h-full rounded-full bg-brand-500" style="width:0%"></div></div>'
            // 四段分步指示器
            + '<div class="bv-steps mt-2 flex items-center justify-between">'
            +   '<span class="bv-step flex items-center gap-1 text-slate-400" data-step="0"><span class="bv-dot inline-block h-1.5 w-1.5 rounded-full bg-slate-300"></span>提交成功</span>'
            +   '<span class="bv-step flex items-center gap-1 text-slate-400" data-step="1"><span class="bv-dot inline-block h-1.5 w-1.5 rounded-full bg-slate-300"></span>配音字幕</span>'
            +   '<span class="bv-step flex items-center gap-1 text-slate-400" data-step="2"><span class="bv-dot inline-block h-1.5 w-1.5 rounded-full bg-slate-300"></span>视频渲染</span>'
            +   '<span class="bv-step flex items-center gap-1 text-slate-400" data-step="3"><span class="bv-dot inline-block h-1.5 w-1.5 rounded-full bg-slate-300"></span>出片完成</span>'
            + '</div>'
            // 信息行：分步文案 + 已等待 + 预计剩余
            + '<div class="bv-info mt-1 text-[10px] text-slate-400">等待开始…</div>'
            + '<div class="bv-actions mt-1 flex gap-2 items-center">'
            + '<button class="bv-log hidden rounded border border-slate-200 bg-white px-2 py-1 text-slate-500" type="button">进度</button>'
            + '<a class="bv-dl hidden rounded bg-brand-500 px-2 py-1 text-white" target="_blank">下载</a>'
            + '<button class="bv-retry hidden rounded border border-slate-200 bg-white px-2 py-1 text-slate-600" type="button">重试</button>'
            + '</div></div>';
    });
    html += '</div>';
    board.innerHTML = html;
}

// 每张卡的轮询起始时间戳（用于"已等待"精确计时，可追溯）
const bvStartTimes = {};

// 批量看板进度渲染：进度条 + 四段分步高亮 + 已等待 + 预计剩余
// data 来自 /studio/scroll/status/{jobId}（含后端的 step_label/progress/eta_sec 增强字段）
function setBvProgress(index, data) {
    const card = document.querySelector('#bvCards [data-bv="'+index+'"]');
    if (!card) return;
    const status = (data && data.status) || 'queued';
    const step = (data && data.step) || (status === 'done' ? 'done' : status === 'failed' ? 'failed' : 'queued');

    // 1) 进度条百分比：优先用后端 progress，缺失时按 step 兜底
    let pct = (data && typeof data.progress === 'number') ? data.progress : null;
    if (pct === null) {
        const m = { queued:8, editing:40, rendering:75, rerender:92, done:100, failed:100 };
        pct = (m[step] !== undefined) ? m[step] : 8;
    }
    const bar = card.querySelector('.bv-bar');
    if (bar) bar.style.width = pct + '%';

    // 2) 四段分步高亮：提交成功 → 配音字幕 → 视频渲染 → 出片完成
    const phaseMap = { queued:0, editing:1, rendering:2, rerender:2, done:3, failed:-1 };
    const cur = (phaseMap[step] !== undefined) ? phaseMap[step] : 0;
    card.querySelectorAll('.bv-step').forEach(el => {
        const s = parseInt(el.getAttribute('data-step'), 10);
        const dot = el.querySelector('.bv-dot');
        el.classList.remove('text-slate-400','text-brand-600','text-green-600','text-red-600','font-medium');
        if (dot) dot.classList.remove('bg-slate-300','bg-brand-500','bg-green-500','bg-red-500');
        if (status === 'failed') {
            el.classList.add(s === cur ? 'text-red-600' : 'text-slate-300');
            if (dot) dot.classList.add(s === cur ? 'bg-red-500' : 'bg-slate-200');
        } else if (s < cur) {
            el.classList.add('text-green-600'); if (dot) dot.classList.add('bg-green-500');
        } else if (s === cur) {
            el.classList.add('text-brand-600','font-medium'); if (dot) dot.classList.add('bg-brand-500');
        } else {
            el.classList.add('text-slate-400'); if (dot) dot.classList.add('bg-slate-300');
        }
    });

    // 3) 信息行：分步文案 + 已等待 + 预计剩余（ETA）
    const info = card.querySelector('.bv-info');
    if (info) {
        if (status === 'done') {
            info.className = 'bv-info mt-1 text-[10px] text-green-600';
            info.textContent = '已完成';
        } else if (status === 'failed') {
            info.className = 'bv-info mt-1 text-[10px] text-red-600';
            info.textContent = '失败：' + ((data && (data.error || data.step_label)) || '请重试');
        } else {
            const st = bvStartTimes[index] || Date.now();
            const elapsed = Math.max(0, Math.floor((Date.now() - st) / 1000));
            const em = Math.floor(elapsed / 60), es = elapsed % 60;
            // 数字人/视频渲染波动大，精确 ETA 容易钉死造成误导，故使用柔性文案
            let eta = '';
            if (status === 'queued' && data && data.queue_pos > 0) {
                eta = '｜前面约 ' + data.queue_pos + ' 个排队';
            } else if (status !== 'queued' && status !== 'done' && status !== 'failed') {
                eta = '｜预计还需数分钟';
            }
            const label = (data && data.step_label) ? data.step_label : (status === 'queued' ? '排队等待渲染资源' : '渲染中');
            info.className = 'bv-info mt-1 text-[10px] text-slate-400';
            info.textContent = label + '｜已等待 ' + em + ' 分 ' + es + ' 秒' + eta;
        }
    }

    // 4) 状态徽章同步
    const st2 = card.querySelector('.bv-status');
    if (st2) {
        if (status === 'done') { st2.textContent = '完成'; st2.className = 'bv-status shrink-0 rounded bg-green-100 px-1.5 py-0.5 text-[10px] text-green-700'; }
        else if (status === 'failed') { st2.textContent = '失败'; st2.className = 'bv-status shrink-0 rounded bg-red-100 px-1.5 py-0.5 text-[10px] text-red-600'; }
        else {
            const lbl = (data && data.step_label) ? data.step_label : (status === 'queued' ? '排队中' : '渲染中');
            st2.textContent = lbl; st2.className = 'bv-status shrink-0 rounded bg-brand-100 px-1.5 py-0.5 text-[10px] text-brand-600';
        }
    }
}

// 兼容旧调用：用合成 data 委托给 setBvProgress（提交中/排队中/终态无实时数据时）
function setBvCard(index, status, label) {
    setBvProgress(index, {
        status: status,
        step: (status === 'done' ? 'done' : status === 'failed' ? 'failed' : 'queued'),
        step_label: label || '',
        progress: (status === 'done' ? 100 : status === 'failed' ? 100 : 0)
    });
}

async function postBvProgress(batchId, index, status, jobId) {
    try {
        await fetch('/studio/batch-video/'+batchId+'/progress', {
            method:'POST', headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf()},
            body: JSON.stringify({ index, status, job_id: jobId || null })
        });
    } catch(e) {}
}

async function submitOneBatchVideo(batchId, config, index, script) {
    const form = config.form;
    const mode = (form === 'avatar') ? 'avatar' : 'scroll';
    const voiceForm = (form === 'scroll_dual') ? 'dialogue' : (form === 'scroll_male' ? 'male_mono' : (form === 'scroll_female' ? 'female_mono' : 'mono'));
    const payload = {
        mode: mode,
        dialogue: script.cleaned,
        title: (script.title||'').slice(0,20),
        voice_form: (form === 'avatar') ? null : voiceForm,
        male_voice: (form === 'avatar' || form === 'scroll_female') ? null : (config.male_voice || null),
        female_voice: (form === 'avatar' || form === 'scroll_male') ? null : (config.female_voice || null),
        batch_id: batchId,
    };
    if (form === 'avatar') { payload.male_voice = config.single_voice || null; payload.female_voice = null; }
    await postBvProgress(batchId, index, 'submitted', null);
    setBvCard(index, 'submitted', '提交中');
    const resp = await fetch('/studio/scroll/generate', {
        method:'POST', headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf()},
        body: JSON.stringify(payload)
    });
    const data = await resp.json().catch(()=>({}));
    if (resp.ok && data.job_id) {
        await postBvProgress(batchId, index, 'submitted', data.job_id);
        pollBvJob(batchId, index, data.job_id);
        return true;
    }
    if (resp.status === 429 || (data && data.code === 'tenant_busy')) {
        return false; // 编排器退避重试
    }
    await postBvProgress(batchId, index, 'failed', null);
    setBvCard(index, 'failed', '失败：'+(data.error||'提交失败'));
    return 'failed';
}

async function pollBvJob(batchId, index, jobId) {
    bvStartTimes[index] = Date.now();  // 记录起始时间戳，用于"已等待"精确计时
    // 显示并绑定本卡「进度」按钮，点击查看时间戳进度记录
    const card0 = document.querySelector('#bvCards [data-bv="'+index+'"]');
    if (card0) {
        const logBtn = card0.querySelector('.bv-log');
        if (logBtn) { logBtn.classList.remove('hidden'); logBtn.onclick = () => openJobLog(jobId); }
    }
    for (let i=0;i<300;i++){
        await sleep(2000);
        try {
            const resp = await fetch('/studio/scroll/status/'+jobId, { headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf()} });
            const data = await resp.json().catch(()=>({}));
            setBvProgress(index, data);  // 实时刷新进度条/分步/已等待/ETA
            if (data.status === 'done') {
                await postBvProgress(batchId, index, 'done', jobId);
                setBvProgress(index, data);
                const card = document.querySelector('#bvCards [data-bv="'+index+'"]');
                if (card) { const dl = card.querySelector('.bv-dl'); if (dl) { dl.classList.remove('hidden'); dl.href = '/studio/scroll/download/'+jobId; } }
                updateBvSummary();
                return;
            } else if (data.status === 'failed') {
                await postBvProgress(batchId, index, 'failed', jobId);
                setBvProgress(index, data);
                const card = document.querySelector('#bvCards [data-bv="'+index+'"]');
                if (card) card.querySelector('.bv-retry')?.classList.remove('hidden');
                updateBvSummary();
                return;
            }
        } catch(e) {}
    }
    setBvCard(index, 'failed', '轮询超时');
}

function updateBvSummary() {
    const cards = document.querySelectorAll('#bvCards [data-bv]');
    let done=0, failed=0;
    cards.forEach(c => {
        const t = c.querySelector('.bv-status')?.textContent || '';
        if (t==='完成') done++; else if (t.indexOf('失败')>=0) failed++;
    });
    const sum = document.getElementById('bvSummary');
    if (sum) sum.textContent = '完成 '+done+' / 失败 '+failed+' / 共 '+cards.length;
}

async function runBatchVideoOrchestrator(batchId, config, scripts, onlyIndices) {
    const pending = (onlyIndices && onlyIndices.length) ? onlyIndices.slice() : scripts.map((_,i)=>i);
    while (pending.length) {
        const idx = pending.shift();
        let attempt = 0, result = null;
        while (attempt <= 3) {
            const r = await submitOneBatchVideo(batchId, config, idx, scripts[idx]);
            if (r === true) { result = true; break; }
            if (r === 'failed') { result = 'failed'; break; }
            attempt++;
            await sleep(Math.min(20000, 5000 * Math.pow(2, attempt-1)));
        }
        if (result !== true && result !== 'failed') {
            await postBvProgress(batchId, idx, 'failed', null);
            setBvCard(idx, 'failed', '提交失败（重试上限）');
        }
        await sleep(300);
    }
}

async function resumeBatchVideo(batchId) {
    try {
        const resp = await fetch('/studio/batch-video/'+batchId, { headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf()} });
        const data = await resp.json();
        if (!resp.ok || !data.scripts) return;
        const config = data.config || {};
        const scripts = data.scripts.map(s => ({ title:s.title, cleaned:s.cleaned }));
        batchVideoState = { batchId, config, scripts };
        renderBatchVideoBoard(batchId, scripts);
        const pending = [];
        data.scripts.forEach((s, i) => {
            if (s.status === 'done') {
                setBvCard(i,'done','完成');
                if (s.job_id) { const card=document.querySelector('#bvCards [data-bv="'+i+'"]'); if(card){const dl=card.querySelector('.bv-dl'); if(dl){dl.classList.remove('hidden'); dl.href='/studio/scroll/download/'+s.job_id;}} }
            } else if (s.status === 'failed') {
                setBvCard(i,'failed','失败');
            } else if (s.job_id) {
                pollBvJob(batchId, i, s.job_id);
            } else {
                pending.push(i);
            }
        });
        updateBvSummary();
        if (pending.length) runBatchVideoOrchestrator(batchId, config, scripts, pending);
    } catch(e) {}
}

// 事件绑定
(function bindBatchVideo(){
    document.getElementById('batchVideoBtn')?.addEventListener('click', openBatchVideoModal);
    document.getElementById('bvStart')?.addEventListener('click', startBatchVideo);
    document.getElementById('bvCancel')?.addEventListener('click', () => document.getElementById('batchVideoModal')?.classList.add('hidden'));
    const modal = document.getElementById('batchVideoModal');
    if (modal) modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.add('hidden'); });
    document.querySelectorAll('[data-bv-form]').forEach(b => b.addEventListener('click', () => selectBvForm(b.dataset.bvForm)));
})();

// 批量出片弹窗：鼠标拖拽移动
(function makeBatchVideoModalDraggable(){
    const card = document.getElementById('batchVideoModalCard');
    const modal = document.getElementById('batchVideoModal');
    if (!card || !modal) return;

    let dragging = false, startX = 0, startY = 0, initialLeft = 0, initialTop = 0;

    // 点击卡片空白处可拖拽；点击按钮/输入框/选择框时不触发拖拽
    card.addEventListener('mousedown', function (e) {
        const tag = (e.target.tagName || '').toLowerCase();
        const isInteractive = ['button', 'input', 'select', 'textarea', 'a', 'label'].includes(tag)
            || e.target.closest('button') || e.target.closest('input') || e.target.closest('select') || e.target.closest('textarea') || e.target.closest('a');
        if (isInteractive) return;
        dragging = true;
        startX = e.clientX;
        startY = e.clientY;
        const rect = card.getBoundingClientRect();
        initialLeft = rect.left;
        initialTop = rect.top;
        card.style.position = 'fixed';
        card.style.left = initialLeft + 'px';
        card.style.top = initialTop + 'px';
        card.style.margin = '0';
        card.style.transform = 'none';
        card.style.maxWidth = rect.width + 'px';
        card.classList.add('select-none');
    });

    window.addEventListener('mousemove', function (e) {
        if (!dragging) return;
        e.preventDefault();
        let nx = initialLeft + (e.clientX - startX);
        let ny = initialTop + (e.clientY - startY);
        const vw = window.innerWidth, vh = window.innerHeight;
        const rect = card.getBoundingClientRect();
        // 限制在可视窗口内，至少保留 40px 可见
        nx = Math.max(0, Math.min(nx, vw - 40));
        ny = Math.max(0, Math.min(ny, vh - 40));
        card.style.left = nx + 'px';
        card.style.top = ny + 'px';
    });

    window.addEventListener('mouseup', function () {
        if (!dragging) return;
        dragging = false;
        card.classList.remove('select-none');
    });
})();

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
                + '<span style="color:#94a3b8">进度 ' + (e.progress||0) + '%</span>'
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
