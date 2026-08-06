<x-app-layout>
<x-workspace-layout title="智能选题">
<div class="mx-auto max-w-5xl p-6">
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- ===== 输入区 ===== -->
        <section class="luxury-glass p-5">
            <form id="topicForm" class="space-y-4">
                <!-- 行业选择：下拉框（15个常见行业） -->
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        行业领域
                        <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500">选填</span>
                    </label>
                    <select id="industry" name="industry"
                        class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option value="">不限行业</option>
                        <optgroup label="── 商业服务 ──">
                            <option value="企业管理">企业管理咨询</option>
                            <option value="法律咨询">法律咨询</option>
                            <option value="企业服务">企业服务</option>
                            <option value="教育培训">教育培训</option>
                        </optgroup>
                        <optgroup label="── 零售消费 ──">
                            <option value="电商带货">电商带货</option>
                            <option value="餐饮美食">餐饮 / 美食</option>
                            <option value="美妆护肤">美妆 / 护肤</option>
                            <option value="服装时尚">服装 / 时尚</option>
                        </optgroup>
                        <optgroup label="── 生活服务 ──">
                            <option value="本地生活">本地生活</option>
                            <option value="房产家居">房产 / 家居</option>
                            <option value="汽车服务">汽车服务</option>
                            <option value="旅游出行">旅游 / 出行</option>
                        </optgroup>
                        <optgroup label="── 知识科技 ──">
                            <option value="知识科普">知识科普</option>
                            <option value="医疗健康">医疗 / 健康</option>
                            <option value="互联网/科技">互联网 / 科技</option>
                        </optgroup>
                    </select>
                    <p class="mt-1 text-xs text-slate-400">选择行业后 AI 将生成该领域的垂直选题；不选则按通用方向推荐。</p>
                </div>

                <!-- 维度筛选（2x2 网格，移除目标平台） -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">
                            热度取向
                            <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500">选填</span>
                        </label>
                        <select id="hotness" name="hotness" class="w-full rounded-lg border border-slate-200 bg-white p-2 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
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
                        <select id="hook" name="hook" class="w-full rounded-lg border border-slate-200 bg-white p-2 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
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
                        <select id="form" name="form" class="w-full rounded-lg border border-slate-200 bg-white p-2 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                            <option value="">不限</option>
                            <option value="单声口播">单声口播（单人出镜/字幕卡）</option>
                            <option value="幕后音口播_双人">幕后音口播（双人对话·男女双声线）</option>
                            <option value="幕后音口播_单人">幕后音口播（单人旁白·单一声线）</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">
                            生成数量
                            <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500">选填</span>
                        </label>
                        <select id="count" name="count"
                            class="w-full rounded-lg border border-slate-200 bg-white p-2 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                            <option value="3">3 条 · 快速</option>
                            <option value="5" selected>5 条 · 推荐</option>
                            <option value="8">8 条 · 丰富</option>
                            <option value="10">10 条 · 批量</option>
                        </select>
                    </div>
                </div>

                <!-- 关键词 -->
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        关键词
                        <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500">选填</span>
                    </label>
                    <input id="keywords" name="keywords" value="" maxlength="120"
                        class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                        placeholder="如：行业热点、用户痛点、产品卖点…（逗号分隔）">
                    <p class="mt-1 text-xs text-slate-400">不填则由 AI 根据所选行业自动推荐热点方向</p>
                </div>

                <!-- 提交按钮 + 校验提示 -->
                <button type="submit" id="genBtn"
                    class="w-full rounded-lg bg-brand-500 px-4 py-2.5 font-medium text-white shadow-sm transition hover:bg-brand-600 disabled:opacity-50 disabled:cursor-not-allowed">
                    生成选题
                </button>
                <p id="formMsg" class="text-sm font-medium text-red-600"></p>
            </form>
        </section>

        <!-- ===== 结果区 ===== -->
        <section class="luxury-glass p-5">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-700">选题建议</h3>
                <span id="statusBadge" class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-500">待生成</span>
            </div>

            <div id="result" class="space-y-3">
                <div class="rounded-lg bg-slate-50 p-6 text-center">
                    <div class="mx-auto mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-slate-200">
                        <svg class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                    </div>
                    <p class="text-sm text-slate-400">生成的选题将显示在这里<br><span class="text-xs text-slate-300">选用后可直接进入「智能二创」改写</span></p>
                </div>
            </div>

            <!-- 底部操作栏 -->
            <div id="actionBar" class="mt-4 hidden rounded-lg border border-brand-200 bg-brand-50 p-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-brand-700"><strong id="topicCount">0</strong> 条选题已生成 — 点击单条选用，或：</p>
                    <div class="flex gap-2">
                        <a href="javascript:void(0)" id="batchRewriteBtn" class="inline-flex items-center gap-1 rounded-md bg-white px-3 py-1.5 text-xs font-medium text-brand-600 shadow-sm transition hover:bg-brand-50">
                            全部去二创 →
                        </a>
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

    // === 前端校验：至少提供一个方向 ===
    const industry = document.getElementById('industry').value.trim();
    const keywords = document.getElementById('keywords').value.trim();

    if (!industry && !keywords) {
        msg.textContent = '⚠ 请至少提供「行业领域」或「关键词」中的一项，否则 AI 无法定向生成选题。';
        msg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        // 同时在错误框显示更详细的说明
        errBox.innerHTML = '<strong>提交失败：缺少必要参数</strong><br><span class="text-xs mt-1 block text-red-400">请填写「行业领域」（下拉选择）或「关键词」（自由输入），两者至少填一项。全部留空时 AI 无法确定选题方向。</span>';
        errBox.classList.remove('hidden');
        return;
    }

    btn.disabled = true;
    btn.textContent = '⏳ AI 思考中…';
    badge.textContent = '生成中';
    badge.className = 'rounded-full bg-brand-100 px-3 py-1 text-xs text-brand-600';

    try {
        const resp = await fetch('/studio/topic/generate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({
                industry: industry,
                keywords: keywords,
                count: parseInt(document.getElementById('count').value, 10),
                hotness: document.getElementById('hotness').value || null,
                hook: document.getElementById('hook').value || null,
                form: document.getElementById('form').value || null,
            })
        });
        const data = await resp.json();
        if (!resp.ok) throw new Error(data.error || '提交失败（HTTP ' + resp.status + '）');
        if (!data.ok) throw new Error(data.error || '生成失败');

        // 成功
        lastTopics = data.topics || [];
        badge.textContent = '完成 · ' + lastTopics.length + '条';
        badge.className = 'rounded-full bg-green-100 px-3 py-1 text-xs text-green-700';
        result.innerHTML = '';
        lastTopics.forEach((t, i) => {
            const el = document.createElement('div');
            el.className = 'group relative rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-brand-300 hover:shadow-md';

            // 呈现形式标签映射
            let formLabel = t.form || '短视频';
            if (formLabel === '幕后音口播_双人') formLabel = '幕后音·双人';
            else if (formLabel === '幕后音口播_单人') formLabel = '幕后音·单人';
            else if (formLabel === '单声口播') formLabel = '单声口播';

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

        // 绑定选用按钮
        result.querySelectorAll('.use-topic-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const idx = parseInt(this.dataset.idx, 10);
                const topic = lastTopics[idx];
                if (!topic) return;
                sessionStorage.setItem('hgt_topic_title', topic.title);
                sessionStorage.setItem('hgt_topic_hook', topic.hook || '');
                sessionStorage.setItem('hgt_topic_form', topic.form || '');
                window.location.href = '/studio/rewrite?from=topic';
            });
        });

        actionBar.classList.remove('hidden');
        document.getElementById('topicCount').textContent = lastTopics.length;
        btn.disabled = false;
        btn.textContent = '生成选题';
        msg.textContent = '';

    } catch (err) {
        btn.disabled = false;
        btn.textContent = '生成选题';
        badge.textContent = '失败';
        badge.className = 'rounded-full bg-red-100 px-3 py-1 text-xs text-red-600';

        // 结构化错误展示
        const errMsg = err.message || '未知错误';
        msg.textContent = '❌ ' + errMsg;
        errBox.innerHTML = '<strong>生成失败</strong><br><span class="text-xs mt-1 block text-red-400">' + escapeHtml(errMsg) + '<br>请检查网络连接或稍后重试。</span>';
        errBox.classList.remove('hidden');
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

function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'':'&#39;'}[c])); }
</script>
