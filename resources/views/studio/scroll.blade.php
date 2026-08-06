<x-app-layout>
<x-workspace-layout title="视频出片工作台">
<div class="mx-auto max-w-5xl p-6">

    <!-- 模式切换 -->
    <div class="mb-4 flex gap-2">
        <button type="button" id="modeScroll"
            class="rounded-lg px-4 py-2 text-sm font-medium border border-brand-500 bg-brand-50 text-brand-700"
            onclick="setMode('scroll')">滚动字幕卡（不出镜）</button>
        <button type="button" id="modeAvatar"
            class="rounded-lg px-4 py-2 text-sm font-medium border border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:text-slate-700"
            onclick="setMode('avatar')">数字人出镜（本地 HEYGEM）</button>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- 输入区 -->
        <section class="luxury-glass p-5">
            <form id="genForm" class="space-y-4">
                <div>
                    <label class="mb-1 flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-700" id="dialogueLabel">
                            文稿内容
                            <span class="ml-1 rounded bg-red-50 px-1.5 py-0.5 text-[11px] font-medium text-red-600">必填</span>
                        </span>
                        <span id="formatHint" class="text-[11px] font-normal"></span>
                    </label>
                    <textarea id="dialogue" name="dialogue" rows="11" required
                        class="w-full rounded-lg border border-slate-200 bg-white p-3 font-mono text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                        placeholder="女：很多人做短视频，开头三秒就决定了完播率。&#10;男：那怎么把开头写好？&#10;女：先抛痛点，再给钩子，别上来就自我介绍。&#10;男：能举个例子吗？&#10;女：比如「你是不是也遇到过这种情况……」">女：很多人做短视频，开头三秒就决定了完播率。
