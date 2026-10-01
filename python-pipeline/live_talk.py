#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""真机前台对话驱动器：忠实复现浏览器在对话工作台里的完整行为。

浏览器实际流程（每条用户消息）：
  1) POST 8500 /chat {message}  —— 编排器只管"接下来该干嘛"
     - 返回 propose / written / answer 等：直接展示
     - 返回 action_ready（带 cap_id + vals）：说明需要前端点「开始执行」
  2) 用户点「开始执行」→ POST 8080 /studio/chat/action {cap, vals}  —— 真正执行能力
     - 出片/发布包等内部能力复用 Laravel Controller（含配额/并发/落库）
     - 其余代理到 8500 端点
     - 返回 {ok, cap, data:{job_id?, ...}}
  3) 长任务（出片）轮询任务进度直到 done
  4) 执行完把结果回灌 8500：POST 8500 /chat {message:"", action:{cap, ok, data}}
     —— 编排器走 _do_action_done：AI 总结 + 纠偏 + 下一步卡片（以真实产物为准）
  5) 异步回灌轮询 /chat/status/{sid} 直到出 action_done 总结卡

本驱动把 2)~5) 自动串起来，等价于人在页面上点卡片、等进度、看总结。
"""
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request

PIPE = "http://127.0.0.1:8500"   # 8500 编排器
LARA = "http://127.0.0.1:8080"   # 8080 Laravel（能力执行端点）
TENANT = "huigentang"
TIMEOUT = 900

sys.stdout.reconfigure(encoding="utf-8")


def _req(base, path, payload=None, method="GET", timeout=TIMEOUT):
    data = json.dumps(payload).encode("utf-8") if payload is not None else None
    req = urllib.request.Request(base + path, data=data,
                                 headers={"Content-Type": "application/json"}, method=method)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        return {"_http_error": e.code, "body": e.read().decode("utf-8", "ignore")[:400]}
    except (urllib.error.URLError, TimeoutError, OSError) as e:
        return {"_url_error": str(e)}


def _poll_chat_status(sid, cap=600):
    waited = 0
    while waited < cap:
        time.sleep(4)
        waited += 4
        st = _req(PIPE, "/chat/status/" + sid, timeout=60)
        stage = st.get("stage")
        # 稳定态（含 idle：done 结果已被取走，或会话空闲）即停止轮询
        if stage not in (None, "", "busy", "async", "pending", "idle"):
            return st
        if st.get("_http_error") or st.get("_url_error"):
            return st
    return {"stage": "timeout"}


def _poll_job(job_id, cap=1800):
    waited = 0
    last = None
    while waited < cap:
        time.sleep(6)
        waited += 6
        st = _req(PIPE, "/status/" + job_id, timeout=120)
        status = st.get("status")
        step = st.get("step") or st.get("warning") or ""
        if step != last:
            print("   [job %s] %s | %s" % (job_id[:8], status, step), flush=True)
            last = step
        if status in ("done", "failed", "cancelled", "error"):
            out = st.get("out") or (st.get("result") or {}).get("out") if isinstance(st.get("result"), dict) else None
            print("   [job %s] %s out=%s" % (job_id[:8], status, out), flush=True)
            return st
    print("   [job %s] 跟到底超时(%ds)" % (job_id[:8], cap), flush=True)
    return {"status": "follow_timeout"}


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
    angles = r.get("angles") or []
    if angles:
        print("   角度 %d 个：" % len(angles))
        for i, a in enumerate(angles[:8]):
            print("     #%d %s | %s" % (i + 1, a.get("title", ""), (a.get("angle") or "")[:60]))
    written = r.get("written") or []
    if written:
        for w in written[-1:]:
            body = w.get("script") or ""
            print("   成稿《%s》 %d 字" % (w.get("title", ""), len(re.sub(r"\s", "", body))))
            print("     " + body[:380].replace("\n", " "))
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
    if r.get("status"):
        print("   job_status=%s" % r["status"])
    if r.get("out"):
        print("   out=%s" % r["out"])
    for k in ("tip", "error", "progress"):
        if r.get(k):
            print("   %s：%s" % (k, str(r[k])[:200]))


def _get_session_script(sid):
    """从会话文件取最后一篇成稿正文（video_render 执行时用）。"""
    try:
        import glob
        cand = glob.glob("data/chat_sessions/%s.json" % sid)
        if not cand:
            return None
        d = json.load(open(cand[0], encoding="utf-8"))
        s = d if isinstance(d, dict) else (d[0] if d else {})
        written = s.get("written") or []
        for w in reversed(written):
            body = (w.get("script") or "").strip()
            if body:
                return body
    except Exception:
        pass
    return None


def _execute_cap(sid, cap_id, vals):
    """复现前端点「开始执行」：直接调 8500 真实能力端点（与 Laravel 代理的同一批），
    长任务跟进度，最后回灌 8500 出 action_done 总结卡。

    说明：Laravel /studio/chat/action 对非 internal 能力就是代理到 8500 对应端点；
    video_render 内部走 VideoController::generate，底层也是调 8500 /generate。
    这里直接打 8500，产物真实、流程忠实，且不需 Laravel 登录态。
    """
    print("   → 执行能力 %s（直接调 8500 真实端点）" % cap_id, flush=True)
    data = {}
    ok = True
    if cap_id == "rewrite":
        text = vals.get("text") or ""
        if not text:
            return {"stage": "error", "ok": False, "error": "rewrite 缺少 text"}
        data = _req(PIPE, "/rewrite", {"text": text, "mode": vals.get("mode", "dual"),
                                        "target_duration": vals.get("target_duration")}, "POST", 180)
        if isinstance(data, dict) and data.get("ok") is False:
            ok = False
    elif cap_id == "video_render":
        dialogue = (vals.get("script") or vals.get("dialogue") or vals.get("text") or "").strip()
        if not dialogue:
            dialogue = _get_session_script(sid) or ""
        if not dialogue:
            return {"stage": "error", "ok": False, "error": "video_render 缺脚本"}
        gen = _req(PIPE, "/generate", {"dialogue": dialogue,
                                        "mode": vals.get("mode") or "scroll",
                                        "voice_form": vals.get("voice_form") or "male_mono",
                                        "tenant_id": TENANT}, "POST", 180)
        if isinstance(gen, dict) and gen.get("job_id"):
            jid = gen["job_id"]
            print("   渲染任务 job_id=%s，跟进度…" % jid[:8], flush=True)
            st = _poll_job(jid)
            out = st.get("out")
            data = {"job_id": jid, "out": out, "status": st.get("status")}
        else:
            ok = False
            data = {"error": str(gen)}
    else:
        # topic/hotspot/dissect/xhs/article 等：统一代理到同名 8500 端点
        path = {"topic": "/topic", "hotspot": "/hotspot", "dissect": "/dissect",
                "xhs": "/xhs_build_note", "article": "/article/write",
                "qc": "/qc", "strategist": "/strategist"}.get(cap_id, "/" + cap_id)
        data = _req(PIPE, path, vals, "POST", 180)
        if isinstance(data, dict) and data.get("ok") is False:
            ok = False
    # 回灌 8500 出 action_done 总结卡（以真实产物为准，验证卡片是否诚实）
    print("   → 回灌 8500（action_done 总结）", flush=True)
    rb = _req(PIPE, "/chat", {"session_id": sid, "message": "", "tenant": TENANT,
                              "action": {"cap": cap_id, "ok": ok, "data": data}}, "POST", 180)
    if rb.get("stage") == "async":
        return _poll_chat_status(sid)
    return rb


def send(sid, msg):
    """发一条用户消息，完整跑完这一回合（含可能的 action_ready→执行→回灌）。"""
    t0 = time.time()
    r = _req(PIPE, "/chat", {"session_id": sid, "message": msg, "tenant": TENANT}, "POST")
    # 异步长任务（拆角度/出稿/检索/回灌）：轮询直到出稳定结果
    if r.get("stage") == "async":
        r = _poll_chat_status(sid)
    # 撞上 busy（上一条还没落定）：等它落定后重试本条
    if r.get("stage") == "busy":
        r = _poll_chat_status(sid)
        if r.get("stage") == "busy":
            return r
    # action_ready：点「开始执行」→ 调 Laravel → 回灌
    # 但 video_render 是重活且会污染会话：只在用户明确说「做成片/生成视频」时才自动执行，
    # 改稿（「改成120秒」等）、二创等非出片意图只记录 action_ready，不自动出片。
    _WANTS_VIDEO = ("做成片", "生成视频", "出片", "渲染", "提交出片", "做视频", "出条视频")
    if r.get("stage") == "action_ready" and r.get("cap"):
        cap_id = r["cap"].get("id")
        vals = r.get("vals") or {}
        if cap_id == "video_render" and not any(w in msg for w in _WANTS_VIDEO):
            print("   （非出片指令，跳过 video_render 自动执行，等用户点「做成片」卡片）", flush=True)
        else:
            r = _execute_cap(sid, cap_id, vals)
    dt = time.time() - t0
    print("\n───────── 我说：%s" % msg[:80], flush=True)
    _dump(r, dt)
    return r


SCENES = {
    "A": [  # 完整出片：选题→角度→写稿(含时长)→改时长→出片(真实渲染)
        "我想做一条短视频，主题是：股东借款年底不还，会被视同分红征税",
        "用第2个角度，写成口播稿，控制在90秒内",
        "这篇改成120秒",
        "做成片",
    ],
    "B": [  # 二创改写：贴原文→执行改写→看总结卡（验证不硬凑下游）
        "帮我把这段文案改成我的口径：很多老板以为公司账上的钱就是自己的钱，其实股东从公司借钱，年底不还就要视同分红交20%个税，这个坑很多人踩过。",
    ],
    "C": [  # 热点选题→写稿
        "今天财税圈有什么热点，给我拆成能拍的选题",
    ],
    "D": [  # 立论模式：给方向+观点写稿
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
