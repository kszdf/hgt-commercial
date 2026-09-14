# -*- coding: utf-8 -*-
"""
慧根堂对话编排器 · 端到端多轮对话测试（本地，假网络桩）
直接 import ChatOrchestrator，跑真实多轮对话，验证"顺畅跑出最终结果"。
重点覆盖本次修复的三处：
  F2 出片(数字人视频)无稿→先要主题→写完自动接出片卡片
  F3 写稿后"改成N字"→重写最新一篇，不误开二创能力
  + 能力卡片(xhs/article)、规划、卡片后续(去发布)、异常输入、哑LLM
"""
import sys, os, json, re, traceback, tempfile, time

PIPE = r"D:/heygem_data/hgt-commercial/python-pipeline"
sys.path.insert(0, PIPE)
import chat_orchestrator as CO

# ---------- 桩 ----------
def fake_cfg():
    return {"model": "deepseek-v4-flash", "key": "k", "base_url": "http://x"}

def plan_7days():
    days = ["周一","周二","周三","周四","周五","周六","周日"]
    pillars = ["创业起步财税","日常财税合规","稽查应对手记","历史遗留稽查","股权设计","电商专题","政策速递"]
    return [{"day":d,"pillar":p,"topic":"%s痛点选题%d"%(p,i+1),"form":"幕后音·滚动字幕","why":"戳老板刚需"} for i,(d,p) in enumerate(zip(days,pillars))]

def fake_chat(prompt, model, key, base_url=None, timeout=None, thinking=None):
    # 规划 prompt（含"周一到周日"或"七支柱"）→ 返回合法 7 天 JSON 数组
    if "周一到周日" in prompt or "七支柱" in prompt:
        return json.dumps(plan_7days(), ensure_ascii=False)
    # 意图解析（聪明 LLM）
    if "意图调度器" in prompt:
        msg = ""
        i = prompt.find("用户本轮：")
        if i >= 0:
            msg = prompt[i+5:].split("\n")[0].strip()
        O = DUMMY
        # 全写/都写/就按这个 → pick=all
        pick = O._pick_from_msg(msg)
        if any(w in msg for w in ("全写","都写","就按这个","全部","按这")):
            pick = "all"
        # 拆角度 / 重新拆角度 / 换个角度 / 再拆一次 → action=propose
        if any(w in msg for w in ("拆角度", "出角度", "重新拆", "换个角度", "再拆一次", "再拆一遍", "重新出角度")):
            return json.dumps({"cap": None, "cap_confidence": 0, "action": "propose",
                               "pick": None, "extract": {}, "asked": ""}, ensure_ascii=False)
        if "继续" in msg or "下一条" in msg or "全写" in msg or O._is_produce_cmd(msg) or pick == "all":
            return json.dumps({"cap": None, "cap_confidence": 0, "action": "write",
                               "pick": pick, "extract": {}, "asked": ""}, ensure_ascii=False)
        if O._looks_like_question(msg) or "税" in msg or "?" in msg or "？" in msg:
            return json.dumps({"cap": None, "cap_confidence": 0, "action": "answer", "answer_ctx": msg}, ensure_ascii=False)
        # 模仿线上 LLM 能力分类：仅有"去发布/发布"等不在关键词表里的意图靠 LLM 判定
        # （出片/小红书/公众号 在线上由 _match_capability 关键词命中，不走这里）
        if "发布" in msg:
            return json.dumps({"cap": "publish_pack", "cap_confidence": 0.9, "action": "ask"}, ensure_ascii=False)
        return json.dumps({"cap": None, "cap_confidence": 0, "action": "answer", "answer_ctx": msg}, ensure_ascii=False)
    return "（模拟回答）已收到"

def fake_ai_topic(industry="", keywords="", count=8):
    n = min(int(count) if count else 8, 8)
    return [{"title": "角度%d：%s" % (i+1, (keywords or "主题")[:8]),
             "angle": "切入角度", "hook": "钩子"} for i in range(n)]