男：那怎么把开头写好？
女：先抛痛点，再给钩子，别上来就自我介绍。
男：能举个例子吗？
女：比如「你是不是也遇到过这种情况……」</textarea>
                    <p id="formatWarning" class="mt-1 hidden rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs text-amber-700"></p>
                </div>

                <!-- 数字人场景选择（仅avatar模式显示） -->
                <div id="sceneSelectWrap" class="hidden rounded-lg border border-slate-200 bg-slate-50/80 p-3.5">
                    <label class="mb-1.5 block text-sm font-medium text-slate-600">出镜场景</label>
                    <select id="scene" name="scene" class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option value="office_a">办公桌前·正面（推荐）</option>
                        <option value="office_b">办公桌前·侧面</option>
                    </select>
                    <p class="mt-1.5 text-xs text-slate-400">数字人将合成到所选场景视频中。滚动字幕卡模式无需选择。</p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 flex items-center justify-between">
                            <span class="text-sm font-medium text-slate-700">
                                标题（≤10字）
                                <span id="titleAutoTag" class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500">选填</span>
                            </span>
                        </label>
                        <input id="title" name="title" maxlength="30"
                            class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <span class="text-[11px] text-slate-400">标题风格</span>
                            <button type="button" data-style="smart" class="title-style-btn rounded-md border border-brand-300 bg-brand-50 px-2 py-0.5 text-[11px] text-brand-600">智能提取</button>
                            <button type="button" data-style="full" class="title-style-btn rounded-md border border-slate-200 bg-white px-2 py-0.5 text-[11px] text-slate-500">首句完整</button>
                            <button type="button" data-style="suspense" class="title-style-btn rounded-md border border-slate-200 bg-white px-2 py-0.5 text-[11px] text-slate-500">悬念式</button>
                        </div>
                        <p id="titleHint" class="mt-1 hidden text-[11px] text-slate-400"></p>
                    </div>
                    <div>
                        <label class="mb-1 flex items-center justify-between">
                            <span class="text-sm font-medium text-slate-700">
                                副标题
                                <span id="subtitleAutoTag" class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500">选填</span>
                            </span>
                        </label>
                        <input id="subtitle" name="subtitle" maxlength="40"
                            class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <p id="subtitleHint" class="mt-1 hidden text-[11px] text-slate-400"></p>
                    </div>
                </div>

                <!-- 模特选择（按出片模式条件显示） -->
                <div id="modelHint" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-xs text-emerald-700">
                    滚动字幕卡模式：无需选模特，已自动跳过。
                </div>
                <div id="modelSelectWrap" class="hidden rounded-lg border border-slate-200 bg-slate-50/80 p-3.5">
                    <label class="mb-1.5 block text-sm font-medium text-slate-600">选择数字人模特</label>
                    <select id="model" name="model" class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option value="BGZSP20260721_t18_silent.mp4">张老师·稳重主力（推荐）</option>
                        <option value="szrsp">自然略晃·备选</option>
                        <optgroup label="我的模特" id="userModelsGroup"></optgroup>
                    </select>
                    <p class="mt-1.5 flex items-center justify-between text-xs text-slate-400">
                        <span>数字人出镜模式需指定模特；滚动字幕卡模式此步自动跳过。</span>
                        <a href="/studio/models" class="text-brand-600 hover:underline">管理我的模特 →</a>
                    </p>
                </div>

                <!-- 封面选择（发布平台需封面） -->
                <div class="rounded-lg border border-slate-200 bg-slate-50/80 p-3.5">
                    <label class="mb-1.5 block text-sm font-medium text-slate-600">选择封面（可选）</label>
                    <select id="coverId" name="cover_id" class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option value="">不使用封面（平台默认）</option>
                        <optgroup label="我的封面" id="userCoversGroup"></optgroup>
                        <optgroup label="平台预设" id="presetCoversGroup"></optgroup>
                    </select>
                    <p class="mt-1.5 flex items-center justify-between text-xs text-slate-400">
                        <span>发布到视频号 / 抖音 / 小红书 时建议指定封面。</span>
                        <a href="/studio/covers" class="text-brand-600 hover:underline">管理封面素材 →</a>
                    </p>
                </div>

                <!-- 声音选择（从本租户克隆的声音库自由选择） -->
                <div class="rounded-lg border border-slate-200 bg-slate-50/80 p-3.5">
                    <label class="mb-1.5 block text-sm font-medium text-slate-600">配音声线（从你的声音库选择）</label>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <select id="maleVoice" name="male_voice"
                                class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                                <option value="">默认男声</option>
                                @foreach($maleVoices as $mv)
                                    <option value="{{ $mv->voice_id }}" @if($mv->is_default) selected @endif>{{ $mv->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <select id="femaleVoice" name="female_voice"
                                class="w-full rounded-lg border border-slate-200 bg-white p-2.5 text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                                <option value="">默认女声</option>
                                @foreach($femaleVoices as $fv)
                                    <option value="{{ $fv->voice_id }}" @if($fv->is_default) selected @endif>{{ $fv->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <p class="mt-1.5 flex items-center justify-between text-xs text-slate-400">
                        <span>女：行用女声、男：行用男声；单人旁白用所选对应声线。</span>
                        <a href="/studio/voices" class="text-brand-600 hover:underline">去克隆新声音 →</a>
                    </p>
                </div>

                <!-- 字幕样式调试（实时预览） -->
                <details class="rounded-lg border border-slate-200 bg-slate-50/80 p-3.5">
                    <summary class="cursor-pointer text-sm font-medium text-slate-600 select-none">字幕样式调试（字号 / 行数 / 描边 / 位置）</summary>
                    <p class="mt-2 text-xs text-slate-400">拖动滑块实时预览字幕效果；不调则使用平台默认样式。</p>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs text-slate-500"><span>字号</span><span id="v_sub_size" class="font-mono text-brand-600">92</span></div>
                            <input type="range" id="subtitle_size" min="48" max="140" step="1" value="92" class="w-full accent-brand-500"
                                oninput="document.getElementById('v_sub_size').textContent=this.value;updateSubPreview()">
                        </div>
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs text-slate-500"><span>描边</span><span id="v_sub_outline" class="font-mono text-brand-600">5</span></div>
                            <input type="range" id="subtitle_outline" min="0" max="10" step="1" value="5" class="w-full accent-brand-500"
                                oninput="document.getElementById('v_sub_outline').textContent=this.value;updateSubPreview()">
                        </div>
                        <div class="space-y-1">
                            <label class="block text-xs text-slate-500">单条字幕行数</label>
                            <select id="subtitle_lines" class="w-full rounded-lg border border-slate-200 bg-white p-2 text-sm text-slate-700" onchange="updateSubPreview()">
                                <option value="1">1 行</option>
                                <option value="2">2 行</option>
                                <option value="3" selected>3 行（默认）</option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <label class="block text-xs text-slate-500">字幕位置</label>
                            <select id="subtitle_position" class="w-full rounded-lg border border-slate-200 bg-white p-2 text-sm text-slate-700" onchange="updateSubPreview()">
                                <option value="bottom" selected>底部（默认）</option>
                                <option value="center">居中</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3 flex justify-center">
                        <canvas id="subPreview" width="270" height="480" class="rounded-lg border border-slate-200 bg-slate-900 shadow-sm"></canvas>
                    </div>
                    <p class="mt-2 text-center text-[11px] text-slate-400">预览为示意，实际以出片为准</p>
                </details>

                <!-- 配音感情/快慢调节（分声线） -->
                <details class="rounded-lg border border-slate-200 bg-slate-50/80 p-3.5">
                    <summary class="cursor-pointer text-sm font-medium text-slate-600 select-none">🎚 配音感情 / 快慢调节（高级）</summary>
                    <p class="mt-2 text-xs text-slate-400">语速越低越慢；音调越高越亮/尖；音量 0-100。男声默认沉稳慢、女声默认略亮亲和。</p>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs text-slate-500"><span>男声·语速</span><span id="v_male_rate" class="font-mono text-brand-600">0.98</span></div>
                            <input type="range" id="male_rate" min="0.5" max="2" step="0.01" value="0.98" class="w-full accent-brand-500"
                                oninput="document.getElementById('v_male_rate').textContent=this.value">
                        </div>
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs text-slate-500"><span>女声·语速</span><span id="v_female_rate" class="font-mono text-brand-600">0.98</span></div>
                            <input type="range" id="female_rate" min="0.5" max="2" step="0.01" value="0.98" class="w-full accent-brand-500"
                                oninput="document.getElementById('v_female_rate').textContent=this.value">
                        </div>
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs text-slate-500"><span>男声·音调</span><span id="v_male_pitch" class="font-mono text-brand-600">0.95</span></div>
                            <input type="range" id="male_pitch" min="0.5" max="2" step="0.01" value="0.95" class="w-full accent-brand-500"
                                oninput="document.getElementById('v_male_pitch').textContent=this.value">
                        </div>
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs text-slate-500"><span>女声·音调</span><span id="v_female_pitch" class="font-mono text-brand-600">1.02</span></div>
                            <input type="range" id="female_pitch" min="0.5" max="2" step="0.01" value="1.02" class="w-full accent-brand-500"
                                oninput="document.getElementById('v_female_pitch').textContent=this.value">
                        </div>
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs text-slate-500"><span>男声·音量</span><span id="v_male_vol" class="font-mono text-brand-600">53</span></div>
                            <input type="range" id="male_vol" min="0" max="100" step="1" value="53" class="w-full accent-brand-500"
                                oninput="document.getElementById('v_male_vol').textContent=this.value">
                        </div>
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs text-slate-500"><span>女声·音量</span><span id="v_female_vol" class="font-mono text-brand-600">49</span></div>
                            <input type="range" id="female_vol" min="0" max="100" step="1" value="49" class="w-full accent-brand-500"
                                oninput="document.getElementById('v_female_vol').textContent=this.value">
                        </div>
                    </div>
                    <label class="mt-3 flex items-center gap-2 rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 text-xs text-brand-700 cursor-pointer hover:bg-brand-100 transition">
                        <input type="checkbox" id="natural" class="accent-brand-500 rounded">
                        🗣 专家自然口吻（女声专业发问 / 男声权威解答，自然对话节奏与停顿，结尾留咨询钩子；已去除口语哼嗯与填充词）
                    </label>
                    <button type="button" onclick="resetVoice()" class="mt-2.5 text-xs text-brand-500 hover:text-brand-700 hover:underline">恢复默认（男声略快自然 / 女声略亮亲和）</button>
                </details>

                <p id="quotaHint" class="text-xs text-slate-400"></p>
                <p id="durationHint" class="mt-2 text-xs text-slate-400"></p>
                <p class="flex items-start gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">
                    <span>⏱</span><span>真实配音约需 <b>5–10 分钟</b>（逐句克隆音 + 字幕卡合成）。提交后本页自动刷新状态，您也可先去其他页面，回来会自动续接进度。</span>
                </p>
                <button type="submit" id="genBtn"
                    class="w-full rounded-lg bg-brand-500 px-4 py-2.5 font-medium text-white shadow-sm transition hover:bg-brand-600 disabled:opacity-50 disabled:cursor-not-allowed">
                    生成视频
                </button>
                <p id="formMsg" class="text-sm text-red-500"></p>
            </form>
        </section>

        <!-- 结果区 -->
        <section class="luxury-glass p-5">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-700">出片状态</h3>
                <span id="statusBadge" class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-500">待生成</span>
            </div>
            <div id="result" class="flex min-h-[320px] items-center justify-center rounded-lg bg-slate-50 text-sm text-slate-400">
                生成后的视频将显示在这里
            </div>
            <div id="errorBox" class="mt-3 hidden rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600"></div>
        </section>
    </div>
</div>

<script>
let currentMode = 'scroll';
let titleDirty = false;       // 用户是否已手动编辑过标题
let subtitleDirty = false;    // 用户是否已手动编辑过副标题

// 从二创页「带稿去出片」跳转过来时，自动填入清洗稿并推荐模式
(function () {
    const params = new URLSearchParams(window.location.search);
    if (params.get('from') === 'rewrite') {
        const cleaned = sessionStorage.getItem('hgt_rewrite_cleaned') || '';
        const mode = sessionStorage.getItem('hgt_rewrite_mode') || '';
        if (cleaned) {
            const ta = document.getElementById('dialogue');
            if (ta) { ta.value = cleaned; }
            autoSuggest();   // 带入改写稿后，立即根据内容生成标题/副标题建议
            sessionStorage.removeItem('hgt_rewrite_cleaned');
            sessionStorage.removeItem('hgt_rewrite_mode');

            // 自动检测文本格式并推荐出片模式
            const lines = cleaned.split('\n').filter(l => l.trim());
            const dialogueLines = lines.filter(l => /^(女|男)[：:]/.test(l.trim()));
            const isDialogue = lines.length > 0 && dialogueLines.length >= Math.min(3, lines.length * 0.6);

            let recommendedMode = 'scroll';
            let reason = '';
            if (mode === 'dual' && isDialogue) {
                recommendedMode = 'avatar';
                reason = '检测到对话格式 + 双声改写模式，推荐「数字人出镜」';
            } else if (mode === 'dual' && !isDialogue) {
                recommendedMode = 'scroll';
                reason = '双声改写但文本非标准对话格式，推荐「滚动字幕卡」（或切换数字人后手动调整格式）';
            } else if (mode === 'single' || mode === 'script') {
                recommendedMode = 'scroll';
                reason = '单声/专业口播稿模式，适合「滚动字幕卡」出片';
            }

            // 应用推荐模式
            setMode(recommendedMode);

            // 顶部提示
            const hint = document.createElement('div');
            hint.className = 'mb-4 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-700';
            hint.innerHTML = '<div class="flex items-center justify-between"><span>已从「智能二创」带入清洗稿。' +
                (reason ? '<br><span class="text-xs text-brand-600 mt-1 inline-block">' + reason + '</span>' : '') +
                '</span></div>' +
                '<a href="/studio/rewrite" class="font-medium underline hover:text-brand-900 text-xs">← 返回二创</a>';
            document.querySelector('header').after(hint);

            // 如果是avatar模式但文本不完全符合对话格式，触发一次校验
            if (recommendedMode === 'avatar') {
                checkDialogueFormat(cleaned, document.getElementById('formatWarning'));
            }
        }
    }
})();

// 拉取当前租户已通过质检的自传模特，填入 avatar 下拉「我的模特」分组
(async function loadUserModels() {
    try {
        const resp = await fetch('/studio/models/json', {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });
        const data = await resp.json();
        const group = document.getElementById('userModelsGroup');
        if (group && data.ok && Array.isArray(data.models)) {
            data.models.forEach(m => {
                const opt = document.createElement('option');
                opt.value = 'User:' + m.id;
                opt.textContent = m.name + (m.scene ? '（' + m.scene + '）' : '');
                group.appendChild(opt);
            });
        }
    } catch (e) { /* 静默：内置模特仍可用 */ }
})();

// 拉取封面（我的封面 + 平台预设），分别填入对应分组
(async function loadCovers() {
    try {
        const resp = await fetch('/studio/covers/json', {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });
        const data = await resp.json();
        if (!data.ok) return;
        const mine = document.getElementById('userCoversGroup');
        const preset = document.getElementById('presetCoversGroup');
        const fill = (group, list) => {
            if (!group || !Array.isArray(list)) return;
            list.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id;
                const tag = c.is_preset ? '（预设）' : '';
                opt.textContent = c.name + tag + (c.width ? '（' + c.width + '×' + c.height + '）' : '');
                group.appendChild(opt);
            });
        };
        fill(mine, data.covers);
        fill(preset, data.presets);
    } catch (e) { /* 静默：可不指定封面 */ }
})();

function setMode(m) {
    currentMode = m;
    const s = document.getElementById('modeScroll');
    const a = document.getElementById('modeAvatar');
    const on = 'rounded-lg px-4 py-2 text-sm font-medium transition border border-brand-500 bg-brand-50 text-brand-700';
    const off = 'rounded-lg px-4 py-2 text-sm font-medium transition border border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:text-slate-700';
    s.className = m === 'scroll' ? on : off;
    a.className = m === 'avatar' ? on : off;

    // 模特/场景区域切换
    document.getElementById('modelHint').classList.toggle('hidden', m !== 'scroll');
    document.getElementById('modelSelectWrap').classList.toggle('hidden', m !== 'avatar');
    document.getElementById('sceneSelectWrap').classList.toggle('hidden', m !== 'avatar');

    // 文本格式提示与校验
    const label = document.getElementById('dialogueLabel');
    const hint = document.getElementById('formatHint');
    const warning = document.getElementById('formatWarning');
    const ta = document.getElementById('dialogue');

    if (m === 'avatar') {
        // 数字人出镜：支持独白与双声对话（不再强制 女：/男：）
        label.innerHTML = '对话稿 / 独白（<span class="text-slate-400">纯文本=男声独白；每行 女：/男： 开头=男女双声对话</span>）';
        hint.innerHTML = '<span class="text-emerald-600">支持独白与双声对话</span>';
        hint.className = 'text-[11px] font-normal text-emerald-600';
        // 检测当前文本格式并给出温和提示（不阻断提交）
        checkDialogueFormat(ta.value, warning);
    } else {
        // 滚动字幕卡：接受任意格式
        label.innerHTML = '文稿内容（<span class="text-slate-400">支持对话 / 独白 / 改写稿，自动适配</span>）';
        hint.innerHTML = '<span class="text-emerald-600">任意格式均可</span>';
        hint.className = 'text-[11px] font-normal text-emerald-600';
        warning.classList.add('hidden');
    }
}

// 检测文本格式并给出温和提示（数字人模式支持独白与双声对话，不阻断提交）
function checkDialogueFormat(text, warnEl) {
    if (!warnEl) return;
    const lines = text.split('\n').filter(l => l.trim());
    const dialogueLines = lines.filter(l => /^(女|男)[：:]/.test(l.trim()));
    if (lines.length === 0) {
        warnEl.classList.add('hidden');
        return;
    }
    if (dialogueLines.length === lines.length) {
        // 纯双声对话
        warnEl.textContent = '已识别男女双声对话：女：行用女声、男：行用男声，数字人将用对应声线配音。';
        warnEl.className = 'mt-1 hidden rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs text-emerald-700';
        warnEl.classList.remove('hidden');
    } else if (dialogueLines.length === 0) {
        // 纯独白
        warnEl.textContent = '检测到独白文本：将用所选男声（数字人形象声线）统一配音。如需男女双声，请每行以 女：/男： 开头。';
        warnEl.className = 'mt-1 hidden rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs text-amber-700';
        warnEl.classList.remove('hidden');
    } else {
        // 混合
        warnEl.textContent = '混合格式：含 女：/男： 的行用对应声线，其余行用男声（数字人形象声线）配音。如需男女双声对话，请确保每行都以 女：/男： 开头。';
        warnEl.className = 'mt-1 hidden rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs text-amber-700';
        warnEl.classList.remove('hidden');
    }
}

// 文本输入时实时检测格式（仅 avatar 模式）
document.getElementById('dialogue')?.addEventListener('input', function () {
    if (currentMode === 'avatar') {
        checkDialogueFormat(this.value, document.getElementById('formatWarning'));
    }
    updateDurationHint();
});

// 根据文稿内容自动生成标题 / 副标题建议（客户端启发式，可人工覆盖）
function stripRolePrefix(line) {
    return line.replace(/^(女|男|旁白|解说|主播|画外音|独白|配音)[:：]\s*/, '');
}
let titleStyle = 'smart';   // 标题生成策略：smart(智能提取) / full(首句完整) / suspense(悬念式)
function suggestTitleSmart(text) {
    const lines = text.split('\n').map(l => l.trim()).filter(Boolean);
    if (!lines.length) return '';
    let first = stripRolePrefix(lines[0]).replace(/[\s，。！？!?；;、…\.,]+/g, '');
    return first.slice(0, 10);
}
function suggestTitleFull(text) {
    const lines = text.split('\n').map(l => l.trim()).filter(Boolean);
    if (!lines.length) return '';
    // 首句完整句（去角色前缀、合并空白），放宽到 30 字
    let first = stripRolePrefix(lines[0]).replace(/\s+/g, ' ').trim();
    return first.slice(0, 30);
}
function suggestTitleSuspense(text) {
    const lines = text.split('\n').map(l => l.trim()).filter(Boolean);
    if (!lines.length) return '';
    let hook = stripRolePrefix(lines[0]).replace(/[\s，。！？!?；;、…\.,]+/g, '').slice(0, 12);
    const tpl = [
        hook + '的真相，90%的人都理解错了',
        '关于' + hook + '，老板不会主动告诉你',
        hook + '？这三点必须提前知道',
        '别等吃亏才懂：' + hook,
    ];
    for (const t of tpl) if (t.length <= 30) return t;
    return tpl[0].slice(0, 30);
}
function suggestTitle(text) {
    if (titleStyle === 'full') return suggestTitleFull(text);
    if (titleStyle === 'suspense') return suggestTitleSuspense(text);
    return suggestTitleSmart(text);
}
function suggestSubtitle(text) {
    const lines = text.split('\n').map(l => l.trim()).filter(Boolean);
    if (!lines.length) return '';
    // 合并前两句（去角色前缀），截 40 字以内，作为内容钩子
    let body = lines.slice(0, 2).map(stripRolePrefix).join(' ');
    body = body.replace(/[\s，。！？!?；;、…\.,]+/g, ' ').trim();
    return body.slice(0, 40);
}
function autoSuggest() {
    const text = document.getElementById('dialogue').value;
    const titleEl = document.getElementById('title');
    const subEl = document.getElementById('subtitle');
    const tTag = document.getElementById('titleAutoTag');
    const sTag = document.getElementById('subtitleAutoTag');
    const tHint = document.getElementById('titleHint');
    const sHint = document.getElementById('subtitleHint');
    const t = suggestTitle(text), s = suggestSubtitle(text);
    if (!titleDirty && t) titleEl.value = t;
    if (!subtitleDirty && s) subEl.value = s;
    // 标签与提示：区分「自动生成」与「已手动修改」
    if (titleDirty) {
        tTag.textContent = '选填 · 已手动修改';
        tTag.className = 'ml-1 rounded bg-brand-50 px-1.5 py-0.5 text-[11px] font-normal text-brand-500';
        tHint.classList.add('hidden');
    } else if (t) {
        tTag.textContent = '选填 · 自动生成';
        tTag.className = 'ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500';
        const styleLabel = {smart:'智能提取（首句关键词）', full:'首句完整句', suspense:'悬念式'}[titleStyle];
        tHint.textContent = '💡 已按「' + styleLabel + '」自动生成，可直接修改';
        tHint.classList.remove('hidden');
    } else {
        tTag.textContent = '选填';
        tTag.className = 'ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500';
        tHint.classList.add('hidden');
    }
    if (subtitleDirty) {
        sTag.textContent = '选填 · 已手动修改';
        sTag.className = 'ml-1 rounded bg-brand-50 px-1.5 py-0.5 text-[11px] font-normal text-brand-500';
        sHint.classList.add('hidden');
    } else if (s) {
        sTag.textContent = '选填 · 自动生成';
        sTag.className = 'ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500';
        sHint.textContent = '💡 已根据文稿前两句自动生成，可直接修改';
        sHint.classList.remove('hidden');
    } else {
        sTag.textContent = '选填';
        sTag.className = 'ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500';
        sHint.classList.add('hidden');
    }
}
// 用户手动编辑标题/副标题后，标记为已改，停止自动覆盖
document.getElementById('title').addEventListener('input', () => { titleDirty = true; autoSuggest(); });
document.getElementById('subtitle').addEventListener('input', () => { subtitleDirty = true; autoSuggest(); });
// 标题风格分段控件：切换策略时，若未手动改过标题则按新风格重新生成
document.querySelectorAll('.title-style-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        titleStyle = btn.dataset.style;
        document.querySelectorAll('.title-style-btn').forEach(b => {
            const on = b === btn;
            b.className = 'title-style-btn rounded-md border px-2 py-0.5 text-[11px] ' +
                (on ? 'border-brand-300 bg-brand-50 text-brand-600' : 'border-slate-200 bg-white text-slate-500');
        });
        autoSuggest();
    });
});
// 文稿变化时（去抖 300ms）自动生成建议
let _suggestTimer = null;
document.getElementById('dialogue')?.addEventListener('input', () => {
    clearTimeout(_suggestTimer);
    _suggestTimer = setTimeout(autoSuggest, 300);
});

