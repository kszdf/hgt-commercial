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
import subprocess
import threading
import uuid
from urllib.parse import urlparse

GPT_SOVITS = r"D:/heygem_data/gpt_sovits"
PY310 = r"D:/heygem/py310/Scripts/python.exe"
SCRIPT_SCROLL = os.path.join(GPT_SOVITS, "make_scroll_video.py")
SCRIPT_AVATAR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "make_avatar_from_dialogue.py")
JOBS_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "jobs")
os.makedirs(JOBS_DIR, exist_ok=True)

# 默认音色（与 gpt_sovits 定稿一致）；租户可在请求里覆盖
DEFAULT_MALE = "cosyvoice-v3-plus-zhangc2-28a7c3541e1c45518a03046c11baeb1d"
DEFAULT_FEMALE = "cosyvoice-v3-plus-jiangnv3-991b204c1d564ac7a60f0cb9a8fd78bd"

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
            args += ["--model", model]
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
        if p.path != "/generate":
            return self._send(404, {"error": "not found"})

        length = int(self.headers.get("Content-Length", 0))
        raw = self.rfile.read(length)
        try:
            data = json.loads(raw.decode("utf-8"))
        except Exception:  # noqa: BLE001
            return self._send(400, {"error": "bad json"})

        dialogue = (data.get("dialogue") or "").strip()
        if not dialogue:
            return self._send(400, {"error": "dialogue required"})

        jid = uuid.uuid4().hex
        with lock:
            jobs[jid] = {"status": "queued", "out": None, "error": None}

        t = threading.Thread(
            target=run_job,
            args=(jid, data),
            daemon=True,
        )
        t.start()
        return self._send(200, {"job_id": jid, "status": "queued"})

    def log_message(self, *a):  # 静默访问日志
        pass


if __name__ == "__main__":
    port = int(os.environ.get("PORT", 8500))
    srv = http.server.ThreadingHTTPServer(("0.0.0.0", port), Handler)
    print(f"[pipeline] listening on :{port}")
    srv.serve_forever()
