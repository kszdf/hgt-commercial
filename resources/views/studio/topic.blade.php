<x-app-layout>
<x-workspace-layout title="智能选题">
<div class="mx-auto max-w-5xl p-6">
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- ===== 左侧：配置 / 输入区 ===== -->
        <div class="space-y-4">
        <section class="luxury-glass p-5">
            <form id="topicForm" class="space-y-4">
                <!-- 行业选择：财税老板行业分群（11项，替代原15个通用行业） -->
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        老板行业
                        <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500">选填</span>
                    </label>
                    <select id="industry" name="industry"
                        class="w-full rounded-lg studio-card studio-card-sm.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option value="">不限行业（通用财税）</option>
                        <optgroup label="── 财税老板分群 ──">
                            <option value="餐饮">餐饮美食</option>
                            <option value="电商直播">电商 / 直播</option>
                            <option value="制造业">生产制造</option>
                            <option value="建筑劳务">建筑工程</option>
                            <option value="贸易">商贸批发</option>
                            <option value="物流">物流运输</option>
                            <option value="零售">零售门店</option>
                            <option value="医疗">医疗健康</option>
                            <option value="教育">教育培训</option>
                            <option value="个体户">个体户 / 小老板</option>
                        </optgroup>
                    </select>
                    <p class="mt-1 text-xs text-slate-400">选择老板行业后，AI 将生成该行业的财税垂直选题（如「餐饮」→ 个人卡收款、员工社保）；不选则按通用财税方向推荐。</p>
                </div>

                <!-- 维度筛选（2x2 网格，移除目标平台） -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">
                            热度取向
                            <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500">选填</span>
                        </label>
                        <select id="hotness" name="hotness" class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                            <option value="">不限</option>
                            <option value="高热度选题">高热度</option>
                            <option value="常规选题">常规</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">
                            钩子类型
                            <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500">选填</span>
                        </label>
                        <select id="hook" name="hook" class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                            <option value="">不限</option>
                            <option value="留资钩子">留资钩子</option>
                            <option value="咨询钩子">咨询钩子</option>
                            <option value="私域钩子">私域钩子</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">
                            呈现形式
                            <span class="ml-1 rounded bg-brand-50 px-1.5 py-0.5 text-[11px] font-normal text-brand-600">建议填写</span>
                        </label>
                        <select id="form" name="form" class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                            <option value="">不限</option>
                            <option value="avatar">单人数字人出镜</option>
                            <option value="scroll_male">男声幕后音</option>
                            <option value="scroll_female">女声幕后音</option>
                            <option value="scroll_dual">男女对话幕后音</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">
                            生成数量
                            <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500">选填</span>
                        </label>
                        <div class="flex items-center justify-between rounded-lg border border-slate-200 bg-white p-1">
                            <button type="button" id="countDec"
                                class="flex h-8 w-8 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
                                aria-label="减少数量">−</button>
                            <div class="min-w-[3rem] text-center text-sm font-medium text-slate-700">
                                <span id="countValue">5</span><span class="text-xs text-slate-400">条</span>
                            </div>
                            <button type="button" id="countInc"
                                class="flex h-8 w-8 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
                                aria-label="增加数量">+</button>
                        </div>
                        <input type="hidden" id="count" name="count" value="5">
                    </div>
                </div>

                <!-- 关键词 -->
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        关键词
                        <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500">选填</span>
                    </label>
                    <input id="keywords" name="keywords" value="" maxlength="120"
                        class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                        placeholder="如：行业热点、用户痛点、产品卖点…（逗号分隔）">
                    <p class="mt-1 text-xs text-slate-400">不填则由 AI 根据所选行业自动推荐选题方向</p>
                </div>

                <!-- 提交按钮 + 校验提示 -->
                <button type="submit" id="genBtn"
                    class="w-full rounded-lg bg-brand-500 px-4 py-2.5 font-medium text-white shadow-sm transition hover:bg-brand-600 disabled:opacity-50 disabled:cursor-not-allowed">
                    生成选题
                </button>
                <p id="formMsg" class="text-sm font-medium text-red-600"></p>
            </form>
        </section>

        <!-- ===== 全网财税热点 配置面板 ===== -->
        <section class="luxury-glass p-5">
            <form id="hotspotForm" class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-700">全网财税热点</h3>
                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-600">实时</span>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">时间段</label>
                    <div class="flex flex-wrap gap-2" id="hsDays">
                        <button type="button" data-days="1" class="hs-day rounded-full border border-slate-200 px-3 py-1 text-xs text-slate-600 transition hover:border-brand-300">今日</button>
                        <button type="button" data-days="3" class="hs-day rounded-full border border-slate-200 px-3 py-1 text-xs text-slate-600 transition hover:border-brand-300">近3天</button>
                        <button type="button" data-days="7" class="hs-day rounded-full border border-brand-300 bg-brand-100 px-3 py-1 text-xs font-medium text-brand-700 transition hover:opacity-80">近7天</button>
                        <button type="button" data-days="30" class="hs-day rounded-full border border-slate-200 px-3 py-1 text-xs text-slate-600 transition hover:border-brand-300">近30天</button>
                    </div>
                    <input type="hidden" id="hsDaysVal" value="7">
                </div>

                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <label class="block text-sm font-medium text-slate-700">财税子领域 <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500">可多选</span></label>
                        <div class="flex gap-2">
                            <button type="button" id="hsSelectAll" class="text-[11px] text-brand-600 hover:underline">全选</button>
                            <button type="button" id="hsClearAll" class="text-[11px] text-slate-400 hover:text-slate-600 hover:underline">清空</button>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2" id="hsSubs"></div>
                    <p id="hsSubHint" class="mt-1 text-xs text-slate-400">已选 <strong class="text-brand-600" id="hsSubCount">10</strong> 个子领域</p>
                </div>

                <button type="submit" id="hsBtn" class="w-full rounded-lg bg-brand-500 px-4 py-2.5 font-medium text-white shadow-sm transition hover:bg-brand-600 disabled:opacity-50 disabled:cursor-not-allowed">获取财税热点</button>
                <p class="text-xs text-slate-400">热点来源为公开财税资讯聚合，建议结合自身解读二次创作。</p>
            </form>
        </section>

        <!-- ===== 每日热点·双题材（微博/百度/头条热榜 → 财税/大事 + 爆款方案） ===== -->
        <section class="luxury-glass p-5">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-semibold text-slate-700">每日热点·双题材 <span class="text-[10px] font-normal text-slate-400">微博/百度/头条热榜 · 财税相关 + 重大热点事件 + 爆款方案</span></h3>
                <button type="button" id="hdRefreshBtn" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-brand-600 disabled:opacity-50">🔄 刷新今日热点</button>
            </div>
            <p class="mb-2 text-xs text-slate-400">只保留「财税直接相关」与「重大热点事件」两类，每条附爆款方案（标题/钩子/结构/留资），点「用此选题」进入二创。</p>
            <div id="hdResult" class="space-y-3 text-sm text-slate-600">加载中…</div>
        </section>
        </div><!-- /左侧空间 -->

        <!-- ===== 结果区 ===== -->
        <section class="luxury-glass p-5">
            <div id="topicHeader" class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-700">选题建议</h3>
                <span id="statusBadge" class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-500">待生成</span>
            </div>

            <!-- Tab 切换：AI 选题建议 / 全网财税热点 -->
            <div class="mb-3 flex gap-2" id="resultTabs">
                <button type="button" id="tabTopic" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-medium text-white transition">AI 选题建议</button>
                <button type="button" id="tabHotspot" class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-200">全网财税热点</button>
            </div>

            <div id="result" class="space-y-3">
                <div class="rounded-lg studio-card text-center">
                    <div class="mx-auto mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-slate-200">
                        <svg class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                    </div>
                    <p class="text-sm text-slate-400">生成的选题将显示在这里<br><span class="text-xs text-slate-300">选用后可直接进入「选题二创」改写</span></p>
                </div>
            </div>

            <!-- 全网财税热点结果区 -->
            <div id="hotspotResult" class="space-y-3 hidden">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-slate-500">共 <strong id="hsCount">0</strong> 条热点<span id="hsFilterStat" class="ml-1 hidden rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-400"></span><span id="hsRealtime" class="ml-1 hidden rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-400">非实时</span></span>
                    <div class="flex items-center gap-2">
                        <button type="button" id="hsBatchRewrite" class="hidden rounded-md bg-brand-500 px-2.5 py-1 text-[11px] font-medium text-white shadow-sm transition hover:bg-brand-600 disabled:opacity-40">全部去二创 →</button>
                        <button type="button" id="hsRefresh" class="text-xs text-brand-600 hover:underline">刷新</button>
                    </div>
                </div>
                <div id="hsDegraded" class="hidden mb-3 rounded-lg px-3 py-2 text-sm" style="background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;">
                    <span style="font-weight:600;">⚠ 热点服务降级：</span><span id="hsDegradedMsg"></span>
                </div>
                <div id="hsList" class="space-y-3">
                    <div class="rounded-lg studio-card text-center">
                        <p class="text-sm text-slate-400">点击左侧「获取财税热点」<br><span class="text-xs text-slate-300">实时聚合全网财税资讯，生成可二创的选题与角度</span></p>
                    </div>
                </div>
            </div>

            <!-- 底部操作栏 -->
            <div id="actionBar" class="mt-4 hidden rounded-lg border border-brand-200 bg-brand-50 p-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-brand-700"><strong id="topicCount">0</strong> 条选题已生成 — 点击单条选用，或：</p>
                    <div class="flex gap-2">
                        <button type="button" id="batchRewriteBtn" class="inline-flex items-center gap-1 rounded-md bg-white px-3 py-1.5 text-xs font-medium text-brand-600 shadow-sm transition hover:bg-brand-50">
                            全部去二创 →
                        </button>
                        <button type="button" id="regenBtn" class="inline-flex items-center gap-1 rounded-md bg-brand-500 px-3 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-brand-600">
                            重新生成
                        </button>
                    </div>
                </div>
            </div>

            <div id="errorBox" class="mt-3 hidden rounded-lg border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600"></div>
        </section>
    </div>
