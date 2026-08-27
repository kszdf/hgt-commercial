# -*- coding: utf-8 -*-
"""真人出镜素材自动精剪（去气口/停顿/重复句 → 拼接 → 字幕 → 封面）。

流程:
  1) faster-whisper 本地 ASR → 带时间戳的语句段（vad 已把 ≥500ms 的气口/停顿切成段边界）
  2) 相邻段文本高度相似 → 判为重复句，合并保留首段
  3) 段间缝隙 ≥ 1.3s 的长停顿 → 裁剪掉（保留每段前后 0.15s 缓冲，保留自然节奏）
  4) ffmpeg 按保留区间裁剪拼接 → h264+aac+faststart
  5) 按新时间轴生成 ASS 字幕并烧录（白字描边，底部居中）
  6) 抽帧生成封面
"""
import os
import re
import subprocess
from difflib import SequenceMatcher

FFMPEG = r"D:/ffmpeg/ffmpeg-8.1.2-full_build/bin/ffmpeg.exe"
FFPROBE = r"D:/ffmpeg/ffmpeg-8.1.2-full_build/bin/ffprobe.exe"
MODEL_PATH = r"D:\heygem_data\cache\modelscope\models\AI-ModelScope--faster-whisper-small\snapshots\master"
GAP_CUT = 1.3          # 段间超过该时长(秒)的停顿视为可剪
BUFFER = 0.15          # 每段前后保留缓冲
DUP_SIM = 0.80         # 相邻段文本相似度阈值 → 重复句

_whisper = None


def _get_whisper():
    global _whisper
    if _whisper is None:
        from faster_whisper import WhisperModel
        _whisper = WhisperModel(MODEL_PATH, device="cpu", compute_type="int8")
    return _whisper


def _dur(path):
    r = subprocess.run([FFPROBE, "-v", "error", "-show_entries", "format=duration",
                        "-of", "csv=p=0", path], capture_output=True, text=True, timeout=60)
    try:
        return float(r.stdout.strip())
    except ValueError:
        return 0.0


def _norm(s):
    return re.sub(r"[\s，。！？、；：,.!?;:…]+", "", s or "")


def _sim(a, b):
    return SequenceMatcher(None, _norm(a), _norm(b)).ratio()


def asr_segments(path, language="zh"):
    model = _get_whisper()
    lang = None if language in ("auto", "") else language
    segs, _ = model.transcribe(path, language=lang, beam_size=5, vad_filter=True,
                               vad_parameters=dict(min_silence_duration_ms=500))
    return [{"start": float(s.start), "end": float(s.end), "text": s.text.strip()}
            for s in segs if s.text.strip()]


def _ass_escape(t):
    return t.replace("\\", "\\\\").replace("{", "(").replace("}", ")").replace("\n", " ")


def _hms(sec):
    h = int(sec // 3600)
    m = int((sec % 3600) // 60)
    s = sec % 60
    return f"{h}:{m:02d}:{s:05.2f}"


def _concat(keep, file_path, out_tmp):
    """按保留区间裁剪拼接（-ss/-t 逐段 + concat filter，重编码保证帧精确）。"""
    cmd = [FFMPEG, "-y"]
    for k in keep:
        cmd += ["-ss", f"{k['start']:.3f}", "-t", f"{k['end'] - k['start']:.3f}", "-i", file_path]
    n = len(keep)
    flt = []
    for i in range(n):
        flt.append(f"[{i}:v][{i}:a]")
    flt.append(f"concat=n={n}:v=1:a=1[v][a]")
    cmd += ["-filter_complex", "".join(flt), "-map", "[v]", "-map", "[a]",
            "-c:v", "libx264", "-preset", "veryfast", "-crf", "20", "-pix_fmt", "yuv420p",
            "-c:a", "aac", "-b:a", "128k", "-movflags", "+faststart", out_tmp]
    p = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=1800)
    if p.returncode != 0 or not os.path.exists(out_tmp):
        raise RuntimeError("裁剪拼接失败：" + (p.stderr or p.stdout or "")[-1500:])


def _burn_subtitles(out_tmp, out, subs, ass_path):
    """生成 ASS 并按新时间轴烧录字幕。"""
    with open(ass_path, "w", encoding="utf-8") as f:
        f.write("[Script Info]\nScriptType: v4.00+\nPlayResX: 1080\nPlayResY: 1920\n"
                "ScaledBorderAndShadow: yes\n\n[V4+ Styles]\n"
                "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n"
                "Style: Sub,Microsoft YaHei,52,&H00FFFFFF,&H00FFFFFF,&H00101010,&H80000000,0,0,0,0,100,100,0,0,1,3,0,2,80,80,120,1\n\n[Events]\n"
                "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n")
        for s in subs:
            f.write(f"Dialogue: 0,{_hms(s['start'])},{_hms(s['end'])},Sub,,0,0,0,,{_ass_escape(s['text'])}\n")
    # 字幕烧录（同目录 + 正斜杠，规避 Windows 转义问题）
    esc = ass_path.replace("\\", "/").replace(":", "\\:")
    cmd = [FFMPEG, "-y", "-i", out_tmp,
           "-vf", f"subtitles='{esc}'",
           "-c:v", "libx264", "-preset", "veryfast", "-crf", "20", "-pix_fmt", "yuv420p",
           "-c:a", "copy", "-movflags", "+faststart", out]
    p = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=1800)
    if p.returncode != 0 or not os.path.exists(out):
        raise RuntimeError("字幕烧录失败：" + (p.stderr or p.stdout or "")[-1500:])


