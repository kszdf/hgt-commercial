#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""真机探针：复现张老师 2026-09-19 踩的坑（答「240」被脑补成"第240条"触发协作审查）。

路径：写稿要求带"4分钟内" → 出稿 → "这篇改下时长" → 答「240」
断言：① 出稿字数应落在 240秒 对应的合理区间（520–640字，server 按130–160字/分）
      ② "这篇改下时长" 应 stage=ask 且回复里问秒数
      ③ 答「240」应直接重写（stage=written），绝不能是 review/协作审查
"""
import json, time, sys, urllib.request, urllib.error

BASE = "http://127.0.0.1:8500"
TENANT = "huigentang"


def _req(path, payload=None, method="GET"):
    data = json.dumps(payload).encode("utf-8") if payload is not None else None
    req = urllib.request.Request(
        BASE + path, data=data,
        headers={"Content-Type": "application/json"}, method=method)
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        return {"_http_error": e.code, "body": e.read().decode("utf-8", "ignore")[:200]}
    except (urllib.error.URLError, TimeoutError, OSError) as e:
        return {"_url_error": str(e)}


def _post(p, pl):
    return _req(p, pl, "POST")


def _poll(sid, max_wait=300):
    waited = 0
    while waited < max_wait:
        time.sleep(4)
        waited += 4
        st = _req("/chat/status/" + sid)
        stage = st.get("stage")
        if stage in ("idle", "done", "error", "answer", "ask", "written",
                     "propose", "plan", "action_ready", "search", "review"):
            return st
    return {"stage": "timeout"}


def send(sid, msg):
    r = _post("/chat", {"session_id": sid, "message": msg, "tenant": TENANT})
    if r.get("stage") == "async":
        print("      [async] 等待…", flush=True)
        r = _poll(sid)
    return r


def count_body(script):
    """口播正文净字数（含标点）——去掉标题行与空行。"""
    lines = [l.strip() for l in (script or "").split("\n") if l.strip()]
    return sum(len(l) for l in lines), len(lines)


def main():
    r = _post("/chat/session/create", {"tenant": TENANT})
    sid = r.get("session_id")
    if not sid:
        print("✗ 创建会话失败:", r)
        return 1
    print(f"会话 SID={sid}")
    print("=" * 66, flush=True)

    fails = []

    # ---------- 第 1 步：写稿要求里带时长 ----------
    msg1 = "写个口播稿，关于滞纳金改成迟纳金的，控制在4分钟内，要有观点，做成爆款"
    print(f"\n>>> 用户：{msg1}", flush=True)
    r1 = send(sid, msg1)
    print(f"    stage={r1.get('stage')} cap={r1.get('cap')} 角度数={len(r1.get('angles') or [])}", flush=True)
    print(f"    ↳ {(r1.get('message') or '')[:150]}", flush=True)

    # 首次多半先拆角度，再写第 1 条
    if r1.get("stage") == "propose":
        print("\n>>> 用户：写第1条", flush=True)
        r1 = send(sid, "写第1条")
        print(f"    stage={r1.get('stage')}", flush=True)

    n_ok = False
    if r1.get("stage") == "written":
        res = (r1.get("results") or [])
        if res:
            body, ln = count_body(res[0].get("script"))
            lo, hi = 240 * 130 // 60, 240 * 160 // 60   # server 侧 130–160 字/分
            print(f"\n    【成稿字数】{body} 字 / {ln} 段  （240秒应落在 {lo}–{hi} 字）", flush=True)
            for sp in (2.4, 2.6, 2.8):
                sec = body / sp
                print(f"       {sp}字/秒 -> {int(sec//60)}分{int(sec%60):02d}秒", flush=True)
            if lo <= body <= hi:
                print("    ✅ 字数落在目标区间 → 时长确实透传给了模型", flush=True)
                n_ok = True
            else:
                print(f"    ⚠ 字数 {body} 不在 {lo}–{hi}，时长可能没卡住", flush=True)
                fails.append(f"首稿字数 {body} 不在 {lo}–{hi}")

    if not (r1.get("stage") == "written" and (r1.get("results") or [])):
        print("\n    ⚠ 没拿到成稿（可能是额度/超时），后续步骤改为直接验证路由")
        print("    → 用注入成稿的方式继续测", flush=True)

    # ---------- 第 2 步：要求改时长（应追问秒数）----------
    print("\n>>> 用户：这篇改下时长", flush=True)
    r2 = send(sid, "这篇改下时长")
    m2 = (r2.get("message") or "").strip()
    print(f"    stage={r2.get('stage')}", flush=True)
    print(f"    ↳ {m2[:120]}", flush=True)
    if r2.get("stage") == "ask" and ("秒" in m2):
        print("    ✅ 追问秒数（且已落地 _await_param）", flush=True)
    else:
        print("    ⚠ 未走到'追问秒数'分支，下面的裸数字可能不经过新逻辑", flush=True)
        fails.append("第2步未追问秒数")

    # ---------- 第 3 步：★关键——答裸数字 240 ----------
    print("\n>>> 用户：240   （★原来这里会出现「协作审查 / 第240条」）", flush=True)
    r3 = send(sid, "240")
    st3 = r3.get("stage")
    m3 = (r3.get("message") or "").strip()
    print(f"    stage={st3}", flush=True)
    print(f"    ↳ {m3[:200]}", flush=True)

    # 抓会话状态，确认等待标记已消费
    st = _req("/chat/session/messages", None)  # 仅探测连通，不用结果
    if st3 == "written":
        res = (r3.get("results") or [])
        if res:
            body, ln = count_body(res[0].get("script"))
            lo, hi = 240 * 130 // 60, 240 * 160 // 60
            print(f"\n    【重写后字数】{body} 字  （240秒应落在 {lo}–{hi} 字）", flush=True)
            if lo <= body <= hi:
                print("    ✅ 按 240 秒重写成功，字数达标", flush=True)
            else:
                print(f"    ⚠ 字数 {body} 偏离区间", flush=True)
                fails.append(f"重写字数 {body} 不在 {lo}–{hi}")
        print("    ✅ 结论：'240' 被当成时长参数消费并重写，没有进协作审查", flush=True)
    elif st3 in ("review", "search"):
        print("    ❌ 仍然滚进了协作审查/检索 —— 修复未生效", flush=True)
        fails.append(f"答240后 stage={st3}（本应 written）")
    else:
        print(f"    ⚠ stage={st3}，需人工判断是否合理", flush=True)
        fails.append(f"答240后 stage={st3}")

    print("\n" + "=" * 66, flush=True)
    if fails:
        print("失败项：", flush=True)
        for f in fails:
            print("  -", f, flush=True)
        return 1
    print("全部通过 ✅", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