</div>
</x-workspace-layout>
</x-app-layout>

<script>
let lastTopics = [];
let topicCount = 5;
const MIN_COUNT = 1;
const MAX_COUNT = 10;
const formLabelMap = {
    'avatar': '数字人出镜',
    'scroll_male': '男声幕后音',
    'scroll_female': '女声幕后音',
    'scroll_dual': '男女对话幕后音',
    '单声口播': '单声口播',
    '幕后音口播_双人': '幕后音·双人',
    '幕后音口播_单人': '幕后音·单人'
};

function updateCount(delta) {
    topicCount = Math.max(MIN_COUNT, Math.min(MAX_COUNT, topicCount + delta));
    document.getElementById('count').value = topicCount;
    document.getElementById('countValue').textContent = topicCount;
    document.getElementById('countDec').disabled = topicCount <= MIN_COUNT;
    document.getElementById('countInc').disabled = topicCount >= MAX_COUNT;
}

document.getElementById('countDec').addEventListener('click', () => updateCount(-1));
document.getElementById('countInc').addEventListener('click', () => updateCount(1));

function buildLoadingHtml() {
    return '<div class="rounded-lg bg-slate-50 p-6 text-center">' +
        '<div class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-brand-100">' +
            '<svg class="h-5 w-5 animate-spin text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">' +
                '<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>' +
                '<path class="opacity-75" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>' +
            '</svg>' +
        '</div>' +
        '<p class="text-sm font-medium text-slate-600">AI 正在生成选题…</p>' +
        '<p class="mt-1 text-xs text-slate-400">方向分析 → 痛点匹配 → 钩子设计，预计 5–15 秒</p>' +
    '</div>';
}

