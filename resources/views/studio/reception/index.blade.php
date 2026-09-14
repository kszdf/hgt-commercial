<x-app-layout>
<x-workspace-layout title="AI 客服" :breadcrumbs="[['label' => '工作台总览', 'url' => '/dashboard'], ['label' => 'AI 客服']]">
<div class="mx-auto max-w-3xl p-6">
    <header class="mb-5">
        <h1 class="text-xl font-bold text-slate-800">AI 客服 · 自动接待配置</h1>
        <p class="mt-1 text-sm text-slate-500">配置 AI 自动接待的话术与转人工规则，覆盖公众号留言 / 评论区等私信通道，把高频问题先接住、意向客户自动标记转人工。{{ $brand ? '品牌：' . $brand : '' }}</p>
    </header>

    @include('components.flash')

    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-800">
        <strong>说明：</strong>当前后端负责持久化配置；真实自动接待需在「发布渠道」页完成对应平台的 OAuth 授权后联调生效。未开放私信 API 的平台，会以公众号留言 / 评论区自动回复兜底。
    </div>

    <section class="luxury-glass p-5">
        <h2 class="mb-3 text-sm font-semibold text-slate-700">接待配置</h2>

        <label class="mb-1 block text-xs font-medium text-slate-600">覆盖平台</label>
        <input id="rcPlatform" type="text" maxlength="40" value="全平台" placeholder="例：全平台 / 视频号+公众号"
            class="mb-3 w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">

        <label class="mb-1 block text-xs font-medium text-slate-600">行业口径</label>
        <input id="rcIndustry" type="text" maxlength="60" placeholder="例：财税 / 建筑工程"
            class="mb-3 w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">

        <label class="mb-1 block text-xs font-medium text-slate-600">欢迎语</label>
        <textarea id="rcGreeting" rows="2" maxlength="300" placeholder="例：您好，我是 AI 客服，常见财税问题我可以先行解答～"
            class="mb-3 w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"></textarea>

        <label class="mb-1 block text-xs font-medium text-slate-600">转人工规则</label>
        <textarea id="rcTransfer" rows="2" maxlength="300" placeholder="例：意向客户才转（AI 评分≥7）/ 涉及金额较大直接转人工"
            class="mb-4 w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"></textarea>

        <button id="rcSubmit" type="button" onclick="rcSave()"
            class="w-full rounded-lg bg-brand-600 px-5 py-2 text-sm font-medium text-white shadow hover:bg-brand-700 disabled:cursor-not-allowed disabled:bg-slate-300 transition-colors">
            保存配置
        </button>
    </section>

    <div id="rcResult" class="mt-4 hidden rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-4">
        <h3 class="text-sm font-semibold text-emerald-800">配置已保存 ✓</h3>
        <div id="rcResultBody" class="mt-2 space-y-1 text-sm text-emerald-900"></div>
        <div id="rcStatus" class="mt-2 rounded bg-white/70 px-3 py-2 text-xs leading-6 text-slate-600"></div>
    </div>

    <div id="rcError" class="mt-4 hidden rounded-lg border border-red-200 bg-red-50 px-5 py-3 text-sm text-red-700"></div>
</div>

<div id="rcToast" class="fixed bottom-6 left-1/2 z-50 hidden -translate-x-1/2 rounded-lg px-4 py-2.5 text-sm shadow-lg"></div>
</x-workspace-layout>
</x-app-layout>

<script>
function rcCsrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; }

function rcToast(msg, type) {
    const el = document.getElementById('rcToast');
    el.className = 'fixed bottom-6 left-1/2 z-50 -translate-x-1/2 rounded-lg px-4 py-2.5 text-sm shadow-lg ' +
        (type === 'ok' ? 'bg-green-600 text-white' : type === 'warn' ? 'bg-amber-500 text-white' : 'bg-red-600 text-white');
    el.textContent = msg;
    el.classList.remove('hidden');
    clearTimeout(window._rcToastTimer);
    window._rcToastTimer = setTimeout(() => el.classList.add('hidden'), 4000);
}

async function rcSave() {
    const btn = document.getElementById('rcSubmit');
    btn.disabled = true; btn.textContent = '保存中…';
    document.getElementById('rcResult').classList.add('hidden');
    document.getElementById('rcError').classList.add('hidden');
    try {
        const resp = await fetch('{{ route('studio.reception.save') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': rcCsrf(), 'Accept': 'application/json' },
            body: JSON.stringify({
                platform: document.getElementById('rcPlatform').value.trim(),
                industry: document.getElementById('rcIndustry').value.trim(),
                greeting: document.getElementById('rcGreeting').value.trim(),
                transfer_rule: document.getElementById('rcTransfer').value.trim(),
            }),
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok || data.ok === false) {
            throw new Error(data.error || ('保存失败（HTTP ' + resp.status + '）'));
        }
        const cfg = data.config || {};
        const rows = [];
        if (cfg.platform) rows.push('覆盖平台：' + cfg.platform);
        if (cfg.industry) rows.push('行业口径：' + cfg.industry);
        if (cfg.greeting) rows.push('欢迎语：' + cfg.greeting);
        if (cfg.transfer_rule) rows.push('转人工：' + cfg.transfer_rule);
        document.getElementById('rcResultBody').innerHTML = rows.map(function (r) { return '<div>· ' + r + '</div>'; }).join('');
        document.getElementById('rcStatus').textContent = data.status || '';
        document.getElementById('rcResult').classList.remove('hidden');
        rcToast('配置已保存', 'ok');
    } catch (e) {
        const box = document.getElementById('rcError');
        box.textContent = '❌ ' + (e.message || '保存失败');
        box.classList.remove('hidden');
    } finally {
        btn.disabled = false; btn.textContent = '保存配置';
    }
}
</script>
