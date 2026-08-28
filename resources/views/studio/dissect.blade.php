<x-app-layout>
<x-workspace-layout title="爆款拆解">
<div class="mx-auto max-w-6xl p-6">
    <div class="mb-5">
        <h1 class="text-xl font-bold text-slate-800">爆款拆解 · 结构复用</h1>
        <p class="mt-1 text-sm text-slate-500">把爆款短视频逐字稿拆解为「结构骨架 + 选题角度 + 运镜建议」，用于创作原创版本。</p>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- ===== 左侧：输入区 ===== -->
        <div class="space-y-4">
            <section class="luxury-glass p-5">
                <!-- 输入方式 Tab -->
                <div class="mb-4 flex gap-2" id="inputTabs">
                    <button type="button" data-mode="paste" class="dissect-tab dissect-tab-active">粘贴文案</button>
                    <button type="button" data-mode="upload" class="dissect-tab">上传视频</button>
                    <button type="button" data-mode="link" class="dissect-tab">粘贴链接</button>
                </div>

                <!-- 粘贴文案 -->
                <div id="panel-paste">
                    <label class="mb-1 block text-sm font-medium text-slate-700">爆款逐字稿 / 台词</label>
                    <textarea id="pasteText" rows="7" placeholder="把爆款视频的台词或逐字稿粘贴到这里（越完整，拆解越准）"
                        class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"></textarea>
                </div>

                <!-- 上传视频 -->
                <div id="panel-upload" class="hidden">
                    <label class="mb-1 block text-sm font-medium text-slate-700">上传爆款视频文件</label>
                    <input type="file" id="videoFile" accept="video/*"
                        class="block w-full text-sm text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100" />
                    <p class="mt-1 text-xs text-slate-400">系统自动转写音轨为文字再拆解。建议上传 1 分钟内的短视频，避免超时。</p>
                    <div class="mt-3">
                        <label class="mb-1 block text-sm font-medium text-slate-700">语言</label>
                        <select id="language" class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                            <option value="zh">中文</option>
                            <option value="auto">自动检测</option>
                            <option value="en">英文</option>
                        </select>
                    </div>
                </div>

                <!-- 粘贴链接 -->
                <div id="panel-link" class="hidden">
                    <label class="mb-1 block text-sm font-medium text-slate-700">视频分享链接</label>
                    <input type="text" id="videoUrl" placeholder="抖音 / 视频号 分享链接（二期支持，当前仅提示）"
                        class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100" />
                    <p class="mt-1 text-xs text-amber-500">链接直解析因平台反爬暂未开放，请改用「上传视频」或「粘贴文案」。</p>
                </div>

                <!-- 平台 / 行业 / 标题 / 呈现形式 -->
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">发布平台<span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500">选填</span></label>
                        <select id="platform" class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                            <option value="">不限</option>
                            <option value="抖音">抖音</option>
                            <option value="微信视频号">微信视频号</option>
                            <option value="小红书">小红书</option>
                            <option value="快手">快手</option>
                            <option value="通用">通用</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">行业领域<span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500">选填</span></label>
                        <select id="industry" class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                            <option value="">不限行业</option>
                            <option value="财税">财税 / 税务</option>
                            <option value="企业管理">企业管理咨询</option>
                            <option value="法律咨询">法律咨询</option>
                            <option value="企业服务">企业服务</option>
                            <option value="教育培训">教育培训</option>
                            <option value="电商带货">电商带货</option>
                            <option value="本地生活">本地生活</option>
                            <option value="互联网/科技">互联网 / 科技</option>
                        </select>
                    </div>
                </div>

                <div class="mt-3 grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">视频标题<span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500">选填</span></label>
                        <input type="text" id="title" maxlength="60" placeholder="原视频标题（用于潜力评估）"
                            class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">建议呈现形式<span class="ml-1 rounded bg-brand-50 px-1.5 py-0.5 text-[11px] font-normal text-brand-600">出片用</span></label>
                        <select id="form" class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                            <option value="">不限</option>
                            <option value="avatar">单人数字人出镜</option>
                            <option value="scroll_male">男声幕后音·动态画面</option>
                            <option value="scroll_female">女声幕后音·动态画面</option>
                            <option value="scroll_dual">男女对话幕后音·动态画面</option>
                        </select>
                    </div>
                </div>

                <button id="analyzeBtn" type="button" onclick="startDissect()"
                    class="zw-btn mt-5 w-full rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">
                    开始拆解
                </button>

                <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-700">
                    ⚠️ 合规边界：拆解仅用于学习「结构、节奏、选题角度」。人物、声音、原客户具体名称与隐私数据、平台水印必须替换，不得照搬他人视频画面或声音。
                </p>
            </section>
        </div>

        <!-- ===== 右侧：结果区 ===== -->
        <div class="space-y-4">
            <div id="resultEmpty" class="luxury-glass flex h-64 flex-col items-center justify-center p-5 text-center">
                <svg class="mb-3 h-10 w-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.428 15.428a8 8 0 11-11.314-11.314 8 8 0 0111.314 11.314z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 9l-6 6m0-6l6 6"/></svg>
                <p class="text-sm text-slate-400">拆解结果将显示在这里</p>
                <p class="mt-1 text-xs text-slate-300">粘贴文案或上传视频后，点击「开始拆解」</p>
            </div>
            <div id="resultLoading" class="luxury-glass hidden p-8 text-center">
                <span class="zw-spinner mr-2"></span><span class="text-sm text-slate-500">正在拆解结构，请稍候…</span>
            </div>
            <div id="resultArea" class="hidden space-y-4"></div>
        </div>
    </div>