document.getElementById('topicForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('genBtn');
    const msg = document.getElementById('formMsg');
    const badge = document.getElementById('statusBadge');
    const result = document.getElementById('result');
    const errBox = document.getElementById('errorBox');
    const actionBar = document.getElementById('actionBar');

    // 清除旧状态
    msg.textContent = '';
    errBox.classList.add('hidden');
    actionBar.classList.add('hidden');

    // 所有筛选项均为选填：空值会由 AI 回退为通用方向继续生成
    zwSetLoading(btn, {loading: true, text: 'AI 思考中…'});
    badge.textContent = '生成中';
    badge.className = 'rounded-full bg-brand-100 px-3 py-1 text-xs text-brand-600';
    result.innerHTML = buildLoadingHtml();

    const signal = HGTAbort.begin('中止：AI 选题生成中…');
    try {
        const valOrNull = (id) => {
            const v = document.getElementById(id).value?.trim();
            return v || null;
        };

        const resp = await fetch('/studio/topic/generate', {
            method: 'POST',
            signal,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({
                industry: valOrNull('industry'),
                keywords: valOrNull('keywords'),
                count: topicCount,
                hotness: valOrNull('hotness'),
                hook: valOrNull('hook'),
                form: valOrNull('form'),
            })
        });
        const data = await resp.json();
        if (!resp.ok) throw new Error(data.error || '提交失败（HTTP ' + resp.status + '）');
        if (!data.ok) throw new Error(data.error || '生成失败');

        // 成功
        lastTopics = data.topics || [];
        renderTopics(lastTopics);
        // 持久化选题列表与筛选条件，支持返回后恢复
        try {
            sessionStorage.setItem('hgt_topic_list', JSON.stringify(lastTopics));
            sessionStorage.setItem('hgt_topic_filters', JSON.stringify({
                industry: document.getElementById('industry').value,
                keywords: document.getElementById('keywords').value,
                count: topicCount,
                hotness: document.getElementById('hotness').value,
                hook: document.getElementById('hook').value,
                form: document.getElementById('form').value,
            }));
        } catch(e) {}
        zwSetLoading(btn, {loading: false});
        msg.textContent = '';

    } catch (err) {
        if (err.name === 'AbortError') {
            zwSetLoading(btn, {loading: false});
            badge.textContent = '已中止';
            badge.className = 'rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-500';
            msg.textContent = '⏹ 已中止选题生成。';
            hgtToast('warn', '已中止选题生成');
            return;
        }
        zwSetLoading(btn, {loading: false});
        badge.textContent = '失败';
        badge.className = 'rounded-full bg-red-100 px-3 py-1 text-xs text-red-600';

        // 结构化错误展示
        const errMsg = err.message || '未知错误';
        msg.textContent = '❌ ' + errMsg;
        errBox.innerHTML = '<strong>生成失败</strong><br><span class="text-xs mt-1 block text-red-400">' + escapeHtml(errMsg) + '<br>请检查网络连接或稍后重试。</span>';
        errBox.classList.remove('hidden');
    } finally {
        HGTAbort.end();
    }
});

// 重新生成
document.getElementById('regenBtn')?.addEventListener('click', function () {
    document.getElementById('topicForm').scrollIntoView({ behavior: 'smooth' });
    document.getElementById('genBtn').click();
});

// 全部去二创
document.getElementById('batchRewriteBtn')?.addEventListener('click', function () {
    if (!lastTopics.length) return;
    sessionStorage.setItem('hgt_batch_topics', JSON.stringify(lastTopics));
    window.location.href = '/studio/rewrite?from=topic-all';
});

