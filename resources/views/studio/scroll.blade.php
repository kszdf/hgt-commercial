<x-app-layout>
<x-workspace-layout title="视频出片工作台">
<div class="mx-auto max-w-5xl p-6">
<style>
/* 出片进度：渲染是黑盒子进程，无实时百分比，流动条样式(.hgt-indet)已定义在全局 workspace-layout */
</style>

    <!-- 出片形式快捷切换：按形式分组（核心形式 + 动态画面声线细项） -->
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <span class="text-xs text-slate-400">出片形式：</span>
        <button type="button" data-form="avatar" class="form-btn rounded-lg px-4 py-2 text-sm font-medium transition" onclick="selectForm('avatar')">🎭 数字人出镜</button>
        <button type="button" data-form="motion" class="form-btn rounded-lg px-4 py-2 text-sm font-medium transition" onclick="selectForm('motion')">🎬 幕后音·动态画面</button>
        <button type="button" data-form="scroll" class="form-btn rounded-lg px-4 py-2 text-sm font-medium transition" onclick="selectForm('scroll')">📋 幕后音·滚动字幕</button>
        <button type="button" data-form="manga" class="form-btn rounded-lg px-4 py-2 text-sm font-medium transition" onclick="selectForm('manga')">📖 AI 漫剧</button>
        <button type="button" data-form="whiteboard" class="form-btn rounded-lg px-4 py-2 text-sm font-medium transition" onclick="selectForm('whiteboard')">✍️ AI 白板图解</button>
        <span class="mx-1 h-4 w-px bg-slate-200"></span>
        <span class="text-xs text-slate-400">动态画面声线：</span>
        <button type="button" data-form="male_mono" class="form-btn rounded-lg px-3 py-2 text-sm font-medium transition" onclick="selectForm('male_mono')">男声</button>
        <button type="button" data-form="female_mono" class="form-btn rounded-lg px-3 py-2 text-sm font-medium transition" onclick="selectForm('female_mono')">女声</button>
        <button type="button" data-form="dialogue" class="form-btn rounded-lg px-3 py-2 text-sm font-medium transition" onclick="selectForm('dialogue')">男女对话</button>
    </div>
    <!-- 幕后音·动态画面：画面主题 -->
    <div class="mb-4 flex flex-wrap items-center gap-2" id="motionStyleWrap">
        <span class="text-xs text-slate-400">画面主题：</span>
        <select id="motion_style" name="motion_style" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600">
            <option value="财经严谨">财经严谨（深色专业·金点缀）</option>
            <option value="带货活力">带货活力</option>
            <option value="简约高级">简约高级</option>        </select>
    </div>

    <!-- 常用预设档：点一个按钮整组套用「出片形式 + 配音声线」 -->
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <span class="text-xs text-slate-400">常用预设：</span>
        <button type="button" data-preset="male_avatar" class="preset-btn rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 hover:border-slate-300" onclick="applyPreset('male_avatar')">男声·单人数字人</button>
        <button type="button" data-preset="female_mono" class="preset-btn rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 hover:border-slate-300" onclick="applyPreset('female_mono')">女声·幕后音</button>
        <button type="button" data-preset="male_mono" class="preset-btn rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 hover:border-slate-300" onclick="applyPreset('male_mono')">男声·幕后音</button>
    </div>

    <!-- 二次剪辑（数字人出镜 / 幕后音·动态画面 均适用：片尾留资卡 + 轻BGM + 转场） -->
    <div class="mb-4 flex flex-wrap items-center gap-2" id="editStyleWrap">
        <span class="text-xs text-slate-400">二次剪辑：</span>
        <select id="edit_style" name="edit_style" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600">
            <option value="fast">快节奏卡点（推荐：片尾留资卡 + 轻BGM + 转场）</option>
            <option value="">标准（不二次剪辑）</option>
        </select>
        <span class="text-[11px] text-slate-400">为成片追加「关注 <span id="ipNameLabel">{{ ($defaults['ipName'] ?? '昆山老张讲财税') }}</span>」留资片尾与轻背景音乐。</span>
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
                    <textarea id="dialogue" name="dialogue" rows="11"
                        class="w-full rounded-lg border border-slate-200 bg-white p-3 font-mono text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100"
                        placeholder="粘贴你的口播稿 / 文案 / 改写稿…&#10;男女对话格式：每行以「女：」「男：」开头&#10;单人独白：直接写正文即可"></textarea>
                    <p id="formatWarning" class="mt-1 hidden rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs text-amber-700"></p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 flex items-center justify-between">
                            <span class="text-sm font-medium text-slate-700">
                                标题（≤15字）
                                <span id="titleAutoTag" class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal text-slate-500">选填</span>
                            </span>
                        </label>
                        <input id="title" name="title" maxlength="15"
                            class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <span class="text-[11px] text-slate-400">标题风格</span>
                            <select id="titleStyle" class="title-style-sel rounded-md border border-slate-200 bg-white px-2 py-1 text-[12px] text-slate-600">
                                <option value="smart">智能提取</option>
                                <option value="full">首句完整</option>
                                <option value="suspense">悬念式</option>
                            </select>
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
                        <input id="subtitle" name="subtitle" maxlength="30"
                            class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <button type="button" id="aiTitleBtn"
                                class="inline-flex items-center gap-1 rounded-lg bg-gradient-to-r from-brand-500 to-brand-600 px-3 py-1.5 text-[12px] font-medium text-white hover:from-brand-600 hover:to-brand-700">
                                ✨ AI 智能生成标题+副标题
                            </button>
                            <span class="hint" data-tip="基于文稿内容，AI 自动生成科学、有吸引力的标题与副标题">?</span>
                            <span id="aiTitleHint" class="hidden text-[11px] text-slate-400"></span>
                        </div>
                        <p id="subtitleHint" class="mt-1 hidden text-[11px] text-slate-400"></p>
                    </div>
                </div>

                <!-- 模特选择（按出片模式条件显示） -->
                <div id="modelHint" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-xs text-emerald-700">
                    幕后音·动态画面模式：无需选模特，已自动跳过。
                </div>
                <div id="modelSelectWrap" class="hidden rounded-lg studio-card studio-card-sm">
                    <label class="mb-1.5 block text-sm font-medium text-slate-600">选择数字人模特</label>
                    <select id="model" name="model" class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option value="BGZSP20260721_t18_silent.mp4">张老师·办公桌前正面（推荐）</option>
                        <option value="yxszr1">张老师·办公桌前侧面</option>
                        <option value="szrsp">自然略晃·备选</option>
                        <optgroup label="我的模特" id="userModelsGroup"></optgroup>
                    </select>
                    <p class="mt-1.5 flex items-center justify-between text-xs text-slate-400">
                        <span class="hint" data-tip="数字人模特已含出镜场景，配合您的克隆声线合成口播；幕后音·动态画面模式此步自动跳过。">?</span>
                        <a href="/studio/models" class="text-brand-600 hover:underline">管理我的模特 →</a>
                    </p>
                </div>

                <!-- 封面选择（发布平台需封面） -->
                <div class="rounded-lg studio-card studio-card-sm">
                    <label class="mb-1.5 block text-sm font-medium text-slate-600">选择封面（可选）</label>
                    <select id="coverId" name="cover_id" class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                        <option value="">不使用封面（平台默认）</option>
                        <optgroup label="我的封面" id="userCoversGroup"></optgroup>
                        <optgroup label="平台预设" id="presetCoversGroup"></optgroup>
                    </select>
                    <p class="mt-1.5 flex items-center justify-between text-xs text-slate-400">
                        <span class="hint" data-tip="发布到视频号 / 抖音 / 小红书 时建议指定封面。">?</span>
                        <a href="/studio/covers" class="text-brand-600 hover:underline">管理封面素材 →</a>
                    </p>
                </div>

                <!-- 声线形式（仅滚动字幕模式显示；数字人模式为单人独白，此控件隐藏） -->
                <div id="voiceFormWrap" class="hidden rounded-lg border border-brand-200 bg-brand-50/60 p-3.5">
                    <label class="mb-1.5 block text-sm font-medium text-slate-600">声线形式<span class="hint" data-tip="男女对话：每行以「女：」「男：」开头，分别用女声/男声；单人独白请在下方选单一声线。">?</span></label>
                    <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="声线形式">
                        <button type="button" data-form="dialogue" class="voice-form-btn rounded-lg border border-brand-400 bg-brand-500 px-3 py-1.5 text-xs font-medium text-white">男女对话</button>
                        <button type="button" data-form="male_mono" class="voice-form-btn rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 hover:border-slate-300">男声独白</button>
                        <button type="button" data-form="female_mono" class="voice-form-btn rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 hover:border-slate-300">女声独白</button>
                        <button type="button" data-form="mono" class="voice-form-btn rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 hover:border-slate-300">单声线</button>
                    </div>
                    <p id="voiceFormHint" class="mt-1.5 text-xs text-slate-400"></p>
                </div>

                <!-- 声音选择 -->
                <div class="rounded-lg studio-card studio-card-sm">
                    <label id="voiceLabel" class="mb-1.5 block text-sm font-medium text-slate-600">配音声线</label>
                    <!-- 独白模式快捷（男声独白/女声独白/单声线/数字人）：老张或江老师二选一 -->
                    <div id="quickVoiceSingle" class="mb-2 flex flex-wrap items-center gap-2">
                        <span class="text-xs text-slate-400">快捷：</span>
                        <button type="button" id="quickVoiceZhang" class="quick-voice-btn rounded-lg border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600 hover:border-brand-300">默认男声</button>
                        <button type="button" id="quickVoiceJiang" class="quick-voice-btn rounded-lg border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600 hover:border-brand-300">默认女声</button>
                    </div>
                    <!-- 对话模式快捷（男女对话）：一键填入男声+女声组合 -->
                    <div id="quickVoiceDialogue" class="mb-2 hidden flex flex-wrap items-center gap-2">
                        <span class="text-xs text-slate-400">常用组合：</span>
                        <button type="button" id="quickComboZJ" class="quick-combo-btn rounded-lg border border-brand-300 bg-brand-50 px-3 py-1 text-xs font-medium text-brand-700 hover:border-brand-400 hover:bg-brand-100">🎙️ 默认男声 + 默认女声</button>
                        <button type="button" id="quickComboZZ" class="quick-combo-btn rounded-lg border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600 hover:border-slate-300">👨 默认男声 + 其他女声</button>
                        <button type="button" id="quickComboJJ" class="quick-combo-btn rounded-lg border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600 hover:border-slate-300">👩 默认女声 + 其他男声</button>
                    </div>
                    <!-- dualVoiceWrap：滚动字幕模式下男/女双声线下拉容器（数字人模式隐藏）；独白时由 setVoiceForm 控制单声线显隐 -->
                    <div id="dualVoiceWrap" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <select id="maleVoice" name="male_voice"
                                class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                                <option value="">默认男声</option>
                                @foreach($maleVoices as $mv)
                                    <option value="{{ $mv->voice_id }}" @if($mv->is_default) selected @endif>{{ $mv->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <select id="femaleVoice" name="female_voice"
                                class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                                <option value="">默认女声</option>
                                @foreach($femaleVoices as $fv)
                                    <option value="{{ $fv->voice_id }}" @if($fv->is_default) selected @endif>{{ $fv->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <!-- 单人（数字人出镜 / 滚动字幕独白） -->
                    <div id="singleVoiceWrap" class="hidden">
                        <select id="singleVoice" name="single_voice"
                            class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-100">
                            <option value="">默认声线</option>
                            @foreach($maleVoices as $mv)
                                <option value="{{ $mv->voice_id }}">{{ $mv->name }}（男）</option>
                            @endforeach
                            @foreach($femaleVoices as $fv)
                                <option value="{{ $fv->voice_id }}">{{ $fv->name }}（女）</option>
                            @endforeach
                        </select>
                    </div>
                    <p id="voiceHint" class="mt-1.5 flex items-center justify-between text-xs text-slate-400">
                        <span id="voiceHintText">女：行用女声、男：行用男声；单人幕后音用所选对应声线。</span>
                        <a href="/studio/voices" class="text-brand-600 hover:underline">去克隆新声音 →</a>
                    </p>
                </div>

                <!-- 字幕样式调试（字号 / 行数 / 描边 / 位置 / 风格 / 字体）— 已隐藏：
                     出片使用 config/studio.php 的默认值（owner 可经 .env 调优，用户端不暴露滑块）；
                     DOM 保留供 JS 读取当前值，避免 getElementById 报错；如需手动微调再取消 hidden -->
                <details class="rounded-lg studio-card studio-card-sm" hidden>
                    <summary class="cursor-pointer text-sm font-medium text-slate-600 select-none">字幕样式调试（字号 / 行数 / 描边 / 位置）</summary>
                    <p class="mt-2 text-xs text-slate-400">已启用平台默认字幕样式，无需手动调节。</p>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs text-slate-500"><span>字号</span><span id="v_sub_size" class="font-mono text-brand-600">{{ $subDefaults['size'] }}</span></div>
                            <input type="range" id="subtitle_size" min="48" max="140" step="1" value="{{ $subDefaults['size'] }}" class="w-full accent-brand-500"
                                oninput="document.getElementById('v_sub_size').textContent=this.value;updateSubPreview()">
                        </div>
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs text-slate-500"><span>描边</span><span id="v_sub_outline" class="font-mono text-brand-600">{{ $subDefaults['outline'] }}</span></div>
                            <input type="range" id="subtitle_outline" min="0" max="10" step="1" value="{{ $subDefaults['outline'] }}" class="w-full accent-brand-500"
                                oninput="document.getElementById('v_sub_outline').textContent=this.value;updateSubPreview()">
                        </div>
                        <div class="space-y-1">
                            <label class="block text-xs text-slate-500">单条字幕行数</label>
                            <select id="subtitle_lines" class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700" onchange="updateSubPreview()">
                                <option value="1" {{ $subDefaults['lines'] == 1 ? 'selected' : '' }}>1 行</option>
                                <option value="2" {{ $subDefaults['lines'] == 2 ? 'selected' : '' }}>2 行</option>
                                <option value="3" {{ $subDefaults['lines'] == 3 ? 'selected' : '' }}>3 行（默认）</option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <label class="block text-xs text-slate-500">字幕位置</label>
                            <select id="subtitle_position" class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700" onchange="updateSubPreview()">
                                <option value="bottom" {{ $subDefaults['position'] === 'bottom' ? 'selected' : '' }}>底部（默认）</option>
                                <option value="center" {{ $subDefaults['position'] === 'center' ? 'selected' : '' }}>居中</option>
                            </select>
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            <label class="block text-xs text-slate-500">字幕风格</label>
                            <select id="subtitle_style" class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700" onchange="updateSubPreview()">
                                <option value="dynamic" {{ $subDefaults['style'] === 'dynamic' ? 'selected' : '' }}>逐字高亮（卡拉OK式·推荐）</option>
                                <option value="minimal" {{ $subDefaults['style'] === 'minimal' ? 'selected' : '' }}>纯净白字（无高亮）</option>
                                <option value="bubble" {{ $subDefaults['style'] === 'bubble' ? 'selected' : '' }}>气泡底衬（高反差场景）</option>
                            </select>
                            <p class="text-[11px] text-slate-400">逐字高亮会让读到的字变金色，字幕跟着配音走；数字人出镜与幕后音·动态画面均支持。</p>
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            <label class="block text-xs text-slate-500">字幕字体</label>
                            <select id="subtitle_font" class="w-full rounded-lg studio-card studio-card-sm text-sm text-slate-700" onchange="updateSubPreview()">
                                <option value="hei" {{ $subDefaults['font'] === 'hei' ? 'selected' : '' }}>黑体（默认·稳重）</option>
                                <option value="yahei" {{ $subDefaults['font'] === 'yahei' ? 'selected' : '' }}>微软雅黑（现代·清晰）</option>
                                <option value="kaiti" {{ $subDefaults['font'] === 'kaiti' ? 'selected' : '' }}>楷体（手写·亲和）</option>
                                <option value="song" {{ $subDefaults['font'] === 'song' ? 'selected' : '' }}>宋体（正式·传统）</option>
                                <option value="fangsong" {{ $subDefaults['font'] === 'fangsong' ? 'selected' : '' }}>仿宋（公文·规整）</option>
                            </select>
                            <p class="text-[11px] text-slate-400">像剪映一样选字幕字体；数字人出镜与幕后音·动态画面均支持。</p>
                        </div>
                    </div>
                    <div class="mt-3 flex justify-center">
                        <canvas id="subPreview" width="270" height="480" class="rounded-lg border border-slate-200 bg-slate-900 shadow-sm"></canvas>
                    </div>
                    <p class="mt-2 text-center text-[11px] text-slate-400">预览为示意，实际以出片为准</p>
                </details>

                <!-- 配音感情/快慢调节（分声线）— 已隐藏：韵律由系统按情绪自动调教（v4 定稿），
                     滑块 DOM 保留供 JS 读取默认值，避免 getElementById 报错；如需手动微调再取消 hidden -->
                <details class="rounded-lg studio-card studio-card-sm" hidden>
                    <summary class="cursor-pointer text-sm font-medium text-slate-600 select-none">🎚 配音感情 / 快慢调节（高级）</summary>
                    <p class="mt-2 text-xs text-slate-400">已启用自动韵律：语速/音调/音量/停顿由系统按内容情绪自动调节，无需手动配置。</p>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs text-slate-500"><span>男声·语速</span><span id="v_male_rate" class="font-mono text-brand-600">0.98</span></div>
                            <input type="range" id="male_rate" min="0.5" max="2" step="0.01" value="0.98" class="w-full accent-brand-500"
                                oninput="document.getElementById('v_male_rate').textContent=this.value">
                        </div>
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs text-slate-500"><span>女声·语速</span><span id="v_female_rate" class="font-mono text-brand-600">0.96</span></div>
                            <input type="range" id="female_rate" min="0.5" max="2" step="0.01" value="0.96" class="w-full accent-brand-500"
                                oninput="document.getElementById('v_female_rate').textContent=this.value">
                        </div>
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs text-slate-500"><span>男声·音调</span><span id="v_male_pitch" class="font-mono text-brand-600">0.97</span></div>
                            <input type="range" id="male_pitch" min="0.5" max="2" step="0.01" value="0.97" class="w-full accent-brand-500"
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
                </details>

                <!-- 专家自然口吻（v4 定稿：语气词按语义适配 + 自动韵律，用户只需勾选这一项） -->
                <label class="mt-2 flex items-center gap-2 rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 text-xs text-brand-700 cursor-pointer hover:bg-brand-100 transition">
                    <input type="checkbox" id="natural" class="accent-brand-500 rounded">
                    🗣 专家自然口吻<span class="hint" data-tip="女声专业发问/男声权威解答，自动匹配语气词与韵律起伏，去 AI 机械感；推荐勾选。">?</span>
                </label>

                <!-- AI 动效（仅漫剧模式显示）：图生视频真动效，画面更惊艳；每幕约0.24元/秒，5幕约6元 -->
                <label id="i2vWrap" class="mt-2 hidden items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700 cursor-pointer hover:bg-amber-100 transition">
                    <input type="checkbox" id="i2v" class="accent-amber-500 rounded">
                    🎬 AI 动效（图生视频：人物眨眼/手势/镜头推近，画面更惊艳；<b>额外计费约 6 元/条</b>）
                </label>

                <p id="quotaHint" class="text-xs text-slate-400"></p>
                <p id="durationHint" class="mt-2 text-xs text-slate-400"></p>
                <p id="queueHint" class="mt-2 text-xs text-slate-400"></p>
                <p class="flex items-start gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">
                    <span>⏱</span><span>出片约需 <b>5–15 分钟</b>（AI 动效/数字人更久）。提交后可在其他页面继续操作，完成后回来查看。</span>
                </p>
                <button type="button" id="genBtn" onclick="handleGenerate(event)"
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
                <div class="flex items-center gap-2">
                    <button id="jobLogBtn" type="button" onclick="openJobLog(currentJobId)" class="hidden rounded-full border border-slate-200 bg-white px-3 py-1 text-xs text-slate-500 hover:bg-slate-50">📋 进度记录</button>
                    <span id="statusBadge" class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-500">待生成</span>
                </div>
            </div>
            <div id="result" class="flex min-h-[320px] flex-col items-center justify-center rounded-lg bg-slate-50 text-sm text-slate-400">
                <div class="mb-2 text-3xl">🎬</div>
                <div class="font-medium">请填写文稿并点击左侧「生成视频」按钮</div>
                <div class="mt-1 text-xs">出片约需 5–15 分钟，支持离开后自动续接进度</div>
            </div>
            <div id="errorBox" class="mt-3 hidden rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600"></div>
        </section>
    </div>
</div>

<!-- 出片进度记录弹窗（纯 inline 样式，避开 Tailwind 扫描静默失效） -->
<div id="jobLogModal" style="position:fixed;inset:0;z-index:50;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.45)">
  <div style="width:100%;max-width:28rem;margin:0 1rem;background:#fff;border-radius:.75rem;box-shadow:0 20px 25px -5px rgba(0,0,0,.15);overflow:hidden">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.75rem 1rem;border-bottom:1px solid #f1f5f9">
      <h4 style="font-size:.875rem;font-weight:600;color:#334155">出片进度记录</h4>
      <button type="button" onclick="closeJobLog()" style="border:none;background:#f8fafc;border-radius:.375rem;padding:.25rem .55rem;color:#94a3b8;cursor:pointer;font-size:.875rem">✕</button>
    </div>
    <div id="jobLogBody" style="max-height:60vh;overflow-y:auto;padding:1rem;font-size:.75rem;color:#475569">加载中…</div>
  </div>
</div>

<script>
// 租户默认配置(P1-⑦): 默认声线/IP名/形象, 快捷预设与片尾品牌从配置读取(多租户各配各的)
window.__tenantDefaults = @json($defaults ?? []);   // showScroll 总注入; 兜底空对象由 JS 侧回退默认
let currentMode = 'motion';    // 默认「幕后音·动态画面」(motion 引擎), 配合 voiceForm='dialogue'(男女对话) 首屏即有高亮(P0-3 修复)
let currentJobId = null;      // 当前出片任务 job_id，供「进度记录」弹窗读取
window.__hgt_pollNow = false; // visibilitychange/手动刷新打断轮询 sleep 的标志
let titleDirty = false;       // 用户是否已手动编辑过标题
let subtitleDirty = false;    // 用户是否已手动编辑过副标题
let jobSubmitted = false;     // 是否已成功提交出片任务（提交后冻结队列提示，避免被定时刷新误报为超限）
let voiceFormManual = false;  // 用户是否手动点过声线形式按钮；手动后不再自动推断
let voiceForm = 'dialogue';   // 当前声线形式（必须在任何 IIFE/setMode 调用前初始化）
let titleStyle = 'smart';     // 标题生成策略（autoSuggest 在 IIFE 中即被调用，必须前置声明避免 TDZ）
let _suggestTimer = null;     // 文稿输入去抖定时器（事件回调引用，前置声明避免 TDZ）
let modeInitializedByUrl = false;  // IIFE 已根据 URL 参数设定初始模式时为 true，防止底部 setMode('scroll') 回退覆盖

// 统一控制生成按钮的加载态（增强状态反馈，避免用户以为没反应）
function setBtnLoading(isLoading, text) {
    const btn = document.getElementById('genBtn');
    if (!btn) return;
    if (isLoading) {
        btn.disabled = true;
        btn.classList.add('zw-btn-loading');
        // 用内联 style 避免 Tailwind 扫描不到 JS 字符串中的任意值类
        btn.innerHTML = '<span class="inline-block h-4 w-4 animate-spin rounded-full" style="margin-right:.25rem;vertical-align:-2px;border:2px solid rgba(255,255,255,0.45);border-top-color:#fff;"></span> ' + (text || '生成中…');
    } else {
        btn.disabled = false;
        btn.classList.remove('zw-btn-loading');
        btn.innerHTML = '生成视频';
    }
}

// 从二创页「带稿去出片」跳转过来时，自动填入清洗稿并推荐模式
(function () {
    const params = new URLSearchParams(window.location.search);
    const src = params.get('src') || 'original';
    const srcMap = {
        topic:    { label: '选题二创', back: '/studio/rewrite' },
        original: { label: '原始稿二创', back: '/studio/rewrite-original' },
        dissect:  { label: '爆款拆解', back: '/studio/dissect' },
        clone:    { label: '爆款复刻', back: '/studio/videos' },
    };
    const srcInfo = srcMap[src] || srcMap.original;
    // 批量二创来源：返回时回到批量二创面板（保留已改写的批量进度）
    let backUrl = srcInfo.back;
    if (params.get('batch') === '1') {
        backUrl = '/studio/rewrite?from=topic-all';
    }

    // 从「我的视频」复刻跳转：拉取原条文稿与形式自动填入（爆款复刻）
    if (src === 'clone') {
        const jobId = params.get('job_id');
        if (jobId) {
            fetch('/studio/videos/' + jobId + '/clone-data', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) return;
                const ta = document.getElementById('dialogue');
                if (ta && d.dialogue) { ta.value = d.dialogue; }
                if (d.title) {
                    const t = document.getElementById('title');
                    if (t && !t.value) { t.value = d.title; }
                }
                // 2026-08-31 修复复刻链路：d.mode 是引擎键(avatar/motion/scroll/manga/whiteboard)，
                // 旧映射表用二创展示键(scroll_male/...)导致必然 miss 静默降级；改为按引擎键映射，
                // 并从 render_config 回填声线/包装/i2v 等出片参数。
                const dm = {
                    'avatar':      { mode: 'avatar',      voiceForm: null },
                    'motion':      { mode: 'motion',      voiceForm: d.config && d.config.voice_form ? d.config.voice_form : 'male_mono' },
                    'scroll':      { mode: 'scroll',      voiceForm: d.config && d.config.voice_form ? d.config.voice_form : 'male_mono' },
                    'manga':       { mode: 'manga',       voiceForm: null },
                    'whiteboard':  { mode: 'whiteboard',  voiceForm: null }
                }[d.mode];
                if (dm) {
                    setMode(dm.mode);
                    if (dm.voiceForm) setVoiceForm(dm.voiceForm);
                    // 回填包装主题与 i2v 开关（漫剧）
                    const cfg = d.config || {};
                    if (dm.mode === 'motion' && cfg.motion_style) {
                        const ms = document.getElementById('motion_style');
                        if (ms && ms.value !== cfg.motion_style) ms.value = cfg.motion_style;
                    }
                    if (dm.mode === 'manga') {
                        const i2vBox = document.getElementById('i2v');
                        if (i2vBox) i2vBox.checked = !!cfg.i2v;
                    }
                }
                autoSuggest();
                hgtToast('info', '已带入原片文稿与形式，可直接生成或微调');
            })
            .catch(function () { hgtToast('error', '复刻数据加载失败'); });
        }
        return;
    }

    // 大文本统一走 sessionStorage；URL 只保留来源标识和模式，避免 URL 过长被服务器/浏览器拒绝
    let cleaned = '';
    if (params.get('from') === 'rewrite' || src === 'topic' || src === 'original') {
        cleaned = sessionStorage.getItem('hgt_rewrite_cleaned') || '';
    }
    if (!cleaned && src === 'matrix') {
        cleaned = sessionStorage.getItem('hgt_matrix_cleaned') || '';
    }
    if (!cleaned && src === 'dissect') {
        cleaned = sessionStorage.getItem('hgt_dissect_text') || '';
    }
    const mode = params.get('mode') || sessionStorage.getItem('hgt_rewrite_mode') || sessionStorage.getItem('hgt_matrix_mode') || '';

    if (cleaned) {
        const ta = document.getElementById('dialogue');
        if (ta) { ta.value = cleaned; }
        autoSuggest();

        let recommendedMode = 'scroll';
        let reason = '';

        // 新呈现形式值：直接决定出片模式与声线形式，不再按文本反推
        const displayModeMap = {
            'avatar':        { mode: 'avatar', voiceForm: null,          label: '单人数字人出镜' },
            'motion':        { mode: 'motion', voiceForm: 'male_mono',   label: '幕后音·动态画面' },
            'scroll':        { mode: 'scroll', voiceForm: 'male_mono',   label: '幕后音·滚动字幕' },
            'manga':         { mode: 'manga', voiceForm: null,           label: 'AI 漫剧' },
            'whiteboard':    { mode: 'whiteboard', voiceForm: null,      label: 'AI 白板图解' },
            // 兼容旧值（动态画面曾拆 3 项声线）→ 统一 motion，声线出片页再选
            'scroll_male':   { mode: 'motion', voiceForm: 'male_mono',   label: '男声幕后音·动态画面' },
            'scroll_female': { mode: 'motion', voiceForm: 'female_mono', label: '女声幕后音·动态画面' },
            'scroll_dual':   { mode: 'motion', voiceForm: 'dialogue',    label: '男女对话幕后音·动态画面' }
        };

        if (displayModeMap[mode]) {
            recommendedMode = displayModeMap[mode].mode;
            reason = '已在「' + srcInfo.label + '」选择「' + displayModeMap[mode].label + '」，已自动匹配出片模式';
            setMode(recommendedMode);
            modeInitializedByUrl = true;
            if ((recommendedMode === 'scroll' || recommendedMode === 'motion') && displayModeMap[mode].voiceForm) {
                setVoiceForm(displayModeMap[mode].voiceForm);
            }
        } else {
            // 兼容旧值 / 无模式：按文本自动推断
            const lines = cleaned.split('\n').filter(l => l.trim());
            const dialogueLines = lines.filter(l => /^(女|男)[：:]/.test(l.trim()));
            const isDialogue = lines.length > 0 && dialogueLines.length >= Math.min(3, lines.length * 0.6);

            if (mode === 'dual' && isDialogue) {
                recommendedMode = 'scroll';
                reason = '检测到对话格式 + 双声改写模式，推荐「幕后音·动态画面」（男女对话）';
            } else if (mode === 'dual' && !isDialogue) {
                recommendedMode = 'scroll';
                reason = '双声改写但文本非标准对话格式，推荐「幕后音·动态画面」';
            } else if (mode === 'single' || mode === 'script') {
                recommendedMode = 'avatar';
                reason = '单声口播模式，适合「数字人出镜」（单人独白）';
            }
            setMode(recommendedMode);
            modeInitializedByUrl = true;
            applyVoiceFormAuto(cleaned);
        }

        // 推荐模式提示
        const hint = document.createElement('div');
        hint.className = 'mb-4 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-700';
        hint.innerHTML = '<div class="flex items-center justify-between"><span>已从「' + srcInfo.label + '」带入清洗稿。' +
            (reason ? '<br><span class="text-xs text-brand-600 mt-1 inline-block">' + reason + '</span>' : '') +
            '</span></div>' +
            '<a href="' + backUrl + '" class="font-medium underline hover:text-brand-900 text-xs">← 返回二创</a>';
        document.querySelector('header').after(hint);

        // 如果是 avatar 模式，触发一次校验
        if (recommendedMode === 'avatar') {
            checkDialogueFormat(cleaned, document.getElementById('formatWarning'));
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
    highlightForm();

    // 模特区域切换：仅数字人(avatar)需要选模特，其余形式显示"无需模特"提示
    document.getElementById('modelHint').classList.toggle('hidden', m === 'avatar');
    document.getElementById('modelSelectWrap').classList.toggle('hidden', m !== 'avatar');
    // 声线形式控件：仅滚动字幕模式显示；数字人/漫剧模式隐藏
    document.getElementById('voiceFormWrap').classList.toggle('hidden', m !== 'scroll');
    // 声线选择：数字人/漫剧=单声线下拉；滚动字幕=由 setVoiceForm 决定
    const singleVW = document.getElementById('singleVoiceWrap');
    const dualVW = document.getElementById('dualVoiceWrap');
    if (m === 'avatar' || m === 'manga' || m === 'whiteboard') {
        singleVW.classList.remove('hidden');
        dualVW.classList.add('hidden');
    } else {
        setVoiceForm(voiceForm);   // 内部会正确设置 single/dual 的显隐
    }

    // AI 动效开关: 仅漫剧模式显示(漫剧默认关=低成本Ken Burns, 勾选=图生视频惊艳动效)
    const i2vWrap = document.getElementById('i2vWrap');
    if (i2vWrap) {
        i2vWrap.classList.toggle('hidden', m !== 'manga');
        i2vWrap.classList.toggle('flex', m === 'manga');
    }

    // 文本格式提示与校验
    const label = document.getElementById('dialogueLabel');
    const hint = document.getElementById('formatHint');
    const warning = document.getElementById('formatWarning');
    const ta = document.getElementById('dialogue');

    if (m === 'avatar') {
        // 数字人出镜：统一单人独白（取消男女对话），角色前缀自动忽略
        label.innerHTML = '独白文稿（<span class="text-slate-400">单人单声，直接写文案即可；无需 女：/男： 前缀</span>）';
        hint.innerHTML = '<span class="text-emerald-600">单人独白模式</span>';
        hint.className = 'text-[11px] font-normal text-emerald-600';
        checkDialogueFormat(ta.value, warning);
    } else if (m === 'manga') {
        // AI 漫剧: 输入财税内容/事件, AI 自动生成剧情分镜与旁白
        label.innerHTML = '财税内容（<span class="text-slate-400">输入要讲的财税问题 / 事件 / 案例，AI 自动生成剧情分镜与旁白；法条政策类自动改走口播</span>）';
        hint.innerHTML = '<span class="text-emerald-600">AI 漫剧：内容 → 分镜 → 生图 → 配音 全自动</span>';
        hint.className = 'text-[11px] font-normal text-emerald-600';
        warning.classList.add('hidden');
    } else if (m === 'whiteboard') {
        // AI 白板图解: 输入财税内容, AI 提炼标题/要点/警示, 手绘逐笔画出
        label.innerHTML = '财税内容（<span class="text-slate-400">输入要讲的财税知识点 / 流程 / 风险，AI 自动提炼成白板要点图解</span>）';
        hint.innerHTML = '<span class="text-emerald-600">AI 白板：内容 → 要点提炼 → 手绘逐笔动画 → 配音 全自动</span>';
        hint.className = 'text-[11px] font-normal text-emerald-600';
        warning.classList.add('hidden');
    } else {
        // 幕后音·动态画面：接受任意格式
        label.innerHTML = '文稿内容（<span class="text-slate-400">支持对话 / 独白 / 改写稿，自动适配</span>）';
        hint.innerHTML = '<span class="text-emerald-600">任意格式均可</span>';
        hint.className = 'text-[11px] font-normal text-emerald-600';
        warning.classList.add('hidden');
    }
}

// 出片形式快捷按钮组：核心形式 + 动态画面声线细项
const FORM_MAP = {
    avatar:      { mode: 'avatar', vf: null },
    motion:      { mode: 'motion', vf: null },
    scroll:      { mode: 'scroll', vf: 'male_mono' },
    manga:       { mode: 'manga', vf: null },
    whiteboard:  { mode: 'whiteboard', vf: null },
    male_mono:   { mode: 'motion', vf: 'male_mono' },
    female_mono: { mode: 'motion', vf: 'female_mono' },
    dialogue:    { mode: 'motion', vf: 'dialogue' },
};
function selectForm(form) {
    const cfg = FORM_MAP[form];
    if (!cfg) return;
    setMode(cfg.mode);
    if (cfg.vf) setVoiceForm(cfg.vf);
    highlightForm();
}
function highlightForm() {
    document.querySelectorAll('.form-btn').forEach(b => {
        const f = b.dataset.form;
        const isMotion = currentMode === 'motion';
        let active = false;
        if (f === 'avatar') active = currentMode === 'avatar';
        else if (f === 'motion') active = isMotion;                          // 动态画面大按钮：模式高亮
        else if (f === 'manga') active = currentMode === 'manga';
        else if (f === 'whiteboard') active = currentMode === 'whiteboard';
        else if (f === 'scroll') active = currentMode === 'scroll';
        else if (f === 'male_mono' || f === 'female_mono' || f === 'dialogue') active = isMotion && voiceForm === f;  // 声线细项
        b.className = 'form-btn rounded-lg px-4 py-2 text-sm font-medium transition ' +
            (active ? 'border border-brand-500 bg-brand-50 text-brand-700'
                    : 'border border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:text-slate-700');
    });
}

// 声线形式切换（滚动字幕模式）：男女对话 / 男声独白 / 女声独白

// 根据文稿内容自动推断合适的声线形式
function detectVoiceForm(text) {
    const lines = (text || '').split('\n').map(l => l.trim()).filter(Boolean);
    if (!lines.length) return voiceForm;
    const femaleLines = lines.filter(l => /^女[：:]/.test(l)).length;
    const maleLines = lines.filter(l => /^男[：:]/.test(l)).length;
    // 对话：必须同时存在男女声前缀，且角色行占比足够
    if (femaleLines > 0 && maleLines > 0 && (femaleLines + maleLines) >= Math.min(3, lines.length * 0.6)) {
        return 'dialogue';
    }
    // 全为一种性别前缀：推荐对应独白
    if (femaleLines > 0) return 'female_mono';
    if (maleLines > 0) return 'male_mono';
    // 无角色前缀：不假设性别，交给租户从全部已克隆声音中自选（单声线下拉）
    return 'mono';
}
function applyVoiceFormAuto(text) {
    if (voiceFormManual || (currentMode !== 'scroll' && currentMode !== 'motion')) return;
    const inferred = detectVoiceForm(text);
    if (inferred !== voiceForm) setVoiceForm(inferred);
}
function setVoiceForm(f) {
    voiceForm = f;
    document.querySelectorAll('.voice-form-btn').forEach(b => {
        const on = b.dataset.form === f;
        b.className = 'voice-form-btn rounded-lg border px-3 py-1.5 text-xs font-medium transition ' +
            (on ? 'border-brand-400 bg-brand-500 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300');
    });
    const dualVW = document.getElementById('dualVoiceWrap');
    const singleVW = document.getElementById('singleVoiceWrap');
    const maleWrap = document.getElementById('maleVoice').parentNode;
    const femaleWrap = document.getElementById('femaleVoice').parentNode;
    const hint = document.getElementById('voiceFormHint');
    const voiceLabel = document.getElementById('voiceLabel');
    const quickSingle = document.getElementById('quickVoiceSingle');
    const quickDialogue = document.getElementById('quickVoiceDialogue');
    const voiceHintText = document.getElementById('voiceHintText');

    // 快捷按钮容器切换
    if (quickSingle && quickDialogue) {
        if (f === 'dialogue') {
            quickSingle.classList.add('hidden');
            quickDialogue.classList.remove('hidden');
        } else {
            quickSingle.classList.remove('hidden');
            quickDialogue.classList.add('hidden');
        }
    }

    if (f === 'mono') {
        // 单声线（不限性别）：从全部已克隆声音中任选一个
        singleVW.classList.remove('hidden');
        dualVW.classList.add('hidden');
        if (voiceLabel) voiceLabel.textContent = '配音声线';
        hint.textContent = '单声线：从全部已克隆声音中任选一个，不限性别；适合无「女：/男：」前缀的独白稿。';
        if (voiceHintText) voiceHintText.textContent = '选择一条声线用于整段独白配音。';
    } else {
        // 对话 / 男声独白 / 女声独白：使用男+女双下拉容器
        dualVW.classList.remove('hidden');
        singleVW.classList.add('hidden');
        if (f === 'male_mono') {
            maleWrap.classList.remove('hidden'); femaleWrap.classList.add('hidden');
            if (voiceLabel) voiceLabel.textContent = '配音声线';
            hint.textContent = '男声独白：整段用男声单口播，无需角色前缀（若文稿含「女：/男：」前缀将自动忽略）。';
            if (voiceHintText) voiceHintText.textContent = '整段用所选男声配音。';
        } else if (f === 'female_mono') {
            maleWrap.classList.add('hidden'); femaleWrap.classList.remove('hidden');
            if (voiceLabel) voiceLabel.textContent = '配音声线';
            hint.textContent = '女声独白：整段用女声单口播，无需角色前缀（若文稿含「女：/男：」前缀将自动忽略）。';
            if (voiceHintText) voiceHintText.textContent = '整段用所选女声配音。';
        } else {
            // dialogue — 男女对话
            maleWrap.classList.remove('hidden'); femaleWrap.classList.remove('hidden');
            if (voiceLabel) voiceLabel.textContent = '角色配音';
            hint.textContent = '男女对话：每行以「女：」「男：」开头，分别用下方选择的女声/男声配音。';
            if (voiceHintText) voiceHintText.textContent = '「女：」行用右侧女声，「男：」行用左侧男声；可点击上方常用组合一键填入。';
        }
    }
}
document.querySelectorAll('.voice-form-btn').forEach(b => {
    b.addEventListener('click', () => {
        voiceFormManual = true;
        setVoiceForm(b.dataset.form);
    });
});

// 检测文本格式并给出温和提示（数字人模式为单人独白，对话前缀会被忽略）
function checkDialogueFormat(text, warnEl) {
    if (!warnEl) return;
    if (currentMode === 'avatar') {
        // 数字人 = 单人独白：角色前缀将被忽略、统一用所选单一声线
        warnEl.textContent = '数字人出镜为单人独白模式：若文稿含「女：/男：」对话前缀，将自动忽略并统一用所选单一声线配音。';
        warnEl.className = 'mt-1 hidden rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs text-amber-700';
        warnEl.classList.remove('hidden');
        return;
    }
    const lines = text.split('\n').filter(l => l.trim());
    const dialogueLines = lines.filter(l => /^(女|男)[：:]/.test(l.trim()));
    if (lines.length === 0) {
        warnEl.classList.add('hidden');
        return;
    }
    if (dialogueLines.length === lines.length) {
        // 纯双声对话
        warnEl.textContent = '已识别男女双声对话：女：行用女声、男：行用男声，分别用对应克隆音配音。';
        warnEl.className = 'mt-1 hidden rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs text-emerald-700';
        warnEl.classList.remove('hidden');
    } else if (dialogueLines.length === 0) {
        // 纯独白
        warnEl.textContent = '检测到独白文本：将用所选单一声线（男声/女声）统一配音。';
        warnEl.className = 'mt-1 hidden rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs text-amber-700';
        warnEl.classList.remove('hidden');
    } else {
        // 混合
        warnEl.textContent = '混合格式：含 女：/男： 的行按角色分声线，其余行用默认男声。单人独白请在上方选「男声独白/女声独白」。';
        warnEl.className = 'mt-1 hidden rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs text-amber-700';
        warnEl.classList.remove('hidden');
    }
}

// 文本输入时实时检测格式与声线形式
document.getElementById('dialogue')?.addEventListener('input', function () {
    if (currentMode === 'avatar') {
        checkDialogueFormat(this.value, document.getElementById('formatWarning'));
    }
    applyVoiceFormAuto(this.value);
    updateDurationHint();
});

// 根据文稿内容自动生成标题 / 副标题建议（客户端启发式，可人工覆盖）
function stripRolePrefix(line) {
    return line.replace(/^(女|男|旁白|解说|主播|画外音|独白|配音)[:：]\s*/, '');
}
function suggestTitleSmart(text) {
    const lines = text.split('\n').map(l => l.trim()).filter(Boolean);
    if (!lines.length) return '';
    let first = stripRolePrefix(lines[0]).replace(/[\s，。！？!?；;、…\.,]+/g, '');
    return first.slice(0, 10);
}
function suggestTitleFull(text) {
    const lines = text.split('\n').map(l => l.trim()).filter(Boolean);
    if (!lines.length) return '';
    // 首句完整句（去角色前缀、合并空白）；2026-08-28 与输入框 maxlength=15 对齐
    let first = stripRolePrefix(lines[0]).replace(/\s+/g, ' ').trim();
    return first.slice(0, 15);
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
    for (const t of tpl) if (t.length <= 15) return t;   // 与 maxlength=15 对齐
    return tpl[0].slice(0, 15);
}
function suggestTitle(text) {
    if (titleStyle === 'full') return suggestTitleFull(text);
    if (titleStyle === 'suspense') return suggestTitleSuspense(text);
    return suggestTitleSmart(text);
}
function suggestSubtitle(text) {
    const lines = text.split('\n').map(l => l.trim()).filter(Boolean);
    if (!lines.length) return '';
    // 合并前两句（去角色前缀），截 30 字以内（与输入框 maxlength=30 对齐）
    let body = lines.slice(0, 2).map(stripRolePrefix).join(' ');
    body = body.replace(/[\s，。！？!?；;、…\.,]+/g, ' ').trim();
    return body.slice(0, 30);
}

// AI 智能生成标题+副标题：调用后端 /studio/scroll/suggest-title（代理到 8500）
async function aiSuggestTitle() {
    const btn = document.getElementById('aiTitleBtn');
    const hint = document.getElementById('aiTitleHint');
    const text = document.getElementById('dialogue').value.trim();
    if (!text) {
        if (hint) { hint.textContent = '请先在上方填写文稿，再生成标题'; hint.className = 'text-[11px] text-red-500'; }
        return;
    }
    // 若已有内容，先确认覆盖，避免用户感觉按钮"没反应"
    const existingTitle = document.getElementById('title').value.trim();
    const existingSubtitle = document.getElementById('subtitle').value.trim();
    if ((existingTitle || existingSubtitle) && !confirm('当前已有标题/副标题，重新生成将覆盖现有内容，是否继续？')) {
        return;
    }
    titleDirty = false;
    subtitleDirty = false;
    zwSetLoading(btn, { loading: true, text: '⏳ AI 生成中…' });
    if (hint) { hint.textContent = 'AI 正在根据文稿构思标题与副标题…'; hint.className = 'text-[11px] text-brand-600'; }
    const signal = HGTAbort.begin('中止：AI 标题生成中…');
    try {
        const resp = await fetch('/studio/scroll/suggest-title', {
            method: 'POST',
            signal,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({ dialogue: text, style: titleStyle })
        });
        const data = await resp.json();
        if (!resp.ok || data.error) {
            throw new Error(data.error || ('生成失败（HTTP ' + resp.status + '）'));
        }
        // AI 结果优先，不再用本地启发式覆盖；截断到输入框上限(maxlength=15/30)防后端422
        if (data.title) { document.getElementById('title').value = String(data.title).slice(0, 15); titleDirty = true; }
        if (data.subtitle) { document.getElementById('subtitle').value = String(data.subtitle).slice(0, 30); subtitleDirty = true; }
        // AI 结果优先，不再用本地启发式覆盖
        if (hint) { hint.textContent = '✓ AI 已生成（' + {smart:'智能提取', full:'首句完整', suspense:'悬念式'}[titleStyle] + '），可直接修改'; hint.className = 'text-[11px] text-emerald-600'; }
    } catch (err) {
        if (err.name === 'AbortError') { if (hint) { hint.textContent = '⏹ 已中止生成'; hint.className = 'text-[11px] text-slate-500'; } }
        else if (hint) { hint.textContent = '生成失败：' + (err.message || '未知错误'); hint.className = 'text-[11px] text-red-500'; }
    } finally {
        zwSetLoading(btn, { loading: false });
        HGTAbort.end();
    }
}
document.getElementById('aiTitleBtn')?.addEventListener('click', aiSuggestTitle);
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
// 标题风格下拉：切换策略时，若未手动改过标题则按新风格重新生成
document.getElementById('titleStyle')?.addEventListener('change', function () {
    const prev = titleStyle;
    titleStyle = this.value;
    // 切换风格时若已有手动/AI内容，先确认覆盖
    const hasContent = document.getElementById('title').value.trim() || document.getElementById('subtitle').value.trim();
    if ((titleDirty || subtitleDirty) && hasContent) {
        if (!confirm('切换风格将按新风格重新生成标题/副标题并覆盖当前内容，是否继续？')) { this.value = prev; titleStyle = prev; return; }
    }
    titleDirty = false;
    subtitleDirty = false;
    autoSuggest();
    const hint = document.getElementById('aiTitleHint');
    if (hint) { hint.textContent = '已按「' + {smart:'智能提取', full:'首句完整', suspense:'悬念式'}[titleStyle] + '」风格生成本地建议，可点右侧按钮用 AI 重新生成'; hint.className = 'text-[11px] text-brand-600'; }
});
// 文稿变化时（去抖 300ms）自动生成建议
document.getElementById('dialogue')?.addEventListener('input', () => {
    clearTimeout(_suggestTimer);
    _suggestTimer = setTimeout(autoSuggest, 300);
});

// 实时预估视频时长（与后端 estimateDurationSec 算法一致：2.4 字/秒 ≈ 145 字/分钟）
function estimateDuration() {
    const text = document.getElementById('dialogue').value;
    let chars = 0;
    text.split('\n').forEach(raw => {
        let line = raw.trim();
        if (!line) return;
        if (/^(女|男)[:：]/.test(line)) line = line.slice(2);
        chars += line.replace(/\s/g, '').length;
    });
    return Math.max(1, Math.round(chars / 2.4));
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

// 出片队列预估：展示「当前队列数 + 预计等待分钟」，并在租户达并发上限时提示
async function fetchQueueEstimate() {
    if (jobSubmitted) return;   // 已提交则冻结，避免把「自己刚提交的任务」误报为超限
    const el = document.getElementById('queueHint');
    if (!el) return;
    try {
        const resp = await fetch('/studio/scroll/queue-estimate', {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });
        if (!resp.ok) return;
        const d = await resp.json();
        if (!d.will_accept) {
            el.className = 'mt-2 text-xs font-medium text-red-600';
            el.textContent = '⚠ 您账号已有 ' + d.tenant_queued + ' 条进行中，达并发上限（' + d.tenant_max + '），请等待完成后再提交（预计约 ' + d.est_wait_min + ' 分钟）。';
        } else if (d.global_queued === 0) {
            el.className = 'mt-2 text-xs text-emerald-600';
            el.textContent = '✓ 当前无排队，提交后通常立即开始渲染（单条约 ' + d.avg_render_min + ' 分钟）。';
        } else {
            el.className = 'mt-2 text-xs text-brand-600';
            el.textContent = '当前全平台有 ' + d.global_queued + ' 条任务在队列（系统同时处理 ' + d.concurrency + ' 条，单条约 ' + d.avg_render_min + ' 分钟），您提交后预计等待约 ' + d.est_wait_min + ' 分钟。';
        }
    } catch (e) { /* 静默：队列提示非关键路径 */ }
}
if (!modeInitializedByUrl) setMode('scroll');   // URL 已指定模式时不再回退为 scroll

// 拆解 / 二创来源预填音色：voice=zhang（老张真声）/ voice=jiang（江老师克隆声）/ 具体 voice_id
(function prefillVoice() {
    const p = new URLSearchParams(location.search);
    const voiceParam = p.get('voice');
    if (!voiceParam) return;
    const sv = document.getElementById('singleVoice');
    if (!sv) return;
    let needle = voiceParam;
    if (voiceParam === 'zhang') needle = TD.maleVoice || 'zhangc2';
    else if (voiceParam === 'jiang') needle = TD.femaleVoice || 'jiangnv3';
    for (const opt of sv.options) {
        if (opt.value.includes(needle)) { sv.value = opt.value; break; }
    }
    highlightQuickVoice(voiceParam === 'zhang' ? 'zhang' : (voiceParam === 'jiang' ? 'jiang' : null));
})();

// 常用声线快捷切换按钮：老张（本人真声）/ 江老师（克隆声）
function highlightQuickVoice(which) {
    document.querySelectorAll('.quick-voice-btn').forEach(b => {
        b.className = 'quick-voice-btn rounded-lg border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600 hover:border-brand-300';
    });
    if (which === 'zhang') {
        const b = document.getElementById('quickVoiceZhang');
        if (b) b.className = 'quick-voice-btn rounded-lg border border-brand-400 bg-brand-500 px-3 py-1 text-xs font-medium text-white';
    } else if (which === 'jiang') {
        const b = document.getElementById('quickVoiceJiang');
        if (b) b.className = 'quick-voice-btn rounded-lg border border-brand-400 bg-brand-500 px-3 py-1 text-xs font-medium text-white';
    }
}

// 常用预设档：整组套用「出片形式 + 配音声线」，一步到位
// P1-⑦ 多租户SaaS: 声线用租户默认(tenants.default_male/female_voice), 不再硬编码老张/江老师
const TD = window.__tenantDefaults || {};
const PRESETS = {
    male_avatar: { form: 'avatar',      voice: TD.maleVoice || 'zhangc2', who: 'male' },   // 默认男声·单人数字人出镜
    female_mono: { form: 'female_mono', voice: TD.femaleVoice || 'jiangnv3', who: 'female' }, // 默认女声·幕后音
    male_mono:   { form: 'male_mono',   voice: TD.maleVoice || 'zhangc2', who: 'male' },   // 默认男声·幕后音
};
function applyPreset(key) {
    const p = PRESETS[key];
    if (!p) return;
    selectForm(p.form);   // 设出片形式 + 声线形式并高亮
    const sv = document.getElementById('singleVoice');
    if (sv) {
        for (const opt of sv.options) {
            if (opt.value.includes(p.voice)) { sv.value = opt.value; break; }
        }
    }
    highlightQuickVoice(p.who);
    highlightPreset(key);
}
function highlightPreset(key) {
    document.querySelectorAll('.preset-btn').forEach(b => {
        const on = b.dataset.preset === key;
        b.className = 'preset-btn rounded-lg border px-3 py-1.5 text-xs font-medium transition ' +
            (on ? 'border-brand-400 bg-brand-500 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300');
    });
}
function applyQuickVoice(needle, which, btn) {
    // 对话模式下快捷按钮不适用（对话需两个声线，用组合按钮），跳过
    if ((currentMode === 'scroll' || currentMode === 'motion') && voiceForm === 'dialogue') return;
    const sv = document.getElementById('singleVoice');
    if (!sv) return;
    let hit = false;
    for (const opt of sv.options) {
        if (opt.value.includes(needle)) { sv.value = opt.value; hit = true; break; }
    }
    if (hit) highlightQuickVoice(which);
}
document.getElementById('quickVoiceZhang').addEventListener('click', e => applyQuickVoice(TD.maleVoice || 'zhangc2', 'male', e.currentTarget));
document.getElementById('quickVoiceJiang').addEventListener('click', e => applyQuickVoice(TD.femaleVoice || 'jiangnv3', 'female', e.currentTarget));
// 手动改单人声线下拉时同步高亮
document.getElementById('singleVoice').addEventListener('change', () => {
    const v = document.getElementById('singleVoice').value;
    if (v.includes(TD.maleVoice || 'zhangc2')) highlightQuickVoice('male');
    else if (v.includes(TD.femaleVoice || 'jiangnv3')) highlightQuickVoice('female');
    else highlightQuickVoice(null);
});

// ── 对话模式组合快捷按钮：一键填入男声+女声 ──
function highlightQuickCombo(activeId) {
    document.querySelectorAll('.quick-combo-btn').forEach(b => {
        const on = b.id === activeId;
        b.className = 'quick-combo-btn rounded-lg border px-3 py-1 text-xs font-medium transition ' +
            (on ? 'border-brand-400 bg-brand-500 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300');
    });
}
/**
 * 填入对话组合：同时设置 maleVoice + femaleVoice 下拉框
 * @param {string} maleNeedle  男声下拉匹配关键词（如 'zhangc2'）
 * @param {string} femaleNeedle 女声下拉匹配关键词（如 'jiangnv3'）
 * @param {string} comboId     当前激活的按钮 id
 */
function applyVoiceCombo(maleNeedle, femaleNeedle, comboId) {
    const maleSel = document.getElementById('maleVoice');
    const femaleSel = document.getElementById('femaleVoice');
    if (maleSel) {
        for (const opt of maleSel.options) {
            if (opt.value.includes(maleNeedle)) { maleSel.value = opt.value; break; }
        }
    }
    if (femaleSel) {
        for (const opt of femaleSel.options) {
            if (opt.value.includes(femaleNeedle)) { femaleSel.value = opt.value; break; }
        }
    }
    highlightQuickCombo(comboId);
}
document.getElementById('quickComboZJ').addEventListener('click', () => applyVoiceCombo(TD.maleVoice || 'zhangc2', TD.femaleVoice || 'jiangnv3', 'quickComboZJ'));
document.getElementById('quickComboZZ').addEventListener('click', () => applyVoiceCombo(TD.maleVoice || 'zhangc2', '', 'quickComboZZ'));
document.getElementById('quickComboJJ').addEventListener('click', () => applyVoiceCombo('', TD.femaleVoice || 'jiangnv3', 'quickComboJJ'));

updateDurationHint();
autoSuggest();
applyVoiceFormAuto(document.getElementById('dialogue').value);
updateSubPreview();
// 出片队列预估：初始拉一次，并每 20 秒刷新（队列动态变化）
fetchQueueEstimate();
setInterval(fetchQueueEstimate, 20000);

function resetVoice() {
    // v4 定稿默认（与 make_scroll_video.py 保持一致）：女声 0.96 略慢、男声音调 0.97 不闷
    const defs = {male_rate:0.98, female_rate:0.96, male_pitch:0.97, female_pitch:1.02, male_vol:53, female_vol:49};
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
    const subStyle = document.getElementById('subtitle_style')?.value || 'dynamic';
    const fontKey = document.getElementById('subtitle_font')?.value || 'hei';
    const FONT_CSS = {hei:'SimHei', yahei:'Microsoft YaHei', kaiti:'KaiTi', song:'SimSun', fangsong:'FangSong'};
    const fontPx = Math.max(8, Math.round(size * sx));
    ctx.font = '700 ' + fontPx + 'px "' + (FONT_CSS[fontKey] || 'SimHei') + '", sans-serif';
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
    // 计算每行像素宽度，供 bubble 底衬使用
    function lineWidthPx(ln) {
        if (outline > 0) {
            ctx.lineWidth = Math.max(1, Math.round(outline * sx * (size / 92)));
            return ctx.measureText(ln).width + ctx.lineWidth * 2;
        }
        return ctx.measureText(ln).width;
    }
    for (let i = 0; i < lines.length; i++) {
        const y = startY + i * gap;
        const lw = lineWidthPx(lines[i]);
        // bubble 风格：半透明圆角底衬
        if (subStyle === 'bubble') {
            const padX = 16 * sx, padY = 10 * sx;
            try {
                ctx.fillStyle = 'rgba(0,0,0,0.55)';
                roundRect(ctx, x - padX, y - fontPx * 0.62 - padY, lw + padX * 2, fontPx + padY * 2, 14 * sx);
                ctx.fill();
            } catch (e) { /* 旧浏览器回退 */ }
        }
        if (outline > 0) {
            ctx.lineWidth = Math.max(1, Math.round(outline * sx * (size / 92)));
            ctx.strokeStyle = 'rgba(0,0,0,0.9)';
            ctx.strokeText(lines[i], x, y);
        }
        // dynamic：前半句金色高亮示意「逐字跟随」；其余白
        if (subStyle === 'dynamic') {
            const hl = Math.floor(lines[i].length * 0.45);
            ctx.fillStyle = '#FFD400';
            ctx.fillText(lines[i].slice(0, hl), x, y);
            ctx.fillStyle = '#ffffff';
            ctx.fillText(lines[i].slice(hl), x + ctx.measureText(lines[i].slice(0, hl)).width, y);
        } else {
            ctx.fillStyle = '#ffffff';
            ctx.fillText(lines[i], x, y);
        }
    }
}
// canvas 圆角矩形兼容 helper
function roundRect(ctx, x, y, w, h, r) {
    if (ctx.roundRect) { ctx.beginPath(); ctx.roundRect(x, y, w, h, r); return; }
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
}

async function handleGenerate(e) {
    if (e && typeof e.preventDefault === 'function') e.preventDefault();
    const msg = document.getElementById('formMsg');
    const badge = document.getElementById('statusBadge');
    const result = document.getElementById('result');
    const errBox = document.getElementById('errorBox');
    if (!msg || !badge || !result || !errBox) {
        alert('页面初始化异常：缺少必要元素。请刷新页面（Ctrl+F5）后再试。');
        return;
    }
    msg.textContent = ''; errBox.classList.add('hidden');

    // 本地预校验：文稿为空时拦截
    const dialogueEl = document.getElementById('dialogue');
    if (!dialogueEl) {
        errBox.textContent = '未找到文稿输入框，请刷新页面后再试。';
        errBox.classList.remove('hidden');
        return;
    }
    const dialogue = dialogueEl.value.trim();
    if (!dialogue) {
        msg.textContent = '⚠ 请输入文稿内容（必填）。单声口播直接写文案；双声对话每行以「女：」「男：」开头。';
        errBox.innerHTML = '<strong>提交失败：文稿为空</strong><br><span class="text-xs mt-1 block text-red-400">「文稿内容」是必填项，请先撰写或从「二创」（选题二创 / 原始稿二创）带入改写稿后再提交出片。</span>';
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

    setBtnLoading(true, '正在提交…');
    badge.textContent = '排队中'; badge.className = 'rounded-full bg-amber-100 px-3 py-1 text-xs text-amber-700';
    result.innerHTML = renderProgressCard({
        title: '正在连接出片服务…',
        hint: '提交成功后进入排队队列',
        step: 'queued',
        stage: 0,
        percent: 5,
        elapsedSec: 0,
        etaSec: null,
        qpos: 0,
        isSkeleton: true
    });
    startProgressTimer();

    const signal = HGTAbort.begin('中止：出片进行中…', {
        onAbort: function () {
            stopProgressTimer();
            if (typeof currentJobId !== 'undefined' && currentJobId) {
                var m = document.querySelector('meta[name="csrf-token"]');
                var tk = m ? m.content : '';
                fetch('/studio/scroll/cancel?job=' + currentJobId, {
                    method: 'POST', keepalive: true,
                    headers: { 'X-CSRF-TOKEN': tk, 'Content-Type': 'application/json' },
                    body: JSON.stringify({})
                }).catch(function () {});
            }
        }
    });
    try {
        const resp = await fetch('/studio/scroll/generate', {
            method: 'POST',
            signal,
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
                motion_style: document.getElementById('motion_style')?.value || '财经严谨',
                edit_style: (currentMode === 'manga' || currentMode === 'whiteboard') ? '' : (document.getElementById('edit_style')?.value || ''),
                dry_tts: false,
                // 韵律参数不向前端发送：声调/快慢/音量由后端脚本按情绪自动调教（v4 定稿），
                // 避免前端硬编码默认值覆盖专业调好的自动韵律
                natural: document.getElementById('natural').checked,
                male_voice: (currentMode === 'avatar' || currentMode === 'manga' || currentMode === 'whiteboard' || (currentMode === 'scroll' && voiceForm === 'mono'))
                    ? (document.getElementById('singleVoice').value || null)
                    : (document.getElementById('maleVoice').value || null),
                female_voice: (currentMode === 'avatar' || currentMode === 'manga' || currentMode === 'whiteboard' || (currentMode === 'scroll' && voiceForm === 'mono'))
                    ? null
                    : (document.getElementById('femaleVoice').value || null),
                voice_form: currentMode === 'avatar' || currentMode === 'manga' || currentMode === 'whiteboard' ? null : voiceForm,
                i2v: currentMode === 'manga' ? (document.getElementById('i2v')?.checked || false) : false,
                model: currentMode === 'avatar' ? (document.getElementById('model').value || null) : null,
                cover_id: document.getElementById('coverId').value ? parseInt(document.getElementById('coverId').value, 10) : null,
                subtitle_size: parseInt(document.getElementById('subtitle_size').value, 10),
                subtitle_lines: parseInt(document.getElementById('subtitle_lines').value, 10),
                subtitle_outline: parseInt(document.getElementById('subtitle_outline').value, 10),
                subtitle_position: document.getElementById('subtitle_position').value,
                subtitle_style: document.getElementById('subtitle_style').value,
                subtitle_font: document.getElementById('subtitle_font')?.value || 'hei',
                industry: sessionStorage.getItem('hgt_rewrite_industry') || '',
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
        currentJobId = data.job_id;
        document.getElementById('jobLogBtn')?.classList.remove('hidden');
        jobSubmitted = true;
        const qh = document.getElementById('queueHint');
        if (qh) { qh.className = 'mt-2 text-xs text-emerald-600'; qh.textContent = '✓ 任务已提交，已进入出片队列，系统将按顺序渲染。'; }
        setBtnLoading(true, '出片中…');
        result.innerHTML = renderProgressCard({
            title: '出片任务已提交',
            hint: '正在真实配音合成，可先去其他页面',
            step: 'editing',
            stage: 1,
            percent: 20,
            elapsedSec: 0,
            etaSec: null,
            qpos: 0,
            isSkeleton: true
        });
        startProgressTimer();
        pollStatus(data.job_id);
    } catch (err) {
        stopProgressTimer();
        if (err.name === 'AbortError') {
            setBtnLoading(false);
            badge.textContent = '已中止'; badge.className = 'rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-500';
            errBox.textContent = '⏹ 已中止出片。'; errBox.classList.remove('hidden');
            hgtToast('warn', '已中止出片');
            HGTAbort.end();
            return;
        }
        setBtnLoading(false);
        badge.textContent = '失败'; badge.className = 'rounded-full bg-red-100 px-3 py-1 text-xs text-red-600';
        errBox.textContent = err.message || '未知错误'; errBox.classList.remove('hidden');
        HGTAbort.end();
    }
}
document.getElementById('genForm').addEventListener('submit', handleGenerate);

// ===== 出片进度通用工具 =====
function fmtDuration(s) {
    s = Math.max(0, Math.round(s));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const r = s % 60;
    if (h > 0) return h + ' 小时 ' + (m > 0 ? m + ' 分' : '');
    if (m > 0) return m + ' 分 ' + (r > 0 ? (r + ' 秒') : '');
    return r + ' 秒';
}

// 进度卡片渲染器：骨架/真实状态统一结构，避免 resumeJob 与 pollStatus 两套 UI
function renderProgressCard(opts) {
    const {
        title, hint, step, stage, percent, elapsedSec, etaSec, etaHint,
        qpos, stuckHint, isSkeleton, errorText
    } = opts;
    const STAGES = ['提交成功', '配音字幕合成', '视频渲染', '出片完成'];
    let stepsHtml = '';
    for (let k = 0; k < STAGES.length; k++) {
        const reached = isSkeleton ? (k === 0) : (k <= stage);
        const current = isSkeleton ? (k === 0) : (k === stage && step !== 'done' && step !== 'failed');
        const dotCls = reached
            ? (current ? 'bg-brand-500 text-white' : 'bg-brand-100 text-brand-700')
            : 'bg-slate-100 text-slate-400';
        stepsHtml += '<div class="flex flex-col items-center gap-1">' +
            '<div class="flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-semibold ' + dotCls + '">' +
            (reached ? (k < stage ? '✓' : (k + 1)) : (k + 1)) + '</div>' +
            '<div class="text-[10px] ' + (current ? 'font-medium text-brand-700' : 'text-slate-400') + '">' + STAGES[k] + '</div>' +
            '</div>';
        if (k < STAGES.length - 1) {
            stepsHtml += '<div class="mt-3 h-0.5 flex-1 ' + (k < stage ? 'bg-brand-300' : 'bg-slate-200') + '"></div>';
        }
    }

    const isDone = !isSkeleton && (step === 'done' || step === 'failed');
    const etaText = isSkeleton
        ? '正在连接出片服务…'
        : (step === 'queued' && qpos > 0)
            ? ('前面约 ' + qpos + ' 个排队')
            : (typeof etaSec === 'number' && etaSec > 0)
                ? ('预计剩余 ' + fmtDuration(etaSec))
                : (etaHint || '预计还需数分钟');

    const progressWidth = isSkeleton ? '45%' : (isDone ? '100%' : (percent + '%'));
    const progressColor = isDone && step === 'failed' ? 'bg-red-500' : 'bg-brand-500';
    const bottomText = isDone
        ? (step === 'failed' ? '出片失败' : '已完成 100%')
        : ('进行中 · ' + (isSkeleton ? '获取最新进度' : (opts.label || '出片处理中')));

    return '<div class="mx-auto w-full max-w-md rounded-xl border border-slate-100 bg-white p-5 shadow-sm" data-hgt-progress="1">' +
        '  <div class="mb-3 text-center">' +
        '    <div class="mb-1 text-sm font-medium text-slate-700">' + (title || '出片处理中') + '</div>' +
        '    <div class="text-xs text-slate-400" data-hgt-timer="' + (elapsedSec || 0) + '">已等待 ' + fmtDuration(elapsedSec || 0) + (isSkeleton ? '' : '　·　' + etaText) + '</div>' +
        '  </div>' +
        '  <div class="mb-4 flex items-center justify-between gap-1">' + stepsHtml + '</div>' +
        '  <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100 ' + (isDone ? '' : 'hgt-indet') + '" style="position:relative">' +
        '    <div class="h-full rounded-full ' + progressColor + ' transition-all duration-500" style="width:' + progressWidth + '"></div>' +
        '  </div>' +
        '  <div class="mt-1 flex items-center justify-between text-[11px] text-slate-400"><span>' + bottomText + '</span><span>' + (hint || '数字人出片约 5–15 分钟，可先去其他页面') + '</span></div>' +
        (stuckHint || '') +
        (errorText ? '<div class="mt-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-600">' + errorText + '</div>' : '') +
        '</div>';
}

// 启动/停止实时计时器（每秒刷新「已等待」文案）
let __hgt_timerInterval = null;
function startProgressTimer() {
    stopProgressTimer();
    __hgt_timerInterval = setInterval(function () {
        document.querySelectorAll('[data-hgt-timer]').forEach(function (el) {
            const base = parseInt(el.getAttribute('data-hgt-timer') || '0', 10);
            // 每次刷新时把基准值 +1，实现自增
            el.setAttribute('data-hgt-timer', String(base + 1));
            const text = el.textContent || '';
            const prefix = text.split('　·　')[0];
            const suffix = text.includes('　·　') ? ('　·　' + text.split('　·　')[1]) : '';
            el.textContent = '已等待 ' + fmtDuration(base + 1) + suffix;
        });
    }, 1000);
}
function stopProgressTimer() {
    if (__hgt_timerInterval) { clearInterval(__hgt_timerInterval); __hgt_timerInterval = null; }
}

// 失败原因中文标签与处置建议（看门狗结构化 failed_reason 透传）
const FAILED_REASON_LABEL = {
    timeout: '出片超时（长时间无进展）',
    service_unavailable: '出片服务异常（持续不可达）',
    resource: '系统资源不足（磁盘/显存/内存）',
    format: '素材或格式问题',
    job_lost: '出片任务丢失（服务侧已无记录）',
    lecture: '法条/政策类内容不漫剧化',
    unknown: '出片失败（原因未知）',
};
const FAILED_REASON_TIP = {
    timeout: '任务卡死已自动终止，可重新提交；若反复超时请缩短内容或分批生成。',
    service_unavailable: '出片服务暂时不可用，请稍后重试；持续失败请联系技术支持。',
    resource: '服务器资源不足，请稍后重试或联系我们扩容。',
    format: '请检查文稿/素材格式后重新提交。',
    job_lost: '任务在服务侧已丢失，请重新提交生成。',
    lecture: '为保证法条表述精确，漫剧不呈现法条内容。建议改用「幕后音·动态画面」或「数字人」口播形式出片。',
    unknown: '可重新提交生成，或联系技术支持。',
};
function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

// 当前轮询请求的 AbortController，用于「立即刷新」强制中断卡住的 fetch
let __hgt_pollController = null;

// 立即刷新：先打断当前可能卡住的请求，再触发下一轮轮询
function forcePollRefresh() {
    window.__hgt_pollNow = true;
    if (__hgt_pollController) {
        try { __hgt_pollController.abort('manual-refresh'); } catch (e) {}
    }
}

async function pollStatus(jobId) {
    const badge = document.getElementById('statusBadge');
    const result = document.getElementById('result');
    const pageLoadMs = Date.now();
    let baseElapsedSec = 0;      // 后端返回的已等待秒数（任务创建至今）
    let lastStep = null;         // 最近一次 8500 返回的 step
    let lastStepMs = Date.now(); // 进入当前 step 的时间戳，用于卡死感知
    for (let i = 0; i < 1800; i++) {  // 最多 60 分钟轮询（数字人视频含重渲染可能 20–40 分钟）
        // 可被 visibilitychange / 手动刷新打断的分段 sleep（2 秒一组，100ms 检查一次标志）
        for (let s = 0; s < 20; s++) {
            if (window.__hgt_pollNow) break;
            await new Promise(r => setTimeout(r, 100));
        }
        window.__hgt_pollNow = false;
        if (!HGTAbort.isActive()) {
            badge.textContent = '已中止'; badge.className = 'rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-500';
            setBtnLoading(false);
            stopProgressTimer();
            hgtToast('warn', '已中止出片');
            HGTAbort.end();
            return;
        }
        let fetchTimeout = null;
        try {
            __hgt_pollController = new AbortController();
            fetchTimeout = setTimeout(() => { __hgt_pollController.abort('timeout'); }, 10000);
            const statusResp = await fetch('/studio/scroll/status/' + jobId, {
                signal: __hgt_pollController.signal,
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                }
            });
            clearTimeout(fetchTimeout);
            const statusText = await statusResp.text();
            let data;
            try { data = JSON.parse(statusText); } catch (_) { continue; } // 网络抖动，跳过本轮继续轮询
            if (data.status === 'done') {
                sessionStorage.removeItem('hgt_active_job');
                badge.textContent = '完成'; badge.className = 'rounded-full bg-green-100 px-3 py-1 text-xs text-green-700';
                const regenWarn = data.regen_failed
                    ? '<div class="mb-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">⚠ 本次自动修复未成功，成片可能含短暂静音/音频缺口，请试听确认；如需更干净可重新生成。</div>'
                    : '';
                result.innerHTML =
                    '<div class="w-full">' +
                    regenWarn +
                    '  <div class="mb-2 flex items-center gap-2 text-sm font-medium text-green-700">出片完成（真实配音短视频）</div>' +
                    '  <video src="/studio/scroll/download/' + jobId + '" controls class="max-h-[55vh] w-full rounded-lg bg-black"></video>' +
                    '  <div class="mt-3 flex flex-wrap gap-2">' +
                    '    <a href="/studio/scroll/download/' + jobId + '" download class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">⬇ 下载视频</a>' +
                    '    <a href="/studio/qc?job_id=' + jobId + '" class="rounded-lg border border-amber-500 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-700 hover:bg-amber-100">去智能质检</a>' +
                    '    <a href="/studio/review" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">去审核</a>' +
                    '    <span title="完成人工审核通过后可发布" class="cursor-not-allowed rounded-lg border border-slate-200 bg-slate-50 px-4 py-2 text-sm font-medium text-slate-400">去发布</span>' +
                    '    <a href="/studio/scroll" onclick="sessionStorage.removeItem(\'hgt_rewrite_cleaned\'); sessionStorage.removeItem(\'hgt_rewrite_mode\');" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm text-slate-500 hover:bg-slate-50">↻ 再出一条</a>' +
                    '  </div>' +
                    '</div>';
                setBtnLoading(false);
                stopProgressTimer();
                HGTAbort.end();
                return;
            } else if (data.status === 'failed') {
                sessionStorage.removeItem('hgt_active_job');
                badge.textContent = '失败'; badge.className = 'rounded-full bg-red-100 px-3 py-1 text-xs text-red-600';
                const eb = document.getElementById('errorBox');
                const reasonKey = data.failed_reason || 'unknown';
                const reasonLabel = FAILED_REASON_LABEL[reasonKey] || '出片失败';
                const detail = data.pipeline_error || data.error || '未知错误';
                const tip = FAILED_REASON_TIP[reasonKey] || '可重新提交生成，或联系技术支持。';
                eb.innerHTML = '<div class="font-medium">出片失败：' + reasonLabel + '</div>'
                    + '<div class="mt-1 break-words">' + escapeHtml(String(detail).slice(0, 300)) + '</div>'
                    + '<div class="mt-1 text-red-500/80">' + tip + '</div>';
                eb.classList.remove('hidden');
                // 同步写一条失败日志到控制台便于排查
                if (window.console) console.warn('[出片失败]', reasonKey, detail);
                setBtnLoading(false);
                stopProgressTimer();
                HGTAbort.end();
                return;
            } else {
                badge.textContent = '出片中'; badge.className = 'rounded-full bg-brand-100 px-3 py-1 text-xs text-brand-600';
                const step = data.step || 'rendering';
                const qpos = data.queue_pos || 0;
                // 后端 enriched 字段优先；缺失时本地兜底映射（兼容旧版 8500 / 缓存）
                const STEP_MAP = {
                    queued:    { label: '排队等待渲染资源', percent: 8,  stage: 0, etaHint: '预计还需 1–10 分钟（视排队情况）' },
                    editing:   { label: '智能配音与字幕合成', percent: 40, stage: 1, etaHint: '预计还需 1–3 分钟' },
                    rendering: { label: '视频 / 数字人渲染中', percent: 75, stage: 2, etaHint: '预计还需 5–15 分钟' },
                    rerender:  { label: '画面精修（自动重渲染）', percent: 92, stage: 2, etaHint: '预计还需 3–8 分钟' },
                    done:      { label: '已完成', percent: 100, stage: 3, etaHint: '' },
                    failed:    { label: '出片失败', percent: 100, stage: 3, etaHint: '' },
                };
                const info = STEP_MAP[step] || { label: '出片处理中', percent: 50, stage: 1 };
                const percent = (typeof data.progress === 'number') ? data.progress : info.percent;
                const isDone = (step === 'done' || step === 'failed');

                // 已等待时长优先取后端计算（任务创建至今），页面刷新/续接时更准确
                baseElapsedSec = (typeof data.elapsed_sec === 'number' && data.elapsed_sec > 0)
                    ? data.elapsed_sec
                    : Math.max(0, Math.round((Date.now() - pageLoadMs) / 1000));
                const etaSec = (typeof data.eta_sec === 'number' && data.eta_sec > 0) ? data.eta_sec : 0;
                const etaHint = data.eta_hint || info.etaHint || '';

                let title, hint;
                if (step === 'queued') {
                    title = qpos > 0 ? ('排队中（前面还有 ' + qpos + ' 个视频在渲染）') : '排队中（等待渲染资源）';
                    hint = '数字人出片较慢，请耐心等待；可先去其他页面，回来会自动续接';
                } else if (step === 'rerender') {
                    title = '检测到音频瑕疵，正在自动重渲染修复';
                    hint = '为保质量平台自动重渲染一次，无需任何操作';
                } else {
                    title = info.label;
                    // 按已等待时长给出不同安抚文案，避免 7 分钟就吓用户"卡住"
                    if (baseElapsedSec < 300) {
                        hint = '数字人出片通常需要 5–15 分钟；可先去其他页面，回来会自动续接';
                    } else if (baseElapsedSec < 900) {
                        hint = '仍在正常范围内，请继续等待；数字人渲染较慢，约 5–15 分钟';
                    } else if (baseElapsedSec < 1500) {
                        hint = '已等待较久（' + fmtDuration(baseElapsedSec) + '），若超过 25 分钟仍无进展可点击刷新';
                    } else {
                        hint = '当前阶段已超过 25 分钟未推进，可能已经卡住，建议刷新或重试';
                    }
                }

                // 同阶段超时感知：阈值与后端看门狗对齐，避免正常长视频被误报
                // 后端：阶段卡死 25 分钟 / 排队 50 分钟 / 绝对 90 分钟 / 服务不可达 10 分钟
                const isRegen = !!data.regen_attempted;
                const STAGE_WARN_SEC = isRegen ? 1200 : 1200;     // 20 分钟：显示"已等待较久"预警
                const STAGE_STUCK_SEC = isRegen ? 1500 : 1500;    // 25 分钟：才报"可能已经卡住"
                const TOTAL_STUCK_SEC = isRegen ? 1800 : 1500;    // 重渲染期总等待阈值放宽（30/25 分钟）
                if (step !== lastStep) { lastStep = step; lastStepMs = Date.now(); }
                const stageStuckSec = Math.floor((Date.now() - lastStepMs) / 1000);
                let stuckHint = '';
                if (stageStuckSec >= STAGE_STUCK_SEC) {
                    stuckHint = '<div class="mt-2 text-xs" style="color:#d97706">⚠ 当前阶段已持续 ' + fmtDuration(stageStuckSec) + ' 未推进，可能已经卡住。<button type="button" onclick="forcePollRefresh()" class="ml-1 rounded border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-amber-700 hover:bg-amber-100">立即刷新</button></div>';
                } else if (stageStuckSec >= STAGE_WARN_SEC) {
                    stuckHint = '<div class="mt-2 text-xs" style="color:#d97706">当前阶段已持续 ' + fmtDuration(stageStuckSec) + '，数字人视频 5–15 分钟属于正常；若继续无推进请<button type="button" onclick="forcePollRefresh()" class="ml-1 rounded border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-amber-700 hover:bg-amber-100">立即刷新</button></div>';
                } else if (baseElapsedSec >= TOTAL_STUCK_SEC) {
                    stuckHint = '<div class="mt-2 text-xs" style="color:#d97706">已等待较久（' + fmtDuration(baseElapsedSec) + '）。数字人视频正常 5–15 分钟，重渲染会更久；若仍无进展请刷新页面或重试。</div>';
                }

                result.innerHTML = renderProgressCard({
                    title: title,
                    label: info.label,
                    hint: hint,
                    step: step,
                    stage: info.stage,
                    percent: percent,
                    elapsedSec: baseElapsedSec,
                    etaSec: etaSec,
                    etaHint: etaHint,
                    qpos: qpos,
                    stuckHint: stuckHint
                });
                startProgressTimer();
            }
        } catch (e) {
            if (fetchTimeout) clearTimeout(fetchTimeout);
            // 手动刷新/超时/网络抖动都继续下一轮轮询
            continue;
        }
    }
    badge.textContent = '超时'; badge.className = 'rounded-full bg-red-100 px-3 py-1 text-xs text-red-600';
    setBtnLoading(false);
    stopProgressTimer();
    HGTAbort.end();
}

// 续接未完成的出片任务（用户离开页面后回来自动恢复轮询）
(function resumeJob() {
    const jobId = sessionStorage.getItem('hgt_active_job');
    const result = document.getElementById('result');
    if (jobId) {
        jobSubmitted = true;
        currentJobId = jobId;
        document.getElementById('jobLogBtn')?.classList.remove('hidden');
        setBtnLoading(true, '出片中…');
        // 立即展示带进度条骨架的卡片，避免"只有文字"的空白等待
        if (result) {
            result.innerHTML = renderProgressCard({
                title: '正在续接出片进度…',
                hint: '任务 ' + jobId.substring(0, 8) + '… 仍在处理',
                step: 'queued',
                stage: 0,
                percent: 8,
                elapsedSec: 0,
                etaSec: null,
                qpos: 0,
                isSkeleton: true
            });
            startProgressTimer();
        }
        HGTAbort.begin('中止：出片进行中…', {
            onAbort: function () {
                stopProgressTimer();
                var m = document.querySelector('meta[name="csrf-token"]');
                var tk = m ? m.content : '';
                fetch('/studio/scroll/cancel?job=' + jobId, {
                    method: 'POST', keepalive: true,
                    headers: { 'X-CSRF-TOKEN': tk, 'Content-Type': 'application/json' },
                    body: JSON.stringify({})
                }).catch(function () {});
            }
        });
        // 先拉一次真实状态，把骨架里的「已等待 0 秒」立刻校正为后端累计时长
        // 2026-08-31 修复：首次 fetch 无超时保护，若请求挂起页面会永久停在"仍在处理"骨架；
        // 加 AbortController 超时(8s)，超时/失败都直接进入 pollStatus(其内部有10s超时+重试)
        const resumeCtrl = new AbortController();
        const resumeTimer = setTimeout(() => resumeCtrl.abort('timeout'), 8000);
        fetch('/studio/scroll/status/' + jobId, {
            signal: resumeCtrl.signal,
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }
        })
        .then(r => r.json())
        .then(data => {
            clearTimeout(resumeTimer);
            if (data.status === 'done' || data.status === 'failed') {
                // 终态直接让 pollStatus 处理展示
                pollStatus(jobId);
            } else {
                // 非终态：用后端 elapsed_sec 刷新骨架计时器基准，再进入轮询
                const elapsed = (typeof data.elapsed_sec === 'number' && data.elapsed_sec > 0) ? data.elapsed_sec : 0;
                const timerEl = result?.querySelector('[data-hgt-timer]');
                if (timerEl) timerEl.setAttribute('data-hgt-timer', String(elapsed));
                pollStatus(jobId);
            }
        })
        .catch(() => {
            clearTimeout(resumeTimer);
            // 首次拉取失败/超时也继续轮询，pollStatus 会自己重试（内部有 10s 超时）
            pollStatus(jobId);
        });
    }
})();

// 切回页面立即拉一次状态（浏览器后台标签会节流 setInterval，避免用户回来还看旧状态）
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        window.__hgt_pollNow = true;
    }
});

// ===== 出片进度记录弹窗（读取后端 /studio/scroll/job-log/{jobId}） =====
function escHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function openJobLog(jobId) {
    const modal = document.getElementById('jobLogModal');
    const body = document.getElementById('jobLogBody');
    if (!modal || !body) return;
    if (!jobId) { body.innerHTML = '<div style="color:#94a3b8">暂无进度记录</div>'; modal.style.display = 'flex'; return; }
    body.innerHTML = '<div style="color:#94a3b8">加载中…</div>';
    fetch('/studio/scroll/job-log/' + jobId, {
        headers: { 'Accept':'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }
    })
    .then(r => r.json())
    .then(d => {
        if (!d.exists || !d.entries || !d.entries.length) {
            body.innerHTML = '<div style="color:#94a3b8">暂无进度记录（任务刚提交或已结束且无阶段切换）</div>';
            return;
        }
        let html = '';
        d.entries.forEach(e => {
            const st = e.status;
            const c = st === 'done' ? '#16a34a' : (st === 'failed' ? '#dc2626' : '#2563eb');
            html += '<div style="display:flex;gap:.5rem;padding:.4rem 0;border-bottom:1px solid #f1f5f9;align-items:baseline;flex-wrap:wrap">'
                + '<span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#64748b;flex-shrink:0">' + escHtml(e.time) + '</span>'
                + '<span style="color:' + c + ';font-weight:600;flex-shrink:0">' + escHtml(e.label) + '</span>'
                + '<span style="color:#94a3b8">' + (st === 'done' ? '已完成' : (st === 'failed' ? '出片失败' : '进行中')) + '</span>'
                + (typeof e.eta === 'number' && e.eta > 0 && st !== 'done' && st !== 'failed' ? '<span style="color:#94a3b8">预计剩余 ' + e.eta + 's</span>' : '')
                + '</div>';
        });
        body.innerHTML = html;
    })
    .catch(() => { body.innerHTML = '<div style="color:#dc2626">读取失败，请稍后重试</div>'; });
    modal.style.display = 'flex';
}
function closeJobLog() {
    const modal = document.getElementById('jobLogModal');
    if (modal) modal.style.display = 'none';
}
document.getElementById('jobLogModal')?.addEventListener('click', function(ev) {
    if (ev.target === this) closeJobLog();
});
</script>
</x-workspace-layout>
</x-app-layout>