</div>

<style>
.dissect-tab { padding: 0.4rem 0.9rem; border-radius: 0.6rem; font-size: 0.8rem; font-weight: 500; color: #64748b; background: #fff; border: 1px solid #e2e8f0; transition: all .15s ease; }
.dissect-tab:hover { border-color: rgba(15,23,42,.12); }
.dissect-tab-active { color: #fff; background: #e11d48; border-color: #e11d48; }
</style>

<script>
let currentDissect = null;   // 完整拆解结果
let currentText = '';        // 提取的文案

// ---------- Tab 切换 ----------
document.querySelectorAll('#inputTabs .dissect-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('#inputTabs .dissect-tab').forEach(b => b.classList.remove('dissect-tab-active'));
        btn.classList.add('dissect-tab-active');
        const mode = btn.dataset.mode;
        document.getElementById('panel-paste').classList.toggle('hidden', mode !== 'paste');
        document.getElementById('panel-upload').classList.toggle('hidden', mode !== 'upload');
        document.getElementById('panel-link').classList.toggle('hidden', mode !== 'link');
    });
});

// ---------- 主流程 ----------
async function startDissect() {
    const btn = document.getElementById('analyzeBtn');
    btn.disabled = true;
    btn.classList.add('zw-btn-loading');
    const old = btn.innerHTML;
    btn.innerHTML = '<span class="zw-spinner"></span> 拆解中…';

    const mode = document.querySelector('#inputTabs .dissect-tab-active').dataset.mode;
    let payload = {
        input_mode: mode,
        platform: document.getElementById('platform').value || null,
        industry: document.getElementById('industry').value || null,
        title: document.getElementById('title').value.trim() || null,
    };

    if (mode === 'paste') {
        payload.text = document.getElementById('pasteText').value.trim();
        if (!payload.text) { return fail('请先粘贴爆款逐字稿'); }
    } else if (mode === 'upload') {
        const f = document.getElementById('videoFile').files[0];
        if (!f) { return fail('请先选择视频文件'); }
        if (f.size > 60 * 1024 * 1024) { return fail('视频过大（建议 ≤ 60MB）'); }
        try {
            payload.video_b64 = await fileToBase64(f);
            payload.language = document.getElementById('language').value;
        } catch (e) { return fail('视频读取失败：' + e.message); }
    } else {
        const url = document.getElementById('videoUrl').value.trim();
        if (!url) { return fail('请先粘贴视频链接（二期开放）'); }
        payload.video_url = url;
    }

    const signal = HGTAbort.begin('中止：爆款拆解中…');
    try {
        const resp = await fetch('/studio/dissect/analyze', {
            method: 'POST',
            signal,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrf() },
            body: JSON.stringify(payload),
        });
        const data = await resp.json();
        if (!resp.ok || data.error) { return fail(data.error || '拆解失败'); }
        currentDissect = data.dissect || {};
        currentText = data.text || '';
        renderResult(data);
    } catch (e) {
        if (e && e.name === 'AbortError') {
            hgtToast('warn', '已中止拆解');
            return;
        }
        fail('网络错误：' + e.message);
    } finally {
        btn.disabled = false;
        btn.classList.remove('zw-btn-loading');
        btn.innerHTML = old;
        HGTAbort.end();
    }
}

function fail(msg) {
    const btn = document.getElementById('analyzeBtn');
    btn.disabled = false; btn.classList.remove('zw-btn-loading'); btn.innerHTML = '开始拆解';
    alert(msg);
}

function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const r = new FileReader();
        r.onload = () => {
            const b64 = r.result.split(',')[1] || '';
            resolve(b64);
        };
        r.onerror = () => reject(new Error('读取失败'));
        r.readAsDataURL(file);
    });
}

function getCsrf() {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.content : '';
}

