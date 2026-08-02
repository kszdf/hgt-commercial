#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
慧根堂商用平台 · 视频出片微服务（滚动字幕卡 + 本地数字人出镜）
零三方依赖（仅 Python 标准库），包装 gpt_sovits 下的出片脚本。

运行（Windows 宿主，复用既有 PY310 环境 + ffmpeg + 音色密钥）:
    D:/heygem/py310/Scripts/python.exe server.py
监听 0.0.0.0:8500

接口:
    GET  /health                     -> {"status":"ok"}
    POST /generate                   -> {"job_id","status":"queued"}
         body: {
           "mode": "scroll" | "avatar",
           "dialogue": "...(女：/男： 对话体)",
           "title": "...", "subtitle": "...",
           "bg": "可选背景图",
           "dry_tts": false,            # true=静音占位(仅验画面)；false=真实 CosyVoice 配音
           "male_voice": "voice_id",    # 可选，覆盖默认男声
           "female_voice": "voice_id"   # 可选，覆盖默认女声(仅 scroll 双声用)
         }
    GET  /status/{job_id}            -> {"job_id","status","result","error"}
    GET  /download/{job_id}          -> video/mp4 流

说明:
    - dry_tts=false（默认）走真实 TTS，需 model_keys.env 中的 dashscope key 与联网。
    - scroll 模式：多声（女：/男：）滚动字幕卡，不出镜。
    - avatar 模式：单声（默认男声=张老师）驱动本地数字人(HEYGEM) 嘴型对齐，出镜。
    - Laravel 容器经 host.docker.internal:8500 调用本服务，服务本身不对外暴露。
