<x-app-layout>
<x-workspace-layout title="客户档案" :breadcrumbs="[['label' => '工作台总览', 'url' => '/dashboard'], ['label' => '客户档案']]">
<div class="mx-auto max-w-6xl p-6">
    <header class="mb-5">
        <h1 class="text-xl font-bold text-slate-800">客户档案 CRM</h1>
        <p class="mt-1 text-sm text-slate-500">把公众号 / 短视频 / 对话里冒出来的意向客户一键入档，按阶段（线索 → 意向 → 成交）管理，同名同联系方式自动去重升级。{{ $brand ? '品牌：' . $brand : '' }}</p>
    </header>

    @include('components.flash')

    <div class="grid gap-4 lg:grid-cols-5">
        {{-- 左：入档表单 --}}
        <section class="luxury-glass p-5 lg:col-span-2">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">录入客户</h2>

            <label class="mb-1 block text-xs font-medium text-slate-600">客户姓名 / 昵称 <span class="text-red-500">*</span></label>
            <input id="crmName" type="text" maxlength="60" placeholder="例：昆山做五金件的周老板"
                class="mb-3 w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">

            <label class="mb-1 block text-xs font-medium text-slate-600">联系方式</label>
            <input id="crmContact" type="text" maxlength="60" placeholder="微信 / 手机 / 抖音号"
                class="mb-3 w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">

            <div class="mb-3 grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">阶段</label>
                    <select id="crmStage"
                        class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option value="线索">线索</option>
                        <option value="意向">意向</option>
                        <option value="成交">成交</option>
                        <option value="流失">流失</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">行业</label>
                    <input id="crmIndustry" type="text" maxlength="60" placeholder="例：建筑施工"
                        class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                </div>
            </div>

            <label class="mb-1 block text-xs font-medium text-slate-600">来源</label>
            <input id="crmSource" type="text" maxlength="60" placeholder="例：公众号 / 视频号 / 对话台"
                class="mb-3 w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">

            <label class="mb-1 block text-xs font-medium text-slate-600">备注</label>
            <textarea id="crmNote" rows="2" maxlength="500" placeholder="需求要点 / 跟进记录"
                class="mb-4 w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"></textarea>

            <button id="crmSubmit" type="button" onclick="crmSave()"
                class="w-full rounded-lg bg-brand-600 px-5 py-2 text-sm font-medium text-white shadow hover:bg-brand-700 disabled:cursor-not-allowed disabled:bg-slate-300 transition-colors">
                入档
            </button>
        </section>

        {{-- 右：列表 --}}
        <section class="luxury-glass p-5 lg:col-span-3">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-700">客户列表 <span id="crmTotal" class="text-xs font-normal text-slate-400"></span></h2>
                <button type="button" onclick="crmLoad()" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 hover:border-brand-300 hover:text-brand-600">刷新</button>
            </div>

            <div id="crmStages" class="mb-3 flex flex-wrap gap-2 text-xs"></div>

            <div id="crmLoading" class="hidden rounded-lg border border-brand-100 bg-brand-50/60 px-4 py-6 text-center text-sm text-brand-700">加载中…</div>
            <div id="crmError" class="hidden rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"></div>
            <div id="crmEmpty" class="rounded-lg border border-dashed border-slate-200 px-4 py-10 text-center text-sm text-slate-400">暂无客户记录</div>

            <div id="crmList" class="hidden space-y-2">
                <!-- 记录由 JS 渲染 -->
            </div>
        </section>
    </div>
</div>

<div id="crmToast" class="fixed bottom-6 left-1/2 z-50 hidden -translate-x-1/2 rounded-lg px-4 py-2.5 text-sm shadow-lg"></div>
</x-workspace-layout>
</x-app-layout>

<script>
function crmCsrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; }

function crmToast(msg, type) {
    const el = document.getElementById('crmToast');
    el.className = 'fixed bottom-6 left-1/2 z-50 -translate-x-1/2 rounded-lg px-4 py-2.5 text-sm shadow-lg ' +
        (type === 'ok' ? 'bg-green-600 text-white' : type === 'warn' ? 'bg-amber-500 text-white' : 'bg-red-600 text-white');
    el.textContent = msg;
    el.classList.remove('hidden');
    clearTimeout(window._crmToastTimer);
    window._crmToastTimer = setTimeout(() => el.classList.add('hidden'), 4000);
}

const STAGE_COLORS = {
    '线索': 'bg-slate-100 text-slate-600',
    '意向': 'bg-sky-100 text-sky-700',
    '成交': 'bg-emerald-100 text-emerald-700',
    '流失': 'bg-rose-100 text-rose-700',
};

