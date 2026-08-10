let lastTopics = [];
let topicCount = 5;
const MIN_COUNT = 1;
const MAX_COUNT = 10;

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
        '<p class="mt-1 text-xs text-slate-400">热门方向分析 → 痛点匹配 → 钩子设计，预计 5–15 秒</p>' +
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
    result.innerHTML = buildLoadingHtml();

    try {
        const valOrNull = (id) => {
            const v = document.getElementById(id).value?.trim();
            return v || null;
        };

        const resp = await fetch('/studio/topic/generate', {
            method: 'POST',
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

