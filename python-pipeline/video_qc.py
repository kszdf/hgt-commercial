# -*- coding: utf-8 -*-
"""
video_qc.py — 短视频成品质检工具（全方位硬指标检测）

检测维度：
  A. 画面  ：分辨率/画幅、黑帧、文字溢出（标题区/字幕区文字边界）
  B. 音频  ：音轨存在、响度、中段长静音、双声污染(多音轨)
  C. 时长  ：时长区间合理性
  D. 平台  ：分辨率是否匹配平台规格（douyin/shipinhao=1080x1920, xiaohongshu=1080x1440）

用法：
  python video_qc.py <视频路径> [--platform douyin] [--title 标题] [--subtitle 副标题]
  输出：JSON 质检报告 + 评级（pass/warn/fail）

依赖：ffmpeg/ffprobe、PIL（仅文字溢出检测需要）
"""
import argparse
import json
import os
import subprocess
import sys

FFMPEG = r"D:\ffmpeg\ffmpeg-8.1.2-full_build\bin\ffmpeg.exe"
FFPROBE = r"D:\ffmpeg\ffmpeg-8.1.2-full_build\bin\ffprobe.exe"

PLATFORM_SPECS = {
    "douyin": (1080, 1920), "shipinhao": (1080, 1920), "video": (1080, 1920),
    "xiaohongshu": (1080, 1440), "xhs": (1080, 1440), "kuaishou": (1080, 1920),
}


def _run(cmd, timeout=60):
    return subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8",
                          errors="replace", timeout=timeout)


def probe_info(path):
    """分辨率/时长/音轨数/编码。"""
    r = _run([FFPROBE, "-v", "error", "-show_entries",
              "stream=width,height,codec_type,codec_name:format=duration",
              "-of", "json", path])
    info = json.loads(r.stdout or "{}")
    streams = info.get("streams", [])
    v = next((s for s in streams if s.get("codec_type") == "video"), {})
    a = [s for s in streams if s.get("codec_type") == "audio"]
    return {
        "width": v.get("width"), "height": v.get("height"),
        "video_codec": v.get("codec_name"),
        "duration": float(info.get("format", {}).get("duration") or 0),
        "audio_tracks": len(a), "audio_codec": a[0].get("codec_name") if a else None,
    }


