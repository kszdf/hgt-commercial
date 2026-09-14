# -*- coding: utf-8 -*-
"""
全格式出片驱动：把 8500 的每条生产线都真跑一遍，最终产出
  - 公众号文章（/article/write）
  - 小红书图文（/xhs_generate）
  - 六类短视频：scroll / avatar / motion / manga / whiteboard / card（/generate）

用法: python produce_all_formats.py
产物落在 ./produce_all_<时间戳>/ 下，并生成 manifest.json 总览。
"""
import json
import os
import sys
import time
import urllib.request
import datetime

BASE = "http://127.0.0.1:8500"
VOICE_M = "cosyvoice-v3-plus-zhangc2-28a7c3541e1c45518a03046c11baeb1d"
VOICE_F = "cosyvoice-v3-plus-jiangnv3-991b204c1d564ac7a60f0cb9a8fd78bd"

THEME = "老板用个人卡收公司货款的税务风险"
SELLING = "个人微信/银行卡收公司货款不申报，被税务稽查调取流水后补税、滞纳金、罚款甚至刑责"
AUDIENCE = "中小企业老板、创业者、个体工商户"

DIALOGUE = (
    "老板们注意了，公司货款打到个人微信或者银行卡收款，不申报、不入账，"
    "看起来方便，其实风险很大。税务稽查现在能直接调取个人银行流水和微信支付记录，"
    "一旦比对出公司收入进了个人口袋，就要补税、加收滞纳金，还可能被认定为偷税，"
    "面临罚款甚至刑事责任。公司收入一定要走对公账户，公私分明才稳妥。"
)

OUT_DIR = "produce_all_%s" % datetime.datetime.now().strftime("%Y%m%d_%H%M%S")


