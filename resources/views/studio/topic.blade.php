<x-app-layout>
<div class="mx-auto max-w-5xl p-6">
    <header class="mb-5">
        <h2 class="text-xl font-semibold text-slate-800">智能选题</h2>
    </header>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- 输入区 -->
        <section class="luxury-glass p-5">
            <form id="topicForm" class="space-y-4">
                <!-- 视频分类：自定义文件夹名称，用于归类存放不同类型视频 -->
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">视频分类</label>
                    <div class="space-y-2">
                        <input type="text" id="industry" name="industry" value="{{ $industryHint }}" maxlength="30"
                            class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                            placeholder="如：税务风险、建筑财税、公转私…">
                        <div class="flex flex-wrap gap-1.5">
                            <button type="button" data-cat="税务风险" class="cat-chip rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 transition hover:border-brand-300 hover:text-brand-600">税务风险</button>
                            <button type="button" data-cat="建筑财税" class="cat-chip rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 transition hover:border-brand-300 hover:text-brand-600">建筑财税</button>
                            <button type="button" data-cat="公转私" class="cat-chip rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 transition hover:border-brand-300 hover:text-brand-600">公转私</button>
                            <button type="button" data-cat="金税四期" class="cat-chip rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 transition hover:border-brand-300 hover:text-brand-600">金税四期</button>
                            <button type="button" data-cat="成本费用" class="cat-chip rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 transition hover:border-brand-300 hover:text-brand-600">成本费用</button>
                        </div>
                        <p class="text-xs text-slate-400">用于将生成的视频归入不同分类文件夹，方便后续管理</p>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">关键词（逗号分隔，可选）</label>
                    <input id="keywords" name="keywords" value="虚开发票、暂估成本、公转私" maxlength="120"
                        class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                        placeholder="如：税务风险、金税四期、公转私">
                    <p class="mt-1 text-xs text-slate-400">不填则由 AI 根据行业自动推荐热点方向</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">生成数量</label>
                    <select id="count" name="count"
                        class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option value="3">3 条 · 快速出题</option>
                        <option value="5" selected>5 条 · 推荐（默认）</option>
                        <option value="8">8 条 · 丰富选题</option>
                        <option value="10">10 条 · 批量生产</option>
                        <option value="12">12 条 · 大量储备</option>
                    </select>
                </div>

                <button type="submit" id="genBtn"
                    class="w-full rounded-lg bg-brand-500 px-4 py-2.5 font-medium text-white shadow-sm transition hover:bg-brand-600 disabled:opacity-50 disabled:cursor-not-allowed">
                    生成选题
                </button>
                <p id="formMsg" class="text-sm text-red-500"></p>
            </form>
        </section>

        <!-- 结果区 -->
        <section class="luxury-glass p-5">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-700">选题建议</h3>
                <span id="statusBadge" class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-500">待生成</span>
            </div>

            <div id="result" class="space-y-3">
                <div class="rounded-lg bg-slate-50 p-6 text-center">
                    <div class="mx-auto mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-slate-200">
                        <svg class="h-5 w-5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                    </div>
                    <p class="text-sm text-slate-400">生成的选题将显示在这里<br><span class="text-xs text-slate-300">选用后可直接进入「智能二创」改写</span></p>
                </div>
            </div>

            <!-- 底部操作栏（生成后显示） -->
            <div id="actionBar" class="mt-4 hidden rounded-lg border border-brand-200 bg-brand-50 p-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-brand-700"><strong id="topicCount">0</strong> 条选题已生成 — 点击单条卡片的按钮选用，或：</p>
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

            <div id="errorBox" class="mt-3 hidden rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600"></div>
        </section>
    </div>
</div>

<script>
let lastTopics = [];

// 分类快捷标签点击
document.querySelectorAll('.cat-chip').forEach(chip => {
    chip.addEventListener('click', function () {
        document.getElementById('industry').value = this.dataset.cat;
        // 高亮选中态
        document.querySelectorAll('.cat-chip').forEach(c => c.classList.remove('bg-brand-100', 'border-brand-400', 'text-brand-700'));
        this.classList.add('bg-brand-100', 'border-brand-400', 'text-brand-700');
    });
});

document.getElementById('topicForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('genBtn');
    const msg = document.getElementById('formMsg');
    const badge = document.getElementById('statusBadge');
    const result = document.getElementById('result');
    const errBox = document.getElementById('errorBox');
    const actionBar = document.getElementById('actionBar');
    msg.textContent = ''; errBox.classList.add('hidden'); actionBar.classList.add('hidden');
    btn.disabled = true; btn.textContent = '⏳ AI 思考中…';
    badge.textContent = '生成中'; badge.className = 'rounded-full bg-brand-100 px-3 py-1 text-xs text-brand-600';
    try {
        const resp = await fetch('/studio/topic/generate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({
                industry: document.getElementById('industry').value,
                keywords: document.getElementById('keywords').value,
                count: parseInt(document.getElementById('count').value, 10),
            })
        });
        const data = await resp.json();
        if (!resp.ok) throw new Error(data.error || '提交失败');
        if (!data.ok) throw new Error(data.error || '生成失败');

        // 成功
        lastTopics = data.topics || [];
        badge.textContent = '完成 · ' + lastTopics.length + '条';
        badge.className = 'rounded-full bg-green-100 px-3 py-1 text-xs text-green-700';
        result.innerHTML = '';
        lastTopics.forEach((t, i) => {
            const el = document.createElement('div');
            el.className = 'group relative rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-brand-300 hover:shadow-md';
            el.innerHTML =
                '<div class="mb-2 flex items-start justify-between gap-2">' +
                    '<div class="min-w-0 flex-1">' +
                        '<div class="mb-1 flex items-center gap-2">' +
                            '<span class="shrink-0 rounded-md bg-brand-50 px-1.5 py-0.5 text-[10px] font-medium text-brand-600">' + (t.form || '短视频') + '</span>' +
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
                // 把标题存到 sessionStorage，跳转到二创页自动填入
                sessionStorage.setItem('hgt_topic_title', topic.title);
                sessionStorage.setItem('hgt_topic_hook', topic.hook || '');
                window.location.href = '/studio/rewrite?from=topic';
            });
        });

        // 显示底部操作栏
        actionBar.classList.remove('hidden');
        document.getElementById('topicCount').textContent = lastTopics.length;

        btn.disabled = false; btn.textContent = '生成选题';
    } catch (err) {
        btn.disabled = false; btn.textContent = '生成选题';
        badge.textContent = '失败'; badge.className = 'rounded-full bg-red-100 px-3 py-1 text-xs text-red-600';
        errBox.textContent = err.message; errBox.classList.remove('hidden');
    }
});

// 重新生成 → 滚动回表单
document.getElementById('regenBtn')?.addEventListener('click', function () {
    document.getElementById('topicForm').scrollIntoView({ behavior: 'smooth' });
    document.getElementById('genBtn').click();
});

// 全部去二创 → 存所有选题到 sessionStorage 跳 rewrite
document.getElementById('batchRewriteBtn')?.addEventListener('click', function () {
    if (!lastTopics.length) return;
    sessionStorage.setItem('hgt_batch_topics', JSON.stringify(lastTopics));
    window.location.href = '/studio/rewrite?from=topic-all';
});

function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
</script>
</x-app-layout>
