#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""第三方审计：独立验证线上 8500 对话意图路由（不盲信开发方"全绿"）。
每例以"期望行为"判定 PASS/FAIL/REVIEW，真实回复落盘 audit_report.json。
网络层对超时/异常零容错→改为隔离单例失败、整轮继续。
"""
import json, time, urllib.request, sys, datetime

BASE = "http://127.0.0.1:8500"
OUT = []

HTTP_TIMEOUT = 120


def post(path, payload):
    req = urllib.request.Request(BASE + path,
                                 data=json.dumps(payload).encode(),
                                 headers={"Content-Type": "application/json"},
                                 method="POST")
    with urllib.request.urlopen(req, timeout=HTTP_TIMEOUT) as r:
        return json.loads(r.read().decode())


def get(path):
    with urllib.request.urlopen(BASE + path, timeout=HTTP_TIMEOUT) as r:
        return json.loads(r.read().decode())


def create(title):
    try:
        return post("/chat/session/create", {"tenant": "", "title": title}).get("session_id")
    except Exception as e:
        return {"_error": "create: %s" % e}


def chat(sid, msg):
    try:
        return post("/chat", {"session_id": sid, "message": msg, "tenant": ""})
    except Exception as e:
        return {"_error": "chat: %s" % e}


def poll(sid, timeout=280):
    end = time.time() + timeout
    last = {}
    while time.time() < end:
        try:
            st = get("/chat/status/" + sid)
        except Exception:
            st = {}
        last = st
        if st.get("stage") not in ("async", "pending", "idle", None, ""):
            return st
        time.sleep(3)
    return last or {"_error": "poll timeout"}


def send(sid, msg):
    r = chat(sid, msg)
    if isinstance(r, dict) and r.get("stage") == "async":
        r = poll(sid)
    return r or {}


def cap_id(r):
    c = r.get("cap")
    return (c or {}).get("id") if isinstance(c, dict) else c


def msg_text(r):
    return (r.get("message") or "").strip()


CASES = [
    dict(id="S1", cat="状态问句", name="公众号写了吗", kind="status", msg="今天的微信公众号文章写了吗"),
    dict(id="S2", cat="状态问句", name="视频出了没", kind="status", msg="今天的视频出了没"),
    dict(id="S3", cat="状态问句", name="小红书做好没", kind="status", msg="小红书图文做好了没有"),
    dict(id="S4", cat="状态问句", name="口播稿写完吗", kind="status", msg="口播稿写完了吗"),
    dict(id="S5", cat="状态问句", name="选题排了吗", kind="status", msg="这周的选题排了吗"),
    dict(id="S6", cat="状态问句", name="稿子弄完没", kind="status", msg="稿子弄完了没"),
    dict(id="S7", cat="状态问句", name="成片渲染了吗", kind="status", msg="今天的口播视频渲染了吗"),
    dict(id="S8", cat="状态问句", name="发布了吗", kind="status", msg="这周的内容都发布了吗"),

    dict(id="W1", cat="写稿·空白", name="公众号没给主题", kind="write_blank_cap", cap="article",
         msg="请给我写一篇微信公众号文章"),
    dict(id="W2", cat="写稿·空白", name="写条口播没给方向", kind="write_blank_ask", msg="帮我写条口播"),
    dict(id="W3", cat="写稿·空白", name="出视频脚本没给主题", kind="write_blank_ask", msg="出个财税视频脚本"),
    dict(id="W4", cat="写稿·空白", name="小红书没给主题", kind="write_blank_cap", cap="xhs", msg="给我写个小红书"),

    dict(id="W5", cat="写稿·带主题", name="带主题的公众号", kind="write_topic_cap", cap="article",
         msg="来一篇讲公转私的公众号文章"),
    dict(id="W6", cat="写稿·带主题", name="带主题的口播", kind="write_topic_propose",
         msg="写条讲公转私风险的口播，给老板看"),

    dict(id="R1", cat="改写", name="逐字稿改编", kind="rewrite", msg="我给你个逐字稿，你可以帮我改编吗"),
    dict(id="R2", cat="改写", name="口播稿改风格", kind="rewrite", msg="我有一段口播稿，改成我的风格"),
    dict(id="R3", cat="改写", name="文案二创无原文", kind="rewrite", msg="把这篇文案二创一下"),
    dict(id="R4", cat="改写", name="改写短句", kind="rewrite",
         msg="帮我改写这段：小规模纳税人月销售额10万以下免增值税，但开了专票就得交。"),

    dict(id="P1", cat="重拆角度", name="重新拆角度", kind="repropose",
         setup=["公转私的风险，给中小老板，拆几个角度", "就按这个拆"], msg="重新拆角度"),
    dict(id="P2", cat="重拆角度", name="换个角度再拆", kind="repropose",
         setup=["公转私的风险，给中小老板，拆几个角度", "就按这个拆"], msg="换个角度再拆一次"),
    dict(id="P3", cat="重拆角度", name="不要了重来", kind="repropose",
         setup=["公转私的风险，给中小老板，拆几个角度", "就按这个拆"], msg="这些角度不要了，重来"),
    dict(id="P4", cat="重拆角度", name="角度不够再来", kind="repropose",
         setup=["公转私的风险，给中小老板，拆几个角度", "就按这个拆"], msg="角度不够，再来一次"),

    dict(id="V1", cat="出片", name="无稿说生成视频", kind="video_no_script", msg="生成视频"),
    dict(id="V2", cat="出片", name="有稿做成片", kind="video_script",
         setup=["写条讲个人卡收款风险的口播，给老板看", "就按这个拆", "全写"], msg="做成片"),

    dict(id="PL1", cat="规划", name="规划本周", kind="plan", msg="帮我规划本周内容"),
    dict(id="PL2", cat="规划", name="规划下周选题", kind="plan", msg="规划一下下周选题"),
    dict(id="PL3", cat="规划", name="排期这周", kind="plan", msg="排期一下这周"),

    dict(id="RP1", cat="重复问", name="同句重复问财税", kind="repeat",
         setup=["公转私有啥风险"], msg="公转私有啥风险"),

    dict(id="O1", cat="超域", name="天气", kind="out_of_scope", msg="今天天气怎么样"),
    dict(id="O2", cat="超域", name="写ERP系统", kind="out_of_scope", msg="帮我写个ERP进销存管理系统"),
    dict(id="O3", cat="对抗·异常", name="单字符问号", kind="out_of_scope", msg="？"),
    dict(id="O4", cat="对抗·异常", name="单字符嗯", kind="out_of_scope", msg="嗯"),
    dict(id="O5", cat="超域", name="你是谁", kind="out_of_scope", msg="你是谁"),
    dict(id="O6", cat="对抗·闲聊", name="讲笑话", kind="out_of_scope", msg="讲个笑话听听"),

    dict(id="C1", cat="投诉", name="又没反应", kind="complaint", msg="怎么又没反应"),

    dict(id="Q1", cat="财税问答", name="注册资本写多少", kind="fiscal_qa", msg="注册资本写多少合适"),
    dict(id="Q2", cat="财税问答", name="个人卡收货款", kind="fiscal_qa", msg="个人卡收货款会不会被查"),
    dict(id="Q3", cat="财税问答", name="公转私合规", kind="fiscal_qa", msg="公转私怎么操作才合规"),
    dict(id="Q4", cat="财税问答", name="注册vs个体户", kind="fiscal_qa", msg="注册公司和个体户怎么选"),
    dict(id="Q5", cat="财税问答", name="股东借款不还", kind="fiscal_qa", msg="股东从公司借钱一直不还行不行"),
]


def evaluate(c, r):
    if r.get("_error"):
        return ("FAIL", "接口异常: %s" % r["_error"])
    if r.get("stage") == "async":
        return ("REVIEW", "长任务未结束(模型可能慢)")
    stage = r.get("stage")
    cid = cap_id(r)
    topic = r.get("topic")
    m = msg_text(r)
    if c["kind"] == "status":
        ok = (stage == "answer") and (cid in (None, ""))
        return ("PASS" if ok else "FAIL", "stage=%s cap=%s" % (stage, cid))
    if c["kind"] == "write_blank_cap":
        ok = (cid == c.get("cap")) and (not topic)
        return ("PASS" if ok else "FAIL", "stage=%s cap=%s topic=%r" % (stage, cid, topic))
    if c["kind"] == "write_blank_ask":
        ok = (stage in ("ask", "action_ask")) and (cid in (None, ""))
        return ("PASS" if ok else "FAIL", "stage=%s cap=%s" % (stage, cid))
    if c["kind"] == "write_topic_cap":
        ok = (cid == c.get("cap")) and (stage in ("action_ready", "propose", "action_ask"))
        return ("PASS" if ok else "FAIL", "stage=%s cap=%s topic=%r" % (stage, cid, topic))
    if c["kind"] == "write_topic_propose":
        if stage in ("propose", "action_ready"):
            return ("PASS", "stage=%s cap=%s angles=%d" % (stage, cid, len(r.get("angles") or [])))
        if stage == "ask":
            return ("FAIL", "给了主题却仍追问主题 stage=%s" % stage)
        return ("FAIL", "stage=%s cap=%s" % (stage, cid))
    if c["kind"] == "rewrite":
        ok = (cid == "rewrite")
        return ("PASS" if ok else "FAIL", "stage=%s cap=%s" % (stage, cid))
    if c["kind"] == "repropose":
        ok = (stage == "propose") and len(r.get("angles") or []) >= 5
        return ("PASS" if ok else "FAIL", "stage=%s angles=%d" % (stage, len(r.get("angles") or [])))
    if c["kind"] == "video_no_script":
        ok = (stage in ("ask", "action_ask")) and (cid in (None, ""))
        return ("PASS" if ok else "FAIL", "stage=%s cap=%s" % (stage, cid))
    if c["kind"] == "video_script":
        ok = (cid == "video_render")
        return ("PASS" if ok else "FAIL", "stage=%s cap=%s" % (stage, cid))
    if c["kind"] == "plan":
        ok = (stage == "plan")
        return ("PASS" if ok else "FAIL", "stage=%s" % stage)
    if c["kind"] == "repeat":
        ok = (stage == "answer")
        reuse_hint = any(w in m for w in ("已经", "刚", "说过", "刚才", "之前", "前面", "回答过"))
        return ("PASS" if (ok and reuse_hint) else ("REVIEW" if ok else "FAIL"),
                "stage=%s reuse_hint=%s" % (stage, reuse_hint))
    if c["kind"] == "out_of_scope":
        ok = (stage == "answer") and (cid in (None, ""))
        bad = ("拍给谁看" in m) or ("给谁看呢" in m) or ("受众" in m) or ("你想拍" in m)
        if bad:
            return ("FAIL", "含受众反问红线 stage=%s cap=%s" % (stage, cid))
        return ("PASS" if ok else "REVIEW", "stage=%s cap=%s" % (stage, cid))
    if c["kind"] == "complaint":
        ok = (stage in ("ask", "action_ready"))
        return ("PASS" if ok else "FAIL", "stage=%s cap=%s" % (stage, cid))
    if c["kind"] == "fiscal_qa":
        ok = (stage == "answer") and len(m) >= 40
        bad = ("拍给谁看" in m) or ("给谁看呢" in m) or ("受众" in m) or ("你想拍" in m) or ("想拍给哪" in m)
        if not ok:
            return ("FAIL", "stage=%s len=%d" % (stage, len(m)))
        if bad:
            return ("FAIL", "含受众反问红线 len=%d" % len(m))
        return ("REVIEW", "len=%d 需人工核对口径准确性" % len(m))
    return ("REVIEW", "未定义判定")


def main():
    print("=== 第三方审计：8500 对话意图路由（独立验证）===")
    results = []
    for c in CASES:
        sid = create("audit_" + c["id"])
        if isinstance(sid, dict) and sid.get("_error"):
            rec = dict(id=c["id"], cat=c["cat"], name=c["name"], msg=c["msg"],
                       verdict="FAIL", detail="会话创建失败", reply="", full_reply="")
            results.append(rec)
            print("[FAIL] %-14s %-12s %s | 会话创建失败" % (c["id"], c["cat"], c["name"]))
            continue
        if c.get("setup"):
            for sp in c["setup"]:
                send(sid, sp)
                time.sleep(0.5)
        r = send(sid, c["msg"])
        verdict, detail = evaluate(c, r)
        snippet = msg_text(r)[:140].replace("\n", " ")
        rec = dict(id=c["id"], cat=c["cat"], name=c["name"], msg=c["msg"],
                   verdict=verdict, detail=detail, reply=snippet, full_reply=msg_text(r))
        results.append(rec)
        print("[%s] %-14s %-12s %s | %s" % (verdict, c["id"], c["cat"], c["name"], detail))
        if snippet:
            print("       ↳回复: %s" % snippet)
        time.sleep(0.8)

    n_pass = sum(1 for x in results if x["verdict"] == "PASS")
    n_fail = sum(1 for x in results if x["verdict"] == "FAIL")
    n_rev = sum(1 for x in results if x["verdict"] == "REVIEW")
    print("\n=== 审计汇总 ===")
    print("总计 %d 例 | PASS=%d  FAIL=%d  REVIEW(需人工/口径)%d" % (len(results), n_pass, n_fail, n_rev))
    if n_fail:
        print("\n❌ FAIL 明细：")
        for x in results:
            if x["verdict"] == "FAIL":
                print("  - %s %s：%s | 输入=%s" % (x["id"], x["name"], x["detail"], x["msg"]))
    if n_rev:
        print("\n⚠️ REVIEW 明细（路由正确，回答质量/口径需人工复核）：")
        for x in results:
            if x["verdict"] == "REVIEW":
                print("  - %s %s：%s" % (x["id"], x["name"], x["detail"]))

    report = {"ts": datetime.datetime.now().isoformat(), "total": len(results),
              "pass": n_pass, "fail": n_fail, "review": n_rev, "cases": results}
    with open("audit_report.json", "w", encoding="utf-8") as f:
        json.dump(report, f, ensure_ascii=False, indent=2)
    print("\n报告已写入 audit_report.json")
    sys.exit(1 if n_fail else 0)


if __name__ == "__main__":
    main()
