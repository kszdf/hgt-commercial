#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""真机前台对话驱动器：走 8500 真实 HTTP /chat 接口（等同用户在页面打字）。

用法：python live_talk.py <场景名>
场景：
  A  完整出片流程：选题 → 角度 → 写稿(带时长) → 改时长 → 出片
  B  二创改写（空产出场景，验证卡片是否只推"重跑"）
  C  热点选题 → 写稿
  D  立论模式：给方向+观点写稿
"""
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request

BASE = "http://127.0.0.1:8500"
TENANT = "huigentang"
TIMEOUT = 300

sys.stdout.reconfigure(encoding="utf-8")


def _req(path, payload=None, method="GET", timeout=TIMEOUT):
    data = json.dumps(payload).encode("utf-8") if payload is not None else None
    req = urllib.request.Request(BASE + path, data=data,
                                 headers={"Content-Type": "application/json"}, method=method)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        return {"_http_error": e.code, "body": e.read().decode("utf-8", "ignore")[:300]}
    except (urllib.error.URLError, TimeoutError, OSError) as e:
        return {"_url_error": str(e)}


def send(sid, msg, tag=""):
    t0 = time.time()
    r = _req("/chat", {"session_id": sid, "message": msg, "tenant": TENANT}, "POST")
    if r.get("stage") == "async":
        waited = 0
        while waited < 300:
            time.sleep(4)
            waited += 4
            st = _req("/chat/status/" + sid, timeout=60)
            if st.get("stage") not in (None, "", "busy", "async"):
                r = st
                break
        else:
            r = {"stage": "timeout"}
    dt = time.time() - t0
    print("\n───────── 我说：%s" % msg[:80], flush=True)
    _dump(r, dt)
    return r


def _dump(r, dt=0.0):
    if not isinstance(r, dict):
        print("   [非 dict 返回] %r" % (r,), flush=True)
        return
    stage = r.get("stage")
    print("   [%.0fs] stage=%s" % (dt, stage), flush=True)
    if r.get("_http_error") or r.get("_url_error"):
        print("   !! 请求错误: %s" % (r.get("_http_error") or r.get("_url_error")), flush=True)
        return
    msg = (r.get("message") or r.get("reply") or r.get("summary") or "")
    if isinstance(msg, str) and msg.strip():
        print("   AI：" + msg.strip().replace("\n", "\n       ")[:900], flush=True)
    # 角度
    angles = r.get("angles") or []
    if angles:
        print("   角度 %d 个：" % len(angles))
        for i, a in enumerate(angles[:8]):
            print("     #%d %s | %s" % (i + 1, a.get("title", ""), (a.get("angle") or "")[:60]))
    # 成稿
    written = r.get("written") or []
    if written:
        for w in written[-1:]:
            body = w.get("script") or ""
            print("   成稿《%s》 %d 字" % (w.get("title", ""), len(re.sub(r"\s", "", body))))
            print("     " + body[:400].replace("\n", " "))
    # 能力卡 / 下一步
    if r.get("cap"):
        c = r["cap"]
        print("   卡片：%s(%s) next=%s" % (c.get("name"), c.get("id"),
                                            [n.get("name") for n in (r.get("next") or [])]))
    if r.get("next"):
        print("   下一步：%s" % [n.get("name") for n in r["next"]])
    if r.get("ok") is not None:
        print("   ok=%s" % r["ok"])
    if r.get("job_id"):
        print("   job_id=%s" % r["job_id"])
    for k in ("tip", "error"):
        if r.get(k):
            print("   %s：%s" % (k, str(r[k])[:200]))


SCENES = {
    "A": [
        "我想做一条短视频，主题是：股东借款年底不还，会被视同分红征税",
        "用第2个角度",
        "就按这个角度写成口播稿，控制在90秒内",
        "这篇改成120秒",
        "做成片",
    ],
    "B": [
        "帮我把这段文案改成我的口径：很多老板以为公司账上的钱就是自己的钱，其实股东从公司借钱，年底不还就要视同分红交20%个税，这个坑很多人踩过。",
    ],
    "C": [
        "今天财税圈有什么热点，给我拆成能拍的选题",
    ],
    "D": [
        "写个口播稿，方向是：个体户真的比公司省税吗。我的观点是：绝大多数老板搞反了，个体户只在特定条件下划算，盲目注册反而多缴税。控制在100秒内。",
    ],
}

if __name__ == "__main__":
    key = (sys.argv[1] if len(sys.argv) > 1 else "A").upper()
    steps = SCENES.get(key, SCENES["A"])
    sid = "%s_%s_%d" % (key.lower(), time.strftime("%H%M%S"), os.getpid())
    print("=== 场景 %s  会话 %s ===" % (key, sid), flush=True)
    for s in steps:
        r = send(sid, s)
    print("\n=== 场景 %s 结束，会话文件：data/chat_sessions/%s.json ===" % (key, sid), flush=True)