def fake_ai_rewrite(source, kind, focus=None, industry=None, **kw):
    # 把目标字数透传进模拟成稿，便于断言"改成N字"生效
    wctag = ""
    if focus and "控制在" in focus:
        m = re.search(r"控制在(\d+)字", focus)
        if m:
            wctag = "[字数=%s]" % m.group(1)
    return {"rewritten": "%s[模拟成稿] %s" % (wctag, (source or "")[:50])}

def fake_search(q, key, topic="general", days=365, max_results=5, timeout=15):
    return {"results": [{"title": "模拟检索：" + q, "url": "http://x", "content": "摘要"}]}

def fake_get_key(n):
    return "testkey"

DUMMY = CO.ChatOrchestrator(fake_ai_topic, fake_ai_rewrite, fake_chat, fake_cfg,
                            search_fn=fake_search, get_key_fn=fake_get_key)
DUMMY._chat_log = lambda *a, **k: None

def new_orch():
    o = CO.ChatOrchestrator(fake_ai_topic, fake_ai_rewrite, fake_chat, fake_cfg,
                            search_fn=fake_search, get_key_fn=fake_get_key)
    o._dir = tempfile.mkdtemp(prefix="hgt_e2e_")   # 隔离磁盘会话，避免加载真实历史数据
    o._chat_log = lambda *a, **k: None
    return o

def stage(res):
    return (res or {}).get("stage")
def cap_id(res):
    c = (res or {}).get("cap")
    return c.get("id") if isinstance(c, dict) else None
def run(o, sid, msg, action=None):
    try:
        return o.step(sid, msg, action=action)
    except Exception as e:
        return {"__exception__": True, "stage": "CRASH", "error": repr(e)}

results = []
def record(label, ok, detail=""):
    results.append((label, ok, detail))
    print("[%s] %s%s" % ("PASS" if ok else "FAIL", label, ("  -> " + detail) if detail else ""))

# ============================================================
# F2：出片(数字人视频)完整流程：选题→写口播稿→可改稿→确认→出片
#   严禁没稿就直接弹 video_render 卡片，更严禁把用户随口话当成脚本。
# ============================================================
def test_render_chain():
    o = new_orch()
    sid = "render_chain"
    r1 = run(o, sid, "帮我出个数字人视频")
    st1 = stage(r1)
    await_topic = o._sessions.get(sid, {}).get("awaiting_topic_for_write")
    ok1 = st1 == "ask" and await_topic
    record("F2.出片无稿→先要主题", ok1, "stage=%s awaiting_topic=%s" % (st1, await_topic))

    r2 = run(o, sid, "讲老板用个人卡收货款的税务风险")
    st2 = stage(r2)
    ok2 = st2 == "propose"
    record("F2.给主题→拆角度", ok2, "stage=%s" % st2)

    # 写稿（若系统还要受众，先补一句）
    r3 = run(o, sid, "就按这个全写")
    if stage(r3) == "ask" and "受众" in (r3.get("missing") or []):
        r3 = run(o, sid, "给中小老板看")
    st3 = stage(r3)
    # 写完后必须先展示口播稿，不能自动跳视频卡片
    script = ""
    for w in o._sessions.get(sid, {}).get("written", []):
        script = w.get("script", "")
    ok3 = st3 == "written" and cap_id(r3) is None and len(script) > 0
    record("F2.写完后展示口播稿(不自动跳视频)", ok3, "stage=%s cap=%s script_len=%d" % (st3, cap_id(r3), len(script)))

    # 看过稿后再说"生成视频" → 弹出 video_render 卡片（此时脚本已齐）
    r4 = run(o, sid, "生成视频")
    st4 = stage(r4)
    cid4 = cap_id(r4)
    dlg = (r4 or {}).get("vals", {}).get("dialogue") or ""
    ok4 = st4 in ("action_ready", "action_ask") and cid4 == "video_render" and len(dlg) > 0
    record("F2.确认生成视频→弹出视频卡片", ok4, "stage=%s cap=%s dlg_len=%d" % (st4, cid4, len(dlg)))

# 无稿时用户随口说"生成视频"（甚至带抱怨）→ 绝不能把这句话填进脚本字段
def test_render_no_script_safe():
    o = new_orch()
    sid = "render_safe"
    r = run(o, sid, "没有口播稿内容，是否要修改呢？就开始直接生成视频？")
    ok = stage(r) == "ask" and cap_id(r) is None
    record("无稿时'生成视频'不弹视频卡片", ok, "stage=%s cap=%s" % (stage(r), cap_id(r)))