function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function renderTopics(topics) {
    lastTopics = topics || [];
    const badge = document.getElementById('statusBadge');
    const result = document.getElementById('result');
    const actionBar = document.getElementById('actionBar');
    badge.textContent = '完成 · ' + lastTopics.length + '条';
    badge.className = 'rounded-full bg-green-100 px-3 py-1 text-xs text-green-700';
    result.innerHTML = '';
    lastTopics.forEach((t, i) => {
        const el = document.createElement('div');
        el.className = 'group relative rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-brand-300 hover:shadow-md';
        let formLabel = formLabelMap[t.form] || (t.form || '短视频');
        el.innerHTML =
            '<div class="mb-2 flex items-start justify-between gap-2">' +
                '<div class="min-w-0 flex-1">' +
                    '<div class="mb-1 flex items-center gap-2">' +
                        '<span class="shrink-0 rounded-md bg-brand-50 px-1.5 py-0.5 text-[10px] font-medium text-brand-600">' + formLabel + '</span>' +
                        '<h4 class="truncate text-sm font-semibold text-slate-800">' + escapeHtml(t.title) + '</h4>' +
                    '</div>' +
                    '<p class="text-xs leading-relaxed text-slate-500">角度：' + escapeHtml(t.angle || '') + '</p>' +
                    '<p class="mt-0.5 text-xs leading-relaxed text-slate-500">潜力：' + escapeHtml(t.potential || '') + '</p>' +
                    '<p class="mt-1.5 rounded-lg bg-amber-50 px-2 py-1.5 text-xs leading-relaxed text-amber-700">留资钩子：' + escapeHtml(t.hook || '') + '</p>' +
                '</div>' +
                '<button type="button" data-idx="' + i + '" class="use-topic-btn shrink-0 rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-brand-600 active:bg-brand-700">去二创</button>' +
            '</div>';
        result.appendChild(el);
    });
    result.querySelectorAll('.use-topic-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const idx = parseInt(this.dataset.idx, 10);
            const topic = lastTopics[idx];
            if (!topic) return;
            sessionStorage.setItem('hgt_topic_title', topic.title);
            sessionStorage.setItem('hgt_topic_hook', topic.hook || '');
            sessionStorage.setItem('hgt_topic_form', topic.form || '');
            sessionStorage.setItem('hgt_topic_industry', document.getElementById('industry').value || '');
            window.location.href = '/studio/rewrite?from=topic';
        });
    });
    actionBar.classList.remove('hidden');
    document.getElementById('topicCount').textContent = lastTopics.length;
}

// 返回「智能选题」时恢复已生成的选题与筛选条件
(function () {
    try {
        const raw = sessionStorage.getItem('hgt_topic_list');
        if (raw) {
            const topics = JSON.parse(raw);
            if (Array.isArray(topics) && topics.length) {
                renderTopics(topics);
            }
        }
        const fraw = sessionStorage.getItem('hgt_topic_filters');
        if (fraw) {
            const f = JSON.parse(fraw);
            if (f.industry) document.getElementById('industry').value = f.industry;
            if (f.keywords != null) document.getElementById('keywords').value = f.keywords;
            if (f.hotness) document.getElementById('hotness').value = f.hotness;
            if (f.hook) document.getElementById('hook').value = f.hook;
            if (f.form) document.getElementById('form').value = f.form;
            if (f.count) {
                topicCount = f.count;
                document.getElementById('count').value = f.count;
                document.getElementById('countValue').textContent = f.count;
                document.getElementById('countDec').disabled = topicCount <= MIN_COUNT;
                document.getElementById('countInc').disabled = topicCount >= MAX_COUNT;
            }
        }
    } catch(e) {}
})();

// ===== 全网财税热点模块 =====
const HS_SUBS = ['增值税','企业所得税','个人所得税','发票管理','税务稽查','金税四期','社保公积金','税收优惠政策','汇算清缴','跨境税收'];

function updateHsSubHint() {
    const n = document.querySelectorAll('#hsSubs .hs-sub.active').length;
    const el = document.getElementById('hsSubCount');
    if (el) el.textContent = n;
}

function setHsSubActive(b, active) {
    if (active) {
        b.classList.add('active', 'bg-brand-100', 'border-brand-300', 'text-brand-700');
        b.classList.remove('bg-white', 'border-slate-200', 'text-slate-600');
    } else {
        b.classList.remove('active', 'bg-brand-100', 'border-brand-300', 'text-brand-700');
        b.classList.add('bg-white', 'border-slate-200', 'text-slate-600');
    }
}

// 渲染子领域胶囊（默认全选）
(function () {
    const box = document.getElementById('hsSubs');
    if (!box) return;
    HS_SUBS.forEach(s => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'hs-sub active rounded-full border px-2.5 py-1 text-[11px] font-medium transition hover:opacity-80 active:scale-95 bg-brand-100 border-brand-300 text-brand-700';
        b.dataset.sub = s;
        b.textContent = s;
        b.addEventListener('click', () => {
            const on = !b.classList.contains('active');
            setHsSubActive(b, on);
            updateHsSubHint();
        });
        box.appendChild(b);
    });
    updateHsSubHint();
})();

document.getElementById('hsSelectAll')?.addEventListener('click', () => {
    document.querySelectorAll('#hsSubs .hs-sub').forEach(b => setHsSubActive(b, true));
    updateHsSubHint();
});
document.getElementById('hsClearAll')?.addEventListener('click', () => {
    document.querySelectorAll('#hsSubs .hs-sub').forEach(b => setHsSubActive(b, false));
    updateHsSubHint();
});

// 时间段胶囊单选
document.querySelectorAll('#hsDays .hs-day').forEach(b => {
    b.addEventListener('click', () => {
        document.querySelectorAll('#hsDays .hs-day').forEach(x => {
            x.classList.remove('bg-brand-100', 'text-brand-700', 'border-brand-300');
            x.classList.add('bg-white', 'text-slate-600', 'border-slate-200');
        });
        b.classList.add('bg-brand-100', 'text-brand-700', 'border-brand-300');
        b.classList.remove('bg-white', 'text-slate-600', 'border-slate-200');
        document.getElementById('hsDaysVal').value = b.dataset.days;
    });
});

