<x-app-layout>
<x-workspace-layout title="小红书图文笔记">
<div class="mx-auto max-w-6xl p-6">
    <div class="mb-5">
        <h1 class="text-xl font-bold text-slate-800">小红书图文笔记 · 分步生成</h1>
        <p class="mt-1 text-sm text-slate-500">第一步：填选题/卖点/受众 → 生成正文（可修改）；第二步：点击「生成图文」产出封面+内文配图。</p>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- ===== 左侧：输入区 ===== -->
        <div class="space-y-4">
            <section class="luxury-glass p-5">
                <label class="mb-1 block text-sm font-medium text-slate-700">选题（必填）</label>
                <input type="text" id="xhsTopic" placeholder="例：个人卡流水过大被查的概率有多高"
                    class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                    value="" />
            </section>

            <section class="luxury-glass p-5">
                <label class="mb-1 block text-sm font-medium text-slate-700">卖点 / 核心观点</label>
                <textarea id="xhsSelling" rows="3" placeholder="这个选题对受众的核心价值是什么？可多句描述。留空则由 AI 自动提炼。"
                    class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"></textarea>
            </section>

            <section class="luxury-glass p-5">
                <label class="mb-1 block text-sm font-medium text-slate-700">目标受众</label>
                <input type="text" id="xAudience" placeholder="例：中小企业老板 / 创业者 / 财务负责人"
                    class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                    value="中小企业老板 / 创业者" />
            </section>

            <section class="luxury-glass p-5 flex items-end gap-4">
                <div class="flex-1">
                    <label class="mb-1 block text-sm font-medium text-slate-700">内文页数（不含封面）</label>
                    <select id="xPages" class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400">
                        <option value="2">2 页（精简）</option>
                        <option value="4" selected>4 页（推荐）</option>
                        <option value="6">6 页（详细）</option>
                        <option value="8">8 页（最多）</option>
                    </select>
                </div>
                <button type="button" id="btnBuildNote" onclick="doBuildNote()"
                    class="rounded-lg bg-slate-700 px-5 py-3 text-sm font-semibold text-white shadow hover:bg-slate-800 transition-colors">
                    生成正文
                </button>
            </section>

            <!-- 正文编辑区 -->
            <section class="luxury-glass p-5">
                <div class="mb-2 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-slate-800">小红书正文（可编辑，也可直接粘贴爆款文案）</h3>
                    <span class="text-xs text-slate-400">修改后点「生成图文」即可重出图</span>
                </div>
                <textarea id="bodyText" rows="8" placeholder="先点「生成正文」由 AI 生成；或者直接粘贴一篇爆款文案到这里，再点「生成图文」。"
                    class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"></textarea>

                <button type="button" id="btnGenerate" onclick="doGenerate()"
                    class="mt-3 w-full rounded-lg bg-brand-600 px-7 py-3 text-base font-semibold text-white shadow hover:bg-brand-700 transition-colors">
                    生成图文
                </button>
            </section>

            <!-- 状态提示 -->
            <div id="genStatus" class="hidden rounded-lg border p-4 text-sm"></div>
        </div>

        <!-- ===== 右侧：预览区 ===== -->
        <div class="space-y-4">
            <!-- 候选标题 -->
            <section class="luxury-glass p-5 hidden" id="secTitles">
                <h3 class="mb-2 text-sm font-bold text-slate-800">候选标题（点击选用）</h3>
                <div id="titleList" class="flex flex-wrap gap-2"></div>
            </section>

            <!-- 图片预览 -->
            <section class="luxury-glass p-5 hidden" id="secImages">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-slate-800">图文预览（共 <span id="imgCount">0</span> 张，最多9张）</h3>
                    <button type="button" id="btnDownload" onclick="doDownload()"
                        class="rounded-md bg-brand-600 px-3 py-1.5 text-xs font-medium text-white shadow hover:bg-brand-700 transition-colors">下载全部图片</button>
                </div>
                <div id="imageGrid" class="grid grid-cols-2 gap-3"></div>
            </section>

            <!-- 发布按钮 -->
            <div class="hidden" id="pubArea">
                <button type="button" id="btnPublish" onclick="doPublish()"
                    class="w-full rounded-lg bg-red-500 px-7 py-3 text-base font-semibold text-white shadow hover:bg-red-600 transition-colors">
                    发布到小红书
                </button>
                <div id="pubStatus" class="mt-3 hidden rounded-lg border p-4 text-sm"></div>
            </div>

            <!-- 放大查看大图 -->
            <div id="bigOverlay" class="fixed inset-0 z-50 hidden bg-black/80 p-6"
                 onclick="closeBig()">
                <img id="bigImg" src="" alt="放大预览"
                     class="max-h-[90vh] max-w-[90vw] rounded-lg shadow-2xl" onclick="event.stopPropagation()" />
                <button type="button" onclick="closeBig()"
                        class="absolute right-4 top-4 rounded-full bg-white/90 px-3 py-1 text-sm font-medium text-slate-800 shadow hover:bg-white">关闭 ✕</button>
            </div>
        </div>
    </div>
