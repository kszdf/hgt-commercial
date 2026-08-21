<x-app-layout>
<x-workspace-layout title="内容矩阵">
<div class="mx-auto max-w-6xl p-6">

    {{-- 输入区 --}}
    <div class="luxury-glass p-5">
        <h3 class="mb-1 text-sm font-semibold text-slate-700">一个选题，三种形态</h3>
        <p class="mb-4 text-xs text-slate-400">输入一次选题与卖点，分别生成：视频口播稿 / 小红书图文 / 朋友圈文案。三个卡片独立生成、独立使用。</p>
        <div class="grid grid-cols-1 gap-3 text-sm md:grid-cols-4">
            <div class="md:col-span-2">
                <label class="mb-1 block text-slate-500">选题（如：金税四期下老板最容易踩的 3 个税务坑）</label>
                <input id="mxTopic" type="text" maxlength="200" placeholder="输入选题…"
                    class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
            </div>
            <div>
                <label class="mb-1 block text-slate-500">核心卖点（选填）</label>
                <input id="mxSelling" type="text" maxlength="1000" placeholder="如：免费税务风险检测"
                    class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
            </div>
            <div>
                <label class="mb-1 block text-slate-500">视频形式（视频稿用）</label>
                <select id="mxForm" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
                    <option value="avatar">单人数字人出镜</option>
                    <option value="scroll_male" selected>男声幕后音</option>
                    <option value="scroll_female">女声幕后音</option>
                    <option value="scroll_dual">男女对话幕后音</option>
                </select>
            </div>
            <div class="md:col-span-4">
                <label class="mb-1 block text-slate-500">目标受众（小红书图文用，选填）</label>
                <input id="mxAudience" type="text" maxlength="300" value="中小企业老板 / 创业者"
                    class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
            </div>
        </div>
    </div>

    {{-- 三卡结果 --}}
    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3">

        {{-- 卡1：视频口播稿 --}}
        <div class="luxury-glass p-5">
            <div class="mb-3 flex items-center justify-between">
                <h4 class="text-sm font-semibold text-slate-700">🎬 视频口播稿</h4>
                <button id="btnVideo" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-700">生成视频稿</button>
            </div>
            <div id="videoResult" class="text-xs text-slate-400">未生成。</div>
            <div id="videoActions" class="mt-3 hidden space-y-2">
                <button id="copyVideo" class="w-full rounded-lg border border-slate-200 bg-white py-1.5 text-xs text-slate-600 hover:bg-slate-50">复制清洗稿</button>
                <button id="goScroll" class="w-full rounded-lg bg-brand-600 py-1.5 text-xs font-medium text-white hover:bg-brand-700">带稿去出片 →</button>
            </div>
        </div>

        {{-- 卡2：小红书图文 --}}
        <div class="luxury-glass p-5">
            <div class="mb-3 flex items-center justify-between">
                <h4 class="text-sm font-semibold text-slate-700">📕 小红书图文</h4>
                <button id="btnXhs" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-700">生成图文</button>
            </div>
            <div id="xhsResult" class="text-xs text-slate-400">未生成（约 30–60 秒）。</div>
            <div id="xhsActions" class="mt-3 hidden space-y-2">
                <div id="xhsImages" class="grid max-h-64 grid-cols-3 gap-1 overflow-y-auto"></div>
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-[11px] text-amber-700">
                    图片已生成，请右键保存后在小红书 App 手动发布。
                </div>
            </div>
        </div>

        {{-- 卡3：朋友圈文案 --}}
        <div class="luxury-glass p-5">
            <div class="mb-3 flex items-center justify-between">
                <h4 class="text-sm font-semibold text-slate-700">💬 朋友圈文案</h4>
                <button id="btnMoment" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-700">生成文案</button>
            </div>
            <div id="momentResult" class="space-y-2 text-xs text-slate-400">未生成（3 版：悬念 / 数据 / 故事）。</div>
        </div>
    </div>

    <p id="matrixMsg" class="mt-4 text-sm font-medium text-red-600"></p>
</div>

@push('scripts')
<script>
function esc(s){ return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function csrf(){ return document.querySelector('meta[name="csrf-token"]')?.content || ''; }
function input(){ return {
    topic: document.getElementById('mxTopic').value.trim(),
    selling_points: document.getElementById('mxSelling').value.trim(),
    audience: document.getElementById('mxAudience').value.trim(),
    form: document.getElementById('mxForm').value,
}; }
function post(url, body){
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify(body),
    }).then(r => r.json());
}

