# -*- coding: utf-8 -*-
"""
照片 -> 数字人微动视频（单图口播前置步骤）

背景/必要性：
  HEYGEM 底层 /easy/submit 只接受 video_url（源视频），不接受图片。
  「单图口播」= 一张照片直接出镜讲，因此必须先把照片转成一段**短视频**，
  再把它当作 --model 喂给现有 make_avatar_from_dialogue 链路（HEYGEM 会重绘嘴部+面部）。

本脚本只做「照片 -> 一段稳定的竖屏微动视频」这一件事：
  1) 竖屏适配：非 9:16 的照片做 contain 缩放 + 高斯模糊铺底（不裁掉人像，四周不黑边难看）；
  2) 微动：默认「本地微动」——缓慢推拉(zoompan) + 轻微呼吸感，画面稳、像新闻主播固定机位。
     预留 --i2v 分支（外部 AI 图生视频），默认关闭：i2v 会改人脸角度/手部结构，
     HEYGEM 再重绘会放大形变错误，且按秒计费；默认不用，需要时一个参数切换；
  3) 静音音轨：永远加 anullsrc 静音轨（HEYGEM 要求模特视频含音频流，且杜绝原声污染）；
  4) 时长：默认 6s（足够 HEYGEM 取首帧做动作参考；太长无意义、徒增渲染）。

输出：1080x1920 H.264 mp4，含静音 AAC 音轨。
"""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys

FFMPEG = r"D:/ffmpeg/ffmpeg-8.1.2-full_build/bin/ffmpeg.exe"
FFPROBE = r"D:/ffmpeg/ffmpeg-8.1.2-full_build/bin/ffprobe.exe"

W, H = 1080, 1920
FPS = 30


def run(cmd, timeout=180):
    return subprocess.run(cmd, capture_output=True, text=True,
                          encoding="utf-8", errors="ignore", timeout=timeout)


def probe_image(path: str) -> dict:
    r = run([FFPROBE, "-v", "error", "-select_streams", "v:0",
             "-show_entries", "stream=width,height", "-of", "json", path])
    try:
        s = json.loads(r.stdout)["streams"][0]
        return {"width": int(s["width"]), "height": int(s["height"])}
    except Exception:  # noqa: BLE001
        return {}


def _has_alpha_or_white_bg(path: str) -> bool:
    return os.path.splitext(path)[1].lower() in (".png",)


def build_local_motion_filter(zoom_end: float = 1.05) -> str:
    """本地微动滤镜链：模糊铺底 + 人像饱满填充 + 缓慢推拉(zoompan)。

    构图策略（实测最优，针对证件照/半身像这类「人像居中、上下有留白」的照片）：
      1) 背景层：把原图等比放大到**同时覆盖** 1080x1920（increase），再裁中间 —— 保证无黑边；
      2) 前景层：等比缩放到高度 1880（FG_H，留 40px 呼吸空间防切头顶），
         再居中裁到宽 1080（缩放后通常宽 > 1080，裁掉两侧多余；若窄则居中保留）；
      3) 两层叠加后再缓慢推拉，画面像固定机位的新闻主播，稳且自然。
    用 split 把源图分成两路（背景/前景），避免同一输入标签重复引用。
    """
    fg_h = H - 40  # 1880：略小于画布高，保证头顶/下巴不被切
    # --- 背景：先放大到覆盖整幅画布，再居中裁剪，最后高斯模糊 ---
    bg = (
        f"[bg0]scale={W}:{H}:force_original_aspect_ratio=increase,"
        f"crop={W}:{H},"
        f"gblur=sigma=32[bg];"
    )
    # --- 前景：高度优先缩放到 fg_h，再居中裁到 W 宽 ---
    fg = (
        f"[fg0]scale=-2:{fg_h},"
        f"crop={W}:{fg_h}:(iw-{W})/2:0[fg];"
    )
    # --- 叠加 + 统一帧率/像素格式 ---
    overlay = f"[bg][fg]overlay=({W}-w)/2:({H}-h)/2:shortest=0,setsar=1,fps={FPS},format=yuv420p[canvas]"
    # --- 缓慢推拉：从 1.0 缓加，收尾停在 zoom_end ---
    n = int(8 * FPS)
    zoom = (
        f"[canvas]zoompan=z='min(zoom+0.00045,{zoom_end})':"
        f"x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d={n}:s={W}x{H}:fps={FPS}[v]"
    )
    return f"split=2[bg0][fg0];{bg}{fg}{overlay};{zoom}"