# 写稿后"改字数/时长/表述"→ 走修改流程，不误开能力
def test_script_modify():
    o = new_orch()
    sid = "script_mod"
    run(o, sid, "写个口播稿")
    run(o, sid, "讲公转私的风险")
    run(o, sid, "就按这个全写")
    r = run(o, sid, "改字数，改成800字")
    msg = r.get("message", "")
    ok = stage(r) == "written" and ("800" in msg or "800" in str(o._sessions.get(sid, {}).get("written", [{}])[-1].get("script", "")))
    record("写稿后改字数→重写(不跳能力)", ok, "stage=%s" % stage(r))

# ============================================================
# F3：写稿后"改成2000字" → 重写最新一篇，不误开二创能力
# ============================================================
def test_param_change():
    o = new_orch()
    sid = "param_chg"
    run(o, sid, "帮我写个口播稿")
    run(o, sid, "讲公转私的风险")
    r2 = run(o, sid, "就按这个全写")   # 先写成稿
    # 现在说"改成2000字"
    r3 = run(o, sid, "改成2000字")
    st3 = stage(r3)
    cid = cap_id(r3)
    msg3 = r3.get("message", "")
    script = ""
    for w in o._sessions.get(sid, {}).get("written", []):
        script = w.get("script", "")
    ok = st3 == "written" and cid != "rewrite" and ("2000" in script or "2000" in msg3)
    record("F3.改成2000字→重写最新篇(非二创)", ok, "stage=%s cap=%s script_has2000=%s" % (st3, cid, "2000" in script))

# ============================================================
# 能力卡片：小红书 / 公众号文章
# ============================================================
def test_cap_cards():
    for label, msg, want in [("小红书", "帮我做小红书", "xhs"), ("公众号", "帮我写篇公众号文章", "article")]:
        o = new_orch()
        sid = "cap_" + want
        r = run(o, sid, msg)
        ok = cap_id(r) == want
        record("能力卡片[%s]" % label, ok, "cap=%s" % cap_id(r))

    # 关键修复验证：只说能力名、没给主题 → 应进入 action_ask 追问主题，不能硬凑"微信"当主题
    o = new_orch()
    sid = "cap_article_blank"
    r = run(o, sid, "请给我写一篇微信公众号文章")
    vals = (r or {}).get("vals") or {}
    ok = (stage(r) == "action_ask" and cap_id(r) == "article" and
          not vals.get("topic"))
    record("公众号能力空白请求→追问主题", ok, "stage=%s cap=%s topic=%s" % (stage(r), cap_id(r), vals.get("topic")))

# ============================================================
# 规划
# ============================================================
def test_plan():
    o = new_orch()
    sid = "plan"
    r = run(o, sid, "帮我规划本周财税内容")
    days = (r or {}).get("plan", {}).get("days") or []
    ok = stage(r) == "plan" and len(days) >= 5
    record("规划→7天排期", ok, "stage=%s days=%d" % (stage(r), len(days)))

# ============================================================
# 卡片后续：去发布（验证不吞消息）
# ============================================================
def test_card_followup():
    o = new_orch()
    sid = "follow"
    r1 = run(o, sid, "帮我做小红书")   # 出 xhs 卡片
    # 模拟前端已点「开始执行」(Laravel 执行完清空 pending_cap，置 last_action_ready)
    sess = o._sessions[sid]
    sess["pending_cap"] = None
    sess["last_action_ready"] = {"cap_id": "xhs", "vals": {"topic": "测试"}, "ts": time.time(), "msg": "帮我做小红书"}
    r2 = run(o, sid, "去发布")          # 后续说去发布
    ok = cap_id(r2) == "publish_pack"
    record("卡片后续'去发布'→发布包", ok, "cap=%s" % cap_id(r2))