def post(path, data, timeout=120):
    req = urllib.request.Request(
        BASE + path,
        data=json.dumps(data).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.loads(r.read().decode("utf-8"))


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


def main():
    os.makedirs(OUT_DIR, exist_ok=True)
    manifest = {"theme": THEME, "generated_at": OUT_DIR, "article": None,
                "xhs": None, "videos": {}}

    # ---------- 1) 公众号文章（同步） ----------
    log("== 公众号文章 /article/write ==")
    try:
        art = post("/article/write", {
            "topic": THEME, "region": "全国", "year": "2026",
            "words": 1500, "style": "干货科普", "cta": "评论区留言",
        }, timeout=240)
        manifest["article"] = art
        if art.get("ok"):
            md = "# %s\n\n" % (art.get("title") or THEME)
            if art.get("digest"):
                md += "> %s\n\n" % art["digest"]
            md += (art.get("content") or "")
            if art.get("tags"):
                md += "\n\n" + " ".join(art["tags"]) + "\n"
            with open(os.path.join(OUT_DIR, "article.md"), "w", encoding="utf-8") as f:
                f.write(md)
            log("  公众号文章已生成：%s（%d 字）" % (art.get("title"), art.get("word_count") or 0))
        else:
            log("  !! 公众号文章失败：%s" % art.get("error"))
    except Exception as e:
        log("  !! 公众号文章异常：%s" % e)
        manifest["article"] = {"ok": False, "error": str(e)}

    # ---------- 2) 小红书（同步渲染） ----------
    log("== 小红书 /xhs_generate ==")
    try:
        xhs = post("/xhs_generate", {
            "topic": THEME, "selling_points": SELLING,
            "audience": AUDIENCE, "pages": 6,
        }, timeout=240)
        manifest["xhs"] = {"ok": xhs.get("ok"), "count": xhs.get("count"),
                           "note": xhs.get("note"), "image_paths": xhs.get("image_paths")}
        if xhs.get("ok") and xhs.get("image_paths"):
            xdir = os.path.join(OUT_DIR, "xhs")
            os.makedirs(xdir, exist_ok=True)
            for i, p in enumerate(xhs["image_paths"]):
                if os.path.isfile(p):
                    dst = os.path.join(xdir, "xhs_%02d.png" % i)
                    with open(p, "rb") as fsrc, open(dst, "wb") as fdst:
                        fdst.write(fsrc.read())
            log("  小红书已生成 %d 张图，存 %s" % (len(xhs["image_paths"]), xdir))
        else:
            log("  !! 小红书失败：%s" % xhs.get("error"))
    except Exception as e:
        log("  !! 小红书异常：%s" % e)
        manifest["xhs"] = {"ok": False, "error": str(e)}

    # ---------- 3) 六类短视频（异步 /generate + 轮询） ----------
    cases = [
        ("scroll",     {"mode": "scroll", "voice_form": "male_mono",
                        "title": "滚动字幕·个人卡收款风险"}),
        ("avatar",     {"mode": "avatar",
                        "title": "数字人·个人卡收款风险"}),
        ("motion",     {"mode": "motion", "voice_form": "male_mono", "edit_style": "fast",
                        "title": "幕后音·个人卡收款风险"}),
        ("manga",      {"mode": "manga",
                        "title": "漫剧·个人卡收款风险"}),
        ("whiteboard", {"mode": "whiteboard",
                        "title": "白板·个人卡收款风险"}),
        ("card",       {"mode": "card",
                        "title": "图解版·个人卡收款风险"}),
    ]

    jobs = []
    for name, payload in cases:
        payload = dict(payload)
        payload.update({"dialogue": DIALOGUE, "male_voice": VOICE_M,
                        "female_voice": VOICE_F})
        try:
            r = post("/generate", payload, timeout=60)
            jid = r.get("job_id")
            jobs.append((name, jid))
            log("  提交 %s -> job_id=%s" % (name, jid))
        except Exception as e:
            log("  !! %s 提交失败：%s" % (name, e))
            manifest["videos"][name] = {"status": "submit_failed", "error": str(e)}

    # 轮询直至全部终态
    log("== 轮询六路出片（并发由 8500 控制，≤2）==")
    pending = list(jobs)
    while pending:
        time.sleep(20)
        still = []
        for name, jid in pending:
            try:
                s = get("/status/%s" % jid, timeout=30)
            except Exception as e:
                still.append((name, jid))
                continue
            st = s.get("status")
            if st in ("done", "failed", "cancelled"):
                rec = {"status": st, "step": s.get("step"),
                       "error": s.get("error"),
                       "qc_video": s.get("qc_video"),
                       "cover": s.get("cover"),
                       "warning": s.get("warning")}
                if st == "done":
                    # 下载成片
                    try:
                        vdir = os.path.join(OUT_DIR, "videos")
                        os.makedirs(vdir, exist_ok=True)
                        vpath = os.path.join(vdir, "%s.mp4" % name)
                        download("/download/%s" % jid, vpath)
                        rec["video_path"] = os.path.abspath(vpath)
                        # 封面
                        if s.get("cover") and os.path.isfile(str(s.get("cover"))):
                            cpath = os.path.join(vdir, "%s_cover.png" % name)
                            with open(s["cover"], "rb") as f1, open(cpath, "wb") as f2:
                                f2.write(f1.read())
                            rec["cover_path"] = os.path.abspath(cpath)
                        log("  ✅ %s 完成 -> %s" % (name, rec.get("video_path")))
                    except Exception as e:
                        rec["download_error"] = str(e)
                        log("  ⚠ %s 完成但下载失败：%s" % (name, e))
                else:
                    log("  ❌ %s 失败：%s" % (name, st))
                manifest["videos"][name] = rec
            else:
                # 进行中，打印进度
                prog = s.get("progress")
                qp = s.get("queue_pos")
                if prog is not None:
                    log("  … %s %s %s%%" % (name, st, prog))
                elif qp:
                    log("  … %s 排队第 %d" % (name, qp))
                else:
                    log("  … %s %s" % (name, st))
                still.append((name, jid))
        pending = still

    # ---------- 写 manifest ----------
    with open(os.path.join(OUT_DIR, "manifest.json"), "w", encoding="utf-8") as f:
        json.dump(manifest, f, ensure_ascii=False, indent=2)

    # ---------- 总览 ----------
    art_ok = bool(manifest.get("article", {}).get("ok"))
    xhs_ok = bool(manifest.get("xhs", {}).get("ok"))
    vids = manifest.get("videos", {})
    v_ok = sum(1 for v in vids.values() if v.get("status") == "done")
    log("")
    log("===== 全格式出片总览 =====")
    log("公众号文章 : %s" % ("OK" if art_ok else "FAIL"))
    log("小红书     : %s (%d 张)" % ("OK" if xhs_ok else "FAIL",
                                     (manifest.get("xhs") or {}).get("count") or 0))
    for name in ("scroll", "avatar", "motion", "manga", "whiteboard", "card"):
        v = vids.get(name, {})
        log("短视频 %-10s : %s" % (name, v.get("status", "未提交")))
    log("产物目录: %s" % os.path.abspath(OUT_DIR))


if __name__ == "__main__":
    sys.stdout.reconfigure(encoding="utf-8")
    main()