</div>
</x-workspace-layout>
</x-app-layout>

<script>
function csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; }

let _note = null;   // 结构化笔记
let _images = [];   // base64 图列表
let _paths = [];    // 磁盘路径（发布用）
let _selectedTitle = '';

function setStatus(elId, msg, type) {
    const el = document.getElementById(elId);
    el.classList.remove('hidden', 'border-green-200', 'bg-green-50', 'text-green-700',
                         'border-amber-200', 'bg-amber-50', 'text-amber-700',
                         'border-red-200', 'bg-red-50', 'text-red-700');
    if (type === 'ok') el.classList.add('border-green-200','bg-green-50','text-green-700');
    else if (type === 'warn') el.classList.add('border-amber-200','bg-amber-50','text-amber-700');
    else el.classList.add('border-red-200','bg-red-50','text-red-700');
    el.textContent = msg;
    el.classList.remove('hidden');
}

function spinnerHtml(size='h-4 w-4') {
    return '<svg class="' + size + ' inline animate-spin align-middle" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
}

function setLoadingStatus(elId, msg) {
    const el = document.getElementById(elId);
    el.classList.remove('hidden', 'border-green-200', 'bg-green-50', 'text-green-700',
                         'border-amber-200', 'bg-amber-50', 'text-amber-700',
                         'border-red-200', 'bg-red-50', 'text-red-700');
    el.classList.add('border-amber-200','bg-amber-50','text-amber-700');
    el.innerHTML = spinnerHtml() + ' <span class="align-middle">' + msg + '</span>' +
        ' <button type="button" onclick="HGTAbort.abort()" class="ml-2 align-middle rounded border border-amber-300 px-1.5 py-0.5 text-xs hover:bg-amber-100">中止</button>';
    el.classList.remove('hidden');
}

// 从当前 textarea 同步 body 到 _note
function syncBodyToNote() {
    if (_note) {
        _note.body = document.getElementById('bodyText').value || _note.body || '';
    }
}

async function doBuildNote() {
    const topic = document.getElementById('xhsTopic').value.trim();
    if (!topic) { alert('请填写选题'); return; }

    const btn = document.getElementById('btnBuildNote');
    zwSetLoading(btn, { loading: true, text: '生成中…' });
    setLoadingStatus('genStatus', '正在调用 AI 生成正文…');
    const signal = HGTAbort.begin('中止：生成正文…');
    try {
        const resp = await fetch('/studio/xhs/build-note', {
            method: 'POST',
            signal,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({
                topic,
                selling_points: document.getElementById('xhsSelling').value.trim(),
                audience: document.getElementById('xAudience').value.trim(),
                pages: parseInt(document.getElementById('xPages').value),
            }),
        });
        const data = await resp.json();
        if (!resp.ok || !data.ok) throw new Error(data.error || '生成正文失败');

        _note = data.note;
        renderNotePreview(data.note);
        setStatus('genStatus', '✅ 正文已生成，可编辑后点「生成图文」', 'ok');
    } catch (e) {
        if (e.name === 'AbortError') { setStatus('genStatus', '⏹ 已中止', 'warn'); return; }
        setStatus('genStatus', '❌ 生成正文失败：' + e.message, 'err');
    } finally {
        HGTAbort.end();
        zwSetLoading(btn, { loading: false });
    }
}