# ============================================================
# 异常输入：嗯 / 1 / 单字（不崩、有回应）
# ============================================================
def test_weird():
    for msg in ["嗯", "1", "好", "？"]:
        o = new_orch()
        sid = "weird_" + msg
        r = run(o, sid, msg)
        ok = not r.get("__exception__") and stage(r) in ("answer", "ask", "idle")
        record("异常输入[%s]" % msg, ok, "stage=%s" % stage(r))

# ============================================================
# 哑 LLM 安全网
# ============================================================
def test_dumb():
    o = new_orch()
    o._understand = lambda s, m: {"cap": None, "cap_confidence": 0, "action": "ask", "asked": "还缺主题"}
    sid = "dumb"
    r = run(o, sid, "公转私怎么操作")
    ok = not r.get("__exception__") and stage(r) in ("answer", "ask", "search")
    record("哑LLM安全网", ok, "stage=%s" % stage(r))

def test_status_inquiry():
    """状态问句必须识别成'问进度'，不能误触发成写稿/出片指令（像人一样对话）。"""
    o = new_orch()
    sid = "st1"
    # 1) 用户原话：不能弹 article 卡片，应 stage=answer
    r1 = run(o, sid, "今天的微信公众号文章写了吗")
    ok1 = stage(r1) == "answer" and cap_id(r1) is None and bool((r1.get("message") or "").strip())
    record("状态问句[公众号文章]→答进度非指令", ok1, "stage=%s cap=%s" % (stage(r1), cap_id(r1)))
    # 1.5) 同一句话重复问，仍应自然答进度（不许变 busy/async/空回复）
    r1b = run(o, sid, "今天的微信公众号文章写了吗")
    ok1b = stage(r1b) == "answer" and cap_id(r1b) is None and bool((r1b.get("message") or "").strip())
    # 重复识别逻辑锁定：check_repeat 对状态类重复返回 ("status", None)，对全新问题返回 None（不误判）
    rep = o.check_repeat(sid, "今天的微信公众号文章写了吗")
    ok_rep = isinstance(rep, tuple) and rep[0] == "status"
    rep_new = o.check_repeat(sid, "完全不同的新问题某某某")
    ok_rep_new = rep_new is None
    record("重复状态问句→仍答进度+识别为重复", ok1b and ok_rep and ok_rep_new,
           "stage=%s cap=%s rep=%s rep_new=%s" % (stage(r1b), cap_id(r1b), rep, rep_new))
    # 2) 视频状态问句
    o2 = new_orch(); sid2 = "st2"
    r2 = run(o2, sid2, "视频出了没")
    ok2 = stage(r2) == "answer" and cap_id(r2) is None
    record("状态问句[视频]→答进度非指令", ok2, "stage=%s cap=%s" % (stage(r2), cap_id(r2)))
    # 3) 真写稿指令仍正常进写稿（不被误判为状态问）
    o3 = new_orch(); sid3 = "st3"
    r3 = run(o3, sid3, "帮我写一篇公转私的公众号文章，给中小老板看")
    ok3 = cap_id(r3) is not None or stage(r3) in ("propose", "write", "action_ready")
    record("写稿指令→正常进写稿(未被当状态问)", ok3, "stage=%s cap=%s" % (stage(r3), cap_id(r3)))

def test_repeat_nonstatus():
    """非状态类重复提问：check_repeat 必须复用上次真实答案，绝不 blank/答非所问/误开能力。"""
    o = new_orch(); sid = "repns"
    r = run(o, sid, "公转私有什么风险")
    # 同一句重复 → 返回 ("answer", 非空)，复用记忆
    rep = o.check_repeat(sid, "公转私有什么风险")
    ok = isinstance(rep, tuple) and rep[0] == "answer" and bool(rep[1])
    # 不同问题不被误判为重复
    rep2 = o.check_repeat(sid, "小红书笔记做了吗")
    ok2 = rep2 is None
    record("非状态类重复→复用记忆答案+不误判", ok and ok2, "rep=%s rep2=%s" % (rep, rep2))

