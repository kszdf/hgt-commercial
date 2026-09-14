# -*- coding: utf-8 -*-
"""视频呈现形式拆解引擎（本地，不依赖云服务）。

拆四层：
  1) 技术规格  —— ffprobe 拿分辨率/帧率/时长/码率/音轨
  2) 画面形态  —— 场景变化点抽帧 + 均匀抽帧 + 九宫格概览（供多模态读图判形态）
  3) 叙事分幕  —— 由转写时间戳 + 画面切换点推算
  4) 声音与文字 —— faster-whisper 本地转写（财税术语后纠正）

用法:
  python video_dissect.py <video_path> [--out DIR] [--tag NAME]

产出目录:
  meta.json          技术规格 + 素材清单
  frames/*.jpg       关键帧（场景变化点优先，不足则均匀补齐）
  contact_sheet.jpg  九宫格概览
  transcript.txt     带时间戳逐字稿
  report.md          拆解报告骨架（语义判读段留待 Agent 读图补全）
"""
import argparse
import json
import os
import re
import subprocess
import sys
import time

FFMPEG = r"D:/ffmpeg/ffmpeg-8.1.2-full_build/bin/ffmpeg.exe"
FFPROBE = r"D:/ffmpeg/ffmpeg-8.1.2-full_build/bin/ffprobe.exe"
WHISPER_MODEL = (r"D:\heygem_data\cache\modelscope\models"
                 r"\AI-ModelScope--faster-whisper-small\snapshots\master")

# 财税高频误听纠正（whisper small 对行业词较弱，先兜底再交 LLM 润色）
TERM_FIX = {
    "同分红": "视同分红", "支纳金": "滞纳金", "进向税": "进项税",
    "销向税": "销项税", "虚开法票": "虚开发票", "补购税": "补个税",
    "私户收款": "私户收款", "公转思": "公转私", "视同销受": "视同销售",
}


def run(cmd):
    p = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8",
                       errors="replace")
    return p.returncode, (p.stdout or "") + (p.stderr or "")


def probe(video):
    """技术规格 + 首条音轨信息。"""
    rc, out = run([FFPROBE, "-v", "error", "-show_format", "-show_streams",
                   "-of", "json", video])
    if rc != 0:
        return {"error": out.strip()[:300]}
    data = json.loads(out)
    fmt = data.get("format", {})
    v = next((s for s in data.get("streams", []) if s.get("codec_type") == "video"), {})
    a = next((s for s in data.get("streams", []) if s.get("codec_type") == "audio"), {})

    def num(x, nd=2):
        try:
            return round(float(x), nd)
        except (TypeError, ValueError):
            return None

    spec = {
        "duration_sec": num(fmt.get("duration")),
        "size_mb": round(int(fmt.get("size") or 0) / 1048576, 2),
        "bitrate_kbps": num(int(fmt.get("bit_rate") or 0) / 1000, 0),
        "width": v.get("width"),
        "height": v.get("height"),
        "fps": v.get("r_frame_rate"),
        "video_codec": v.get("codec_name"),
        "audio_codec": a.get("codec_name"),
        "audio_sample_rate": a.get("sample_rate"),
        "audio_channels": a.get("channels"),
        "has_audio": bool(a),
    }
    w, h = spec["width"], spec["height"]
    spec["aspect"] = ("竖屏 9:16" if h and w and h > w * 1.5 else
                      "横屏 16:9" if w and h and w > h * 1.5 else
                      "方形/其他" if w and h else None)
    # 22050Hz 单声道 + aac ≈ TTS 合成音；44.1k/48k 立体声 ≈ 真人收音
    if a:
        sr = int(a.get("sample_rate") or 0)
        spec["voice_hint"] = ("疑似 TTS 合成音（低采样率单声道）" if sr <= 24000
                              else "疑似真人收音（高采样率）")
    return spec


def extract_frames(video, frames_dir, max_frames=12, scene_thr=0.25):
    """场景变化点抽帧；若变化点太少（固定机位），用均匀抽帧补齐。"""
    os.makedirs(frames_dir, exist_ok=True)
    for f in os.listdir(frames_dir):
        os.remove(os.path.join(frames_dir, f))

    run([FFMPEG, "-y", "-hide_banner", "-loglevel", "error", "-i", video,
         "-vf", f"select='gt(scene,{scene_thr})',scale=540:-2",
         "-vsync", "vfr", "-frames:v", str(max_frames),
         os.path.join(frames_dir, "scene_%02d.jpg")])
    scene_n = len([f for f in os.listdir(frames_dir) if f.startswith("scene_")])

    if scene_n < 6:
        rc, out = run([FFPROBE, "-v", "error", "-show_entries", "format=duration",
                       "-of", "default=nw=1:nk=1", video])
        try:
            dur = float(out.strip())
        except ValueError:
            dur = 30.0
        step = max(2.0, dur / 9.0)
        run([FFMPEG, "-y", "-hide_banner", "-loglevel", "error", "-i", video,
             "-vf", f"fps=1/{step:.2f},scale=540:-2",
             os.path.join(frames_dir, "even_%02d.jpg")])

    return sorted(os.listdir(frames_dir)), scene_n