async function doGenerate() {
    const topic = document.getElementById('xhsTopic').value.trim();
    if (!topic) { alert('请填写选题'); return; }

    const bodyText = document.getElementById('bodyText').value.trim();
    if (!bodyText) { alert('请先生成正文，或把爆款文案粘贴到正文框'); return; }

    syncBodyToNote();

    const btn = document.getElementById('btnGenerate');
    zwSetLoading(btn, { loading: true, text: '出图中…' });
    setLoadingStatus('genStatus', '正在渲染封面+内文配图（约 30~60 秒）…');
    const signal = HGTAbort.begin('中止：图文生成中…');
    try {
        const payload = {
            topic,
            selling_points: document.getElementById('xhsSelling').value.trim(),
            audience: document.getElementById('xAudience').value.trim(),
            pages: parseInt(document.getElementById('xPages').value),
            brand: '慧根堂 · 老张讲财税',
        };
        // 如果已有完整 note 结构，优先用它（保留用户在 textarea 的修改）
        if (_note && _note.cover && Array.isArray(_note.pages) && _note.pages.length > 0) {
            payload.note = _note;
            delete payload.topic; // 后端以 note 为准
        } else {
            // 否则把当前正文当 raw_body 交给后端整理再渲染（适合直接粘贴爆款文案）
            payload.raw_body = bodyText;
        }

        const resp = await fetch('/studio/xhs/generate', {
            method: 'POST',
            signal,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify(payload),
        });
        const data = await resp.json();
        if (!resp.ok || !data.ok) throw new Error(data.error || '生成失败');

        _note = data.note;
        _images = data.images || [];
        _paths = data.image_paths || [];
        renderImages(_images);
        document.getElementById('pubArea').classList.remove('hidden');
        setStatus('genStatus', `✅ 成功！已生成 ${_images.length} 张图`, 'ok');
    } catch (e) {
        if (e.name === 'AbortError') { setStatus('genStatus', '⏹ 已中止生成', 'warn'); return; }
        setStatus('genStatus', '❌ 生成图文失败：' + e.message, 'err');
    } finally {
        HGTAbort.end();
        zwSetLoading(btn, { loading: false });
    }
}

function renderNotePreview(note) {
    // 候选标题
    const tl = document.getElementById('titleList');
    tl.innerHTML = '';
    (note.titles || []).forEach((t, i) => {
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'px-3 py-1.5 rounded-full text-xs font-medium border border-brand-300 text-brand-700 hover:bg-brand-50 cursor-pointer transition-colors';
        b.textContent = t; b.onclick = () => { _selectedTitle = t; b.classList.replace('border-brand-300','border-brand-500'); b.classList.add('bg-brand-100'); };
        tl.appendChild(b);
    });
    document.getElementById('secTitles').classList.remove('hidden');

    // 正文
    document.getElementById('bodyText').value = note.body || '';
}

function renderImages(images) {
    const ig = document.getElementById('imageGrid');
    ig.innerHTML = '';
    images.forEach((src, i) => {
        const div = document.createElement('div');
        div.className = 'relative overflow-hidden rounded-lg border border-slate-200';
        const img = new Image(); img.src = src; img.className = 'w-full aspect-[3/4] object-cover cursor-pointer transition-opacity hover:opacity-90';
        img.onclick = () => openBig(src);
        div.appendChild(img);
        if (i === 0) {
            const cap = document.createElement('div');
            cap.className = 'absolute bottom-0 left-0 right-0 bg-black/60 px-2 py-1 text-white text-xs';
            cap.textContent = '封面';
            div.appendChild(cap);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.id = 'btnRegenCover';
            btn.textContent = '重新生成封面';
            btn.className = 'absolute right-2 top-2 rounded-md bg-white/90 px-2 py-1 text-xs font-medium text-brand-700 shadow hover:bg-white transition-colors';
            btn.onclick = doRegenCover;
            div.appendChild(btn);
        } else {
            const cap = document.createElement('div');
            cap.className = 'absolute bottom-0 left-0 right-0 bg-black/60 px-2 py-1 text-white text-xs';
            cap.textContent = `第${i}页`;
            div.appendChild(cap);
        }
        ig.appendChild(div);
    });
    document.getElementById('imgCount').textContent = String(images.length);
    document.getElementById('secImages').classList.remove('hidden');
}