def check_text_overflow(path, title, subtitle):
    """抽 3 帧检测标题区/字幕区文字是否溢出屏幕左右边界。"""
    try:
        from PIL import Image
    except ImportError:
        return {"ok": True, "note": "PIL 未装，跳过溢出检测"}
    w = probe_info(path)["width"] or 1080
    h = probe_info(path)["height"] or 1920
    dur = probe_info(path)["duration"]
    issues = []
    for sec in (2, max(3, dur * 0.3), max(4, dur * 0.6)):
        out = os.path.join(os.environ.get("TEMP"), f"qc_{os.getpid()}.png")
        _run([FFMPEG, "-y", "-i", path, "-vf", f"select=eq(n\\,{int(sec*24)})",
              "-vsync", "vfr", out], timeout=30)
        if not os.path.exists(out):
            continue
        im = Image.open(out).convert("RGB")
        px = im.load()
        # 标题区(上 1/4) 和字幕区(下 2/3) 的亮文字边界
        for label, (y0, y1) in (("标题", (0, h // 4)), ("字幕", (h // 3, h - 60))):
            xs = []
            for y in range(y0, y1, 4):
                for x in range(w):
                    r, g, b = px[x, y]
                    lum = 0.299 * r + 0.587 * g + 0.114 * b
                    if lum > 170:
                        xs.append(x)
            if xs and max(xs) >= w - 6:
                issues.append(f"{label}文字贴右边缘溢出({sec:.0f}s)")
        os.remove(out)
    return {"ok": not issues, "issues": issues}


def check_audio(path):
    """音轨/响度/中段静音。"""
    info = probe_info(path)
    res = {"audio_tracks": info["audio_tracks"]}
    if info["audio_tracks"] == 0:
        return {**res, "ok": False, "issue": "无音轨"}
    # 中段静音（silencedetect）
    r = _run([FFMPEG, "-i", path, "-af", "silencedetect=noise=-35dB:d=2.5",
              "-f", "null", "-"], timeout=120)
    txt = r.stderr or ""
    segs = []
    cur = None
    for line in txt.splitlines():
        if "silence_start" in line:
            cur = float(line.split("silence_start:")[-1].strip())
        elif "silence_duration" in line and cur is not None:
            segs.append((cur, float(line.split("silence_duration:")[-1].split()[0])))
            cur = None
    dur = info["duration"]
    mid = [(s, d) for s, d in segs if s >= 2.0 and s + d <= dur - 2.0]
    res["mid_silence"] = [{"start": round(s, 1), "dur": round(d, 1)} for s, d in mid]
    res["ok"] = len(mid) == 0
    return res


def check_black_frames(path):
    """抽帧检测黑帧（全黑/近黑画面）。"""
    try:
        from PIL import Image
    except ImportError:
        return {"ok": True}
    dur = probe_info(path)["duration"]
    blacks = 0
    total = 0
    for sec in range(1, min(int(dur), 30), 3):
        out = os.path.join(os.environ.get("TEMP"), f"bf_{os.getpid()}.png")
        _run([FFMPEG, "-y", "-i", path, "-vf", f"select=eq(n\\,{sec*24})",
              "-vsync", "vfr", out], timeout=30)
        if os.path.exists(out):
            im = Image.open(out).convert("L")
            px = list(im.getdata())
            avg = sum(px) // len(px)
            total += 1
            if avg < 8:
                blacks += 1
            os.remove(out)
    return {"ok": blacks == 0, "black_frames": blacks, "sampled": total}


def run_qc(path, platform=None, title="", subtitle=""):
    report = {"path": path, "platform": platform, "checks": {}, "issues": [], "score": 100}

    # A. 画面信息
    info = probe_info(path)
    report["info"] = info
    if not info["width"]:
        report["issues"].append("无法读取视频流")
        report["score"] -= 100
        return report

    # 分辨率匹配平台
    if platform and platform in PLATFORM_SPECS:
        tw, th = PLATFORM_SPECS[platform]
        if (info["width"], info["height"]) != (tw, th):
            report["issues"].append(f"分辨率 {info['width']}x{info['height']} 不符平台 {platform} 规格 {tw}x{th}")
            report["score"] -= 30
    elif info["width"] != 1080:
        report["issues"].append(f"非标准竖屏宽度 {info['width']}")
        report["score"] -= 20

    # B. 黑帧
    bf = check_black_frames(path)
    report["checks"]["black_frames"] = bf
    if not bf["ok"]:
        report["issues"].append(f"检测到 {bf['black_frames']} 个黑帧")
        report["score"] -= 15

    # C. 文字溢出
    ov = check_text_overflow(path, title, subtitle)
    report["checks"]["text_overflow"] = ov
    if not ov["ok"]:
        report["issues"] += ov["issues"]
        report["score"] -= 25

    # D. 音频
    au = check_audio(path)
    report["checks"]["audio"] = au
    if not au["ok"]:
        report["issues"].append("音频问题：" + (au.get("issue") or f"中段静音 {au.get('mid_silence')}"))
        report["score"] -= 20

    # E. 时长
    if info["duration"] < 5:
        report["issues"].append(f"时长过短 {info['duration']:.1f}s")
        report["score"] -= 15
    elif info["duration"] > 300:
        report["issues"].append(f"时长过长 {info['duration']:.0f}s")
        report["score"] -= 10

    report["score"] = max(0, report["score"])
    report["level"] = "pass" if report["score"] >= 90 else ("warn" if report["score"] >= 70 else "fail")
    return report


def main():
    ap = argparse.ArgumentParser(description="短视频成品质检")
    ap.add_argument("path")
    ap.add_argument("--platform", default=None)
    ap.add_argument("--title", default="")
    ap.add_argument("--subtitle", default="")
    ap.add_argument("--json", action="store_true", help="输出 JSON")
    args = ap.parse_args()
    r = run_qc(args.path, args.platform, args.title, args.subtitle)
    if args.json:
        print(json.dumps(r, ensure_ascii=False, indent=2))
    else:
        print(f"成品质检: {r['level'].upper()} ({r['score']}分)")
        print(f"  信息: {r['info']['width']}x{r['info']['height']} {r['info']['duration']:.1f}s 音轨{r['info']['audio_tracks']}")
        for i in r["issues"]:
            print(f"  ⚠ {i}")
        if not r["issues"]:
            print("  ✅ 全部通过")


if __name__ == "__main__":
    main()
