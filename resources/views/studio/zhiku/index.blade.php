<x-app-layout>
<x-workspace-layout title="智库" :breadcrumbs="[['label' => '工作台总览', 'url' => '/dashboard'], ['label' => '智库']]">
<div class="mx-auto max-w-6xl p-6">
    <header class="mb-5">
        <h1 class="text-xl font-bold text-slate-800">智库</h1>
        <p class="mt-1 text-sm text-slate-500">面向财税同行的 AI 咨询入口：把遇到的实务问题直接抛给 AI，先拿一份可参考的初答，再决定要不要走人工深度咨询。{{ $brand ? '品牌：' . $brand : '' }}</p>
    </header>

    @include('components.flash')

    {{-- 合规免责声明：AI 初答不构成正式税务意见，必须常驻可见，不可折叠隐藏 --}}
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-800">
        <strong>免责声明：</strong>本页回答由 AI 自动生成，属于<strong>初答参考</strong>，不构成正式税务意见、审计意见或法律意见，也不替代执业税务师/注册会计师的专业判断。涉税金额较大、涉及稽查/处罚/历史补缴等事项，请以主管税务机关口径和专业机构出具的正式意见为准。
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- 左栏：提问区 --}}
        <section class="luxury-glass p-5">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">向智库提问</h2>

            <label for="zkTopic" class="mb-1 block text-xs font-medium text-slate-600">你的问题 <span class="text-red-500">*</span></label>
            <textarea id="zkTopic" rows="6" maxlength="200"
                placeholder="例：个人卡收货款被稽查了怎么办"
                class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"></textarea>
            <p class="mt-1 text-xs text-slate-400">问题描述得越具体（时间、金额、主体性质、是否已收到通知），初答越可用。</p>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="zkIndustry" class="mb-1 block text-xs font-medium text-slate-600">所在行业（选填）</label>
                    <input id="zkIndustry" type="text" maxlength="100" placeholder="例：建筑施工 / 电商零售 / 餐饮"
                        class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                </div>
                <div>
                    <label for="zkUrgency" class="mb-1 block text-xs font-medium text-slate-600">紧急程度（选填）</label>
                    <select id="zkUrgency"
                        class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option value="">不指定</option>
                        <option value="一般">一般</option>
                        <option value="较急">较急</option>
                        <option value="紧急">紧急</option>
                    </select>
                </div>
            </div>

            <div class="mt-4 flex items-center gap-2">
                <button id="zkSubmit" type="button" onclick="zkAsk()"
                    class="rounded-lg bg-brand-600 px-5 py-2 text-sm font-medium text-white shadow hover:bg-brand-700 disabled:cursor-not-allowed disabled:bg-slate-300 transition-colors">
                    提交提问
                </button>
                <span class="text-xs text-slate-400">AI 检索分析通常需要十几秒到一分钟</span>
            </div>

            {{-- 本次会话的提问历史（纯前端数组，不落库，刷新即清空） --}}
            <div class="mt-5 border-t border-slate-200/70 pt-4">
                <div class="mb-2 flex items-center justify-between">
                    <h3 class="text-xs font-semibold text-slate-600">本次会话提问历史</h3>
                    <button type="button" onclick="zkClearHistory()" class="text-xs text-slate-400 hover:text-red-500">清空</button>
                </div>
                <ul id="zkHistory" class="space-y-1.5 text-xs">
                    <li class="text-slate-400">暂无记录</li>
                </ul>
            </div>
        </section>

        {{-- 右栏：AI 初答 --}}
        <section class="luxury-glass p-5">
            <div class="mb-3 flex items-start justify-between gap-3">
                <h2 class="text-sm font-semibold text-slate-700">AI 初答</h2>
                <span id="zkMeta" class="text-xs text-slate-400"></span>
            </div>

            <div id="zkPlaceholder" class="rounded-lg border border-dashed border-slate-200 px-4 py-10 text-center text-sm text-slate-400">
                在左侧输入问题后提交，这里会显示 AI 初答
            </div>

            <div id="zkLoading" class="hidden rounded-lg border border-brand-100 bg-brand-50/60 px-4 py-10 text-center text-sm text-brand-700">
                <span class="inline-flex items-center gap-2">
                    <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    AI 正在检索分析…
                </span>
            </div>

            <div id="zkError" class="hidden rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm leading-6 text-red-700"></div>

            <div id="zkResult" class="hidden">
                {{-- answer 是纯文本（含换行），用 whitespace-pre-wrap 保留排版 --}}
                <article id="zkAnswer" class="whitespace-pre-wrap text-sm leading-7 text-slate-700"></article>

                {{-- 8500 回传的 disclaimer 必须显式展示，不能藏起来 --}}
                <div id="zkDisclaimerWrap" class="mt-4 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs leading-6 text-slate-500">
                    <span class="font-medium text-slate-600">提示：</span><span id="zkDisclaimer"></span>
                </div>
            </div>
        </section>
    </div>