// 实时预估视频时长（与后端 estimateDurationSec 算法一致）
function estimateDuration() {
    const text = document.getElementById('dialogue').value;
    let chars = 0;
    text.split('\n').forEach(raw => {
        let line = raw.trim();
        if (!line) return;
        if (/^(女|男)[:：]/.test(line)) line = line.slice(2);
        chars += line.replace(/\s/g, '').length;
    });
    return Math.max(1, Math.round(chars / 4.5));
}
function updateDurationHint() {
    const el = document.getElementById('durationHint');
    if (!el) return;
    const sec = estimateDuration();
    const max = 1800;
    if (sec > max) {
        el.className = 'mt-2 text-xs font-medium text-red-600';
        el.textContent = '⚠ 预估时长 ' + Math.floor(sec / 60) + '分' + (sec % 60) + '秒，超过单次上限 30 分钟，请拆分内容后再提交。';
    } else {
        el.className = 'mt-2 text-xs text-slate-400';
        el.textContent = '预估视频时长：约 ' + Math.floor(sec / 60) + '分' + (sec % 60) + '秒（单次上限 30 分钟）';
    }
}
updateDurationHint();
autoSuggest();
updateSubPreview();

function resetVoice() {
    const defs = {male_rate:0.98, female_rate:0.98, male_pitch:0.95, female_pitch:1.02, male_vol:53, female_vol:49};
    for (const k in defs) {
        const el = document.getElementById(k);
        if (el) { el.value = defs[k]; const v = document.getElementById('v_' + k); if (v) v.textContent = defs[k].toFixed(2).replace(/\.?0+$/, '') || defs[k]; }
    }
}