function crmStageBadge(stage) {
    const c = STAGE_COLORS[stage] || 'bg-slate-100 text-slate-600';
    return '<span class="inline-block rounded px-2 py-0.5 text-xs font-medium ' + c + '">' + (stage || '线索') + '</span>';
}

function crmRenderStages(byStage) {
    const box = document.getElementById('crmStages');
    box.innerHTML = '';
    const keys = Object.keys(byStage || {});
    if (!keys.length) return;
    keys.forEach(function (k) {
        const span = document.createElement('span');
        span.className = 'inline-flex items-center gap-1 rounded bg-slate-50 px-2 py-1 text-slate-500';
        span.innerHTML = (STAGE_COLORS[k] ? crmStageBadge(k) : '<span class="rounded px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-600">' + k + '</span>') + ' <b class="text-slate-700">' + byStage[k] + '</b>';
        box.appendChild(span);
    });
}

function crmRenderList(records) {
    const list = document.getElementById('crmList');
    const empty = document.getElementById('crmEmpty');
    list.innerHTML = '';
    if (!records || !records.length) {
        list.classList.add('hidden');
        empty.classList.remove('hidden');
        return;
    }
    empty.classList.add('hidden');
    list.classList.remove('hidden');
    records.forEach(function (r) {
        const div = document.createElement('div');
        div.className = 'rounded-lg border border-slate-200/70 bg-white px-4 py-3';
        const note = r.note ? '<div class="mt-1 text-xs text-slate-500">备注：' + esc(r.note) + '</div>' : '';
        const meta = [];
        if (r.industry) meta.push('行业：' + esc(r.industry));
        if (r.source) meta.push('来源：' + esc(r.source));
        if (r.contact) meta.push('联系：' + esc(r.contact));
        if (r.ts) meta.push(esc(r.ts));
        div.innerHTML =
            '<div class="flex items-center justify-between gap-3">' +
                '<div class="min-w-0">' +
                    '<div class="truncate text-sm font-medium text-slate-800">' + esc(r.name || '未命名') + '</div>' +
                    (meta.length ? '<div class="mt-0.5 truncate text-xs text-slate-400">' + meta.map(esc).join(' · ') + '</div>' : '') +
                    note +
                '</div>' +
                crmStageBadge(r.stage) +
            '</div>';
        list.appendChild(div);
    });
}

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

async function crmLoad() {
    document.getElementById('crmError').classList.add('hidden');
    document.getElementById('crmLoading').classList.remove('hidden');
    try {
        const resp = await fetch('{{ route('studio.crm.list') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': crmCsrf(), 'Accept': 'application/json' },
            body: JSON.stringify({}),
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok || data.ok === false) {
            throw new Error(data.error || ('加载失败（HTTP ' + resp.status + '）'));
        }
        document.getElementById('crmTotal').textContent = '共 ' + (data.total || 0) + ' 条';
        crmRenderStages(data.by_stage);
        crmRenderList(data.records);
    } catch (e) {
        const box = document.getElementById('crmError');
        box.textContent = '❌ ' + (e.message || '加载失败');
        box.classList.remove('hidden');
    } finally {
        document.getElementById('crmLoading').classList.add('hidden');
    }
}

async function crmSave() {
    const name = document.getElementById('crmName').value.trim();
    if (!name) {
        crmToast('请填写客户姓名 / 昵称', 'warn');
        document.getElementById('crmName').focus();
        return;
    }
    const btn = document.getElementById('crmSubmit');
    btn.disabled = true; btn.textContent = '入档中…';
    try {
        const resp = await fetch('{{ route('studio.crm.store') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': crmCsrf(), 'Accept': 'application/json' },
            body: JSON.stringify({
                name: name,
                contact: document.getElementById('crmContact').value.trim(),
                stage: document.getElementById('crmStage').value,
                industry: document.getElementById('crmIndustry').value.trim(),
                source: document.getElementById('crmSource').value.trim(),
                note: document.getElementById('crmNote').value.trim(),
            }),
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok || data.ok === false) {
            throw new Error(data.error || ('保存失败（HTTP ' + resp.status + '）'));
        }
        crmToast(data.updated ? '已更新该客户阶段' : '客户已入档', 'ok');
        document.getElementById('crmName').value = '';
        document.getElementById('crmContact').value = '';
        document.getElementById('crmIndustry').value = '';
        document.getElementById('crmSource').value = '';
        document.getElementById('crmNote').value = '';
        crmLoad();
    } catch (e) {
        crmToast('❌ ' + (e.message || '保存失败'), 'err');
    } finally {
        btn.disabled = false; btn.textContent = '入档';
    }
}

document.addEventListener('DOMContentLoaded', crmLoad);
</script>
