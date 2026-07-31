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
  - avatar 为单声出镜（默认男声=张老师）；数字人模特为男模 BGZSP，故统一用男声。
  - 依赖 gpt_sovits 下的 qwen_tts（真实配音）、make_avatar_video.py（HEYGEM 流程）、
    本地 HEYGEM 容器(http://localhost:8383)、ffmpeg 全量、model_keys.env 的 dashscope key。

用法:
  python make_avatar_from_dialogue.py --dialogue dlg.txt --out out.mp4 --voice <voice_id>
"""
import argparse
import os
import subprocess
import sys
import tempfile
import uuid

HERE = os.path.dirname(os.path.abspath(__file__))
GPT_SOVITS = r"D:/heygem_data/gpt_sovits"
sys.path.insert(0, GPT_SOVITS)

FFMPEG = r"D:/ffmpeg/ffmpeg-8.1.2-full_build/bin/ffmpeg.exe"
FFPROBE = r"D:/ffmpeg/ffmpeg-8.1.2-full_build/bin/ffprobe.exe"
MAKE_AVATAR = os.path.join(GPT_SOVITS, "make_avatar_video.py")
PY310 = r"D:/heygem/py310/Scripts/python.exe"
DEFAULT_MODEL = "/code/data/BGZSP20260721_t18_silent.mp4"  # 容器内男模路径

from qwen_tts import synth as qwen_synth


def _clean(text):
    return text.replace("**", "")


def parse_dialogue(text):
    segs = []
    for line in text.splitlines():
        line = line.strip()
        if not line:
            continue
        if line.startswith("女") or line.startswith("女：") or line.startswith("女:"):
            txt = line[line.find("：") + 1:] if "：" in line else line[line.find(":") + 1:]
        elif line.startswith("男") or line.startswith("男：") or line.startswith("男:"):
            txt = line[line.find("：") + 1:] if "：" in line else line[line.find(":") + 1:]
        else:
            txt = line
        txt = txt.strip()
        if txt:
            segs.append(txt)
    return segs


def wav_duration(path):
    r = subprocess.run(
        [FFPROBE, "-v", "error", "-show_entries", "format=duration",
         "-of", "default=noprint_wrappers=1:nokey=1", path],
        capture_output=True, text=True, check=True)
    return float(r.stdout.strip())


def synth_concat(segs, voice, tmpdir, gap=0.25):
    """逐句合成真实配音，句间插 gap，拼成总音频；返回 (总wav, [(start,end,display)])。"""
    seg_wavs, starts, ends, displays = [], [], [], []
    t = 0.0
    for i, txt in enumerate(segs):
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
    ap.add_argument("--dialogue", required=True, help="对话稿 txt（每行 女：/男： 开头）")
    ap.add_argument("--out", required=True, help="输出 mp4 路径")
    ap.add_argument("--voice", default="cosyvoice-v3-plus-zhangc2-28a7c3541e1c45518a03046c11baeb1d",
                    help="配音音色 voice_id（默认张老师克隆音）")
    ap.add_argument("--model", default=DEFAULT_MODEL, help="容器内模特视频路径")
    args = ap.parse_args()

    with open(args.dialogue, encoding="utf-8-sig") as f:
        segs = parse_dialogue(f.read())
    if not segs:
        sys.exit("对话稿为空或解析失败")

    tmp = tempfile.mkdtemp(prefix="avatar_")
    audio_wav, timed = synth_concat(segs, args.voice, tmp)
    ass_path = os.path.join(tmp, "sub.ass")
    write_ass(timed, ass_path)
    print(f"[avatar] 配音 {len(segs)} 句，总时长 {timed[-1][1]:.1f}s，字幕已生成")

    out = os.path.abspath(args.out)
    os.makedirs(os.path.dirname(out), exist_ok=True)
    tag = "hgt_" + uuid.uuid4().hex[:6]
    r = subprocess.run(
        [PY310, MAKE_AVATAR, "--audio", audio_wav, "--ass", ass_path,
         "--model", args.model, "--out", out, "--name", tag],
        cwd=GPT_SOVITS, capture_output=True, text=True)
    if r.returncode != 0:
        sys.stderr.write(r.stdout + "\n" + r.stderr)
        sys.exit(f"make_avatar_video 失败 (rc={r.returncode})")
    if not os.path.exists(out):
        sys.exit("成品未生成")
    print(f"\n成品: {out}  ({os.path.getsize(out)//1024} KB)")


if __name__ == "__main__":
    main()