// ---------- 结果渲染 ----------
function renderResult(data) {
    document.getElementById('resultEmpty').classList.add('hidden');
    document.getElementById('resultLoading').classList.add('hidden');
    const area = document.getElementById('resultArea');
    area.classList.remove('hidden');

    const d = data.dissect || {};
    const s = data.strategist || {};
    let html = '';

    // 潜力评分角标
    if (s && s.potential_score != null) {
        const score = s.potential_score;
        const color = score >= 80 ? 'bg-emerald-500' : (score >= 65 ? 'bg-amber-500' : 'bg-slate-400');
        html += '<div class="luxury-glass flex items-center gap-3 p-4">'
            + '<div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full ' + color + ' text-lg font-bold text-white">' + score + '</div>'
            + '<div><p class="text-sm font-semibold text-slate-700">爆款潜力评分 · ' + (s.level || '') + '</p>'
            + '<p class="mt-0.5 text-xs text-slate-500">' + esc(s.industry_fit || '基于行业适配度评估') + '</p></div></div>';
    }

    // 开头钩子 + 痛点 + 案例
    html += card('拆解要点', `
        ${field('开头钩子类型', d.hook_type)}
        ${listBlock('命中的痛点', d.pain_points)}
        ${listBlock('案例 / 数据证据', d.case_evidence)}
    `);

    // 情绪节奏
    if (Array.isArray(d.emotion_rhythm) && d.emotion_rhythm.length) {
        html += card('情绪节奏', d.emotion_rhythm.map(e =>
            `<div class="flex gap-2 text-sm"><span class="shrink-0 rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">${e.sec ?? 0}s</span><span class="text-slate-700"><b>${esc(e.emotion||'')}</b> · ${esc(e.note||'')}</span></div>`
        ).join(''));
    }

    // 可复刻骨架时间轴
    if (Array.isArray(d.structure) && d.structure.length) {
        html += card('同款运镜骨架（仅作参考，不承诺动作 1:1）',
            '<div class="space-y-2">' + d.structure.map(seg => `
                <div class="rounded-lg border border-slate-200 p-2.5">
                    <div class="flex items-center gap-2">
                        <span class="shrink-0 rounded bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700">${seg.sec ?? 0}s</span>
                        <span class="text-sm font-medium text-slate-700">${esc(seg.content || '')}</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">情绪：${esc(seg.emotion||'—')} ｜ 运镜建议：${esc(seg.camera_hint||'—')}</p>
                </div>`).join('') + '</div>'
        );
    }

    // 必须替换 / 可复刻 / 二创建议
    html += card('合规拆分', `
        ${listBlock('✅ 可学习复用', d.reusable_parts, 'text-emerald-700')}
        ${listBlock('⛔ 必须替换（人物/声音/隐私/水印）', d.must_replace, 'text-rose-700')}
        ${listBlock('✍️ 二创方向建议', d.rewrite_suggestions, 'text-brand-700')}
    `);

    // 操作按钮
    html += '<div class="luxury-glass flex flex-wrap gap-2 p-4">'
        + '<button onclick="goRewrite()" class="zw-btn rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">去二创</button>'
        + '<button onclick="goScroll()" class="zw-btn rounded-lg bg-slate-700 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">直接出片</button>'
        + '<button onclick="goScrollWith(\'scroll_female\',\'jiang\')" class="zw-btn rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700">江老师·女声出片</button>'
        + '</div>';

    area.innerHTML = html;
}

function card(title, body) {
    return `<section class="luxury-glass p-4"><h3 class="mb-3 text-sm font-semibold text-slate-800">${title}</h3>${body}</section>`;
}
function field(label, val) {
    if (!val) return '';
    return `<p class="text-sm text-slate-700"><span class="text-slate-400">${label}：</span>${esc(val)}</p>`;
}
function listBlock(label, arr, color) {
    if (!Array.isArray(arr) || !arr.length) return '';
    const cls = color || 'text-slate-700';
    return `<div class="mt-2"><p class="mb-1 text-xs font-medium text-slate-400">${label}</p><ul class="space-y-1">`
        + arr.map(x => `<li class="text-sm ${cls}">· ${esc(x)}</li>`).join('') + '</ul></div>';
}
function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
}

// ---------- 去二创 ----------
function goRewrite() {
    if (!currentDissect) return;
    const title = (document.getElementById('title').value.trim()) || currentDissect.hook_type || '爆款拆解二创';
    const form = document.getElementById('form').value || 'avatar';
    const hook = Array.isArray(currentDissect.rewrite_suggestions) ? currentDissect.rewrite_suggestions.join('；') : '';
    const angle = (currentDissect.hook_type || '') + (Array.isArray(currentDissect.pain_points) ? '；' + currentDissect.pain_points.join('；') : '');
    sessionStorage.setItem('hgt_dissect_title', title);
    sessionStorage.setItem('hgt_dissect_text', currentText);
    sessionStorage.setItem('hgt_dissect_hook', hook);
    sessionStorage.setItem('hgt_dissect_form', form);
    sessionStorage.setItem('hgt_dissect_angle', angle);
    location.href = '/studio/rewrite?from=dissect';
}

// ---------- 联动：直接出片 ----------
function goScroll() {
    // 默认出口：按用户所选呈现形式 + 默认声线（女声幕后用江老师，其余用老张）
    goScrollWith(null, null);
}
function goScrollWith(forceForm, forceVoice) {
    if (!currentText) return;
    sessionStorage.setItem('hgt_dissect_text', currentText);
    const form = forceForm || (document.getElementById('form').value || 'avatar');
    let voice = forceVoice;
    if (!voice) voice = (form === 'scroll_female') ? 'jiang' : 'zhang';
    location.href = '/studio/scroll?src=dissect&mode=' + form + '&voice=' + voice;
}
</script>
</x-workspace-layout>
</x-app-layout>