// 结果区 Tab 切换
function switchTab(tab) {
    const topic = tab === 'topic';
    document.getElementById('tabTopic').className = 'rounded-lg px-3 py-1.5 text-xs font-medium transition ' + (topic ? 'bg-brand-100 text-brand-700' : 'bg-slate-100 text-slate-600 hover:bg-slate-200');
    document.getElementById('tabHotspot').className = 'rounded-lg px-3 py-1.5 text-xs font-medium transition ' + (topic ? 'bg-slate-100 text-slate-600 hover:bg-slate-200' : 'bg-brand-100 text-brand-700');
    document.getElementById('topicHeader').classList.toggle('hidden', !topic);
    document.getElementById('result').classList.toggle('hidden', !topic);
    document.getElementById('actionBar').classList.toggle('hidden', !topic);
    document.getElementById('errorBox').classList.toggle('hidden', !topic);
    document.getElementById('hotspotResult').classList.toggle('hidden', topic);
}
document.getElementById('tabTopic').addEventListener('click', () => switchTab('topic'));
document.getElementById('tabHotspot').addEventListener('click', () => switchTab('hotspot'));

function buildHsLoadingHtml() {
    return '<div class="rounded-lg bg-slate-50 p-6 text-center">' +
        '<div class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-brand-100">' +
            '<svg class="h-5 w-5 animate-spin text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>' +
        '</div>' +
        '<p class="text-sm font-medium text-slate-600">正在抓取全网财税热点…</p>' +
        '<p class="mt-1 text-xs text-slate-400">实时检索 → 角度分析，预计 10–30 秒</p>' +
    '</div>';
}

// 渲染热点卡片列表
function renderHotspots(list) {
    const hsList = document.getElementById('hsList');
    const hsCount = document.getElementById('hsCount');
    hsList.innerHTML = '';
    hsCount.textContent = list.length;
    window.hsCurrentList = list;
    if (!list.length) {
        hsList.innerHTML = '<div class="rounded-lg studio-card text-center"><p class="text-sm text-slate-400">暂无命中热点，试试放宽时间段或子领域。</p></div>';
        return;
    }
    list.forEach((h, i) => {
        const card = document.createElement('div');
        card.className = 'hs-card rounded-xl border border-slate-200 bg-white p-4 shadow-sm';
        const heat = (h.heat_score != null) ? h.heat_score : '';
        const date = h.published_at || '';
        const tags = (h.tags || []).map(t => '<span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">#'+escapeHtml(t)+'</span>').join(' ');
        const angles = h.angles || [];
        let anglesHtml = '';
        angles.forEach((a, ai) => {
            const fLabel = formLabelMap[a.form] || (a.form || '短视频');
            anglesHtml +=
                '<div class="flex gap-2 rounded-lg bg-slate-50 p-2">' +
                    '<span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-500 text-[10px] font-semibold text-white">'+ (ai+1) +'</span>' +
                    '<div class="min-w-0">' +
                        '<p class="text-xs font-semibold text-slate-700">'+ escapeHtml(a.name || '') +'</p>' +
                        '<p class="mt-0.5 text-xs leading-relaxed text-slate-500">'+ escapeHtml(a.suggestion || '') +'</p>' +
                        '<span class="mt-1 inline-block rounded bg-brand-50 px-1.5 py-0.5 text-[10px] text-brand-600">形式建议：'+ fLabel +'</span>' +
                    '</div>' +
                '</div>';
        });
        const defaultSug = (angles[0] && angles[0].suggestion) ? angles[0].suggestion : '';
        const defaultForm = (angles[0] && angles[0].form) ? angles[0].form : '';
        card.dataset.default = defaultSug;
        card.dataset.form = defaultForm;
        card.dataset.source = h.source_url || '';
        card.dataset.sub = h.matched_sub || '';
        card.dataset.hook = h.hook || '';
        const subTag = (h.matched_sub ? '<span class="rounded bg-brand-50 px-1.5 py-0.5 text-[10px] text-brand-600">'+ escapeHtml(h.matched_sub) +'</span>' : '');
        const srcLink = (h.source_url ? '<a href="'+ escapeHtml(h.source_url) +'" target="_blank" rel="noopener" class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500 hover:text-brand-600">看原文 ↗</a>' : '');
        card.innerHTML =
            '<div class="mb-2 flex items-center justify-between text-[11px] text-slate-400">' +
                '<span>🔥 热度 '+ escapeHtml(String(heat)) +'　'+ escapeHtml(date) +'</span>' +
                '<span class="flex flex-wrap items-center justify-end gap-1">'+ tags + subTag + srcLink +'</span>' +
            '</div>' +
            '<h4 class="text-sm font-semibold text-slate-800">'+ escapeHtml(h.title || '') +'</h4>' +
            '<p class="mt-1 text-xs leading-relaxed text-slate-500">'+ escapeHtml(h.summary || '') +'</p>' +
            (h.hook ? '<p class="mt-1.5 rounded-lg bg-amber-50 px-2 py-1.5 text-xs leading-relaxed text-amber-700">留资钩子：'+ escapeHtml(h.hook) +'</p>' : '') +
            (angles.length ? '<button type="button" class="hs-angle-toggle mt-2 text-xs font-medium text-brand-600">▶ 创作角度建议（'+ angles.length +' 个）</button>' : '') +
            '<div class="hs-angles hidden mt-2 space-y-2">'+ anglesHtml +'</div>' +
            '<div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-3">' +
                '<button type="button" class="hs-edit-toggle text-xs text-slate-500 hover:text-brand-600">编辑建议</button>' +
                '<button type="button" class="hs-go inline-flex items-center gap-1 rounded-md bg-brand-500 px-3 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-brand-600" data-idx="'+ i +'">去二创 →</button>' +
            '</div>' +
            '<div class="hs-edit hidden mt-2">' +
                '<textarea class="hs-edit-area w-full rounded-lg border border-slate-200 p-2 text-xs leading-relaxed text-slate-700 outline-none focus:border-brand-400" rows="3">'+ escapeHtml(defaultSug) +'</textarea>' +
                '<div class="mt-1 flex items-center justify-between">' +
                    '<button type="button" class="hs-restore text-[11px] text-slate-400 hover:underline">恢复默认</button>' +
                '</div>' +
            '</div>';

        hsList.appendChild(card);
    });

    hsList.querySelectorAll('.hs-card').forEach(card => {
        const toggle = card.querySelector('.hs-angle-toggle');
        const anglesBox = card.querySelector('.hs-angles');
        if (toggle && anglesBox) {
            toggle.addEventListener('click', () => {
                const willShow = anglesBox.classList.contains('hidden');
                anglesBox.classList.toggle('hidden');
                const n = card.querySelectorAll('.hs-angles > div').length || 0;
                toggle.textContent = (willShow ? '▼' : '▶') + ' 创作角度建议（'+ n +' 个）';
            });
        }
        const editToggle = card.querySelector('.hs-edit-toggle');
        const editBox = card.querySelector('.hs-edit');
        if (editToggle && editBox) editToggle.addEventListener('click', () => editBox.classList.toggle('hidden'));
        const restoreBtn = card.querySelector('.hs-restore');
        const area = card.querySelector('.hs-edit-area');
        if (restoreBtn && area) restoreBtn.addEventListener('click', () => { area.value = card.dataset.default || ''; });
        const goBtn = card.querySelector('.hs-go');
        if (goBtn) goBtn.addEventListener('click', () => {
            const idx = parseInt(goBtn.dataset.idx, 10);
            const h = list[idx];
            if (!h) return;
            const edited = (area && area.value.trim()) ? area.value.trim() : (card.dataset.default || '');
            const form = card.dataset.form || '';
            sessionStorage.setItem('hgt_topic_title', h.title || '');
            sessionStorage.setItem('hgt_topic_summary', h.summary || '');
            sessionStorage.setItem('hgt_topic_angle', edited);
            sessionStorage.setItem('hgt_topic_hook', h.hook || '');
            sessionStorage.setItem('hgt_topic_form', form);
            sessionStorage.setItem('hgt_topic_source_url', card.dataset.source || '');
            sessionStorage.setItem('hgt_topic_matched_sub', card.dataset.sub || '');
            sessionStorage.setItem('hgt_topic_from', 'hotspot');
            window.location.href = '/studio/rewrite?from=hotspot';
        });
    });
}

