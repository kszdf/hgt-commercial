<x-app-layout>
<div class="mx-auto max-w-5xl p-6">
    <header class="mb-5">
        <h2 class="text-xl font-semibold text-slate-800">智能二创</h2>
        <p class="mt-0.5 text-sm text-slate-400">三模式改写 + 违禁词自动标红，去 AI 感，可直接用于配音出片。</p>
    </header>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="luxury-glass p-5">
            <form id="rwForm" class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">改写模式</label>
                    <select id="mode" name="mode" class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option value="dual">双声对话（女问男答·推荐）</option>
                        <option value="single">单人口播（男声张老师）</option>
                        <option value="script">专业口播稿（保留术语）</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">原始稿</label>
                    <textarea id="text" name="text" rows="11" required
                        class="w-full rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                        placeholder="粘贴你的口播稿 / 文案…">虚开发票是违法的。企业应该规范入账。公转私风险很高，要注意。</textarea>
                </div>
                <button type="submit" id="genBtn"
                    class="w-full rounded-lg bg-brand-500 px-4 py-2.5 font-medium text-white shadow-sm transition hover:bg-brand-600 disabled:opacity-50 disabled:cursor-not-allowed">
                    智能改写
                </button>
                <p id="formMsg" class="text-sm text-red-500"></p>
            </form>
        </section>

        <section class="luxury-glass p-5">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-700">改写结果</h3>
                <span id="statusBadge" class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-500">待生成</span>
            </div>
            <div id="result" class="space-y-3">
                <p class="rounded-lg bg-slate-50 p-4 text-sm text-slate-400">改写后的稿子与违禁词标记将显示在这里</p>
            </div>
            <div id="errorBox" class="mt-3 hidden rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600"></div>
        </section>
    </div>
</div>

<script>
document.getElementById('rwForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('genBtn');
    const msg = document.getElementById('formMsg');
    const badge = document.getElementById('statusBadge');
    const result = document.getElementById('result');
    const errBox = document.getElementById('errorBox');
    msg.textContent = ''; errBox.classList.add('hidden');
    btn.disabled = true; btn.textContent = '改写中…';
    badge.textContent = '改写中'; badge.className = 'rounded-full bg-brand-100 px-3 py-1 text-xs text-brand-600';
    try {
        const resp = await fetch('/studio/rewrite/generate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({
                mode: document.getElementById('mode').value,
                text: document.getElementById('text').value,
            })
        });
        const data = await resp.json();
        if (!resp.ok) throw new Error(data.error || '提交失败');
        if (!data.ok) throw new Error(data.error || '生成失败');
        badge.textContent = '完成'; badge.className = 'rounded-full bg-green-100 px-3 py-1 text-xs text-green-700';
        const hits = (data.hits || []);
        let html = '';
        html += '<div class="rounded-xl border border-slate-200 bg-white p-4"><div class="mb-1.5 text-xs font-medium text-slate-500">改写稿（违禁词已标红）</div>';
        html += '<div class="whitespace-pre-wrap text-sm text-slate-700 leading-relaxed">' + highlight(data.rewritten, hits) + '</div></div>';
        if (hits.length) {
            html += '<div class="rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-600">命中违禁词 ' + hits.length + ' 处：' + hits.map(h => escapeHtml(h.word || '')).join('、') + '</div>';
        }
        html += '<div class="rounded-xl border border-slate-200 bg-white p-4"><div class="mb-1.5 flex items-center justify-between text-xs font-medium text-slate-500"><span>清洗后可配音稿</span><button type="button" onclick="copyText()" class="text-brand-500 hover:underline">复制</button></div>';
        html += '<div id="cleaned" class="whitespace-pre-wrap text-sm text-slate-700 leading-relaxed">' + escapeHtml(data.cleaned || '') + '</div></div>';
        result.innerHTML = html;
        btn.disabled = false; btn.textContent = '智能改写';
    } catch (err) {
        btn.disabled = false; btn.textContent = '智能改写';
        badge.textContent = '失败'; badge.className = 'rounded-full bg-red-100 px-3 py-1 text-xs text-red-600';
        errBox.textContent = err.message; errBox.classList.remove('hidden');
    }
});

function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function highlight(text, hits){
    let h = escapeHtml(text);
    (hits||[]).forEach(it => {
        const w = escapeHtml(it.word || '');
        if (w) h = h.split(w).join('<mark class="bg-red-100 text-red-700 rounded px-0.5">' + w + '</mark>');
    });
    return h;
}
function copyText(){
    const t = document.getElementById('cleaned')?.innerText || '';
    if (navigator.clipboard) navigator.clipboard.writeText(t);
}
</script>
</x-app-layout>
