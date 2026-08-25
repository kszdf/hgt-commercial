#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
对话稿 -> 本地数字人(HEYGEM)出镜视频（商用平台 avatar 模式）。

流程:
  1) 解析 女：/男： 对话体，逐句用千问(张老师克隆音/指定 voice)合成真实配音 wav
  2) 按真实音频时长生成时间轴，写 ASS 字幕（与配音对齐）
  3) 调用 make_avatar_video.py：千问音频驱动 HEYGEM 嘴型对齐 -> 去双声 -> mux ->
     烧字幕 + 拼品牌片头，产出发布级成品 mp4

说明:
  - avatar 支持男声独白 / 男女双声对话：女：行用女声、男：行与独白行用男声；
    数字人模特为男模 BGZSP，故独白/混合行统一用男声（声画一致）。
  - 依赖 gpt_sovits 下的 qwen_tts（真实配音）、make_avatar_video.py（HEYGEM 流程）、
    本地 HEYGEM 容器(http://localhost:8383)、ffmpeg 全量、model_keys.env 的 dashscope key。

用法:
  # 独白（纯文本）：用男声统一配音
  python make_avatar_from_dialogue.py --dialogue dlg.txt --out out.mp4
  # 双声对话（每行 女：/男： 开头）：女：行用女声、男：行用男声
  python make_avatar_from_dialogue.py --dialogue dlg.txt --out out.mp4 \
      --male-voice <男声voice_id> --female-voice <女声voice_id>
"""
import argparse
import hashlib
import json
import os
import shutil
import subprocess
import sys
import tempfile

from pathlib import Path

# 字幕预处理换行依赖 PIL（py310 环境已装）
try:
    from PIL import Image, ImageDraw, ImageFont
    HAS_PIL = True
except Exception:
    HAS_PIL = False
import uuid

HERE = os.path.dirname(os.path.abspath(__file__))
GPT_SOVITS = r"D:/heygem_data/gpt_sovits"
sys.path.insert(0, GPT_SOVITS)

FFMPEG = r"D:/ffmpeg/ffmpeg-8.1.2-full_build/bin/ffmpeg.exe"
FFPROBE = r"D:/ffmpeg/ffmpeg-8.1.2-full_build/bin/ffprobe.exe"
MAKE_AVATAR = os.path.join(GPT_SOVITS, "make_avatar_video.py")
PY310 = r"D:/heygem/py310/Scripts/python.exe"
DEFAULT_MODEL = "/code/data/BGZSP20260721_t18_silent.mp4"  # 容器内男模路径

# 默认配音音色（与 server.py / gpt_sovits 定稿一致）
DEFAULT_MALE_VOICE = ""   # 新租户初始无自带声音；须由租户克隆/选择后显式传入
DEFAULT_FEMALE_VOICE = ""

from qwen_tts import synth as qwen_synth


def _clean(text):
    return text.replace("**", "")


def parse_dialogue(text):
    """解析对话/独白稿，返回 [(gender, txt), ...]。
    gender: 'female'（女：/女: 行）/ 'male'（男：/男: 行）/ None（纯文本行，独白或混合）。
    独白（无前缀）与混合行统一归为男声（数字人形象为男模，声画一致）。"""
    segs = []
    for line in text.splitlines():
        line = line.strip()
        if not line:
            continue
        if line.startswith("女") or line.startswith("女：") or line.startswith("女:"):
            gender = "female"
            sep = "：" if "：" in line else ":"
            txt = line[line.find(sep) + 1:]
        elif line.startswith("男") or line.startswith("男：") or line.startswith("男:"):
            gender = "male"
            sep = "：" if "：" in line else ":"
            txt = line[line.find(sep) + 1:]
        else:
            gender = None
            txt = line
        txt = txt.strip()
        if txt:
            segs.append((gender, txt))
    return segs


def wav_duration(path):
    r = subprocess.run(
        [FFPROBE, "-v", "error", "-show_entries", "format=duration",
         "-of", "default=noprint_wrappers=1:nokey=1", path],
        capture_output=True, text=True, encoding="utf-8", errors="ignore", check=True)
    return float(r.stdout.strip())


def synth_concat(segs, male_voice, female_voice, tmpdir, gap=0.25):
    """逐句合成真实配音，句间插 gap，拼成总音频；返回 (总wav, [(start,end,display)])。
    按句性别选声线：女：行用女声，男：行/独白行用男声。
    机制A2：逐句音频按 md5(文本+音色+参数) 缓存到 GPT_SOVITS/_tts_cache（持久目录，非 temp），
    重跑/跨天复用命中则跳过 API 合成 —— 防"temp 被系统清理 → 全流程重来"（昨晚事故根因之一）。"""
    CACHE = os.path.join(GPT_SOVITS, "_tts_cache")
    seg_wavs, starts, ends, displays = [], [], [], []
    t = 0.0
    for i, (gender, txt) in enumerate(segs):
        voice = female_voice if gender == "female" else male_voice
        wav = os.path.join(tmpdir, f"s_{i:03d}.wav")
        _clean_txt = _clean(txt)
        _key = hashlib.md5(
            f"{_clean_txt}|{voice or ''}|cosyvoice-v3-plus|1.0|1.0|50".encode("utf-8")).hexdigest()[:20]
        _cached = os.path.join(CACHE, _key + ".wav")
        if os.path.exists(_cached) and os.path.getsize(_cached) > 1000:
            shutil.copy(_cached, wav)
        else:
            qwen_synth(_clean_txt, voice, wav,
                       model="cosyvoice-v3-plus", speech_rate=1.0, pitch_rate=1.0, volume=50)
            try:
                os.makedirs(CACHE, exist_ok=True)
                shutil.copy(wav, _cached)
            except OSError:
                pass  # 缓存写入失败不影响主流程
        d = wav_duration(wav)
        starts.append(t)
        ends.append(t + d)
        displays.append(txt)
        t += d + (0.0 if i == len(segs) - 1 else gap)
    # 拼总音频（句间 gap 静音）
    gap_wav = os.path.join(tmpdir, "gap.wav")
    subprocess.run([FFMPEG, "-y", "-f", "lavfi", "-i", "anullsrc=r=22050:cl=mono",
                    "-t", f"{gap:.3f}", "-c:a", "pcm_s16le", gap_wav],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    files = []
    for i, sw in enumerate(seg_wavs := [os.path.join(tmpdir, f"s_{i:03d}.wav") for i in range(len(segs))]):
        files.append(sw)
        if i < len(segs) - 1:
            files.append(gap_wav)
    listfile = os.path.join(tmpdir, "audio_list.txt")
    with open(listfile, "w", encoding="utf-8") as f:
        for p in files:
            f.write(f"file '{p.replace(chr(92), '/')}'\n")
    total = os.path.join(tmpdir, "audio_total.wav")
    subprocess.run([FFMPEG, "-y", "-f", "concat", "-safe", "0", "-i", listfile,
                    "-c", "copy", total], check=True,
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    timed = list(zip(starts, ends, displays))
    return total, timed


# 字幕换行宽度必须按 finalize 实际绘制画布计算，不能按成品输出宽度：
# 数字人 HEYGEM 源视频是 720x1264，finalize 直接在源尺寸帧上画字幕（SUB_SIZE=34，左右各留 40px）。
# 此前按 1080 输出宽度（1000px）换行 → 长行在 720 画布上居中时左右双双超界 → 放大到 1080 后文字贴边/切边。
# 修复：按 720 画布换行（720-80=640px），与 finalize 无 karaoke 兜底的 max(W-80, W*0.86)=640 完全一致。
SUBTITLE_VIDEO_W = 720
SUBTITLE_FONT_SIZE = 34
SUBTITLE_HMARGIN = 40
SUBTITLE_MAX_W = SUBTITLE_VIDEO_W - SUBTITLE_HMARGIN * 2   # 640px


def _load_sub_font(path, size):
    p = Path(path)
    if p.exists():
        try:
            return ImageFont.truetype(str(p), size)
        except Exception:
            return None
    return None


def _char_pixel_width(draw, ch, fonts, fallback_size=SUBTITLE_FONT_SIZE):
    """用 PIL 测量单个字符在指定字体下的像素宽度。"""
    for f in fonts:
        if f is None:
            continue
        try:
            if f.getmask(ch).getbbox() is not None:
                return draw.textlength(ch, font=f)
        except Exception:
            pass
    # 兜底：按字体大小的一半估算
    return fallback_size * 0.5


def _wrap_display_by_count(display, max_chars):
    lines = []
    for raw_line in display.split("\n"):
        for i in range(0, len(raw_line), max_chars):
            lines.append(raw_line[i:i + max_chars])
    return "\n".join(lines)


def _wrap_display_by_width(display, fonts, max_width=SUBTITLE_MAX_W):
    """按像素宽度对一句字幕自动换行，保证 finalize 阶段不会溢出屏幕。
    保持字符顺序，不影响 karaoke 逐字高亮同步。"""
    if not HAS_PIL:
        # 无 PIL 时保守按字符数截断（每行最多 12 个汉字）
        return _wrap_display_by_count(display, 12)
    tmp = Image.new("RGB", (1, 1))
    draw = ImageDraw.Draw(tmp)
    out_lines = []
    for raw_line in display.split("\n"):
        chars, w = [], 0.0
        for ch in raw_line:
            cw = _char_pixel_width(draw, ch, fonts)
            if w + cw > max_width and chars:
                out_lines.append("".join(chars))
                chars, w = [ch], cw
            else:
                chars.append(ch)
                w += cw
        if chars:
            out_lines.append("".join(chars))
    return "\n".join(out_lines)


def detect_graphics(timed):
    """按对话内容识别「图解句」→ 生成数字人出镜时穿插的智能图解时间轴。
    数据喂给 make_motion 的成熟渲染（真图表/AI生图/流程），非简化大字卡。
    规则（克制，只插最该视觉化的，总段数 ≤4）：
      - 含金额/百分比 → number 数字卡（大数字+图表）
      - 含 风险/红线/稽查/补税/罚款/滞纳 → warn 警示卡（motion 走 quote/红卡）
      - 含 第一/第二/首先/然后/步骤/三步 → step 流程卡（箭头串联）
      - 含 对比/比/相比/和…差 → table 表格卡（列对比）
      - 含 案例/一个老板/最近 → scene 场景卡（AI 生图插画）
    返回 [{"start","end","kind","title","data"}, ...]。"""
    import re as _re
    out = []
    for start, end, display in timed:
        if len(out) >= 4:
            break
        txt = display or ""
        title = txt[:12]
        nums = _re.findall(r"\d+(?:\.\d+)?\s*[万亿%元]?", txt)
        if nums:
            out.append({"start": round(start, 2), "end": round(end, 2), "kind": "number",
                        "title": title, "tone": "risk",
                        "data": {"num": nums[0], "sub": "重点数据", "keywords": ["数据", "金额"]}})
        elif _re.search(r"风险|红线|稽查|补税|罚款|滞纳|被查|盯上|别|不能", txt):
            kw = "、".join([w for w in ("风险", "稽查", "补税", "罚款", "滞纳", "红线")
                            if w in txt][:2]) or "注意"
            out.append({"start": round(start, 2), "end": round(end, 2), "kind": "quote",
                        "title": title, "tone": "risk",
                        "data": {"quote": kw + "，别抱侥幸", "keywords": [kw]}})
        elif _re.search(r"第一|第二|首先|然后|步骤|三步|第一步|第二步|第三步", txt):
            steps = [s.strip() for s in _re.split(r"[。；;]", txt)
                     if _re.search(r"第[一二三0-9]|第一|第二|第三", s)][:4] or ["停掉个人卡收款", "主动补申报", "顾问合规梳理"]
            out.append({"start": round(start, 2), "end": round(end, 2), "kind": "step",
                        "title": title, "tone": "safe",
                        "data": {"steps": steps, "keywords": ["步骤"]}})
        elif _re.search(r"对比|相比|比.*高|比.*低|和.*差|多.*少", txt):
            head = ["项目", "说明"]
            rows = [[txt[:6], "见详情"]]
            out.append({"start": round(start, 2), "end": round(end, 2), "kind": "table",
                        "title": title, "tone": "neutral",
                        "data": {"table": {"head": head, "rows": rows}, "keywords": []}})
        elif _re.search(r"案例|例子|一个老板|最近|举例", txt):
            prompt = ("财税顾问在办公室审阅账本，画面专业沉稳，扁平商务插画，"
                      "低饱和配色，无文字无数字")
            out.append({"start": round(start, 2), "end": round(end, 2), "kind": "scene",
                        "title": title, "tone": "neutral",
                        "data": {"prompt": prompt, "keywords": ["案例"]}})
    return out


def annotate_face_positions(gfx, video_path, fps=30):
    """数字人图解浮层自适应：渲染后抽每段起始帧，用 Haar 人脸检测定位数字人主体，
    把 face=(x,y,w,h) 写进每段 graphics，供 finalize 半透明叠加时避让/变尺寸。
    cv2 不可用时跳过（finalize 退化为底部固定浮层）。"""
    try:
        import cv2  # noqa: F401
        import numpy as np  # noqa: F401
    except Exception:  # noqa: BLE001
        print("[avatar] cv2 不可用，图解浮层退化为固定底部位置")
        return gfx
    cascade = cv2.CascadeClassifier(
        os.path.join(GPT_SOVITS, "haarcascade_frontalface_default.xml"))
    for g in gfx:
        sec = float(g.get("start", 0))
        frame = os.path.join(tempfile.gettempdir(), "face_%s_%s.png" % (
            os.path.basename(video_path), uuid.uuid4().hex[:6]))
        try:
            subprocess.run(
                [FFMPEG, "-y", "-ss", str(max(0, sec - 0.3)), "-i", video_path,
                 "-frames:v", "1", frame],
                capture_output=True, timeout=30, check=True)
            if os.path.exists(frame):
                img = cv2.imread(frame)
                if img is not None:
                    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
                    faces = cascade.detectMultiScale(gray, scaleFactor=1.1,
                                                    minNeighbors=5, minSize=(80, 80))
                    big = [f for f in faces if f[2] >= 200]
                    if big:
                        fx, fy, fw, fh = max(big, key=lambda f: f[2] * f[3])
                        g["face"] = [int(fx), int(fy), int(fw), int(fh)]
        except Exception as e:  # noqa: BLE001
            print(f"  [WARN] 人脸检测失败 @{sec}s: {e}")
        finally:
            try:
                os.remove(frame)
            except OSError:
                pass
    return gfx


def build_karaoke(timed, style):
    """根据每句配音时长，按比例把每个可见字符摊到时间轴，产出逐字高亮所需 sidecar。
    结构：{"style":..., "events":[{"start","end","lines":[[{c,s,e}...]...]}]}。
    lines 与 ASS 文本按 \\n 对齐，finalize 按帧时间 t 判定已读到字符 -> 高亮（金）。"""
    events = []
    for start, end, display in timed:
        chars = list(display)
        n = max(1, len(chars))
        dur = max(0.01, end - start)
        per = dur / n
        lines, cur = [], []
        for i, ch in enumerate(chars):
            cs = round(start + i * per, 3)
            ce = round(start + (i + 1) * per, 3)
            if ch == "\n":
                lines.append(cur)
                cur = []
            else:
                cur.append({"c": ch, "s": cs, "e": ce})
        if cur:
            lines.append(cur)
        events.append({"start": round(start, 2), "end": round(end, 2), "lines": lines})
    return {"style": style, "events": events}


def write_ass(timed, path):
    """写 ASS，时间轴与配音对齐。
    注意：finalize_v2_pil.parse_ass 用 float(parts[1]) 解析 Start/End，
    故此处必须写「秒.float」格式（如 3.50），不能写 h:mm:ss.cc。"""
    def fmt(s):
        return "%.2f" % s

    lines = [
        "[Script Info]",
        "ScriptType: v4.00+",
        "WrapStyle: 2",
        "ScaledBorderAndShadow: yes",
        "",
        "[V4+ Styles]",
        "Format: Name, Fontname, Fontsize, PrimaryColour, OutlineColour, Bold, "
        "Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, "
        "Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding",
        "Style: Default,SimHei,64,&H00FFFFFF,&H00000000,0,0,0,0,100,100,0,0,4,2,2,30,30,30,1",
        "",
        "[Events]",
        "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text",
    ]
    for start, end, txt in timed:
        txt = txt.replace("\n", "\\N").replace(",", "，")
        lines.append(f"Dialogue: 0,{fmt(start)},{fmt(end)},Default,,0,0,0,,{txt}")
    with open(path, "w", encoding="utf-8") as f:
        f.write("\n".join(lines) + "\n")


def split_segments(segs, timed, seg_max=170.0):
    """机制B1：按句子边界把长稿切成 ≤seg_max 的段（贪心，尽量均衡），
    返回 [segs 切片, ...]。切点永远落在句子之间，绝不切句子中间。
    段1 末句与段2 首句原本相邻：段内最后一句无尾 gap、段2 从 0 起 → 拼接后天然无缝。"""
    durs = [e - s for s, e, _ in timed]
    total = sum(durs)
    if total <= seg_max:
        return [segs]
    chunks, cur = [], []
    cur_sum = 0.0
    for seg, d in zip(segs, durs):
        if cur and cur_sum + d > seg_max:
            chunks.append(cur)
            cur, cur_sum = [], 0.0
        cur.append(seg)
        cur_sum += d
    if cur:
        chunks.append(cur)
    return chunks


def concat_mp4(parts, out):
    """把多段成品 mp4 拼接为一个（各段同为 finalize 输出：libx264+aac44100，可流拷贝）。
    若时间戳问题导致流拷贝失败，自动回退重编码兜底。"""
    tmp = tempfile.mkdtemp(prefix="avatar_concat_")
    lst = os.path.join(tmp, "list.txt")
    with open(lst, "w", encoding="utf-8") as f:
        for p in parts:
            f.write(f"file '{os.path.abspath(p).replace(chr(92), '/')}'\n")
    cmd = [FFMPEG, "-y", "-f", "concat", "-safe", "0", "-i", lst, "-c", "copy", out]
    r = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8", errors="ignore")
    if r.returncode != 0 or not os.path.exists(out) or os.path.getsize(out) < 1024:
        print("[avatar] concat 流拷贝失败，回退重编码拼接 ...")
        cmd = [FFMPEG, "-y", "-f", "concat", "-safe", "0", "-i", lst,
               "-c:v", "libx264", "-preset", "medium", "-crf", "20",
               "-pix_fmt", "yuv420p", "-c:a", "aac", "-ar", "44100", out]
        r = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8", errors="ignore")
        if r.returncode != 0 or not os.path.exists(out):
            sys.exit(f"拼接失败: {(r.stderr or '')[-400:]}")
    return out


def render_one(timed, audio_wav, tag, out, args, tmpdir):
    """渲染单段（或整稿）：字幕预处理 -> ass/karaoke/graphics -> make_avatar_video -> 成品。
    返回成品路径。机制A1：tag 确定性 → 同段重跑命中 HEYGEM 产物复用（省 30 分钟渲染）。"""
    base = Path(GPT_SOVITS)
    font_main = _load_sub_font(args.font, SUBTITLE_FONT_SIZE) if args.font else None
    font_fallback = _load_sub_font(str(base / "fonts/simhei.ttf"), SUBTITLE_FONT_SIZE)
    fonts = [font_main, font_fallback]
    wrapped = []
    for start, end, display in timed:
        txt = _wrap_display_by_width(display, fonts)
        wrapped.append((start, end, txt))
    timed = wrapped

    ass_path = os.path.join(tmpdir, "sub.ass")
    write_ass(timed, ass_path)
    karaoke_path = os.path.join(tmpdir, "sub.ass.karaoke.json")
    with open(karaoke_path, "w", encoding="utf-8") as kf:
        json.dump(build_karaoke(timed, args.subtitle_style), kf, ensure_ascii=False)
    print(f"[avatar] 配音 {len(timed)} 句，时长 {timed[-1][1]:.1f}s，字幕已生成（风格={args.subtitle_style}）")

    graphics_path = os.path.join(tmpdir, "sub.graphics.json")
    gfx = detect_graphics(timed)
    with open(graphics_path, "w", encoding="utf-8") as gf:
        json.dump(gfx, gf, ensure_ascii=False)
    if gfx:
        print(f"[avatar] 智能图解 {len(gfx)} 段: " + ", ".join(f"{g['kind']}@{g['start']}s" for g in gfx))

    os.makedirs(os.path.dirname(out), exist_ok=True)
    cmd = [PY310, MAKE_AVATAR, "--audio", audio_wav, "--ass", ass_path,
           "--model", args.model, "--out", out, "--name", tag,
           "--subtitle-style", args.subtitle_style, "--karaoke", karaoke_path,
           "--graphics", graphics_path]
    if args.font:
        cmd += ["--font", args.font]
    # 长视频稳定输出：整体重试（HEYGEM 渲染偶发失败/超时自动重跑，最多 3 次，无需人盯）
    max_tries = 3
    last_err = ""
    for attempt in range(1, max_tries + 1):
        if attempt > 1:
            print(f"\n[avatar] 渲染第 {attempt}/{max_tries} 次重试（上次失败: {last_err[:80]}）")
            try:
                if os.path.exists(out):
                    os.remove(out)
            except OSError:
                pass
        r = subprocess.run(cmd, cwd=GPT_SOVITS, capture_output=True, text=True,
                           encoding="utf-8", errors="ignore")
        if r.returncode == 0 and os.path.exists(out):
            break
        last_err = (r.stderr or r.stdout or "")[-300:]
        print(f"[avatar] 第 {attempt} 次失败: {last_err[:120]}")
    else:
        sys.stderr.write(last_err + "\n")
        sys.exit(f"make_avatar_video 失败（重试 {max_tries} 次后仍失败）")
    if not os.path.exists(out):
        sys.exit("成品未生成")
    return out


def main():
    ap = argparse.ArgumentParser(description="对话稿 -> 本地数字人出镜视频")
    ap.add_argument("--dialogue", required=True, help="对话/独白稿 txt（女：/男： 开头=双声对话，纯文本=男声独白）")
    ap.add_argument("--out", required=True, help="输出 mp4 路径")
    ap.add_argument("--male-voice", default=DEFAULT_MALE_VOICE,
                    help="男声/独白配音音色 voice_id（默认张老师克隆音）")
    ap.add_argument("--female-voice", default=DEFAULT_FEMALE_VOICE,
                    help="女声配音音色 voice_id（对话中 女： 行使用）")
    ap.add_argument("--model", default=DEFAULT_MODEL, help="容器内模特视频路径")
    ap.add_argument("--subtitle-style", default="dynamic",
                    choices=["dynamic", "minimal", "bubble"],
                    help="字幕风格：dynamic=逐字高亮（卡拉OK式）/ minimal=纯净白字 / bubble=气泡底衬")
    ap.add_argument("--font", default=None, help="字幕主字体路径（透传 make_avatar_video）")
    ap.add_argument("--mono", action="store_true", default=True,
                    help="单人单声线（默认）：去除 女：/男： 前缀，整稿用 male_voice 配音（数字人语义）")
    ap.add_argument("--dual", action="store_true",
                    help="保留男女对话双声（需同时传 --female-voice；默认关闭，数字人应为单声线）")
    ap.add_argument("--max-seg", type=float, default=170.0,
                    help="数字人单段稳定渲染上限（秒），超过自动分段（机制B1）")
    args = ap.parse_args()

    with open(args.dialogue, encoding="utf-8-sig") as f:
        raw = f.read()
    if not args.dual and args.mono:
        # 数字人统一单人独白：去掉角色前缀，整稿单一声线（与 server.py avatar 语义一致）
        import re as _re
        raw = _re.sub(r"^\s*(?:女|男|旁白)[:：]\s*", "", raw, flags=_re.M)
        args.female_voice = ""   # 单声线：女声槽位清空，杜绝误用女声
    segs = parse_dialogue(raw)
    if not segs:
        sys.exit("对话稿为空或解析失败")

    tmp = tempfile.mkdtemp(prefix="avatar_")
    # 先整体合成拿每句时长（A2 TTS 缓存：段内句子重跑命中，不会重复 API 合成）
    audio_wav, timed = synth_concat(segs, args.male_voice, args.female_voice, tmp)
    total_dur = timed[-1][1] if timed else 0
    print(f"[avatar] 全稿配音 {len(segs)} 句，总时长 {total_dur:.1f}s")

    # 机制一：tag 必须【确定性】（稿子内容+音色派生），否则每次重跑 code 都变，
    # make_avatar_video 的产物复用（avatar_{tag}_{hash}）永不命中 → 每次都重新 HEYGEM 渲染。
    import hashlib as _hl
    _tag_seed = (raw or "") + "|" + (args.male_voice or "") + "|" + (args.female_voice or "")
    tag = "hgt_" + _hl.md5(_tag_seed.encode("utf-8")).hexdigest()[:6]
    out = os.path.abspath(args.out)

    # 机制B1：超长自动分段（>max-seg 分 2 段渲染 + 拼接）
    # 306s 稿 → 2 段各 ~85s，渲染各 ~8-10 分钟，失败只重跑失败段；不再单段 30+ 分钟赌命。
    SEG_MAX = args.max_seg
    if total_dur > SEG_MAX:
        chunks = split_segments(segs, timed, seg_max=SEG_MAX)
        print(f"[avatar] ⚠ 口播 {total_dur:.0f}s 超过单段上限 {SEG_MAX:.0f}s → 自动分段 {len(chunks)} 段渲染+拼接")
        seg_outs = []
        seg_dir = os.path.join(tmp, "segs")
        os.makedirs(seg_dir, exist_ok=True)
        for i, seg_segs in enumerate(chunks):
            seg_tmp = os.path.join(seg_dir, f"seg{i}")
            os.makedirs(seg_tmp, exist_ok=True)
            # 段内重新合成（TTS 缓存命中 → 秒回），时间轴从 0 起
            seg_audio, seg_timed = synth_concat(seg_segs, args.male_voice, args.female_voice, seg_tmp)
            seg_dur = seg_timed[-1][1] if seg_timed else 0
            print(f"[avatar]   段{i+1}/{len(chunks)}: {len(seg_segs)} 句, {seg_dur:.1f}s")
            seg_out = os.path.join(seg_dir, f"seg{i}.mp4")
            render_one(seg_timed, seg_audio, f"{tag}_p{i}", seg_out, args, seg_tmp)
            seg_outs.append(seg_out)
        concat_mp4(seg_outs, out)
        print(f"\n成品: {out}  ({os.path.getsize(out)//1024} KB)（{len(seg_outs)} 段拼接）")
    else:
        render_one(timed, audio_wav, tag, out, args, tmp)
        print(f"\n成品: {out}  ({os.path.getsize(out)//1024} KB)")

    # 机制A3：出片即质检（QC 前置）——硬指标问题在出片后立即暴露，不等人工观看才发现。
    # 不过时：打印醒目 FAIL 明细并保留视频（供人工查看），同时把 exit code 置 2 供脚本/CI 识别。
    try:
        qc_py = os.path.join(HERE, "video_qc.py")
        if os.path.exists(qc_py):
            qc_r = subprocess.run([sys.executable, qc_py, out, "--platform", "douyin", "--json"],
                                  capture_output=True, text=True, encoding="utf-8",
                                  errors="replace", timeout=300)
            qc_out = qc_r.stdout or qc_r.stderr or ""
            print("\n========== 出片质检 video_qc ==========")
            print(qc_out[-1500:])
            print("=======================================")
            try:
                _start = qc_out.find("{")
                qc_json = json.loads(qc_out[_start:])
                score = int(qc_json.get("score", 0))
                ok_all = all(c.get("ok", True) for c in qc_json.get("checks", {}).values())
            except Exception:  # noqa: BLE001
                score, ok_all = 0, False
            if score < 90 or not ok_all:
                print(f"❌ QC 未过（score={score}）：视频已保留，请按《SKILL_短视频成品质检》修复后重出，勿发布。")
                sys.exit(2)
            print("✅ QC 通过（score≥90）")
    except SystemExit:
        raise
    except Exception as e:  # noqa: BLE001
        print(f"[QC] 质检运行失败(跳过): {e}")


if __name__ == "__main__":
    main()