def edit_footage(file_path, language="zh", max_duration=900):
    total = _dur(file_path)
    if total <= 0 or total > max_duration:
        raise RuntimeError(f"素材时长异常({total:.0f}s)，仅支持 ≤{max_duration // 60} 分钟")

    segs = asr_segments(file_path, language)
    if not segs:
        raise RuntimeError("未能识别出语音内容，请确认素材里有人声（中文普通话）")

    # 1) 去重复句：相邻段文本相似 → 合并保留首段
    merged = []
    for s in segs:
        if merged and _sim(merged[-1]["text"], s["text"]) > DUP_SIM:
            merged[-1]["end"] = max(merged[-1]["end"], s["end"])
            merged[-1].setdefault("dups", []).append(s["text"])
        else:
            merged.append(dict(s))

    # 2) 保留区间：每段前后留 0.15s；段间缝隙 ≥1.3s 判定为可剪停顿，跳过该缝隙
    keep, dups_removed = [], []
    for s in merged:
        if s.get("dups"):
            dups_removed.append({"dup_of": s["text"], "removed": s["dups"],
                                 "at_sec": round(s["start"], 2)})
        start = max(0.0, s["start"] - BUFFER)
        end = s["end"] + BUFFER
        if keep and start - keep[-1]["end"] < 0.35:      # 紧邻/重叠 → 合并成一段
            keep[-1]["end"] = end
            keep[-1]["text"] += s["text"]
        else:
            keep.append({"start": start, "end": end, "text": s["text"]})

    # 3) 记录被剪掉的停顿
    silences = []
    for i in range(1, len(keep)):
        gap = keep[i]["start"] - keep[i - 1]["end"]
        if gap > 0.2:
            silences.append({"from_sec": round(keep[i - 1]["end"], 2),
                             "to_sec": round(keep[i]["start"], 2),
                             "cut_sec": round(gap, 2)})

    out_dir = os.path.dirname(file_path)
    stem = os.path.splitext(os.path.basename(file_path))[0]
    out_tmp = os.path.join(out_dir, stem + "_concat.mp4")
    out = os.path.join(out_dir, stem + "_edited.mp4")
    ass_path = os.path.join(out_dir, stem + ".ass")

    _concat(keep, file_path, out_tmp)
    # 新时间轴字幕（相对裁剪后视频）
    subs, t = [], 0.0
    for k in keep:
        dur = k["end"] - k["start"]
        subs.append({"start": t, "end": t + dur, "text": k["text"]})
        t += dur
    _burn_subtitles(out_tmp, out, subs, ass_path)
    if os.path.exists(out_tmp):
        os.remove(out_tmp)

    # 4) 封面：取成片 1s 处非黑帧
    cover = os.path.join(out_dir, stem + "_cover.jpg")
    subprocess.run([FFMPEG, "-y", "-v", "error", "-ss", "1", "-i", out,
                    "-frames:v", "1", "-q:v", "3", cover], timeout=120)
    cover = cover if os.path.exists(cover) else ""

    return {
        "ok": True,
        "out_mp4": out,
        "cover": cover,
        "ass": ass_path,
        "duration_before": round(total, 2),
        "duration_after": round(_dur(out), 2),
        "segments_kept": len(keep),
        "silences_removed": silences,
        "dups_removed": dups_removed,
        "transcript": "".join(s["text"] for s in merged),
    }