// 获取热点（真实数据，后端代理 8500 /hotspot）
async function fetchHotspots() {
    const btn = document.getElementById('hsBtn');
    const days = parseInt(document.getElementById('hsDaysVal').value, 10) || 7;
    const subs = Array.from(document.querySelectorAll('#hsSubs .hs-sub.active')).map(b => b.dataset.sub);
    if (!subs.length) {
        switchTab('hotspot');
        document.getElementById('hsList').innerHTML = '<div class="rounded-lg border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">请至少选择一个财税子领域，或点「全选」。</div>';
        return;
    }
    switchTab('hotspot');
    document.getElementById('hsList').innerHTML = buildHsLoadingHtml();
    document.getElementById('hsRealtime').classList.add('hidden');
    zwSetLoading(btn, {loading: true, text: '抓取热点中…'});
    const signal = HGTAbort.begin('中止：抓取热点中…');
    try {
        const resp = await fetch('/studio/topic/hotspots', {
            method: 'POST',
            signal,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({ days: days, subfields: subs })
        });
        const data = await resp.json();
        if (!resp.ok) throw new Error(data.error || ('请求失败（HTTP ' + resp.status + '）'));
        if (!data.ok) throw new Error(data.error || '获取失败');
        const topics = data.topics || [];
        if (!topics.length && data.filtered) {
            document.getElementById('hsList').innerHTML = '<div class="rounded-lg border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">检索到的内容与所选子领域关联度不足，已自动过滤。请尝试：①换个子领域 ②放宽时间段 ③减少同时选择的子领域数量。</div>';
        } else {
            renderHotspots(topics);
        }
        // 过滤统计 + 批量入口显隐
        const hsBatchBtn = document.getElementById('hsBatchRewrite');
        if (hsBatchBtn) hsBatchBtn.classList.toggle('hidden', !(topics && topics.length));
        const fs = document.getElementById('hsFilterStat');
        if (data.filtered && data.total != null && (data.total - (data.returned != null ? data.returned : topics.length)) > 0) {
            fs.textContent = '（从 ' + data.total + ' 条中过滤掉 ' + (data.total - (data.returned != null ? data.returned : topics.length)) + ' 条）';
            fs.classList.remove('hidden');
        } else if (fs) {
            fs.classList.add('hidden');
        }
        // Tavily key 失效 / 检索异常降级红字提示
        const deg = document.getElementById('hsDegraded');
        if (data.tavily_degraded) {
            document.getElementById('hsDegradedMsg').textContent = data.tavily_message || 'Tavily Key 异常，热点已降级处理。';
            deg.classList.remove('hidden');
        } else {
            deg.classList.add('hidden');
        }
        if (data.realtime === false) document.getElementById('hsRealtime').classList.remove('hidden');
    } catch (err) {
        if (err.name === 'AbortError') {
            document.getElementById('hsList').innerHTML = '<div class="rounded-lg border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">⏹ 已中止热点抓取。</div>';
            hgtToast('warn', '已中止热点抓取');
        } else {
            document.getElementById('hsList').innerHTML = '<div class="rounded-lg border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600">'+ escapeHtml(err.message || '未知错误') +'<br><span class="mt-1 block text-xs text-red-400">请检查网络或稍后重试。</span></div>';
        }
    } finally {
        zwSetLoading(btn, {loading: false});
        HGTAbort.end();
    }
}