async function doRegenCover() {
    if (!_note || !_note.cover) { alert('请先生成图文'); return; }
    const btn = document.getElementById('btnRegenCover');
    if (!btn) return;
    const old = btn.textContent;
    btn.disabled = true; btn.textContent = '生成中…';
    setLoadingStatus('genStatus', '正在重新生成封面（仅换背景，文字不变）…');
    const signal = HGTAbort.begin('中止：重新生成封面中…');
    try {
        const resp = await fetch('/studio/xhs/regen-cover', {
            method: 'POST',
            signal,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({
                cover: _note.cover,
                seed: Math.floor(Math.random() * 1000000),
                topic: document.getElementById('xhsTopic').value.trim(),
                selling_points: document.getElementById('xhsSelling').value.trim(),
                audience: document.getElementById('xAudience').value.trim(),
            }),
        });
        const data = await resp.json();
        if (!resp.ok || !data.ok) throw new Error(data.error || '重新生成封面失败');

        _images[0] = data.cover;
        if (data.cover_path) _paths[0] = data.cover_path;
        const firstDiv = document.getElementById('imageGrid').firstElementChild;
        const img = firstDiv ? firstDiv.querySelector('img') : null;
        if (img) img.src = data.cover;

        const mode = (data.seed !== undefined && data.cover_path && data.cover_path.indexOf('ai') >= 0)
            ? 'AI 插画' : '配色模板';
        setStatus('genStatus', `✅ 封面已重新生成（${mode}背景已更换，文字不变）`, 'ok');
    } catch (e) {
        if (e.name === 'AbortError') { setStatus('genStatus', '⏹ 已中止重新生成', 'warn'); return; }
        setStatus('genStatus', '❌ 重新生成封面失败：' + e.message, 'err');
    } finally {
        HGTAbort.end();
        btn.disabled = false; btn.textContent = old;
    }
}

async function doPublish() {
    if (!_paths.length) { alert('请先生成图文'); return; }
    const title = _selectedTitle || (_note?.cover?.title || '');
    const desc = document.getElementById('bodyText')?.value || _note?.body || '';

    const btn = document.getElementById('btnPublish');
    zwSetLoading(btn, { loading: true, text: '发布中…' });
    setStatus('pubStatus', '正在发布到小红书…', 'warn');
    const signal = HGTAbort.begin('中止：小红书发布中…');
    try {
        const resp = await fetch('/studio/xhs/publish', {
            method: 'POST',
            signal,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({
                image_paths: _paths,
                title,
                description: desc,
                tags: [],
            }),
        });
        const data = await resp.json();
        if (!resp.ok) throw new Error(data.error || '发布失败');

        const r = (data.results && data.results[0]) || data;
        if (r.status === 'published' && r.simulated) {
            setStatus('pubStatus', `🟡 模拟发布（未真实发出）：${r.error || '未配置小红书账号授权。图片已生成，可以下载后手动发；如需自动发布，请先在「平台账号」完成小红书 OAuth 授权。'}`, 'warn');
        } else if (r.status === 'published') {
            setStatus('pubStatus', `✅ 发布成功！笔记链接：${r.url || r.platform_post_id || ''}`, 'ok');
        } else if (r.status === 'failed') {
            setStatus('pubStatus', `❌ 发布失败：${r.error || JSON.stringify(r)}`, 'err');
        } else {
            setStatus('pubStatus', `📤 状态：${r.status} — ${r.url || r.post_id || ''}`, 'ok');
        }
    } catch (e) {
        if (e.name === 'AbortError') { setStatus('pubStatus', '⏹ 已中止发布', 'warn'); return; }
        setStatus('pubStatus', '❌ 发布异常：' + e.message, 'err');
    } finally {
        HGTAbort.end();
        zwSetLoading(btn, { loading: false });
    }
}

function openBig(src) {
    const ov = document.getElementById('bigOverlay');
    document.getElementById('bigImg').src = src;
    ov.classList.remove('hidden');
    ov.classList.add('flex', 'items-center', 'justify-center');
}
function closeBig() {
    const ov = document.getElementById('bigOverlay');
    ov.classList.add('hidden');
    ov.classList.remove('flex', 'items-center', 'justify-center');
}
async function doDownload() {
    if (!_images || !_images.length) { alert('请先生成图文'); return; }
    const btn = document.getElementById('btnDownload');
    if (!btn) return;
    const old = btn.textContent;
    btn.disabled = true; btn.textContent = '打包中…';
    try {
        const resp = await fetch('/studio/xhs/download', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ images: _images }),
        });
        if (!resp.ok) {
            const d = await resp.json().catch(() => ({}));
            throw new Error(d.error || ('下载失败 ' + resp.status));
        }
        const blob = await resp.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = 'xiaohongshu_' + Date.now() + '.zip';
        document.body.appendChild(a); a.click(); a.remove();
        URL.revokeObjectURL(url);
        setStatus('genStatus', '✅ 已打包下载（' + _images.length + ' 张 PNG）', 'ok');
    } catch (e) {
        setStatus('genStatus', '❌ 下载失败：' + e.message, 'err');
    } finally {
        btn.disabled = false; btn.textContent = old;
    }
}
</script>
