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
import json
import os
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
    按句性别选声线：女：行用女声，男：行/独白行用男声。"""
    seg_wavs, starts, ends, displays = [], [], [], []
    t = 0.0
    for i, (gender, txt) in enumerate(segs):
        voice = female_voice if gender == "female" else male_voice
        wav = os.path.join(tmpdir, f"s_{i:03d}.wav")
        qwen_synth(_clean(txt), voice, wav,
                   model="cosyvoice-v3-plus", speech_rate=1.0, pitch_rate=1.0, volume=50)
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


# 目标视频为竖屏 720x1280；finalize_v2_pil 里 SUB_SIZE=42，左右各留 40px 安全边距
SUBTITLE_VIDEO_W = 720
SUBTITLE_FONT_SIZE = 42
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
    """按对话内容识别「图解句」→ 生成数字人出镜时的智能图解时间轴。
    规则（克制，只插最该视觉化的）：
      - 含金额/数字（数字+万/%/元）→ number 数据卡（大数字）
      - 含 风险/红线/别/不能/被查/稽查/补税/罚款/滞纳 → warn 警示卡
      - 含 第一/第二/首先/然后/步骤/三步 → step 流程卡
      - 含 案例/例子/一个老板/最近 → scene 场景卡（标题+说明）
    返回 [{"start","end","kind","title","data"}, ...]，每句最多 1 段，总段数 ≤4（克制）。"""
    import re as _re
    out = []
    for start, end, display in timed:
        if len(out) >= 4:
            break
        txt = display or ""
        title = txt[:12]
        if _re.search(r"\d+(?:\.\d+)?\s*[万亿%元]?", txt):
            m = _re.search(r"(\d+(?:\.\d+)?\s*[万亿%元]?)", txt)
            out.append({"start": round(start, 2), "end": round(end, 2), "kind": "number",
                        "title": title, "data": {"num": m.group(1), "sub": "重点数据"}})
        elif _re.search(r"风险|红线|别|不能|被查|稽查|补税|罚款|滞纳|盯上", txt):
            kw = "、".join([w for w in ("风险", "稽查", "补税", "罚款", "滞纳", "红线")
                            if w in txt][:2]) or "注意"
            out.append({"start": round(start, 2), "end": round(end, 2), "kind": "warn",
                        "title": title, "data": {"kw": kw}})
        elif _re.search(r"第一|第二|首先|然后|步骤|三步|第一步|第二步|第三步", txt):
            steps = [s.strip() for s in _re.split(r"[。；;]", txt)
                     if _re.search(r"第[一二三0-9]|第一|第二|第三", s)][:4] or ["步骤一", "步骤二", "步骤三"]
            out.append({"start": round(start, 2), "end": round(end, 2), "kind": "step",
                        "title": title, "data": {"steps": steps}})
        elif _re.search(r"案例|例子|一个老板|最近|举例", txt):
            out.append({"start": round(start, 2), "end": round(end, 2), "kind": "scene",
                        "title": title, "data": {"desc": txt[:40]}})
    return out


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
    args = ap.parse_args()

    with open(args.dialogue, encoding="utf-8-sig") as f:
        segs = parse_dialogue(f.read())
    if not segs:
        sys.exit("对话稿为空或解析失败")

    tmp = tempfile.mkdtemp(prefix="avatar_")
    audio_wav, timed = synth_concat(segs, args.male_voice, args.female_voice, tmp)

    # 预处理字幕：按目标视频宽度自动换行，从源头避免溢出；保持 karaoke 同步
    base = Path(GPT_SOVITS)
    font_main = _load_sub_font(args.font, SUBTITLE_FONT_SIZE) if args.font else None
    font_fallback = _load_sub_font(str(base / "fonts/simhei.ttf"), SUBTITLE_FONT_SIZE)
    fonts = [font_main, font_fallback]
    wrapped = []
    for start, end, display in timed:
        txt = _wrap_display_by_width(display, fonts)
        wrapped.append((start, end, txt))
    timed = wrapped

    ass_path = os.path.join(tmp, "sub.ass")
    write_ass(timed, ass_path)
    # 逐字高亮 sidecar（仅 dynamic 真正使用，其他风格忽略）
    karaoke_path = os.path.join(tmp, "sub.ass.karaoke.json")
    with open(karaoke_path, "w", encoding="utf-8") as kf:
        json.dump(build_karaoke(timed, args.subtitle_style), kf, ensure_ascii=False)
    print(f"[avatar] 配音 {len(segs)} 句，总时长 {timed[-1][1]:.1f}s，字幕已生成（风格={args.subtitle_style}）")

    # 智能图解：按内容识别"数据/警示/流程/案例"句 → 数字人出镜时穿插图解卡
    graphics_path = os.path.join(tmp, "sub.graphics.json")
    gfx = detect_graphics(timed)
    with open(graphics_path, "w", encoding="utf-8") as gf:
        json.dump(gfx, gf, ensure_ascii=False)
    if gfx:
        print(f"[avatar] 智能图解 {len(gfx)} 段: " + ", ".join(f"{g['kind']}@{g['start']}s" for g in gfx))

    out = os.path.abspath(args.out)
    os.makedirs(os.path.dirname(out), exist_ok=True)
    tag = "hgt_" + uuid.uuid4().hex[:6]
    cmd = [PY310, MAKE_AVATAR, "--audio", audio_wav, "--ass", ass_path,
           "--model", args.model, "--out", out, "--name", tag,
           "--subtitle-style", args.subtitle_style, "--karaoke", karaoke_path,
           "--graphics", graphics_path]
    if args.font:
        cmd += ["--font", args.font]
    r = subprocess.run(cmd, cwd=GPT_SOVITS, capture_output=True, text=True,
                       encoding="utf-8", errors="ignore")
    if r.returncode != 0:
        sys.stderr.write((r.stdout or "") + "\n" + (r.stderr or ""))
        sys.exit(f"make_avatar_video 失败 (rc={r.returncode})")
    if not os.path.exists(out):
        sys.exit("成品未生成")
    print(f"\n成品: {out}  ({os.path.getsize(out)//1024} KB)")


if __name__ == "__main__":
    main()
