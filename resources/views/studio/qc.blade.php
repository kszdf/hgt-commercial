<x-app-layout>
<x-workspace-layout title="智能质检">
<div class="mx-auto max-w-5xl p-6">

    {{-- 发布门禁：合规（文本）+ 质量（技术）双把关，给出"能否发布"的明确结论 --}}
    <section id="gatePanel" class="mb-4 overflow-hidden rounded-2xl border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 text-brand-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <h3 class="text-sm font-semibold text-slate-700">发布门禁</h3>
                <span class="hint" data-tip="合规（文本违禁词/风险）+ 质量（视频技术）双把关，给出可否发布的明确结论。结论仅作人工拍板前的把关提示，绝不替您自动发布。">?</span>
            </div>
            <button id="goPublish" disabled
                class="rounded-lg bg-brand-500 px-4 py-1.5 text-sm font-medium text-white shadow-sm transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50">
                进入发布助手 →
            </button>
        </div>
        <div class="px-5 py-4">
            <div id="gateVerdict" class="flex items-center gap-3 rounded-xl bg-slate-50 px-4 py-3">
                <span id="gateIcon" class="text-2xl">⚪</span>
                <div>
                    <div id="gateLabel" class="text-base font-semibold text-slate-500">尚未检测</div>
                    <div id="gateHint" class="mt-0.5 text-xs text-slate-400">运行「文本质检」或选择「关联成片」后，这里给出可否发布的明确结论。</div>
                </div>
            </div>
            <ul id="gateReasons" class="mt-3 space-y-1.5"></ul>
        </div>
    </section>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="luxury-glass p-5">
            <h3 class="mb-3 text-sm font-semibold text-slate-700">文本质检<span class="hint" data-tip="对二创/口播稿做平台化违禁词与风险预检，出片前用。">?</span></h3>
            <form id="qcForm" class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">目标平台（可选，影响违禁词库）</label>
                    <select id="platform" name="platform" class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option value="">全平台</option>
                        <option value="视频号">视频号</option>
                        <option value="抖音">抖音</option>
                        <option value="小红书">小红书</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">关联成片（可选，把关结论会记入该成片）</label>
                    <select id="linkedJob" name="linkedJob" class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option value="">不关联（仅看本次文本结论）</option>
                        @foreach($jobs as $j)
                            <option value="{{ $j['job_id'] }}"
                                data-text="{{ $j['text_qc_status'] ?? '' }}"
                                data-tech="{{ $j['qc_status'] ?? '' }}"
                                data-verdict="{{ $j['verdict']['level'] }}">
                                {{ $j['title'] ?: '未命名' }} · {{ $j['mode'] }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600">待检稿（对话稿 / 口播稿）</label>
                    <textarea id="text" name="text" rows="9" required
                        class="w-full rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                        placeholder="粘贴待检稿…"></textarea>
                </div>
                <button type="submit" id="genBtn"
                    class="w-full rounded-lg bg-brand-500 px-4 py-2.5 font-medium text-white shadow-sm transition hover:bg-brand-600 disabled:opacity-50 disabled:cursor-not-allowed">
                    文本质检
                </button>
                <p id="formMsg" class="text-sm text-red-500"></p>
            </form>
        </section>

        <section class="luxury-glass p-5">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-700">质检报告</h3>
                <span id="statusBadge" class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-500">待检测</span>
            </div>
            <div id="result" class="space-y-3">
                <p class="rounded-lg studio-card studio-card-sm text-sm text-slate-400">质检结果将显示在这里</p>
            </div>
            <div id="errorBox" class="mt-3 hidden rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600"></div>
        </section>
    </div>

    <section class="luxury-glass mt-4 p-5">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-700">视频技术质检<span class="hint" data-tip="对已完成渲染的视频做音轨/画幅/时长检测，结果记入质检报告与发布门禁。">?</span></h3>
        </div>
        <div id="jobList" class="space-y-2">
            @forelse($jobs as $j)
                <div class="flex items-center justify-between rounded-lg studio-card studio-card-sm" data-job="{{ $j['job_id'] }}">
                    <div>
                        <div class="text-sm text-slate-700">{{ $j['title'] ?: '未命名' }} <span class="text-xs text-slate-400">· {{ $j['mode'] }}</span></div>
                        <div class="qc-status text-xs text-slate-400">{{ $j['qc_status'] ? '已质检：'.$j['qc_status'] : '待质检' }} · {{ $j['verdict']['label'] }}</div>
                    </div>
                    <button class="run-qc rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-brand-600" data-job="{{ $j['job_id'] }}">运行技术质检</button>
                </div>
            @empty
                <p class="text-sm text-slate-400">暂无已完成出片（请先在「视频出片」生成一条）。</p>
            @endforelse
        </div>
    </section>
</div>

<script>
// ----- 发布门禁状态 -----
const gate = {
    text: { level: 'none', label: '文本合规未检', hits: 0 },
    tech: { level: 'none', label: '技术质检未做' },
    jobId: null,
};

const LEVEL_COLOR = {
    ok:    { icon: '🟢', ring: 'bg-green-50 border-green-200', text: 'text-green-700', label: 'text-green-700' },
    warn:  { icon: '🟡', ring: 'bg-amber-50 border-amber-200', text: 'text-amber-700', label: 'text-amber-700' },
    block: { icon: '🔴', ring: 'bg-red-50 border-red-200',   text: 'text-red-700',   label: 'text-red-700' },
    none:  { icon: '⚪', ring: 'bg-slate-50 border-slate-200', text: 'text-slate-500', label: 'text-slate-500' },
};

function combineVerdict(t, tech) {
    const blocked = t.level === 'block' || tech.level === 'block';
    const warned  = t.level === 'warn'  || tech.level === 'warn';
    if (blocked) return { level: 'block', label: '不建议发布', can: false };
    if (warned)  return { level: 'warn',  label: '谨慎发布（建议先修改）', can: true };
    if (t.level === 'none' && tech.level === 'none') return { level: 'none', label: '尚未检测', can: false };
    return { level: 'ok', label: '可以发布', can: true };
}

function renderGate() {
    const v = combineVerdict(gate.text, gate.tech);
    const c = LEVEL_COLOR[v.level];
    const verdictEl = document.getElementById('gateVerdict');
    verdictEl.className = 'flex items-center gap-3 rounded-xl border px-4 py-3 ' + c.ring;
    document.getElementById('gateIcon').textContent = c.icon;
    document.getElementById('gateLabel').textContent = v.label;
    document.getElementById('gateLabel').className = 'text-base font-semibold ' + c.label;
    const hint = document.getElementById('gateHint');
    hint.className = 'mt-0.5 text-xs ' + c.text;
    hint.textContent = v.level === 'block'
        ? '存在硬性风险，须先修改后再走发布流程（仅作把关提示，不会自动发布）。'
        : (v.level === 'warn'
            ? '有可改进项，建议人工通读修改后再发布。'
            : (v.level === 'ok'
                ? (gate.text.level === 'none'
                    ? '技术质检已通过；文本合规尚未检测，建议先跑「文本质检」后再发布。'
                    : '合规与质量双关均已通过，可进入发布助手人工拍板。')
                : '运行「文本质检」或选择「关联成片」后给出结论。'));

    const reasons = [];
    reasons.push({ level: gate.text.level, text: '合规预检：' + gate.text.label + (gate.text.hits ? '（命中 ' + gate.text.hits + ' 处）' : '') });
    reasons.push({ level: gate.tech.level, text: '技术质检：' + gate.tech.label });
    const ul = document.getElementById('gateReasons');
    ul.innerHTML = reasons.map(r => {
        const rc = LEVEL_COLOR[r.level] || LEVEL_COLOR.none;
        return '<li class="flex items-center gap-2 text-xs ' + rc.text + '"><span>' + rc.icon + '</span><span>' + escapeHtml(r.text) + '</span></li>';
    }).join('');

    const btn = document.getElementById('goPublish');
    btn.disabled = !v.can;
}

document.getElementById('goPublish').addEventListener('click', function () {
    const v = combineVerdict(gate.text, gate.tech);
    if (v.level === 'block') { hgtToast('warn', '存在硬性风险，请先修改后再发布'); return; }
    if (v.level === 'warn') {
        hgtConfirm({
            title: '谨慎发布提醒',
            text: '质检发现可改进项（中风险/技术告警），建议人工通读修改后再发布。是否仍进入发布助手人工拍板？',
            confirmText: '仍进入发布',
            cancelText: '再改改',
        }).then(ok => { if (ok) location.href = '/studio/publish'; });
        return;
    }
    location.href = '/studio/publish';
});

// 选择关联成片：立即展示该成片已有的门禁结论
document.getElementById('linkedJob').addEventListener('change', function () {
    const opt = this.selectedOptions[0];
    gate.jobId = this.value || null;
    if (!this.value) {
        gate.text = { level: 'none', label: '文本合规未检', hits: 0 };
        gate.tech = { level: 'none', label: '技术质检未做' };
        renderGate();
        return;
    }
    const tMap = { passed: 'ok', warned: 'warn', blocked: 'block', '': 'none' };
    const techMap = { passed: 'ok', warned: 'warn', failed: 'block', '': 'none' };
    const t = opt.dataset.text || '';
    const tech = opt.dataset.tech || '';
    gate.text = { level: tMap[t] || 'none', label: t ? '关联成片已有文本结论' : '文本合规未检', hits: 0 };
    gate.tech = { level: techMap[tech] || 'none', label: tech ? '关联成片已有技术结论' : '技术质检未做' };
    renderGate();
});

// 从二创页「跑质检」跳转过来时，自动填入清洗稿
(function () {
    const params = new URLSearchParams(window.location.search);
    let text = '';
    if (params.get('from') === 'rewrite') {
        text = sessionStorage.getItem('hgt_qc_text') || '';
    }
    if (text) {
        const ta = document.getElementById('text');
        if (ta) { ta.value = text; }
        const src = '/studio/rewrite';
        const hint = document.createElement('div');
        hint.className = 'mb-4 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-700';
        hint.innerHTML = '已从「二创」带入清洗稿，可直接点击「文本质检」。 <a href="' + src + '" class="font-medium underline hover:text-brand-900">← 返回二创</a>';
        document.querySelector('header').after(hint);
    }
})();

document.getElementById('qcForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('genBtn');
    const msg = document.getElementById('formMsg');
    const badge = document.getElementById('statusBadge');
    const result = document.getElementById('result');
    const errBox = document.getElementById('errorBox');
    msg.textContent = ''; errBox.classList.add('hidden');
    zwSetLoading(btn, {loading: true, text: '检测中…'});
    badge.textContent = '检测中'; badge.className = 'rounded-full bg-brand-100 px-3 py-1 text-xs text-brand-600';
    const signal = HGTAbort.begin('中止：智能质检中…');
    try {
        const body = {
            text: document.getElementById('text').value,
            platform: document.getElementById('platform').value || null,
            job_id: document.getElementById('linkedJob').value || null,
        };
        const resp = await fetch('/studio/qc/generate', {
            method: 'POST',
            signal,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify(body)
        });
        const data = await resp.json();
        if (!resp.ok) throw new Error(data.error || '提交失败');
        if (!data.ok) throw new Error(data.error || '检测失败');
        badge.textContent = '完成'; badge.className = 'rounded-full bg-green-100 px-3 py-1 text-xs text-green-700';
        const hits = (data.hits || []);
        const riskColor = data.risk_level === 'high' ? 'bg-red-100 text-red-700' : (data.risk_level === 'medium' ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700');
        let html = '';
        html += '<div class="rounded-xl border border-slate-200 bg-white p-4 space-y-2">';
        html += '<div class="flex flex-wrap items-center gap-2 text-xs"><span class="rounded-full ' + riskColor + ' px-2.5 py-1 font-medium">风险等级：' + (data.risk_level || '-') + '</span>';
        html += '<span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-500">字数 ' + (data.chars || 0) + '</span>';
        html += '<span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-500">预估时长 ' + (data.duration_est_sec || 0) + 's</span>';
        html += '<span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-500">命中 ' + hits.length + ' 处</span></div>';
        if (hits.length) {
            html += '<div class="space-y-1.5">';
            hits.forEach(h => {
                const tag = h.level === 'high' ? '高危' : '中等';
                html += '<div class="rounded-lg border border-red-200 bg-red-50 p-2.5 text-xs text-red-700"><span class="font-medium">' + tag + ' “' + escapeHtml(h.word || '') + '”</span> <span class="text-red-500">建议：' + escapeHtml(h.suggest || '删除/替换') + '</span><div class="mt-1 text-red-400">…' + escapeHtml(h.context || '') + '…</div></div>';
            });
            html += '</div>';
        } else {
            html += '<div class="rounded-lg bg-green-50 p-2.5 text-xs text-green-700">未发现违禁词风险（仍建议人工通读）</div>';
        }
        html += '</div>';
        result.innerHTML = html;

        // 更新发布门禁（合规一关）
        const tMap = { high: 'block', medium: 'warn', low: 'ok' };
        const lvl = tMap[data.risk_level] || 'ok';
        gate.text = { level: lvl, label: '文本合规：' + (data.risk_level || 'low'), hits: hits.length };
        if (body.job_id && data.job_text_qc_status) {
            // 同步下拉项的关联成片文本结论
            const opt = document.querySelector('#linkedJob option[value="' + body.job_id + '"]');
            if (opt) opt.dataset.text = data.job_text_qc_status;
        }
        renderGate();
        zwSetLoading(btn, {loading: false});
    } catch (err) {
        if (err.name === 'AbortError') {
            zwSetLoading(btn, {loading: false});
            badge.textContent = '已中止'; badge.className = 'rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-500';
            msg.textContent = '⏹ 已中止质检。';
            hgtToast('warn', '已中止质检');
            return;
        }
        zwSetLoading(btn, {loading: false});
        badge.textContent = '失败'; badge.className = 'rounded-full bg-red-100 px-3 py-1 text-xs text-red-600';
        errBox.textContent = err.message; errBox.classList.remove('hidden');
    } finally {
        HGTAbort.end();
    }
});

function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

// 对已完成出片运行技术质检
document.querySelectorAll('.run-qc').forEach(btn => {
    btn.addEventListener('click', async () => {
        const jobId = btn.dataset.job;
        const row = btn.closest('[data-job]');
        const statusEl = row.querySelector('.qc-status');
        btn.disabled = true; btn.classList.add('zw-btn-loading'); btn.textContent = '质检中…';
        const signal = HGTAbort.begin('中止：视频质检中…');
        try {
            const resp = await fetch('/studio/qc/video/' + jobId, {
                method: 'POST',
                signal,
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                }
            });
            const data = await resp.json();
            if (!resp.ok) throw new Error(data.error || '检测失败');
            const lvl = data.qc?.level;
            const color = lvl === 'high' ? 'text-red-600' : (lvl === 'medium' ? 'text-amber-600' : 'text-green-600');
            statusEl.className = 'qc-status text-xs ' + color;
            statusEl.textContent = '状态：' + (data.qc?.status || '-') + ' · 分数 ' + (data.qc?.score || 0) + ' · 问题 ' + ((data.qc?.issues || []).length);

            // 更新发布门禁（质量一关）；若该项为关联成片则同步下拉
            const techMap = { high: 'block', medium: 'warn', low: 'ok' };
            gate.tech = { level: techMap[lvl] || 'ok', label: '技术质检：' + (data.qc?.status || '-') };
            const opt = document.querySelector('#linkedJob option[value="' + jobId + '"]');
            if (opt) opt.dataset.tech = data.qc?.status || '';
            renderGate();
            btn.textContent = '重新质检';
        } catch (e) {
            if (e && e.name === 'AbortError') { statusEl.className = 'qc-status text-xs text-slate-500'; statusEl.textContent = '已中止'; btn.textContent = '质检'; HGTAbort.end(); return; }
            statusEl.className = 'qc-status text-xs text-red-600';
            statusEl.textContent = '失败：' + e.message;
            btn.textContent = '重试';
        }
        btn.classList.remove('zw-btn-loading'); btn.disabled = false;
        HGTAbort.end();
    });
});

renderGate();
</script>
</x-workspace-layout>
</x-app-layout>
