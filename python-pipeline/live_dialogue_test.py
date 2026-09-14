#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""真实打 8500 接口，跑多轮自然对话，看系统反应是否自然流畅。"""
import json, time, urllib.request, urllib.error

BASE = "http://127.0.0.1:8500"

def _post(path, payload):
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(BASE + path, data=data,
                                 headers={"Content-Type": "application/json"}, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        return {"_http_error": e.code, "body": e.read().decode("utf-8", "ignore")}
    except (urllib.error.URLError, TimeoutError, OSError) as e:
        return {"_url_error": str(e)}

def _get(path):
    # /chat/status/<sid> 是 GET 路由，必须用 GET 轮询（POST 会 404）
    req = urllib.request.Request(BASE + path, headers={"Content-Type": "application/json"}, method="GET")
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        return {"_http_error": e.code, "body": e.read().decode("utf-8", "ignore")}
    except (urllib.error.URLError, TimeoutError, OSError) as e:
        return {"_url_error": str(e)}

def _poll(sid):
    # 最长轮询 250s（含长任务 4 分钟看门狗余量）；pending 持续等，拿到终态即返回
    for _ in range(84):
        time.sleep(3)
        st = _get("/chat/status/" + sid)
        stage = st.get("stage")
        if stage in ("idle", "done", "error", "answer", "ask", "written",
                     "propose", "plan", "action_ready", "search", "review"):
            return st
    return {"stage": "timeout"}

def send(sid, msg, tenant="huigentang"):
    r = _post("/chat", {"session_id": sid, "message": msg, "tenant": tenant})
    if r.get("stage") == "async":
        print("    [async] 等待结果…")
        r = _poll(sid)
    return r

def show(label, r):
    stage = r.get("stage")
    cap = r.get("cap")
    msg = (r.get("message") or "").strip()
    opts = r.get("options") or []
    nres = len(r.get("results") or [])
    extra = ""
    if cap: extra += f" cap={cap}"
    if opts: extra += f" 选项={opts}"
    if nres: extra += f" 结果数={nres}"
    print(f"  [{label}] stage={stage}{extra}")
    if msg:
        print(f"         ↳ {msg[:120]}")
    return r

def main():
    r = _post("/chat/session/create", {"tenant": "huigentang"})
    sid = r.get("session_id")
    print(f"会话 SID={sid}\n{'='*60}")

    cases = [
        ("打招呼", "你好"),
        ("状态问句", "今天的微信公众号文章写了吗"),
        ("随意问进度", "昨天写的口播稿在哪看"),
        ("自然指令（带受众）", "帮我写一篇关于公转私风险的公众号文章，给中小老板看"),
        ("改字数", "改成2000字"),
        ("出片链", "把这个做成数字人视频"),
        ("模糊出片", "再给我出个短视频"),
        ("规划", "帮我规划一下这周要发的内容"),
        ("异常短句", "嗯"),
        ("反问式", "你觉得公转私和个独哪个风险大"),
        ("重复状态问句", "今天的微信公众号文章写了吗"),
    ]
    for label, msg in cases:
        print(f"\n>>> 用户：{msg}")
        r = send(sid, msg)
        show(label, r)
        time.sleep(1)

    print("\n" + "="*60)
    print("对话测试结束")

if __name__ == "__main__":
    main()
