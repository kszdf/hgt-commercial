<x-app-layout>
<x-workspace-layout title="任务队列" :breadcrumbs="[['label' => '工作台', 'url' => '/dashboard'], ['label' => '任务队列']]">
<div class="mx-auto max-w-6xl px-6 py-6">

    <div class="studio-pagehead">
        <p>一次扔一批进去，不用守着等。跑完在这里看结果，失败可一键重试，支持优先级与连环追爆款。</p>
    </div>

    <!-- ===== 顶部：队列总览（一眼看清跑得怎么样） ===== -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <div class="stat-card">
            <div class="stat-label">待处理</div>
            <div class="stat-num text-slate-700" id="stQueued">{{ $stats['queued'] }}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">处理中</div>
            <div class="stat-num text-brand-600" id="stRunning">{{ $stats['running'] }}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">已完成</div>
            <div class="stat-num text-emerald-600" id="stDone">{{ $stats['done'] }}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">失败</div>
            <div class="stat-num text-red-600" id="stFailed">{{ $stats['failed'] }}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">并发上限<span class="hint" data-tip="同时跑几条由服务器性能决定，排队的不占资源，先来的先跑。">?</span></div>
            <div class="stat-num text-slate-500" id="stConc">{{ $stats['concurrency'] }}</div>
        </div>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-[380px_1fr]">

        <!-- ===== 左：批量提交 ===== -->
        <section class="luxury-glass p-5">
            <h3 class="mb-1 text-sm font-semibold text-slate-700">批量提交</h3>
            <p class="mb-4 text-xs leading-relaxed text-slate-400">一次扔一批进去，不用守着等。跑完在这里看结果，失败可一键重试。</p>

            <form id="queueForm" class="space-y-4">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">任务类型</label>
                    <div class="grid grid-cols-2 gap-2" id="kindPicker">
                        @foreach($kinds as $k => $spec)
                            <button type="button" data-kind="{{ $k }}"
                                class="kind-btn rounded-lg border border-slate-200 bg-white px-3 py-2 text-left text-xs font-medium text-slate-600 transition hover:border-brand-300 hover:text-brand-600">
                                {{ $spec['label'] }}
                            </button>
                        @endforeach
                    </div>
                    <input type="hidden" name="kind" id="kindInput" value="rewrite">
                </div>

                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <label class="text-xs font-medium text-slate-600">内容（一行一条）</label>
                        <span class="text-xs text-slate-400"><span id="lineCount">0</span> / {{ $maxBatch }} 条</span>
                    </div>
                    <textarea id="itemsInput" name="items" rows="9" required
                        class="w-full rounded-lg border border-slate-200 bg-white p-3 text-sm leading-relaxed text-slate-700 outline-none transition focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                        placeholder="每行一条…"></textarea>
                    <p class="mt-1 text-xs text-slate-400" id="kindTip"></p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">行业<span class="hint" data-tip="填了会更贴合你的行业口径，可留空。">?</span></label>
                        <input name="industry" id="industryInput" type="text"
                            class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                            placeholder="如：建筑、电商">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">优先级</label>
                        <select name="priority" id="priorityInput"
                            class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                            <option value="1">最高（插队先跑）</option>
                            <option value="3">较高</option>
                            <option value="5" selected>普通</option>
                            <option value="9">最低（不急，空了再跑）</option>
                        </select>
                    </div>
                </div>

                <button type="submit" id="submitBtn"
                    class="w-full rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50">
                    加入队列
                </button>
                <p id="formMsg" class="text-xs text-red-500"></p>
            </form>
        </section>

        <!-- ===== 右：实时队列 ===== -->
        <section class="luxury-glass flex min-h-[420px] flex-col p-5">
            <div class="mb-3 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-semibold text-slate-700">实时队列</h3>
                    <span id="liveDot" class="hidden items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-600">
                        <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>自动刷新中
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" id="clearBtn"
                        class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-500 transition hover:border-slate-300 hover:text-slate-700">
                        清空已结束
                    </button>
                    <button type="button" id="refreshBtn"
                        class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-500 transition hover:border-slate-300 hover:text-slate-700">
                        刷新
                    </button>
                </div>
            </div>

            <div id="jobList" class="flex-1 space-y-2 overflow-y-auto pr-1">
                <div class="flex h-40 flex-col items-center justify-center rounded-xl border border-dashed border-slate-200 text-center">
                    <p class="text-sm text-slate-400">正在读取队列…</p>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- 结果详情抽屉 -->