</div>

{{-- 全局操作提示 --}}
<div id="zkToast" class="fixed bottom-6 left-1/2 z-50 hidden -translate-x-1/2 rounded-lg px-4 py-2.5 text-sm shadow-lg"></div>
</x-workspace-layout>
</x-app-layout>

<script>
function zkCsrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; }

function zkToast(msg, type) {
    const el = document.getElementById('zkToast');
    el.className = 'fixed bottom-6 left-1/2 z-50 -translate-x-1/2 rounded-lg px-4 py-2.5 text-sm shadow-lg ' +
        (type === 'ok' ? 'bg-green-600 text-white'
            : type === 'warn' ? 'bg-amber-500 text-white'
            : 'bg-red-600 text-white');
    el.textContent = msg;
    el.classList.remove('hidden');
    clearTimeout(window._zkToastTimer);
    window._zkToastTimer = setTimeout(() => el.classList.add('hidden'), 4000);
}

// 提问历史：仅本次会话内存，最多保留 10 条（不落库，刷新即失效）
var zkHistory = [];
var ZK_HISTORY_MAX = 10;

function zkRenderHistory() {
    const ul = document.getElementById('zkHistory');
    ul.innerHTML = '';
    if (zkHistory.length === 0) {
        ul.innerHTML = '<li class="text-slate-400">暂无记录</li>';
        return;
    }
    // 最新在前
    zkHistory.slice().reverse().forEach(function (item) {
        const li = document.createElement('li');
        li.className = 'flex items-start gap-2';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'truncate text-left text-slate-600 hover:text-brand-600 hover:underline';
        btn.title = item.topic;
        btn.textContent = '· ' + item.topic;
        btn.onclick = function () { zkShow(item); };
        li.appendChild(btn);
        ul.appendChild(li);
    });
}

function zkClearHistory() {
    zkHistory = [];
    zkRenderHistory();
}

function zkPushHistory(item) {
    // 同一问题重复提交时移到队尾，避免历史里堆重复项
    zkHistory = zkHistory.filter(function (h) { return h.topic !== item.topic; });
    zkHistory.push(item);
    if (zkHistory.length > ZK_HISTORY_MAX) zkHistory.shift();
    zkRenderHistory();
}

function zkShow(item) {
    document.getElementById('zkPlaceholder').classList.add('hidden');
    document.getElementById('zkError').classList.add('hidden');
    document.getElementById('zkResult').classList.remove('hidden');
    document.getElementById('zkAnswer').textContent = item.answer || '';
    document.getElementById('zkDisclaimer').textContent = item.disclaimer || 'AI 初答仅供参考，涉税决策请以专业意见为准';
    document.getElementById('zkDisclaimerWrap').classList.remove('hidden');
    const meta = [];
    if (item.industry) meta.push('行业：' + item.industry);
    if (item.urgency) meta.push('紧急程度：' + item.urgency);
    document.getElementById('zkMeta').textContent = meta.join(' · ');
}

function zkSetLoading(on) {
    const btn = document.getElementById('zkSubmit');
    btn.disabled = on;
    btn.textContent = on ? 'AI 正在检索分析…' : '提交提问';
    document.getElementById('zkLoading').classList.toggle('hidden', !on);
}

async function zkAsk() {
    const topic = document.getElementById('zkTopic').value.trim();
    const industry = document.getElementById('zkIndustry').value.trim();
    const urgency = document.getElementById('zkUrgency').value;

    if (!topic) {
        zkToast('请先填写你的问题', 'warn');
        document.getElementById('zkTopic').focus();
        return;
    }

    zkSetLoading(true);
    document.getElementById('zkError').classList.add('hidden');
    document.getElementById('zkResult').classList.add('hidden');
    document.getElementById('zkPlaceholder').classList.add('hidden');

    try {
        const resp = await fetch('{{ route('studio.zhiku.ask') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': zkCsrf(), 'Accept': 'application/json' },
            body: JSON.stringify({ topic: topic, industry: industry, urgency: urgency }),
        });
        // 422 校验失败等场景 body 结构不同，统一尽量取出可读文案
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok || data.ok === false) {
            throw new Error(data.error || data.message || ('提问失败（HTTP ' + resp.status + '）'));
        }

        const item = {
            topic: data.topic || topic,
            industry: data.industry || industry,
            urgency: data.urgency || urgency,
            answer: data.answer || '',
            disclaimer: data.disclaimer || '',
        };
        zkShow(item);
        zkPushHistory(item);
    } catch (e) {
        const box = document.getElementById('zkError');
        box.textContent = '❌ ' + (e.message || '提问失败') + ' 若长时间无响应，请确认 8500 服务已运行。';
        box.classList.remove('hidden');
    } finally {
        zkSetLoading(false);
    }
}

// 支持 Ctrl/Cmd + Enter 快捷提交
document.getElementById('zkTopic').addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        zkAsk();
    }
});
</script>
