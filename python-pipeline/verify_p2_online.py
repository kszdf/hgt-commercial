#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""P2 边界真机验证：已写稿状态下说'换个角度再拆一次'必须重拆。
前置：Restart-Service HGTCommercial8500 + 浏览器 Ctrl+F5。
"""
import json, time, urllib.request, sys

BASE = "http://127.0.0.1:8500"


def post(path, payload):
    req = urllib.request.Request(BASE + path,
                                 data=json.dumps(payload).encode(),
                                 headers={"Content-Type": "application/json"},
                                 method="POST")
    with urllib.request.urlopen(req, timeout=120) as r:
        return json.loads(r.read().decode())


def get(path):
    with urllib.request.urlopen(BASE + path, timeout=20) as r:
        return json.loads(r.read().decode())


def create():
    return post("/chat/session/create", {"tenant": "", "title": "p2verify"})["session_id"]


def chat(sid, msg):
    return post("/chat", {"session_id": sid, "message": msg, "tenant": ""})


def poll(sid, timeout=300):
    end = time.time() + timeout
    while time.time() < end:
        try:
            st = get("/chat/status/" + sid)
        except Exception:
            st = {}
        if st.get("stage") not in ("async", "pending", "idle", None, ""):
            return st
        time.sleep(3)
    return st


def wait_done(sid, r):
    return poll(sid) if r.get("stage") == "async" else r


def main():
    print("=== P2 真机验证：已写稿后再说重拆词必须重拆 ===")
    sid = create()

    # 1) 拆角度
    r1 = wait_done(sid, chat(sid, "公转私的风险，给中小老板，拆几个角度"))
    a1 = r1.get("angles") or []
    print("[1 拆角度] stage=%s angles=%d" % (r1.get("stage"), len(a1)))
    if r1.get("stage") != "propose" or len(a1) < 5:
        print("FAIL @1 未出角度方案"); sys.exit(1)

    # 2) 写稿（置 written=True）
    r2 = wait_done(sid, chat(sid, "就按第一个角度全写"))
    written = r2.get("written") or (r2.get("stage") == "action_ready")
    print("[2 写稿] stage=%s written=%s | %s" % (r2.get("stage"), written, (r2.get("message") or "")[:40]))
    # 兼容：写稿可能进入 action_ready 或返回成稿
    time.sleep(2)

    # 3) 重拆词（修复后必须重拆出新角度，而非回"稿已写好"）
    r3 = wait_done(sid, chat(sid, "换个角度再拆一次"))
    a3 = r3.get("angles") or []
    msg3 = (r3.get("message") or "")
    print("[3 重拆] stage=%s angles=%d | %s" % (r3.get("stage"), len(a3), msg3[:60]))

    ok = r3.get("stage") == "propose" and len(a3) >= 5
    print("\n=== 结果 ===")
    if ok:
        print("P2 PASS ✅ 已写稿后说'换个角度再拆一次'→ 重拆出新角度方案(%d条)" % len(a3))
    else:
        print("P2 FAIL ❌ stage=%s angles=%d | 回复=%s" % (r3.get("stage"), len(a3), msg3))
        sys.exit(1)


if __name__ == "__main__":
    main()
