<x-app-layout>
<div class="mx-auto max-w-5xl p-6">
    <header class="mb-5">
        <h2 class="text-xl font-semibold text-slate-800">智能选题</h2>
        <p class="mt-0.5 text-sm text-slate-400">面向财税企业主，AI 生成高转化短视频选题与留资钩子。</p>
    </header>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- 输入区 -->
        <section class="luxury-glass p-5">
            <form id="topicForm" class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">行业</label>
                    <input id="industry" name="industry" value="建筑工程" maxlength="40"
                        class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                        placeholder="如：建筑工程 / 餐饮 / 电商">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">关键词（逗号分隔，可选）</label>
                    <input id="keywords" name="keywords" value="虚开发票、暂估成本、公转私" maxlength="120"
                        class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                        placeholder="如：税务风险、金税四期、公转私">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">生成数量</label>
                    <select id="count" name="count" class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option>3</option><option selected>5</option><option>8</option><option>10</option><option>12</option>
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
                <p class="rounded-lg bg-slate-50 p-4 text-sm text-slate-400">生成的选题将显示在这里</p>
            </div>
            <div id="errorBox" class="mt-3 hidden rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600"></div>
        </section>
    </div>
</div>

<script>
document.getElementById('topicForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('genBtn');
    const msg = document.getElementById('formMsg');
    const badge = document.getElementById('statusBadge');
    const result = document.getElementById('result');
    const errBox = document.getElementById('errorBox');
    msg.textContent = ''; errBox.classList.add('hidden');
    btn.disabled = true; btn.textContent = '生成中…';
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
        badge.textContent = '完成'; badge.className = 'rounded-full bg-green-100 px-3 py-1 text-xs text-green-700';
        result.innerHTML = '';
        (data.topics || []).forEach((t, i) => {
            const el = document.createElement('div');
            el.className = 'rounded-xl border border-slate-200 bg-white p-4 shadow-sm';
            el.innerHTML =
                '<div class="mb-1 flex items-center gap-2"><span class="rounded-md bg-brand-50 px-1.5 py-0.5 text-[10px] font-medium text-brand-600">' + (t.form || '短视频') + '</span>' +
                '<h4 class="text-sm font-semibold text-slate-800">' + (i + 1) + '. ' + escapeHtml(t.title) + '</h4></div>' +
                '<p class="text-xs text-slate-500">角度：' + escapeHtml(t.angle || '') + '</p>' +
                '<p class="mt-1 text-xs text-slate-500">潜力：' + escapeHtml(t.potential || '') + '</p>' +
                '<p class="mt-1.5 rounded-lg bg-amber-50 px-2 py-1 text-xs text-amber-700">钩子：' + escapeHtml(t.hook || '') + '</p>';
            result.appendChild(el);
        });
        btn.disabled = false; btn.textContent = '生成选题';
    } catch (err) {
        btn.disabled = false; btn.textContent = '生成选题';
        badge.textContent = '失败'; badge.className = 'rounded-full bg-red-100 px-3 py-1 text-xs text-red-600';
        errBox.textContent = err.message; errBox.classList.remove('hidden');
    }
});

function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
</script>
</x-app-layout>