def contact_sheet(video, out_path, dur, grid=9):
    """九宫格概览图，用于一眼看清整体形态与节奏。"""
    step = max(1.0, (dur or 30.0) / grid)
    cols = 3
    rows = (grid + cols - 1) // cols
    run([FFMPEG, "-y", "-hide_banner", "-loglevel", "error", "-i", video,
         "-vf", f"fps=1/{step:.2f},scale=360:-2,tile={cols}x{rows}",
         "-frames:v", "1", "-q:v", "3", out_path])
    return os.path.exists(out_path)


def transcribe(video, out_dir):
    wav = os.path.join(out_dir, "audio16k.wav")
    run([FFMPEG, "-y", "-hide_banner", "-loglevel", "error", "-i", video,
         "-vn", "-ac", "1", "-ar", "16000", "-f", "wav", wav])
    if not os.path.exists(wav):
        return [], "音频抽取失败"

    try:
        from faster_whisper import WhisperModel
    except ImportError:
        return [], "faster_whisper 未安装"

    model = WhisperModel(WHISPER_MODEL, device="cpu", compute_type="int8")
    segments, info = model.transcribe(wav, language="zh", vad_filter=True, beam_size=1)

    rows, lines = [], []
    for s in segments:
        text = (s.text or "").strip()
        for bad, good in TERM_FIX.items():
            text = text.replace(bad, good)
        rows.append({"start": round(s.start, 2), "end": round(s.end, 2), "text": text})
        lines.append(f"{s.start:6.2f}-{s.end:6.2f}  {text}")

    with open(os.path.join(out_dir, "transcript.txt"), "w", encoding="utf-8") as f:
        f.write("\n".join(lines))
    try:
        os.remove(wav)
    except OSError:
        pass
    return rows, None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("video")
    ap.add_argument("--out", default=None)
    ap.add_argument("--tag", default=None)
    args = ap.parse_args()

    video = os.path.abspath(args.video)
    if not os.path.exists(video):
        print(json.dumps({"ok": False, "error": f"文件不存在: {video}"},
                         ensure_ascii=False))
        sys.exit(1)

    tag = args.tag or os.path.splitext(os.path.basename(video))[0]
    out_dir = args.out or os.path.join(os.path.dirname(video), f"_dissect_{tag}")
    os.makedirs(out_dir, exist_ok=True)
    frames_dir = os.path.join(out_dir, "frames")

    t0 = time.time()
    spec = probe(video)
    files, scene_n = extract_frames(video, frames_dir)
    sheet_ok = contact_sheet(video, os.path.join(out_dir, "contact_sheet.jpg"),
                             spec.get("duration_sec"))
    segs, err = transcribe(video, out_dir)

    meta = {
        "ok": True,
        "source": video,
        "tag": tag,
        "spec": spec,
        "frames": files,
        "scene_change_frames": scene_n,
        "contact_sheet": sheet_ok,
        "segments": segs,
        "transcript_error": err,
        "elapsed_sec": round(time.time() - t0, 1),
    }
    with open(os.path.join(out_dir, "meta.json"), "w", encoding="utf-8") as f:
        json.dump(meta, f, ensure_ascii=False, indent=2)

    full_text = "".join(s["text"] for s in segs)
    lines = [
        f"# 视频拆解 · {tag}", "",
        "## 一、技术规格",
        f"- 时长 {spec.get('duration_sec')}s ｜ {spec.get('width')}×{spec.get('height')} "
        f"（{spec.get('aspect')}）｜ {spec.get('fps')} fps",
        f"- 码率 {spec.get('bitrate_kbps')} kbps ｜ 体积 {spec.get('size_mb')} MB",
        f"- 音轨 {spec.get('audio_codec')} ｜ {spec.get('audio_sample_rate')} Hz ｜ "
        f"{spec.get('audio_channels')} 声道 ｜ {spec.get('voice_hint')}",
        "", "## 二、画面形态",
        f"- 场景切换点 {scene_n} 个（切换少=固定机位/单场景，切换多=多幕剪辑）",
        f"- 关键帧 {len(files)} 张，见 frames/ 与 contact_sheet.jpg",
        "- 【待判读】形态归类（实拍/数字人/卡通/纯图文）、角色与场景、镜头语言",
        "", "## 三、叙事分幕",
        f"- 语音段落 {len(segs)} 段",
        "- 【待判读】分幕结构与情绪曲线（结合关键帧与语音时间戳）",
        "", "## 四、声音与文字",
        "- 【待判读】配音形式（单声/男女对话）、有无字幕及其位置样式",
        "", "## 五、逐字稿",
        full_text or "（无语音）",
        "",
        "## 六、可复刻形式建议",
        "- 【待判读】映射到 scroll / avatar / motion / manga / whiteboard",
    ]
    with open(os.path.join(out_dir, "report.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(lines))

    print(json.dumps({
        "ok": True,
        "out_dir": out_dir,
        "duration": spec.get("duration_sec"),
        "frames": len(files),
        "scene_changes": scene_n,
        "segments": len(segs),
        "contact_sheet": os.path.join(out_dir, "contact_sheet.jpg"),
        "report": os.path.join(out_dir, "report.md"),
        "elapsed_sec": meta["elapsed_sec"],
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
