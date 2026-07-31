<x-app-layout>
<div class="mx-auto max-w-5xl p-6">
    <header class="mb-6">
        <h2 class="text-2xl font-semibold">视频出片工作台</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            输入男女对话稿，一键生成短视频。已接入真实 CosyVoice 克隆配音（张老师 / 江老师音色）。
        </p>
    </header>

    <!-- 模式切换 -->
    <div class="mb-5 flex gap-2">
        <button type="button" id="modeScroll"
            class="rounded-xl px-4 py-2 text-sm font-medium transition border border-brand-500 bg-brand-500/20 text-brand-200"
            onclick="setMode('scroll')">滚动字幕卡（不出镜）</button>
        <button type="button" id="modeAvatar"
            class="rounded-xl px-4 py-2 text-sm font-medium transition border border-white/15 text-slate-400 hover:text-slate-200"
            onclick="setMode('avatar')">数字人出镜（本地 HEYGEM）</button>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- 输入区 -->
        <section class="luxury-glass rounded-2xl p-5">
            <form id="genForm" class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-600 dark:text-slate-300">对话稿（每行 <span class="font-mono">女：</span> / <span class="font-mono">男：</span> 开头）</label>
                    <textarea id="dialogue" name="dialogue" rows="12" required
                        class="w-full rounded-xl border border-white/15 bg-black/20 p-3 font-mono text-sm text-slate-100 outline-none focus:border-brand-500"
                        placeholder="女：老板们注意了，暂估成本这个坑千万别踩。&#10;男：那要是年底还没票，税务局怎么看？&#10;女：轻则纳税调整，重则认定虚列成本。&#10;男：那正确做法是什么？&#10;女：能票的走票，不能票的走合同和流水，别硬估。">女：老板们注意了，暂估成本这个坑千万别踩。
男：那要是年底还没票，税务局怎么看？
女：轻则纳税调整，重则认定虚列成本。
男：那正确做法是什么？
女：能票的走票，不能票的走合同和流水，别硬估。</textarea>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-600 dark:text-slate-300">标题（≤10字）</label>
                        <input id="title" name="title" value="暂估成本避坑" maxlength="10"
                            class="w-full rounded-xl border border-white/15 bg-black/20 p-2.5 text-sm text-slate-100 outline-none focus:border-brand-500">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-600 dark:text-slate-300">副标题</label>
                        <input id="subtitle" name="subtitle" value="建筑财税·老张讲财税" maxlength="40"
                            class="w-full rounded-xl border border-white/15 bg-black/20 p-2.5 text-sm text-slate-100 outline-none focus:border-brand-500">
                    </div>
                </div>
                <p id="quotaHint" class="text-xs text-slate-500"></p>
                <button type="submit" id="genBtn"
                    class="w-full rounded-xl bg-brand-600 px-4 py-3 font-medium text-white transition hover:bg-brand-500 disabled:opacity-50">
                    生成视频
                </button>
                <p id="formMsg" class="text-sm text-red-300"></p>
            </form>
        </section>

        <!-- 结果区 -->
        <section class="luxury-glass rounded-2xl p-5">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-base font-semibold">出片状态</h3>
                <span id="statusBadge" class="rounded-full bg-white/10 px-3 py-1 text-xs text-slate-300">待生成</span>
            </div>
            <div id="result" class="flex min-h-[320px] items-center justify-center rounded-xl bg-black/30 text-sm text-slate-400">
                生成后的视频将显示在这里
            </div>
            <div id="errorBox" class="mt-3 hidden rounded-xl border border-red-400/40 bg-red-500/10 px-3 py-2 text-sm text-red-300"></div>
        </section>
    </div>
</div>

<script>
let currentMode = 'scroll';
function setMode(m) {
    currentMode = m;
    const s = document.getElementById('modeScroll');
    const a = document.getElementById('modeAvatar');
    const on = 'rounded-xl px-4 py-2 text-sm font-medium transition border border-brand-500 bg-brand-500/20 text-brand-200';
    const off = 'rounded-xl px-4 py-2 text-sm font-medium transition border border-white/15 text-slate-400 hover:text-slate-200';
    s.className = m === 'scroll' ? on : off;
    a.className = m === 'avatar' ? on : off;
}

document.getElementById('genForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('genBtn');
    const msg = document.getElementById('formMsg');
    const badge = document.getElementById('statusBadge');
    const result = document.getElementById('result');
    const errBox = document.getElementById('errorBox');
    msg.textContent = ''; errBox.classList.add('hidden');

    btn.disabled = true; btn.textContent = '提交中…';
    badge.textContent = '排队中'; badge.className = 'rounded-full bg-amber-500/20 px-3 py-1 text-xs text-amber-300';

    try {
        const resp = await fetch('/studio/scroll/generate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({
                mode: currentMode,
                dialogue: document.getElementById('dialogue').value,
                title: document.getElementById('title').value,
                subtitle: document.getElementById('subtitle').value,
                dry_tts: false
            })
        });
        const data = await resp.json();
        if (!resp.ok) {
            throw new Error(data.error || '提交失败');
        }
        if (data.quota != null) {
            document.getElementById('quotaHint').textContent =
                '本月用量 ' + data.usage + ' / ' + (data.quota === 0 ? '不限' : data.quota);
        }
        pollStatus(data.job_id);
    } catch (err) {
        btn.disabled = false; btn.textContent = '生成视频';
        badge.textContent = '失败'; badge.className = 'rounded-full bg-red-500/20 px-3 py-1 text-xs text-red-300';
        errBox.textContent = err.message; errBox.classList.remove('hidden');
    }
});

async function pollStatus(jobId) {
    const badge = document.getElementById('statusBadge');
    const btn = document.getElementById('genBtn');
    const result = document.getElementById('result');
    for (let i = 0; i < 180; i++) {
        await new Promise(r => setTimeout(r, 2000));
        try {
            const resp = await fetch('/studio/scroll/status/' + jobId);
            const data = await resp.json();
            if (data.status === 'done') {
                badge.textContent = '完成'; badge.className = 'rounded-full bg-green-500/20 px-3 py-1 text-xs text-green-300';
                result.innerHTML = '<video src="/studio/scroll/download/' + jobId + '" controls class="max-h-[60vh] w-full rounded-xl"></video>';
                btn.disabled = false; btn.textContent = '生成视频';
                return;
            } else if (data.status === 'failed') {
                badge.textContent = '失败'; badge.className = 'rounded-full bg-red-500/20 px-3 py-1 text-xs text-red-300';
                const eb = document.getElementById('errorBox');
                eb.textContent = '出片失败：' + (data.error || '未知错误'); eb.classList.remove('hidden');
                btn.disabled = false; btn.textContent = '生成视频';
                return;
            } else {
                badge.textContent = '出片中 ' + (i * 2) + 's'; badge.className = 'rounded-full bg-brand-500/20 px-3 py-1 text-xs text-brand-300';
            }
        } catch (e) { /* keep polling */ }
    }
    badge.textContent = '超时'; badge.className = 'rounded-full bg-red-500/20 px-3 py-1 text-xs text-red-300';
    btn.disabled = false; btn.textContent = '生成视频';
}
</script>
</x-app-layout>
