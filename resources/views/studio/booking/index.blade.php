<x-app-layout>
<x-workspace-layout title="1v1 预约" :breadcrumbs="[['label' => '工作台总览', 'url' => '/dashboard'], ['label' => '1v1 预约']]">
<div class="mx-auto max-w-3xl p-6">
    <header class="mb-5">
        <h1 class="text-xl font-bold text-slate-800">老张 1v1 视频诊断预约</h1>
        <p class="mt-1 text-sm text-slate-500">把公转私、稽查应对、股权架构等深度问题，预约一次 30 分钟视频诊断。每月限 30 单，先到先得；排到队会用留下的联系方式通知拉群。{{ $brand ? '品牌：' . $brand : '' }}</p>
    </header>

    @include('components.flash')

    <section class="luxury-glass p-5">
        <h2 class="mb-3 text-sm font-semibold text-slate-700">提交预约</h2>

        <label class="mb-1 block text-xs font-medium text-slate-600">想诊断的问题 <span class="text-red-500">*</span></label>
        <textarea id="bkTopic" rows="3" maxlength="200" placeholder="例：个人卡收了公司货款被稽查了，怎么补最稳"
            class="mb-3 w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"></textarea>

        <label class="mb-1 block text-xs font-medium text-slate-600">联系方式 <span class="text-red-500">*</span></label>
        <input id="bkContact" type="text" maxlength="60" placeholder="微信 / 手机"
            class="mb-3 w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">

        <div class="mb-4 grid gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">行业（选填）</label>
                <input id="bkIndustry" type="text" maxlength="60" placeholder="例：电商 / 建筑施工"
                    class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">年营收规模（选填）</label>
                <input id="bkRevenue" type="text" maxlength="40" placeholder="例：500万 / 3000万以上"
                    class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
            </div>
        </div>

        <button id="bkSubmit" type="button" onclick="bkSubmit()"
            class="w-full rounded-lg bg-brand-600 px-5 py-2 text-sm font-medium text-white shadow hover:bg-brand-700 disabled:cursor-not-allowed disabled:bg-slate-300 transition-colors">
            提交预约
        </button>
    </section>

    <div id="bkResult" class="mt-4 hidden rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-4">
        <h3 class="text-sm font-semibold text-emerald-800">预约已提交 ✓</h3>
        <div id="bkResultBody" class="mt-2 space-y-1 text-sm text-emerald-900"></div>
    </div>

    <div id="bkWaitlist" class="mt-4 hidden rounded-lg border border-amber-200 bg-amber-50 px-5 py-4">
        <h3 class="text-sm font-semibold text-amber-800">本月名额已满</h3>
        <p id="bkWaitlistBody" class="mt-1 text-sm text-amber-900"></p>
    </div>

    <div id="bkError" class="mt-4 hidden rounded-lg border border-red-200 bg-red-50 px-5 py-3 text-sm text-red-700"></div>
</div>

<div id="bkToast" class="fixed bottom-6 left-1/2 z-50 hidden -translate-x-1/2 rounded-lg px-4 py-2.5 text-sm shadow-lg"></div>
</x-workspace-layout>
</x-app-layout>

<script>
function bkCsrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; }

function bkToast(msg, type) {
    const el = document.getElementById('bkToast');
    el.className = 'fixed bottom-6 left-1/2 z-50 -translate-x-1/2 rounded-lg px-4 py-2.5 text-sm shadow-lg ' +
        (type === 'ok' ? 'bg-green-600 text-white' : type === 'warn' ? 'bg-amber-500 text-white' : 'bg-red-600 text-white');
    el.textContent = msg;
    el.classList.remove('hidden');
    clearTimeout(window._bkToastTimer);
    window._bkToastTimer = setTimeout(() => el.classList.add('hidden'), 4000);
}

async function bkSubmit() {
    const topic = document.getElementById('bkTopic').value.trim();
    const contact = document.getElementById('bkContact').value.trim();
    if (!topic || !contact) {
        bkToast('请填写诊断问题和联系方式', 'warn');
        (!topic ? document.getElementById('bkTopic') : document.getElementById('bkContact')).focus();
        return;
    }
    const btn = document.getElementById('bkSubmit');
    btn.disabled = true; btn.textContent = '提交中…';
    document.getElementById('bkResult').classList.add('hidden');
    document.getElementById('bkWaitlist').classList.add('hidden');
    document.getElementById('bkError').classList.add('hidden');
    try {
        const resp = await fetch('{{ route('studio.booking.store') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': bkCsrf(), 'Accept': 'application/json' },
            body: JSON.stringify({
                topic: topic,
                contact: contact,
                industry: document.getElementById('bkIndustry').value.trim(),
                annual_revenue: document.getElementById('bkRevenue').value.trim(),
            }),
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok || data.ok === false) {
            if (data.waitlist) {
                document.getElementById('bkWaitlistBody').textContent = data.error || '本月名额已满，可候补。';
                document.getElementById('bkWaitlist').classList.remove('hidden');
                return;
            }
            throw new Error(data.error || ('提交失败（HTTP ' + resp.status + '）'));
        }
        const body = document.getElementById('bkResultBody');
        const rows = [];
        if (typeof data.queue_position === 'number') rows.push('排队位次：第 ' + data.queue_position + ' 位');
        if (typeof data.remaining_this_month === 'number') rows.push('本月剩余名额：' + data.remaining_this_month);
        if (data.note) rows.push(data.note);
        body.innerHTML = rows.map(function (r) { return '<div>· ' + r + '</div>'; }).join('');
        document.getElementById('bkResult').classList.remove('hidden');
        bkToast('预约提交成功', 'ok');
        document.getElementById('bkTopic').value = '';
        document.getElementById('bkContact').value = '';
        document.getElementById('bkIndustry').value = '';
        document.getElementById('bkRevenue').value = '';
    } catch (e) {
        const box = document.getElementById('bkError');
        box.textContent = '❌ ' + (e.message || '提交失败');
        box.classList.remove('hidden');
    } finally {
        btn.disabled = false; btn.textContent = '提交预约';
    }
}
</script>