// 字幕样式实时预览：等比映射 1080x1920 → canvas，模拟当前行字号/行数/描边/位置
function updateSubPreview() {
    const cv = document.getElementById('subPreview');
    if (!cv) return;
    const ctx = cv.getContext('2d');
    const W = cv.width, H = cv.height;
    const g = ctx.createLinearGradient(0, 0, 0, H);
    g.addColorStop(0, '#0f172a'); g.addColorStop(1, '#1e293b');
    ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);

    const sx = W / 1080;
    const size = parseInt(document.getElementById('subtitle_size').value, 10);
    const linesN = parseInt(document.getElementById('subtitle_lines').value, 10);
    const outline = parseInt(document.getElementById('subtitle_outline').value, 10);
    const position = document.getElementById('subtitle_position').value;
    const fontPx = Math.max(8, Math.round(size * sx));
    ctx.font = '700 ' + fontPx + 'px "Microsoft YaHei", SimHei, sans-serif';
    ctx.textBaseline = 'middle';
    ctx.textAlign = 'left';

    // 示例文本（按字数均分到 linesN 行，示意折行效果）
    const sample = '很多新手容易踩的坑，其实都有规律可循';
    const per = Math.max(1, Math.ceil(sample.length / linesN));
    const lines = [];
    for (let i = 0; i < sample.length; i += per) lines.push(sample.slice(i, i + per));

    const gap = Math.round(172 * sx * (size / 92));   // 行距随字号同比例
    const x = 80 * sx;
    let startY;
    if (position === 'center') {
        startY = H / 2 - ((lines.length - 1) * gap) / 2;
    } else {
        startY = H - 120 * sx - (lines.length - 1) * gap;
    }
    ctx.lineJoin = 'round';
    for (let i = 0; i < lines.length; i++) {
        const y = startY + i * gap;
        if (outline > 0) {
            ctx.lineWidth = Math.max(1, Math.round(outline * sx * (size / 92)));
            ctx.strokeStyle = 'rgba(0,0,0,0.9)';
            ctx.strokeText(lines[i], x, y);
        }
        ctx.fillStyle = '#ffffff';
        ctx.fillText(lines[i], x, y);
    }
}