document.getElementById('hotspotForm').addEventListener('submit', function (e) {
    e.preventDefault();
    fetchHotspots();
});
document.getElementById('hsRefresh')?.addEventListener('click', fetchHotspots);

// 全部去二创（批量丢进改写页，from=hotspot-all）
document.getElementById('hsBatchRewrite')?.addEventListener('click', function () {
    const list = window.hsCurrentList || [];
    if (!list.length) return;
    const payload = list.map(h => ({
        title: h.title || '',
        summary: h.summary || '',
        angle: (h.angles && h.angles[0] && h.angles[0].suggestion) || '',
        hook: h.hook || '',
        form: (h.angles && h.angles[0] && h.angles[0].form) || '',
        source_url: h.source_url || '',
        matched_sub: h.matched_sub || ''
    }));
    sessionStorage.setItem('hgt_batch_hotspots', JSON.stringify(payload));
    window.location.href = '/studio/rewrite?from=hotspot-all';
});

// 话术模板联动：从「话术模板」的选题角度带入 ?kw= 到关键词输入框
(function () {
    try {
        var p = new URLSearchParams(window.location.search);
        var kw = p.get('kw');
        if (kw) {
            var input = document.getElementById('keywords');
            if (input) input.value = kw;
            hgtToast('info', '已带入模板选题方向，可直接生成');
        }
    } catch (e) {}
})();