"""

import http.server
import json
import os
import shutil
import subprocess
import sys
import threading
import uuid
from urllib.parse import urlparse

GPT_SOVITS = r"D:/heygem_data/gpt_sovits"
# 复用 gpt_sovits 侧已验证的 DeepSeek 写稿封装与违禁词库（key 不进 Laravel，仅本机 model_keys.env）
sys.path.insert(0, GPT_SOVITS)
from model_providers import get_text_config, deepseek_chat  # noqa: E402
import forbidden_words  # noqa: E402
PY310 = r"D:/heygem/py310/Scripts/python.exe"
FFMPEG = r"D:/ffmpeg/ffmpeg-8.1.2-full_build/bin/ffmpeg.exe"
FFPROBE = r"D:/ffmpeg/ffmpeg-8.1.2-full_build/bin/ffprobe.exe"
# HEYGEM 数据根（宿主 d:/heygem_data/face2face 挂容器 /code/data）；用户自传模特存 uploads/ 下
FAC2FACE = r"d:/heygem_data/face2face"
# 项目 storage（宿主项目目录，bind mount 进 Laravel 容器，用于预览/管理）
PROJECT_STORAGE = r"D:/heygem_data/hgt-commercial/storage/app"
SCRIPT_SCROLL = os.path.join(GPT_SOVITS, "make_scroll_video.py")
SCRIPT_AVATAR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "make_avatar_from_dialogue.py")
JOBS_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "jobs")
os.makedirs(JOBS_DIR, exist_ok=True)

# 默认音色（与 gpt_sovits 定稿一致）；租户可在请求里覆盖
DEFAULT_MALE = "cosyvoice-v3-plus-zhangc2-28a7c3541e1c45518a03046c11baeb1d"
DEFAULT_FEMALE = "cosyvoice-v3-plus-jiangnv3-991b204c1d564ac7a60f0cb9a8fd78bd"

# 数字人模特注册表：前端/请求可传「友好名或裸文件名」，统一解析为 HEYGEM 容器内完整路径。
# 必须用 *_silent.mp4（静音音轨），否则 HEYGEM 会对原声做嘴型对齐导致原声污染。
DEFAULT_AVATAR_MODEL = "/code/data/BGZSP20260721_t18_silent.mp4"
MODEL_REGISTRY = {
    "bgzsp": DEFAULT_AVATAR_MODEL,
    "BGZSP20260721_t18_silent.mp4": DEFAULT_AVATAR_MODEL,
    "BGZSP20260721.mp4": "/code/data/BGZSP20260721.mp4",
    "szrsp": "/code/data/szrsp_silent.mp4",
    "szrsp_silent.mp4": "/code/data/szrsp_silent.mp4",
    "szrsp.mp4": "/code/data/szrsp.mp4",
    "yxszr": "/code/data/YXSZR.mp4",
    "yxszr1": "/code/data/YXSZR1.mp4",
    "cjps": "/code/data/cjps.mp4",
    "zmszr": "/code/data/zmszr20260727.mp4",
}

# ============ AI 文本能力（选题 / 二创，复用 gpt_sovits 的 DeepSeek + 违禁词）============
def ai_topic(industry, keywords, count):
    """智能选题：用 DeepSeek 生成财税短视频选题建议列表。返回 list[dict]。"""
    cfg = get_text_config()
    cnt = max(3, min(12, int(count or 6)))
    prompt = (
        f"你是资深财税短视频选题策划。面向「{industry or '财税'}」行业的企业主/老板，"
        f"结合关键词「{keywords or '税务风险、金税四期、公转私'}」，"
        f"生成 {cnt} 个高转化短视频选题。\n"
        "每个选题严格按 JSON 数组输出，元素结构：\n"
        '{"title":"标题(吸睛、戳痛点,≤18字)","angle":"切入角度/痛点","potential":"爆款潜力理由","hook":"结尾留资钩子建议","form":"建议形式:单声口播/双声对话"}\n'
        "只输出 JSON 数组，不要任何解释或代码块标记。"
    )
    content = deepseek_chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=90)
    content = content.strip()
    if content.startswith("```"):
        content = content.split("```")[1]
    try:
        return json.loads(content)
    except Exception:
        return [{"title": "解析失败", "angle": content[:200]}]


def ai_rewrite(text, mode, focus=None, target_duration=None, preserve=None):
    """智能二创：三模式改写 + 违禁词标红/清洗。返回含元数据的完整结果。"""
    cfg = get_text_config()
    if mode == "single":
        role = ("单人口播（男声=张老师，实战派税务顾问）。口语化、去AI播音腔、像真人在跟客户面对面聊；"
                "说话干脆利落、不拖长音、不堆语气词；该严肃严肃、该轻松轻松，自然有起伏，不演")
    elif mode == "script":
        role = "专业口播稿（保留财税术语与权威感，结构清晰、重点突出，适合直接配音）"
    else:
        role = ("双声对话：女声=江老师(抛疑问/场景)，男声=张老师(耐心解答，说话干脆、不拖长音、不堆语气词，像真人在跟客户聊)；"
                "女称呼男用「张老师」不用「张哥」；语气词极克制；结尾女声留咨询钩子")

    focus_hint = ""
    if focus and isinstance(focus, str) and focus.strip():
        focus_hint = f"\n【用户指定的重点方向】：{focus.strip()} — 请在改写中特别强化这个方向的内容比重与表达力度。\n"

    # 目标时长约束
    dur_hint = ""
    if target_duration is not None:
        try:
            secs = int(target_duration)
            if secs > 0:
                chars_low = max(30, round(secs * 130 / 60))   # 慢速约 130字/分
                chars_high = round(secs * 160 / 60)             # 快速约 160字/分
                dur_hint = (f"\n【目标时长约束】：用户要求改写稿适合 {secs} 秒的视频"
                            f"（约 {chars_low}–{chars_high} 字）。请严格控制输出长度在此范围内，"
                            f"过长则精简、过短则适当展开，但不要注水凑字数。\n")
        except (ValueError, TypeError):
            pass

    # 保留要素约束
    preserve_hint = ""
    if preserve and isinstance(preserve, str) and preserve.strip():
        items = [line.strip() for line in preserve.strip().splitlines() if line.strip()]
        if items:
            preserve_hint = ("\n【必须保留的要素】（以下内容在改写时绝对不能删除、替换或改写，"
                             "必须原样保留在输出稿中）：\n" +
                             "\n".join(f"  • {item}" for item in items) + "\n")

    prompt = (
        f"你是资深财税短视频脚本编辑。请把下面的稿子改写为「{role}」的自然口语稿，"
        "彻底去除AI机械感与书面腔，但保持财税专业准确性、不编造数据、不改原意。"
        f"{focus_hint}{dur_hint}{preserve_hint}"
        "要求：保留原意与关键结论；长短句结合、自然停顿；不堆砌语气词、禁用'啊/嘛/呢/哎哟'等夸张口语；"
        "对话感来自内容互动而非语气词；老张说话干脆直给。\n"
        "只输出改写后的稿子本身，不要解释、不要标题、不要代码块。\n\n"
        "原稿：\n" + text
    )
    rewritten = deepseek_chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=90)
    rewritten = rewritten.strip()
    if rewritten.startswith("```"):
        rewritten = rewritten.split("```")[1]
    hits = forbidden_words.scan(rewritten)
    cleaned = forbidden_words.clean_script(rewritten)

    # 元数据：字数 + 预估时长（中文约 4.5 字/秒，含自然停顿）
    orig_chars = len(text.replace(" ", "").replace("\n", ""))
    clean_chars = len(cleaned.replace(" ", "").replace("\n", ""))
    est_sec = max(1, round(clean_chars / 4.5))

    return {
        "ok": True,
        "rewritten": rewritten,
        "hits": hits,
        "cleaned": cleaned,
        "meta": {
            "orig_chars": orig_chars,
            "clean_chars": clean_chars,
            "char_delta": clean_chars - orig_chars,
            "duration_est_sec": est_sec,
            "duration_fmt": f"{est_sec // 60}分{est_sec % 60}秒" if est_sec >= 60 else f"约{est_sec}秒",
            "hit_count": len(hits),
            "high_risk_count": len([h for h in hits if h.get("level") == "high"]),
        },
    }


def ai_qc(text, platform=None):
    """智能质检：违禁词扫描 + 时长预估 + 风险等级。返回 dict。"""
    hits = forbidden_words.scan(text, platform)
    chars = len(text)
    est_sec = max(1, round(chars / 4.5))  # 中文约 4.5 字/秒（含停顿）
    high = [h for h in hits if h.get("level") == "high"]
    risk = "high" if high else ("medium" if hits else "low")
    return {
        "ok": True,
        "hits": hits,
        "chars": chars,
        "duration_est_sec": est_sec,
        "risk_level": risk,
        "suggestions": [h.get("suggest", "") for h in hits if h.get("suggest")],
    }


def probe_video(path):
    """用 ffprobe 取视频元信息，失败返回 None。"""
    try:
        out = subprocess.run(
            [FFPROBE, "-v", "error", "-show_format", "-show_streams", "-of", "json", path],
            capture_output=True, text=True, timeout=30,
        ).stdout
        return json.loads(out)
    except Exception:  # noqa: BLE001
        return None


def _streams(probe):
    video = audio = None
    for s in probe.get("streams", []):
        if s.get("codec_type") == "video" and video is None:
            video = s
        elif s.get("codec_type") == "audio" and audio is None:
            audio = s
    dur = float(probe.get("format", {}).get("duration", 0) or 0)
    return video, audio, dur


def _summarize(issues):
    high = [i for i in issues if i["level"] == "high"]
    level = "high" if high else ("medium" if issues else "low")
    status = "blocked" if high else ("warned" if issues else "passed")
    score = max(0, 100 - len(issues) * 15)
    return level, status, score


def ai_qc_video(path, platform=None, rules=None):
    """出片产物技术质检：视频流/音轨/画幅/时长。返回 dict。"""
    rules = rules or {}
    probe = probe_video(path)
    issues = []
    if not probe:
        issues.append({"code": "probe_fail", "level": "high", "message": "无法解析视频文件"})
    else:
        video, audio, dur = _streams(probe)
        if video is None:
            issues.append({"code": "no_video", "level": "high", "message": "缺少视频流"})
        else:
            w = int(video.get("width", 0))
            h = int(video.get("height", 0))
            if h > 0 and w > 0 and w >= h:
                issues.append({"code": "not_portrait", "level": "medium",
                               "message": f"画幅非竖屏（{w}x{h}），建议 9:16"})
        if audio is None:
            issues.append({"code": "no_audio", "level": "high", "message": "缺少音轨"})
        maxd = rules.get("max_duration_sec", 180)
        if dur > maxd:
            issues.append({"code": "too_long", "level": "medium",
                           "message": f"时长 {dur:.0f}s 超过上限 {maxd}s"})
    level, status, score = _summarize(issues)
    return {"ok": True, "kind": "video", "issues": issues,
            "score": score, "level": level, "status": status, "duration": dur}


def ai_qc_asset(path, rules=None):
    """用户上传模特素材质检：竖屏/时长/音轨（原声污染预警）。返回 dict。"""
    rules = rules or {}
    probe = probe_video(path)
    issues = []
    if not probe:
        issues.append({"code": "probe_fail", "level": "high", "message": "无法解析素材文件"})
    else:
        video, audio, dur = _streams(probe)
        w = h = 0
        if video is None:
            issues.append({"code": "no_video", "level": "high", "message": "缺少视频流"})
        else:
            w = int(video.get("width", 0))
            h = int(video.get("height", 0))
            if not (h > w):
                issues.append({"code": "not_portrait", "level": "high",
                               "message": f"必须竖屏 9:16，当前 {w}x{h}"})
        if audio is not None:
            issues.append({"code": "has_audio", "level": "medium",
                           "message": "素材含音轨，出片前将自动静音化（避免原声污染）"})
        dmin = rules.get("min_duration_sec", 3)
        dmax = rules.get("max_duration_sec", 30)
        if dur < dmin or dur > dmax:
            issues.append({"code": "duration_out", "level": "medium",
                           "message": f"时长 {dur:.0f}s，建议 {dmin}-{dmax}s"})
    level, status, score = _summarize(issues)
    resol = f"{w}x{h}" if (w and h) else None
    return {"ok": True, "kind": "asset", "issues": issues,
            "score": score, "level": level, "status": status,
            "duration": dur, "resolution": resol}


def process_asset(raw_path, tenant_id):
    """用户上传模特素材处理：
    1) 转码 H.264 + 自动静音化（加 anullsrc 静音音轨，杜绝原声污染）
    2) 写入 HEYGEM 可读路径 face2face/uploads/{tenant}/{id}.mp4（容器 /code/data/uploads/...）
    3) 同步副本到项目 storage/app/models/{tenant}/ 供 Laravel 预览
    4) 跑 asset QC，返回结果与各路径
    """
    tenant_id = str(tenant_id)
    render_dir = os.path.join(FAC2FACE, "uploads", tenant_id)
    preview_dir = os.path.join(PROJECT_STORAGE, "models", tenant_id)
    os.makedirs(render_dir, exist_ok=True)
    os.makedirs(preview_dir, exist_ok=True)
    rid = uuid.uuid4().hex
    render_path = os.path.join(render_dir, rid + ".mp4")
    preview_path = os.path.join(preview_dir, rid + ".mp4")

    cmd = [
        FFMPEG, "-y", "-i", raw_path,
        "-f", "lavfi", "-i", "anullsrc=r=22050:cl=mono",
        "-c:v", "libx264", "-preset", "veryfast", "-pix_fmt", "yuv420p",
        "-c:a", "aac", "-shortest", "-movflags", "+faststart",
        render_path,
    ]
    proc = subprocess.run(cmd, capture_output=True, text=True, timeout=300)
    if proc.returncode != 0 or not os.path.exists(render_path):
        tail = (proc.stderr or proc.stdout or "")[-2000:]
        raise RuntimeError("转码失败：" + tail)
    shutil.copy(render_path, preview_path)

    qc = ai_qc_asset(render_path)
    container_path = render_path.replace(FAC2FACE, "/code/data")
    return {
        "ok": True,
        "qc": qc,
        "file_path": render_path,
        "preview_path": preview_path,
        "container_path": container_path,
        "duration": qc.get("duration"),
        "resolution": qc.get("resolution"),
    }



jobs = {}          # job_id -> {"status","out","error"}
lock = threading.Lock()


def run_job(job_id, payload):
    mode = (payload.get("mode") or "scroll").lower()
    dialogue = payload.get("dialogue", "").strip()
    if not dialogue:
        with lock:
            jobs[job_id]["status"] = "failed"
            jobs[job_id]["error"] = "dialogue required"
        return

    job_dir = os.path.join(JOBS_DIR, job_id)
    os.makedirs(job_dir, exist_ok=True)
    dlg_path = os.path.join(job_dir, "dialogue.txt")
    with open(dlg_path, "w", encoding="utf-8") as f:
        f.write(dialogue)
    out_path = os.path.join(job_dir, "out.mp4")

    if mode == "avatar":
        voice = payload.get("male_voice") or payload.get("voice") or DEFAULT_MALE
        args = [PY310, SCRIPT_AVATAR, "--dialogue", dlg_path,
                "--out", out_path, "--voice", voice]
        model = payload.get("model")
        if model:
            # 友好名/裸文件名 -> 容器内完整路径；已是完整路径则原样用；未知则回退默认
            model = MODEL_REGISTRY.get(
                model,
                model if model.startswith("/code/data/") else DEFAULT_AVATAR_MODEL,
            )
            args += ["--model", model]
        # 场景选择（办公桌前正面/侧面等预录场景）
        scene = payload.get("scene")
        if scene:
            args += ["--scene", scene]
    else:  # scroll
        args = [PY310, SCRIPT_SCROLL, "--dialogue", dlg_path, "--out", out_path]
        if payload.get("title"):
            args += ["--title", payload["title"]]
        if payload.get("subtitle"):
            args += ["--subtitle", payload["subtitle"]]
        if payload.get("bg"):
            args += ["--bg", payload["bg"]]
        # 真实 TTS 为默认；仅当显式 dry_tts=true 才用静音占位
        if payload.get("dry_tts"):
            args += ["--dry-tts"]
        mv = payload.get("male_voice")
        if mv:
            args += ["--male-voice", mv]
        fv = payload.get("female_voice")
        if fv:
            args += ["--female-voice", fv]
        # 分声线感情/快慢（可选；不传则用脚本默认值：男声沉稳慢、女声略活泼）
        for key, flag in (("male_rate", "--male-rate"), ("female_rate", "--female-rate"),
                          ("male_pitch", "--male-pitch"), ("female_pitch", "--female-pitch"),
                          ("male_vol", "--male-vol"), ("female_vol", "--female-vol")):
            v = payload.get(key)
            if v is not None:
                args += [flag, str(v)]
        # 口语化自然润色（去AI感）：显式开启才调 DeepSeek 改写稿子
        if payload.get("natural"):
            args += ["--natural"]

    try:
        with lock:
            jobs[job_id]["status"] = "rendering"
        proc = subprocess.run(
            args, cwd=GPT_SOVITS, capture_output=True, text=True, timeout=1200
        )
        if proc.returncode == 0 and os.path.exists(out_path):
            with lock:
                jobs[job_id]["status"] = "done"
                jobs[job_id]["out"] = out_path
        else:
            tail = (proc.stderr or proc.stdout or "")[-4000:]
            with lock:
                jobs[job_id]["status"] = "failed"
                jobs[job_id]["error"] = tail
    except Exception as e:  # noqa: BLE001
        with lock:
            jobs[job_id]["status"] = "failed"
            jobs[job_id]["error"] = str(e)


class Handler(http.server.BaseHTTPRequestHandler):
    def _send(self, code, obj=None, body=None, ctype="application/json; charset=utf-8"):
        self.send_response(code)
        if obj is not None:
            data = json.dumps(obj, ensure_ascii=False).encode("utf-8")
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)
        elif body is not None:
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        else:
            self.end_headers()

    def do_GET(self):
        p = urlparse(self.path)
        if p.path == "/health":
            return self._send(200, {"status": "ok"})

        if p.path.startswith("/status/"):
            jid = p.path.rsplit("/", 1)[-1]
            with lock:
                j = jobs.get(jid)
            if not j:
                return self._send(404, {"error": "not found"})
            return self._send(200, {
                "job_id": jid,
                "status": j["status"],
                "result": f"/download/{jid}" if j["status"] == "done" else None,
                "error": j.get("error"),
            })

        if p.path.startswith("/download/"):
            jid = p.path.rsplit("/", 1)[-1]
            with lock:
                j = jobs.get(jid)
            if not j or j["status"] != "done":
                return self._send(404, {"error": "not ready"})
            with open(j["out"], "rb") as f:
                data = f.read()
            return self._send(200, body=data, ctype="video/mp4")

        return self._send(404, {"error": "not found"})

    def do_POST(self):
        p = urlparse(self.path)
        length = int(self.headers.get("Content-Length", 0) or 0)
        raw = self.rfile.read(length) if length else b""
        try:
            data = json.loads(raw.decode("utf-8"))
        except Exception:  # noqa: BLE001
            return self._send(400, {"error": "bad json"})

        if p.path == "/generate":
            return self._handle_generate(data)
        if p.path == "/topic":
            return self._handle_topic(data)
        if p.path == "/rewrite":
            return self._handle_rewrite(data)
        if p.path == "/qc":
            return self._handle_qc(data)
        if p.path == "/qc-video":
            return self._handle_qc_video(data)
        if p.path == "/qc-asset":
            return self._handle_qc_asset(data)
        if p.path == "/process-asset":
            return self._handle_process_asset(data)
        if p.path == "/delete-asset":
            return self._handle_delete_asset(data)
        return self._send(404, {"error": "not found"})

    # ---- 出片（异步 job）----
    def _handle_generate(self, data):
        dialogue = (data.get("dialogue") or "").strip()
        if not dialogue:
            return self._send(400, {"error": "dialogue required"})
        jid = uuid.uuid4().hex
        with lock:
            jobs[jid] = {"status": "queued", "out": None, "error": None}
        t = threading.Thread(target=run_job, args=(jid, data), daemon=True)
        t.start()
        return self._send(200, {"job_id": jid, "status": "queued"})

    # ---- 智能选题（同步 AI）----
    def _handle_topic(self, data):
        try:
            result = ai_topic(
                data.get("industry", "") or "",
                data.get("keywords", "") or "",
                int(data.get("count", 6) or 6),
            )
            return self._send(200, {"ok": True, "topics": result})
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- 智能二创（同步 AI + 违禁词）----
    def _handle_rewrite(self, data):
        text = (data.get("text") or "").strip()
        if not text:
            return self._send(400, {"error": "text required"})
        try:
            return self._send(200, ai_rewrite(text, data.get("mode", "dual"), data.get("focus"),
                                              data.get("target_duration"), data.get("preserve")))
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- 智能质检（同步：违禁词 + 时长 + 风险）----
    def _handle_qc(self, data):
        text = (data.get("text") or "").strip()
        if not text:
            return self._send(400, {"error": "text required"})
        try:
            return self._send(200, ai_qc(text, data.get("platform")))
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- 出片产物技术质检（按 job_id 从磁盘解析产物路径，重启后仍可用）----
    def _handle_qc_video(self, data):
        jid = (data.get("job_id") or "").strip()
        if not jid:
            return self._send(400, {"error": "job_id required"})
        # 优先用内存中的产物路径，否则回退磁盘（服务重启后内存丢失但文件仍在）
        with lock:
            j = jobs.get(jid)
        candidate = (j or {}).get("out") or os.path.join(JOBS_DIR, jid, "out.mp4")
        if not os.path.exists(candidate):
            return self._send(404, {"error": "video not ready"})
        try:
            return self._send(200, ai_qc_video(candidate, data.get("platform"), data.get("rules")))
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- 用户上传素材质检（按宿主文件路径）----
    def _handle_qc_asset(self, data):
        path = (data.get("file_path") or "").strip()
        if not path or not os.path.exists(path):
            return self._send(400, {"error": "file_path required / not exist"})
        try:
            return self._send(200, ai_qc_asset(path, data.get("rules")))
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- 用户上传模特素材处理（转码+静音化+双写+QC）----
    def _handle_process_asset(self, data):
        path = (data.get("file_path") or "").strip()
        tenant_id = data.get("tenant_id")
        if not path or not os.path.exists(path):
            return self._send(400, {"error": "file_path required / not exist"})
        if not tenant_id:
            return self._send(400, {"error": "tenant_id required"})
        try:
            return self._send(200, process_asset(path, tenant_id))
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- 删除宿主上的素材文件（容器无法直接删宿主文件，由 8500 代理）----
    def _handle_delete_asset(self, data):
        paths = data.get("paths") or []
        removed = []
        for p in paths:
            if not isinstance(p, str):
                continue
            # 仅允许删除 face2face / storage 下的文件，防任意删除
            if (p.startswith(FAC2FACE) or p.startswith(PROJECT_STORAGE)) and os.path.exists(p):
                try:
                    os.remove(p)
                    removed.append(p)
                except OSError:
                    pass
        return self._send(200, {"ok": True, "removed": removed})

    def log_message(self, *a):  # 静默访问日志
        pass


if __name__ == "__main__":
    port = int(os.environ.get("PORT", 8500))
    srv = http.server.ThreadingHTTPServer(("0.0.0.0", port), Handler)
    print(f"[pipeline] listening on :{port}")
    srv.serve_forever()