<div id="resultModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 px-4 backdrop-blur-sm">
    <div class="luxury-glass w-full max-w-2xl p-5">
        <div class="mb-3 flex items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="text-sm font-semibold text-slate-800" id="rmTitle">任务结果</div>
                <div class="mt-0.5 text-xs text-slate-400" id="rmMeta"></div>
            </div>
            <button type="button" onclick="closeResult()" class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div id="rmBody" class="max-h-[60vh] overflow-y-auto whitespace-pre-wrap rounded-lg border border-slate-200 bg-white p-4 text-sm leading-relaxed text-slate-700"></div>
        <div class="mt-4 flex justify-end gap-2">
            <button type="button" id="rmCopy" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50">复制结果</button>
            <button type="button" onclick="closeResult()" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600">关闭</button>
        </div>
    </div>
</div>

<style>
.stat-card{border-radius:12px;border:1px solid var(--surface-card-border,#e2e8f0);background:var(--surface-card,#fff);padding:14px 16px}
.stat-label{font-size:12px;color:#94a3b8;display:flex;align-items:center}
.stat-num{margin-top:4px;font-size:24px;font-weight:600;line-height:1.1}
.kind-btn.active{border-color:var(--color-brand-500,#6366f1)!important;background:var(--color-brand-50,#eef2ff)!important;color:var(--color-brand-700,#4338ca)!important}
.qrow{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:10px;border:1px solid var(--surface-card-border,#e2e8f0);background:var(--surface-card,#fff);transition:border-color .15s ease}
.qrow:hover{border-color:#cbd5e1}
.qrow.run{border-color:#c7d2fe;background:#f8f9ff}
.qrow.fail{border-color:#fecaca;background:#fffafa}
.qbar{position:relative;height:4px;border-radius:9999px;background:#eef2f7;overflow:hidden}
</style>

<script>
const KINDS = @json($kinds);
const CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
let timer = null;
let lastPayload = null;

/* ---- 任务类型切换 ---- */
function pickKind(k) {
    document.getElementById('kindInput').value = k;
    document.querySelectorAll('.kind-btn').forEach(b => b.classList.toggle('active', b.dataset.kind === k));
    document.getElementById('kindTip').textContent = KINDS[k] ? KINDS[k].tip : '';
    const ph = {text:'每行一条…', keyword:'每行一个行业或关键词…'}[KINDS[k] ? KINDS[k].input : 'text'];
    const ta = document.getElementById('itemsInput');
    if (!ta.value.trim()) ta.placeholder = ph;
}
document.querySelectorAll('.kind-btn').forEach(b => b.addEventListener('click', () => pickKind(b.dataset.kind)));
pickKind('hot');

/* ---- 行数统计 ---- */
document.getElementById('itemsInput').addEventListener('input', function () {
    const n = this.value.split(/\r\n|\r|\n/).filter(s => s.trim()).length;
    document.getElementById('lineCount').textContent = n;
});

/* ---- 状态徽章 ---- */
function badge(st) {
    const map = {
        queued:  ['bg-slate-100 text-slate-600', '待处理'],
        running: ['bg-brand-50 text-brand-700', '处理中'],
        done:    ['bg-emerald-50 text-emerald-700', '已完成'],
        failed:  ['bg-red-50 text-red-600', '失败'],
        canceled:['bg-slate-100 text-slate-400', '已取消'],
    };
    const m = map[st] || map.queued;
    return '<span class="rounded-full px-2 py-0.5 text-[11px] font-medium ' + m[0] + '">' + m[1] + '</span>';
}

/* ---- 渲染一行 ---- */
function rowHtml(j) {
    const cls = j.status === 'running' ? ' run' : (j.status === 'failed' ? ' fail' : '');
    const bar = j.status === 'running'
        ? '<div class="qbar mt-2 hgt-indet"><i></i></div>'
        : (j.status === 'queued' ? '<div class="qbar mt-2"></div>' : '');
    let acts = '';
    if (j.status === 'done') acts += '<button class="act-view rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-600 transition hover:border-brand-300 hover:text-brand-600" data-id="' + j.id + '">看结果</button>';
    if (j.status === 'failed') acts += '<button class="act-retry rounded-lg border border-red-200 bg-white px-2.5 py-1 text-xs font-medium text-red-600 transition hover:bg-red-50" data-id="' + j.id + '">重试</button>';
    if (j.status === 'queued' || j.status === 'running') acts += '<button class="act-cancel rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-500 transition hover:bg-slate-50" data-id="' + j.id + '">取消</button>';

    const right = j.status === 'done' && j.summary
        ? '<div class="truncate text-xs text-slate-400">' + esc(j.summary) + '</div>'
        : (j.error ? '<div class="truncate text-xs text-red-500">' + esc(j.error) + '</div>' : '');

    return '<div class="qrow' + cls + '" data-id="' + j.id + '">'
        + '<div class="min-w-0 flex-1">'
        +   '<div class="flex items-center gap-2">'
        +     '<span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-500">' + esc(j.kind_label) + '</span>'
        +     '<span class="truncate text-sm text-slate-700">' + esc(j.title || '未命名') + '</span>'
        +     badge(j.status)
        +   '</div>'
        +   '<div class="mt-1 flex items-center gap-3 text-xs text-slate-400">'
        +     '<span>第 ' + j.attempts + ' 次</span><span>耗时 ' + j.elapsed + 's</span>'
        +     '<span>' + (j.priority <= 1 ? '优先级 最高' : (j.priority >= 9 ? '优先级 最低' : '优先级 ' + j.priority_label)) + '</span>'
        +     (j.created_at ? '<span>' + j.created_at + '</span>' : '')
        +   '</div>'
        +   (right ? '<div class="mt-1">' + right + '</div>' : '')
        +   bar
        + '</div>'
        + '<div class="flex shrink-0 items-center gap-1.5">' + acts + '</div>'
        + '</div>';
}

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/* ---- 拉取并渲染 ---- */
function render(data) {
    lastPayload = data;
    document.getElementById('stQueued').textContent = data.stats.queued;
    document.getElementById('stRunning').textContent = data.stats.running;
    document.getElementById('stDone').textContent = data.stats.done;
    document.getElementById('stFailed').textContent = data.stats.failed;
    document.getElementById('stConc').textContent = data.stats.concurrency;

    const list = document.getElementById('jobList');
    if (!data.jobs.length) {
        list.innerHTML = '<div class="flex h-40 flex-col items-center justify-center rounded-xl border border-dashed border-slate-200 text-center">'
            + '<p class="text-sm text-slate-400">队列是空的</p>'
            + '<p class="mt-1 text-xs text-slate-300">左边扔一批进去，这里会实时跑给你看</p></div>';
    } else {
        list.innerHTML = data.jobs.map(rowHtml).join('');
    }

    const live = data.stats.running > 0 || data.stats.queued > 0;
    document.getElementById('liveDot').classList.toggle('hidden', !live);
    document.getElementById('liveDot').classList.toggle('inline-flex', live);
    schedule(live);
}

function schedule(live) {
    if (timer) { clearInterval(timer); timer = null; }
    if (live) timer = setInterval(refresh, 3000);
}

async function refresh() {
    try {
        const r = await fetch('/studio/queue/json', { headers: { 'Accept': 'application/json' } });
        if (!r.ok) return;
        render(await r.json());
    } catch (e) {}
}

/* ---- 提交 ---- */
document.getElementById('queueForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    const msg = document.getElementById('formMsg');
    msg.textContent = '';
    const items = document.getElementById('itemsInput').value;
    if (!items.trim()) { msg.textContent = '请先填内容，一行一条'; return; }

    btn.disabled = true; btn.textContent = '提交中…';
    try {
        const r = await fetch('/studio/queue/submit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({
                kind: document.getElementById('kindInput').value,
                items: items,
                priority: parseInt(document.getElementById('priorityInput').value, 10),
                industry: document.getElementById('industryInput').value,
            })
        });
        const d = await r.json();
        if (!r.ok || d.ok === false) { msg.textContent = d.error || '提交失败'; return; }
        document.getElementById('itemsInput').value = '';
        document.getElementById('lineCount').textContent = '0';
        hgtToast('success', '已加入队列 ' + d.count + ' 条，正在处理');
        refresh();
    } catch (err) {
        msg.textContent = '提交失败：' + err.message;
    } finally {
        btn.disabled = false; btn.textContent = '加入队列';
    }
});

/* ---- 行内操作（事件委托，列表会重绘）---- */
document.getElementById('jobList').addEventListener('click', async function (e) {
    const t = e.target.closest('button');
    if (!t) return;
    const id = t.dataset.id;
    if (t.classList.contains('act-retry')) {
        const r = await fetch('/studio/queue/' + id + '/retry', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF } });
        const d = await r.json();
        hgtToast(d.ok ? 'success' : 'error', d.ok ? '已重新排队' : (d.error || '重试失败'));
        refresh();
    } else if (t.classList.contains('act-cancel')) {
        const r = await fetch('/studio/queue/' + id + '/cancel', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF } });
        const d = await r.json();
        hgtToast(d.ok ? 'info' : 'error', d.ok ? '已取消' : (d.error || '取消失败'));
        refresh();
    } else if (t.classList.contains('act-view')) {
        const j = (lastPayload ? lastPayload.jobs : []).find(x => String(x.id) === String(id));
        if (j) showResult(j);
    }
});

/* ---- 结果抽屉 ---- */
function showResult(j) {
    document.getElementById('rmTitle').textContent = (j.kind_label || '') + ' · ' + (j.title || '');
    document.getElementById('rmMeta').textContent = j.status_label + ' · 耗时 ' + j.elapsed + 's · 第 ' + j.attempts + ' 次';
    let txt = '';
    const r = j.result || {};
    if (typeof r === 'string') txt = r;
    else if (r.raw) txt = r.raw;
    else if (r.text) txt = r.text;
    else if (r.result) txt = typeof r.result === 'string' ? r.result : JSON.stringify(r.result, null, 2);
    else if (r.summary) txt = r.summary;
    else txt = JSON.stringify(r, null, 2);
    document.getElementById('rmBody').textContent = txt || '（无结果内容）';
    const m = document.getElementById('resultModal');
    m.classList.remove('hidden'); m.classList.add('flex');
}
function closeResult() {
    const m = document.getElementById('resultModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}
document.getElementById('rmCopy').addEventListener('click', function () {
    const txt = document.getElementById('rmBody').textContent;
    navigator.clipboard.writeText(txt).then(() => hgtToast('success', '已复制'), () => hgtToast('error', '复制失败'));
});

/* ---- 清空 / 手动刷新 ---- */
document.getElementById('clearBtn').addEventListener('click', async function () {
    hgtConfirm({
        title: '清空已结束任务', message: '已完成、失败、已取消的记录会被清掉，进行中的不受影响。',
        danger: false, okText: '清空',
        onConfirm: async function () {
            const r = await fetch('/studio/queue/clear', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF } });
            const d = await r.json();
            hgtToast('success', '已清空 ' + (d.cleared || 0) + ' 条');
            refresh();
        }
    });
});
document.getElementById('refreshBtn').addEventListener('click', refresh);

/* ---- 首屏：立即拉一次全量快照，有活儿才持续轮询（空队列不打扰服务器）---- */
refresh();
</script>
</x-workspace-layout>
</x-app-layout>