document.getElementById('genForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('genBtn');
    const msg = document.getElementById('formMsg');
    const badge = document.getElementById('statusBadge');
    const result = document.getElementById('result');
    const errBox = document.getElementById('errorBox');
    msg.textContent = ''; errBox.classList.add('hidden');

    // 本地预校验：文稿为空时拦截
    const dialogue = document.getElementById('dialogue').value.trim();
    if (!dialogue) {
        msg.textContent = '⚠ 请输入文稿内容（必填）。单声口播直接写文案；双声对话每行以「女：」「男：」开头。';
        errBox.innerHTML = '<strong>提交失败：文稿为空</strong><br><span class="text-xs mt-1 block text-red-400">「文稿内容」是必填项，请先撰写或从「智能二创」带入改写稿后再提交出片。</span>';
        errBox.classList.remove('hidden');
        document.getElementById('dialogue').focus();
        return;
    }

    // 本地预校验：时长超限直接拦，不白提交（后端有 422 双保险）
    const est = estimateDuration();
    if (est > 1800) {
        errBox.textContent = '时长超限：预估约 ' + est + ' 秒，超过单次上限 1800 秒（30分钟）。请拆分内容分批生成。';
        errBox.classList.remove('hidden');
        return;
    }

    btn.disabled = true; btn.textContent = '提交中…';
    badge.textContent = '排队中'; badge.className = 'rounded-full bg-amber-100 px-3 py-1 text-xs text-amber-700';

    try {
        const resp = await fetch('/studio/scroll/generate', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({
                mode: currentMode,
                dialogue: document.getElementById('dialogue').value,
                title: document.getElementById('title').value,
                subtitle: document.getElementById('subtitle').value,
                dry_tts: false,
                male_rate: parseFloat(document.getElementById('male_rate').value),
                female_rate: parseFloat(document.getElementById('female_rate').value),
                male_pitch: parseFloat(document.getElementById('male_pitch').value),
                female_pitch: parseFloat(document.getElementById('female_pitch').value),
                male_vol: parseInt(document.getElementById('male_vol').value, 10),
                female_vol: parseInt(document.getElementById('female_vol').value, 10),
                natural: document.getElementById('natural').checked,
                male_voice: document.getElementById('maleVoice').value || null,
                female_voice: document.getElementById('femaleVoice').value || null,
                model: currentMode === 'avatar' ? (document.getElementById('model').value || null) : null,
                scene: currentMode === 'avatar' ? (document.getElementById('scene')?.value || null) : null,
                cover_id: document.getElementById('coverId').value ? parseInt(document.getElementById('coverId').value, 10) : null,
                subtitle_size: parseInt(document.getElementById('subtitle_size').value, 10),
                subtitle_lines: parseInt(document.getElementById('subtitle_lines').value, 10),
                subtitle_outline: parseInt(document.getElementById('subtitle_outline').value, 10),
                subtitle_position: document.getElementById('subtitle_position').value,
            })
        });
        // 防护：后端可能返回HTML异常页而非JSON
        const respText = await resp.text();
        let data;
        try {
            data = JSON.parse(respText);
        } catch (parseErr) {
            // 提取HTML中的有用信息
            let errMsg = '服务器返回了非JSON响应（可能是PHP异常或服务未启动）';
            if (respText.includes('<!DOCTYPE') || respText.startsWith('<')) {
                const titleMatch = respText.match(/<h1[^>]*>(.*?)<\/h1>/is);
                const msgMatch = respText.match(/<p[^>]*class=".*?exception.*?"[^>]*>(.*?)<\/p>/is);
                if (msgMatch) errMsg = msgMatch[1].replace(/<[^>]+>/g, '').trim();
                else if (titleMatch) errMsg = titleMatch[1].replace(/<[^>]+>/g, '').trim();
                else errMsg = '后端返回了HTML页面（HTTP ' + resp.status + '），请检查Laravel日志或重启Docker容器';
            }
            throw new Error(errMsg);
        }
        if (!resp.ok) {
            // Laravel 验证错误返回 {message, errors: {field: [msg]}}；业务错误返回 {error: string}
            if (data.errors) {
                const fields = Object.values(data.errors).flat();
                throw new Error(fields.join('；') || data.message || '提交失败（HTTP ' + resp.status + '）');
            }
            throw new Error(data.error || '提交失败（HTTP ' + resp.status + '）');
        }
        if (data.quota != null) {
            document.getElementById('quotaHint').textContent =
                '本月用量 ' + data.usage + ' / ' + (data.quota === 0 ? '不限' : data.quota);
        }
        // 持久化当前任务，用户离开页面后回来可自动续接进度
        sessionStorage.setItem('hgt_active_job', data.job_id);
        result.innerHTML = '<div class="text-center text-slate-400"><div class="mb-2 text-3xl">⏳</div>'
            + '<div class="font-medium text-slate-600">出片任务已提交，正在真实配音合成…</div>'
            + '<div class="mt-1 text-xs">您可先去其他页面，回来会自动续接进度</div></div>';
        pollStatus(data.job_id);
    } catch (err) {
        btn.disabled = false; btn.textContent = '生成视频';
        badge.textContent = '失败'; badge.className = 'rounded-full bg-red-100 px-3 py-1 text-xs text-red-600';
        errBox.textContent = err.message; errBox.classList.remove('hidden');
    }
});