# ============================================================
# F-x：意图识别与回答合并为 1 次模型调用（优化"双调用"）
# 真实 _understand 在判定 action=answer 时会顺带生成 answer 字段，
# _do_answer 应复用它而不再打第二次模型。
# ============================================================
def test_repropose():
    """用户点「重新拆角度」→ 应重新拆出一版新角度，不能没反应或误进写稿。"""
    o = new_orch()
    sid = "repropose"
    # 直接注入主题+受众（fake_chat 不模拟 extract，这里测的是重拆逻辑本身）
    sess = o._get(sid)
    sess["topic"] = "滞纳金与迟纳金的区别"
    sess["audience"] = "已注册、正在经营的中小老板"
    run(o, sid, "拆角度")
    a1 = o._sessions.get(sid, {}).get("angles") or []
    # 重新拆角度
    r = run(o, sid, "重新拆角度")
    st = stage(r)
    a2 = r.get("angles") or []
    ok = st == "propose" and len(a2) >= 5 and len(a2) == len(a1)
    record("重新拆角度→重新出角度方案", ok, "stage=%s angles=%d->%d" % (st, len(a1), len(a2)))
    # 同义表达：换个角度
    r2 = run(o, sid, "换个角度")
    ok2 = stage(r2) == "propose" and len(r2.get("angles") or []) >= 5
    record("换个角度→重新出角度方案", ok2, "stage=%s" % stage(r2))

def test_merge_single_call():
    calls = {"n": 0}
    def counting_chat(prompt, model, key, base_url=None, timeout=None, thinking=None):
        calls["n"] += 1
        if "意图调度器" in prompt:
            # 模拟线上：意图识别同时把回答正文一并生成
            return json.dumps({"cap": None, "cap_confidence": 0,
                               "action": "answer", "answer_ctx": "公转私的风险",
                               "answer": "公转私容易被稽查认定为隐匿收入、公私不分，建议走合规通道。"},
                              ensure_ascii=False)
        return "（模拟回答）已收到"

    def mk_orch():
        o = CO.ChatOrchestrator(fake_ai_topic, fake_ai_rewrite, counting_chat, fake_cfg,
                                search_fn=fake_search, get_key_fn=fake_get_key)
        o._dir = tempfile.mkdtemp(prefix="hgt_merge_")
        o._chat_log = lambda *a, **k: None
        return o

    # 消息不含疑问词/问号 → _answer_needs_search=False → 应复用 prepared（1 次调用）
    calls["n"] = 0
    o = mk_orch()
    sid = o.create_session("")["session_id"]
    r = o.step(sid, "公转私的风险", "")
    ok1 = stage(r) == "answer" and "隐匿收入" in (r.get("message") or "")
    record("合并调用·普通问答只打1次模型", ok1 and calls["n"] == 1,
           "stage=%s calls=%d msg=%s" % (stage(r), calls["n"], (r.get("message") or "")[:16]))

    # 兜底：_understand 没给 answer（解析失败）→ 必须仍答、不崩（走原路径，长于1次调用也允许）
    calls["n"] = 0
    o2 = mk_orch()
    def broken_chat(prompt, model, key, base_url=None, timeout=None, thinking=None):
        calls["n"] += 1
        if "意图调度器" in prompt:
            return "这不是合法JSON"
        return "（兜底）公转私有风险。"
    o2._chat = broken_chat
    sid2 = o2.create_session("")["session_id"]
    r2 = o2.step(sid2, "公转私的风险", "")
    ok2 = stage(r2) == "answer" and r2.get("message")
    record("合并调用·解析失败仍正常回答(无回退)", ok2,
           "stage=%s msg=%s" % (stage(r2), (r2.get("message") or "")[:12]))

if __name__ == "__main__":
    test_render_chain()
    test_render_no_script_safe()
    test_script_modify()
    test_param_change()
    test_cap_cards()
    test_plan()
    test_card_followup()
    test_weird()
    test_dumb()
    test_status_inquiry()
    test_repeat_nonstatus()
    test_repropose()
    test_merge_single_call()
    total = len(results); failed = sum(1 for _, ok, _ in results if not ok)
    print("\n==== 本地端到端对话测试：%d 项，失败 %d ====" % (total, failed))
    if failed:
        print("失败项：")
        for label, ok, detail in results:
            if not ok:
                print("  - %s : %s" % (label, detail))
    sys.exit(1 if failed else 0)