def build_still_filter() -> str:
    """静止版（不推拉）：与人像构图一致的模糊铺底 + 饱满人像，只是不加 zoompan。"""
    fg_h = H - 40
    return (
        f"split=2[bg0][fg0];"
        f"[bg0]scale={W}:{H}:force_original_aspect_ratio=increase,crop={W}:{H},"
        f"gblur=sigma=32[bg];"
        f"[fg0]scale=-2:{fg_h},crop={W}:{fg_h}:(iw-{W})/2:0[fg];"
        f"[bg][fg]overlay=({W}-w)/2:({H}-h)/2:shortest=0,setsar=1,fps={FPS},"
        f"format=yuv420p[v]"
    )


def photo_to_video(photo: str, out: str, duration: float = 6.0,
                   i2v: bool = False, still: bool = False) -> dict:
    if not os.path.exists(photo):
        sys.exit(f"照片不存在：{photo}")

    os.makedirs(os.path.dirname(os.path.abspath(out)), exist_ok=True)
    info = probe_image(photo)

    if i2v:
        # 预留位：接外部图生视频模型（当前未接入，直接回退本地微动并提示）
        sys.stderr.write("[photo] --i2v 暂未接入外部模型，回退本地微动\n")
        i2v = False

    if still:
        graph = "[0:v]" + build_still_filter()
    else:
        graph = "[0:v]" + build_local_motion_filter()

    # 两个输入：图片（循环）+ 静音音源；所有滤镜/时长/映射均置于输出侧
    cmd = [
        FFMPEG, "-y",
        "-loop", "1", "-i", photo,
        "-f", "lavfi", "-i", "anullsrc=r=22050:cl=mono",
        "-filter_complex", graph,
        "-map", "[v]", "-map", "1:a",
        "-t", str(duration),
        "-c:v", "libx264", "-preset", "medium", "-crf", "20",
        "-pix_fmt", "yuv420p", "-r", str(FPS),
        "-c:a", "aac", "-b:a", "96k", "-shortest",
        "-movflags", "+faststart",
        out,
    ]
    r = run(cmd, timeout=240)
    if r.returncode != 0 or not os.path.exists(out):
        sys.stderr.write((r.stderr or r.stdout or "")[-600:] + "\n")
        sys.exit("ffmpeg 生成微动视频失败")

    size = os.path.getsize(out)
    return {"out": out, "duration": duration, "width": W, "height": H,
            "size": size, "src": info, "mode": "still" if still else "motion"}


def main():
    ap = argparse.ArgumentParser(description="照片 -> 数字人微动视频（单图口播前置）")
    ap.add_argument("--photo", required=True, help="输入照片（jpg/png）")
    ap.add_argument("--out", required=True, help="输出竖屏 mp4 路径")
    ap.add_argument("--duration", type=float, default=6.0, help="时长秒，默认 6")
    ap.add_argument("--i2v", action="store_true", help="尝试外部 AI 图生视频（预留，默认关）")
    ap.add_argument("--still", action="store_true", help="完全静止（不做推拉微动）")
    args = ap.parse_args()

    info = photo_to_video(args.photo, args.out, args.duration, args.i2v, args.still)
    print(json.dumps({"ok": True, **info}, ensure_ascii=False))


if __name__ == "__main__":
    main()
