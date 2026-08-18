<x-app-layout>
<x-workspace-layout title="智能质检">
<div class="mx-auto max-w-5xl p-6">

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="luxury-glass p-5">
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
                    <label class="mb-1 block text-sm font-medium text-slate-600">待检稿（对话稿 / 口播稿）</label>
                    <textarea id="text" name="text" rows="11" required
                        class="w-full rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                        placeholder="粘贴待检稿…"></textarea>
                </div>
                <button type="submit" id="genBtn"
                    class="w-full rounded-lg bg-brand-500 px-4 py-2.5 font-medium text-white shadow-sm transition hover:bg-brand-600 disabled:opacity-50 disabled:cursor-not-allowed">
                    开始质检
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
            <h3 class="text-sm font-semibold text-slate-700">对已完成出片做技术质检</h3>
            <span class="text-xs text-slate-400">渲染完成的视频可手动运行技术质检（音轨 / 画幅 / 时长），结果记入质检报告</span>
        </div>
        <div id="jobList" class="space-y-2">
            @forelse($jobs as $j)
                <div class="flex items-center justify-between rounded-lg studio-card studio-card-sm" data-job="{{ $j->job_id }}">
                    <div>
                        <div class="text-sm text-slate-700">{{ $j->title ?: '未命名' }} <span class="text-xs text-slate-400">· {{ $j->mode }}</span></div>
                        <div class="qc-status text-xs text-slate-400">{{ $j->qc_status ? '已质检：'.$j->qc_status : '待质检' }}</div>
                    </div>
                    <button class="run-qc rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-brand-600" data-job="{{ $j->job_id }}">运行技术质检</button>
                </div>
            @empty
                <p class="text-sm text-slate-400">暂无已完成出片（请先在「视频出片」生成一条）。</p>
            @endforelse
        </div>
    </section>
</div>

<script>
// 从二创页「跑质检」跳转过来时，自动填入清洗稿
(function () {
    const params = new URLSearchParams(window.location.search);
    // 大文本走 sessionStorage，避免 URL 过长导致连接被关闭
    let text = '';
    if (params.get('from') === 'rewrite') {
        text = sessionStorage.getItem('hgt_qc_text') || '';
    }
    if (text) {
        const ta = document.getElementById('text');
        if (ta) { ta.value = text; }
        const src = params.get('src') === 'topic' ? '/studio/rewrite' : '/studio/rewrite-original';
        const hint = document.createElement('div');
        hint.className = 'mb-4 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-700';
        hint.innerHTML = '已从「二创」带入清洗稿，可直接点击「开始质检」。 <a href="' + src + '" class="font-medium underline hover:text-brand-900">← 返回二创</a>';
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
        const resp = await fetch('/studio/qc/generate', {
            method: 'POST',
            signal,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({
                text: document.getElementById('text').value,
                platform: document.getElementById('platform').value || null,
            })
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
</script>
</x-workspace-layout>
</x-app-layout>