// ===== 每日热点·双题材：触发 8500 /hot-daily 抓榜 → 轮询结果 → 渲染财税/大事双组卡片 =====
(function () {
    const btn = document.getElementById('hdRefreshBtn');
    const box = document.getElementById('hdResult');
    if (!btn || !box) return;
    let timer = null;
    let busy = false;

    function csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content || '';
    }

    function setBusy(on) {
        busy = on;
        btn.disabled = on;
        btn.textContent = on ? '⏳ 抓取中…' : '🔄 刷新今日热点';
    }

    // 单条选题卡片：原标题/来源 + 爆款方案(成片标题/钩子/结构/留资) + 用此选题
    function planCard(t, catLabel) {
        const p = (t && t.plan && typeof t.plan === 'object') ? t.plan : null;
        const hook = (p && p.hook_line) ? p.hook_line : '';
        const cta = (p && p.cta) ? p.cta : '';
        const struct = (p && Array.isArray(p.structure)) ? p.structure.filter(Boolean) : [];
        const structHtml = struct.length
            ? '<ol class="mt-1.5 space-y-1">' + struct.map(function (s, i) {
                return '<li class="flex gap-1.5 text-xs leading-relaxed text-slate-500"><span class="shrink-0 font-medium text-brand-600">' + (i + 1) + '.</span><span>' + escapeHtml(s) + '</span></li>';
              }).join('') + '</ol>'
            : '';
        const srcLink = (t.url ? '<a href="' + escapeHtml(t.url) + '" target="_blank" rel="noopener" class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500 hover:text-brand-600">看原文 ↗</a>' : '');
        return '<div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-title="' + escapeHtml(t.title || '') + '" data-source="' + escapeHtml(t.source || '') + '" data-url="' + escapeHtml(t.url || '') + '" data-plan="' + escapeHtml(JSON.stringify(p || {})) + '">' +
            '<div class="mb-1.5 flex flex-wrap items-center justify-between gap-1 text-[11px] text-slate-400">' +
                '<span><span class="rounded bg-brand-50 px-1.5 py-0.5 font-medium text-brand-600">' + catLabel + '</span>　' + escapeHtml(t.source || '') + '</span>' +
                srcLink +
            '</div>' +
            '<h4 class="text-sm font-semibold text-slate-800">' + escapeHtml(t.title || '') + '</h4>' +
            (p && p.title ? '<p class="mt-1 text-xs font-medium text-brand-600">成片标题：' + escapeHtml(p.title) + '</p>' : '') +
            (hook ? '<p class="mt-1.5 rounded-lg bg-amber-50 px-2 py-1.5 text-xs leading-relaxed text-amber-700">开头钩子：' + escapeHtml(hook) + '</p>' : '') +
            structHtml +
            (cta ? '<p class="mt-1.5 rounded-lg bg-emerald-50 px-2 py-1.5 text-xs leading-relaxed text-emerald-700">留资钩子：' + escapeHtml(cta) + '</p>' : '') +
            '<div class="mt-3 border-t border-slate-100 pt-2.5 text-right">' +
                '<button type="button" class="hd-go rounded-md bg-brand-500 px-3 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-brand-600">用此选题 →</button>' +
            '</div>' +
        '</div>';
    }

    function renderGroup(title, items, catLabel) {
        const wrap = document.createElement('div');
        const h = document.createElement('h4');
        h.className = 'mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400';
        h.textContent = title + '（' + items.length + '）';
        wrap.appendChild(h);
        const grid = document.createElement('div');
        grid.className = 'grid gap-3 md:grid-cols-2';
        items.forEach(function (t) { grid.insertAdjacentHTML('beforeend', planCard(t, catLabel)); });
        wrap.appendChild(grid);
        return wrap;
    }

    function renderResult(data) {
        const r = (data && data.result) ? data.result : null;
        if (!r) {
            box.innerHTML = '<div class="rounded-lg border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">今日热点尚未生成，点「🔄 刷新今日热点」抓取微博/百度/头条热榜并生成爆款方案（约 1-3 分钟）。</div>';
            return;
        }
        const fin = r.finance || [];
        const ev = r.event || [];
        box.innerHTML = '';
        const meta = document.createElement('p');
        meta.className = 'mb-3 text-xs text-slate-400';
        meta.textContent = '数据日期 ' + (r.date || '') + ' · 生成于 ' + (r.generated_at || '') + ' · 共抓取 ' + (r.raw_count != null ? r.raw_count : '?') + ' 条 · 来源 ' + ((r.sources || []).join(' / ') || '—');
        box.appendChild(meta);
        if (!fin.length && !ev.length) {
            box.insertAdjacentHTML('beforeend', '<div class="rounded-lg border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">本次没有过滤出符合条件的选题，可稍后重试。</div>');
            return;
        }
        if (fin.length) box.appendChild(renderGroup('财税直接相关', fin, '财税'));
        if (ev.length) box.appendChild(renderGroup('重大热点事件', ev, '大事'));
    }

    // 轮询结果：running 时继续等，否则渲染
    function poll() {
        clearTimeout(timer);
        fetch('/studio/topic/hot-daily-result', { headers: { 'Accept': 'application/json' } })
            .then(function (resp) { return resp.json().catch(function () { return {}; }); })
            .then(function (data) {
                if (data && data.running) {
                    box.innerHTML = '<div class="rounded-lg border-brand-200 bg-brand-50 px-3 py-2 text-sm text-brand-700">⏳ 正在抓取热榜并生成爆款方案，请稍候…</div>';
                    timer = setTimeout(poll, 3000);
                } else {
                    setBusy(false);
                    renderResult(data);
                }
            })
            .catch(function () {
                setBusy(false);
                box.innerHTML = '<div class="rounded-lg border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600">读取每日热点结果失败，请稍后重试。</div>';
            });
    }

    btn.addEventListener('click', function () {
        if (busy) return;
        setBusy(true);
        box.innerHTML = '<div class="rounded-lg border-brand-200 bg-brand-50 px-3 py-2 text-sm text-brand-700">⏳ 正在触发抓取…</div>';
        fetch('/studio/topic/hot-daily', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf()
            },
            body: JSON.stringify({})
        })
            .then(function (resp) { return resp.json().catch(function () { return {}; }); })
            .then(function (data) {
                if (data && data.running) {
                    timer = setTimeout(poll, 2500);
                } else {
                    setBusy(false);
                    box.innerHTML = '<div class="rounded-lg border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600">' + escapeHtml((data && data.error) || '触发失败，请确认 8500 微服务已启动') + '</div>';
                }
            })
            .catch(function () {
                setBusy(false);
                box.innerHTML = '<div class="rounded-lg border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600">触发失败：网络错误，请稍后重试。</div>';
            });
    });

    // 卡片「用此选题」→ 二创（事件委托）
    box.addEventListener('click', function (e) {
        const go = e.target.closest('.hd-go');
        if (!go) return;
        const card = go.closest('[data-title]');
        if (!card) return;
        let plan = {};
        try { plan = JSON.parse(card.dataset.plan || '{}'); } catch (err) { plan = {}; }
        sessionStorage.setItem('hgt_topic_title', plan.title || card.dataset.title || '');
        sessionStorage.setItem('hgt_topic_summary', card.dataset.title || '');
        sessionStorage.setItem('hgt_topic_angle', [plan.hook_line, (plan.structure || []).join('；')].filter(Boolean).join('\n'));
        sessionStorage.setItem('hgt_topic_hook', plan.cta || '');
        sessionStorage.setItem('hgt_topic_form', '');
        sessionStorage.setItem('hgt_topic_source_url', card.dataset.url || '');
        sessionStorage.setItem('hgt_topic_matched_sub', card.dataset.source || '');
        sessionStorage.setItem('hgt_topic_from', 'daily-hot');
        window.location.href = '/studio/rewrite?from=daily-hot';
    });

    // 打开页面先读一次已有结果
    poll();
})();
</script>