async function pollStatus(jobId) {
    const badge = document.getElementById('statusBadge');
    const btn = document.getElementById('genBtn');
    const result = document.getElementById('result');
    for (let i = 0; i < 300; i++) {  // 最多 10 分钟轮询（真实配音约 5–10 分钟）
        await new Promise(r => setTimeout(r, 2000));
        try {
            const statusResp = await fetch('/studio/scroll/status/' + jobId, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                }
            });
            const statusText = await statusResp.text();
            let data;
            try { data = JSON.parse(statusText); } catch (_) { return; } // 网络抖动，跳过本轮
            if (data.status === 'done') {
                sessionStorage.removeItem('hgt_active_job');
                badge.textContent = '完成'; badge.className = 'rounded-full bg-green-100 px-3 py-1 text-xs text-green-700';
                result.innerHTML =
                    '<div class="w-full">' +
                    '  <div class="mb-2 flex items-center gap-2 text-sm font-medium text-green-700">出片完成（真实配音短视频）</div>' +
                    '  <video src="/studio/scroll/download/' + jobId + '" controls class="max-h-[55vh] w-full rounded-lg bg-black"></video>' +
                    '  <div class="mt-3 flex flex-wrap gap-2">' +
                    '    <a href="/studio/scroll/download/' + jobId + '" download class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">⬇ 下载视频</a>' +
                    '    <a href="/studio/publish" class="rounded-lg border border-brand-300 bg-white px-4 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50">去发布</a>' +
                    '    <a href="/studio/scroll" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm text-slate-500 hover:bg-slate-50">↻ 再出一条</a>' +
                    '  </div>' +
                    '</div>';
                btn.disabled = false; btn.textContent = '生成视频';
                return;
            } else if (data.status === 'failed') {
                sessionStorage.removeItem('hgt_active_job');
                badge.textContent = '失败'; badge.className = 'rounded-full bg-red-100 px-3 py-1 text-xs text-red-600';
                const eb = document.getElementById('errorBox');
                eb.textContent = '出片失败：' + (data.error || '未知错误'); eb.classList.remove('hidden');
                btn.disabled = false; btn.textContent = '生成视频';
                return;
            } else {
                badge.textContent = '出片中'; badge.className = 'rounded-full bg-brand-100 px-3 py-1 text-xs text-brand-600';
                const sec = i * 2;
                result.innerHTML = '<div class="text-center text-slate-400">' +
                    '<div class="mb-2 text-3xl">⏳</div>' +
                    '<div class="font-medium text-slate-600">真实配音合成中…</div>' +
                    '<div class="mt-1 text-xs">已等待 ' + sec + ' 秒 · 预计 5–10 分钟</div>' +
                    '<div class="mt-1 text-xs text-brand-500">可先去其他页面，回来会自动续接</div></div>';
            }
        } catch (e) { /* 网络抖动，继续轮询 */ }
    }
    badge.textContent = '超时'; badge.className = 'rounded-full bg-red-100 px-3 py-1 text-xs text-red-600';
    btn.disabled = false; btn.textContent = '生成视频';
}

// 续接未完成的出片任务（用户离开页面后回来自动恢复轮询）
(function resumeJob() {
    const jobId = sessionStorage.getItem('hgt_active_job');
    if (jobId) {
        const btn = document.getElementById('genBtn');
        btn.disabled = true; btn.textContent = '出片中…';
        pollStatus(jobId);
    }
})();
</script>
</x-workspace-layout>
</x-app-layout>
