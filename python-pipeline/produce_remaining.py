# -*- coding: utf-8 -*-
"""
补跑脚本：专跑 v1 因并发 429 未提交成功的 4 个视频模式
  motion / manga / whiteboard / card
策略：逐个提交，遇 429 退避重试（尊重单租户并发≤2），提交后轮询至完成并下载成片。
产物写入 v1 同一个 produce_all_* 目录，结果另存 videos_extra_manifest.json。
"""
import json
import os
import sys
import time
import urllib.request
import glob
import datetime

BASE = "http://127.0.0.1:8500"
VOICE_M = "cosyvoice-v3-plus-zhangc2-28a7c3541e1c45518a03046c11baeb1d"
VOICE_F = "cosyvoice-v3-plus-jiangnv3-991b204c1d564ac7a60f0cb9a8fd78bd"

THEME = "老板用个人卡收公司货款的税务风险"
DIALOGUE = (
    "老板们注意了，公司货款打到个人微信或者银行卡收款，不申报、不入账，"
    "看起来方便，其实风险很大。税务稽查现在能直接调取个人银行流水和微信支付记录，"
    "一旦比对出公司收入进了个人口袋，就要补税、加收滞纳金，还可能被认定为偷税，"
    "面临罚款甚至刑事责任。公司收入一定要走对公账户，公私分明才稳妥。"
)

# 自动定位 v1 的产物目录（最新的 produce_all_YYYYMMDD_HHMMSS 目录）
DIRS = sorted([d for d in glob.glob("produce_all_*") if os.path.isdir(d)], reverse=True)
OUT_DIR = DIRS[0] if DIRS else "produce_all_fallback"
os.makedirs(os.path.join(OUT_DIR, "videos"), exist_ok=True)


def post(path, data, timeout=60):
    req = urllib.request.Request(
        BASE + path,
        data=json.dumps(data).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.loads(r.read().decode("utf-8")), r.status


def get(path, timeout=30):
    with urllib.request.urlopen(BASE + path, timeout=timeout) as r:
        return json.loads(r.read().decode("utf-8"))


def download(path, out_path):
    req = urllib.request.Request(BASE + path, method="GET")
    with urllib.request.urlopen(req, timeout=600) as r:
        with open(out_path, "wb") as f:
            while True:
                chunk = r.read(65536)
                if not chunk:
                    break
                f.write(chunk)


def log(msg):
    ts = datetime.datetime.now().strftime("%H:%M:%S")
    print("[%s] %s" % (ts, msg), flush=True)


def submit_with_retry(payload, max_retry=60):
    """提交并容忍 429（退避重试）。返回 (jid, None) 或 (None, err)。"""
    for i in range(max_retry):
        try:
            r, code = post("/generate", payload, timeout=60)
            if code == 200 and r.get("job_id"):
                return r["job_id"], None
            if code == 429:
                log("  429 并发满，退避 25s 重试 (%d/%d)" % (i + 1, max_retry))
                time.sleep(25)
                continue
            return None, "HTTP %d: %s" % (code, r.get("error"))
        except Exception as e:
            log("  提交异常 %s，10s 后重试" % e)
            time.sleep(10)
    return None, "重试耗尽仍 429"


def main():
    cases = [
        ("motion",     {"mode": "motion", "voice_form": "male_mono", "edit_style": "fast",
                        "title": "幕后音·个人卡收款风险"}),
        ("manga",      {"mode": "manga", "title": "漫剧·个人卡收款风险"}),
        ("whiteboard", {"mode": "whiteboard", "title": "白板·个人卡收款风险"}),
        ("card",       {"mode": "card", "title": "图解版·个人卡收款风险"}),
    ]
    manifest = {"videos": {}}
    for name, payload in cases:
        payload = dict(payload)
        payload.update({"dialogue": DIALOGUE, "male_voice": VOICE_M, "female_voice": VOICE_F})
        log("== 提交 %s ==" % name)
        jid, err = submit_with_retry(payload)
        if not jid:
            manifest["videos"][name] = {"status": "submit_failed", "error": err}
            log("  !! %s 提交失败：%s" % (name, err))
            continue
        log("  job_id=%s，开始轮询" % jid)
        # 轮询
        rec = None
        while True:
            time.sleep(20)
            try:
                s = get("/status/%s" % jid, timeout=30)
            except Exception as e:
                continue
            st = s.get("status")
            if st in ("done", "failed", "cancelled"):
                rec = {"status": st, "step": s.get("step"), "error": s.get("error"),
                       "qc_video": s.get("qc_video"), "cover": s.get("cover"),
                       "warning": s.get("warning")}
                if st == "done":
                    try:
                        vpath = os.path.join(OUT_DIR, "videos", "%s.mp4" % name)
                        download("/download/%s" % jid, vpath)
                        rec["video_path"] = os.path.abspath(vpath)
                        if s.get("cover") and os.path.isfile(str(s.get("cover"))):
                            cpath = os.path.join(OUT_DIR, "videos", "%s_cover.png" % name)
                            with open(s["cover"], "rb") as f1, open(cpath, "wb") as f2:
                                f2.write(f1.read())
                            rec["cover_path"] = os.path.abspath(cpath)
                        log("  ✅ %s 完成 -> %s" % (name, rec["video_path"]))
                    except Exception as e:
                        rec["download_error"] = str(e)
                        log("  ⚠ %s 下载失败：%s" % (name, e))
                else:
                    log("  ❌ %s 失败：%s" % (name, st))
                break
            else:
                prog = s.get("progress")
                if prog is not None:
                    log("  … %s %s %s%%" % (name, st, prog))
                else:
                    log("  … %s %s" % (name, st))
        manifest["videos"][name] = rec

    with open(os.path.join(OUT_DIR, "videos_extra_manifest.json"), "w", encoding="utf-8") as f:
        json.dump(manifest, f, ensure_ascii=False, indent=2)
    log("补跑完成，结果存 %s/videos_extra_manifest.json" % OUT_DIR)


if __name__ == "__main__":
    sys.stdout.reconfigure(encoding="utf-8")
    main()