// ---- 卡1 视频稿 ----
document.getElementById('btnVideo').addEventListener('click', async function () {
    const i = input();
    if (!i.topic) { document.getElementById('matrixMsg').textContent = '请先填写选题。'; return; }
    const btn = this; zwSetLoading(btn, {loading:true, text:'生成中…'});
    document.getElementById('matrixMsg').textContent = '';
    const signal = HGTAbort.begin('中止：生成视频稿中…');
    try {
        const d = await post('/studio/matrix/video', i);
        if (!d.ok) throw new Error(d.error || '生成失败');
        window.__matrixCleaned = d.cleaned;
        const hits = (d.hits||[]).map(h=>h.word).filter(Boolean);
        let html = esc(d.cleaned);
        (hits||[]).forEach(h => { const w = esc(h.word||''); if(w) html = html.split(w).join('<mark class="bg-red-100 text-red-700 rounded px-0.5">'+w+'</mark>'); });
        document.getElementById('videoResult').innerHTML =
            '<div class="mb-2 rounded-lg border border-slate-200 bg-white p-2.5"><div class="mb-1 text-[10px] text-slate-400">清洗稿（可配音）</div>' +
            '<div class="max-h-56 overflow-y-auto whitespace-pre-wrap leading-relaxed text-slate-700">' + html + '</div></div>' +
            (hits.length ? '<div class="rounded-lg bg-amber-50 px-2 py-1.5 text-amber-700">命中 '+hits.length+' 处违禁词，已在清洗稿中替换</div>' : '');
        document.getElementById('videoActions').classList.remove('hidden');
    } catch (err) {
        if (err.name === 'AbortError') { hgtToast('warn','已中止'); return; }
        document.getElementById('videoResult').textContent = '生成失败：' + (err.message||'未知错误');
    } finally { zwSetLoading(btn,{loading:false}); HGTAbort.end(); }
});
document.getElementById('copyVideo').addEventListener('click', async function () {
    try { await navigator.clipboard.writeText(window.__matrixCleaned||''); hgtToast('success','清洗稿已复制'); }
    catch(e) { hgtToast('error','复制失败，请手动选择复制'); }
});
document.getElementById('goScroll').addEventListener('click', function () {
    const i = input();
    if (window.__matrixCleaned) {
        sessionStorage.setItem('hgt_matrix_cleaned', window.__matrixCleaned);
        sessionStorage.setItem('hgt_matrix_mode', i.form);
    }
    location.href = '/studio/scroll?src=matrix&mode=' + i.form;
});

// ---- 卡2 小红书图文 ----
document.getElementById('btnXhs').addEventListener('click', async function () {
    const i = input();
    if (!i.topic) { document.getElementById('matrixMsg').textContent = '请先填写选题。'; return; }
    const btn = this; zwSetLoading(btn, {loading:true, text:'生成中（约30-60秒）…'});
    document.getElementById('matrixMsg').textContent = '';
    const signal = HGTAbort.begin('中止：生成图文笔记中…');
    try {
        const d = await post('/studio/matrix/xhs', i);
        if (!d.ok) throw new Error(d.error || '生成失败');
        window.__matrixXhs = d;
        let imgs = (d.images||[]).map(src => '<img src="'+src+'" class="w-full rounded border border-slate-100" alt="页">').join('');
        const titles = (d.note&&d.note.titles||[]).map(t=>'<span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600">'+esc(t)+'</span>').join(' ');
        document.getElementById('xhsResult').innerHTML =
            '<div class="mb-2 rounded-lg border border-slate-200 bg-white p-2.5"><div class="mb-1 text-[10px] text-slate-400">候选标题</div><div class="flex flex-wrap gap-1">' + titles + '</div>' +
            '<div class="mb-1 mt-2 text-[10px] text-slate-400">正文（'+(d.count||0)+' 张图）</div>' +
            '<div class="max-h-32 overflow-y-auto whitespace-pre-wrap text-slate-700">' + esc((d.note&&d.note.body)||'') + '</div></div>';
        document.getElementById('xhsImages').innerHTML = imgs;
        document.getElementById('xhsActions').classList.remove('hidden');
    } catch (err) {
        if (err.name === 'AbortError') { hgtToast('warn','已中止'); return; }
        document.getElementById('xhsResult').textContent = '生成失败：' + (err.message||'未知错误');
    } finally { zwSetLoading(btn,{loading:false}); HGTAbort.end(); }
});
// ---- 卡3 朋友圈文案 ----
document.getElementById('btnMoment').addEventListener('click', async function () {
    const i = input();
    if (!i.topic) { document.getElementById('matrixMsg').textContent = '请先填写选题。'; return; }
    const btn = this; zwSetLoading(btn, {loading:true, text:'生成中…'});
    document.getElementById('matrixMsg').textContent = '';
    const signal = HGTAbort.begin('中止：生成文案中…');
    try {
        const d = await post('/studio/matrix/moment', i);
        if (!d.ok) throw new Error(d.error || '生成失败');
        const items = d.items || [];
        document.getElementById('momentResult').innerHTML = items.length ? items.map((it, idx) =>
            '<div class="rounded-lg border border-slate-200 bg-white p-2.5">' +
            '<div class="mb-1 flex items-center justify-between"><span class="rounded bg-brand-50 px-1.5 py-0.5 text-[10px] font-medium text-brand-600">' + esc(it.type||('版本'+(idx+1))) + '</span>' +
            '<button type="button" class="moment-copy text-[10px] text-brand-600 hover:underline" data-idx="'+idx+'">复制</button></div>' +
            '<p class="whitespace-pre-wrap leading-relaxed text-slate-700">' + esc(it.text||'') + '</p>' +
            (it.reason ? '<p class="mt-1 text-[10px] text-slate-400">推荐理由：'+esc(it.reason)+'</p>' : '') +
            '</div>').join('')
            : '<div class="text-xs text-slate-400">无结果</div>';
        window.__moments = items;
        document.querySelectorAll('.moment-copy').forEach(b => b.addEventListener('click', async function(){
            const t = window.__moments[this.dataset.idx]?.text || '';
            try { await navigator.clipboard.writeText(t); hgtToast('success','文案已复制'); }
            catch(e) { hgtToast('error','复制失败'); }
        }));
    } catch (err) {
        if (err.name === 'AbortError') { hgtToast('warn','已中止'); return; }
        document.getElementById('momentResult').innerHTML = '生成失败：' + esc(err.message||'未知错误');
    } finally { zwSetLoading(btn,{loading:false}); HGTAbort.end(); }
});
</script>
@endpush
</x-workspace-layout>
</x-app-layout>
