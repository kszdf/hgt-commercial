# -*- coding: utf-8 -*-
"""
对话出稿工作台 · 一期编排器（独立模块，server.py 注册式调用，避免循环 import）。

职责：把「选题 + 改写 + 成稿」三段串成一个有状态的对话工作台。
交互模型（张老师定稿）：
    用户给 主题/受众/关键要求/数量(可选) → AI 参照主提示词自主拆角度方案
    → 用户确认/挑角度 → 逐条成稿（复用 ai_topic 拆角度、ai_rewrite 写成稿）。

状态机阶段 phase:
    collect  收要素（缺什么追问什么）
    propose  要素齐，出角度方案（等确认）
    write    已选角度，逐条成稿（等选下一条 / 收尾）

意图由一次轻量 LLM 解析（输出严格 JSON），执行复用 ai_topic / ai_rewrite。

会话存储（二期改造）：
    落盘到 data/chat_sessions/<sid>.json，服务重启不丢、超时不清理。
    会话分两类：临时会话（temp，未命名）/ 主题空间（space，已命名，长期工作区）。
    每个会话保存完整消息流 messages[]，切换回来可原样回放，AI 也带完整上下文继续。
"""
import json
import os
import time
import uuid
import threading

try:
    import capabilities as _CAP
except Exception:  # noqa: BLE001
    _CAP = None

# 主提示词（张老师 v1.0 写稿规范的轻量化固化，注入到意图理解里让 AI 保持一致口径）
MASTER_PROMPT = (
    "你是一名资深财税与股权专家助理，服务「老张」——一位深耕财税20余年、"
    "具备税务稽查与历史遗留问题处理背景的专家。你在帮他运营面向中小企业老板的财税短视频。\n"
    "写稿铁律（成稿时交给改写器遵守，你在意图判断时也要据此理解用户意图）：\n"
    "1. 面向受众：初次创业及已有规模的中小老板（决策者，不是会计）。\n"
    "2. 口吻：冷静、专业、权威、讲人话；禁情感语气词与网络流行语（'打肿脸充胖子''真金白银'等）。\n"
    "3. ★悬念必须落在口播正文的开口第一句，不放标题；标题只作提示语。\n"
    "4. 不教逃税、不提供规避监管的'技巧'；数据/法条必须可溯源、不编造。\n"
    "5. 收尾给合规建议或专业咨询引导，不硬塞营销话术。"
)

# 常量：可接受的数量范围
MIN_COUNT, MAX_COUNT = 1, 10


class ChatOrchestrator:
    def __init__(self, ai_topic_fn, ai_rewrite_fn, deepseek_chat_fn, text_cfg_fn,
                 search_fn=None, get_key_fn=None):
        self._ai_topic = ai_topic_fn
        self._ai_rewrite = ai_rewrite_fn
        self._chat = deepseek_chat_fn
        self._cfg = text_cfg_fn
        self._search = search_fn            # 联网检索（tavily_search），未注入则检索能力关闭
        self._get_key = get_key_fn or (lambda n: None)
        self._sessions = {}
        self._lock = threading.Lock()
        # 会话落盘目录：服务重启后空间与历史对话仍在
        self._dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data", "chat_sessions")
        try:
            os.makedirs(self._dir, exist_ok=True)
        except Exception:
            pass

    # ---- 会话持久化 ----
    def _path(self, sid):
        return os.path.join(self._dir, str(sid) + ".json")

    def _save(self, s):
        """落盘（best-effort，写失败不影响主流程）。"""
        try:
            tmp = self._path(s["id"]) + ".tmp"
            with open(tmp, "w", encoding="utf-8") as f:
                json.dump(s, f, ensure_ascii=False)
            os.replace(tmp, self._path(s["id"]))
        except Exception:
            pass

    def _load(self, sid):
        """从磁盘读回；文件不存在/损坏返回 None。"""
        try:
            with open(self._path(sid), "r", encoding="utf-8") as f:
                d = json.load(f)
            return d if isinstance(d, dict) else None
        except Exception:
            return None

    # ---- 会话生命周期 ----
    def _get(self, sid):
        with self._lock:
            if sid and sid in self._sessions:
                return self._sessions[sid]
            s = None
            if sid:
                s = self._load(sid)          # 重启后从磁盘恢复
            if not s:
                s = {}
            # 补齐字段（兼容旧会话文件）
            s.setdefault("id", sid or uuid.uuid4().hex)
            s.setdefault("topic", "")
            s.setdefault("audience", "")
            s.setdefault("requirement", "")
            s.setdefault("count", 0)
            s.setdefault("phase", "collect")
            s.setdefault("angles", [])       # list[dict] 拆出的角度方案
            s.setdefault("chosen", [])       # list[dict] 用户选定待成稿的角度
            s.setdefault("written", [])      # list[dict] 已写完的口播稿
            s.setdefault("history", [])      # 供 LLM 理解的简短对话历史
            s.setdefault("messages", [])     # 完整消息流（前端回放 + 长期上下文）
            s.setdefault("title", "")        # 空间名（空 = 临时会话）
            s.setdefault("kind", "temp")     # temp 临时会话 / space 主题空间
            s.setdefault("tenant", "")       # 租户隔离
            s.setdefault("pinned", False)
            s.setdefault("created", time.time())
            s.setdefault("search_refs", [])  # 联网检索到的证据，供后续出稿参考
            s["last"] = time.time()
            self._sessions[s["id"]] = s
            return s

    def _touch(self, s):
        s["last"] = time.time()
        # 仅从内存淘汰（磁盘保留，切回来自动恢复），避免内存膨胀
        with self._lock:
            if len(self._sessions) > 300:
                now = time.time()
                for k in [k for k, v in self._sessions.items()
                          if now - v.get("last", 0) > 7200 and not v.get("pinned")]:
                    self._sessions.pop(k, None)

    def _record(self, s, role, payload):
        """把一条消息写进完整消息流（user 存原文，ai 存可原样渲染的 result）。"""
        s.setdefault("messages", []).append({
            "role": role,
            "ts": time.time(),
            "payload": payload,
        })
        # 上限保护：只保留最近 300 条（成稿文本较大，防止单文件无限膨胀）
        if len(s["messages"]) > 300:
            s["messages"] = s["messages"][-300:]

    def _auto_title(self, s):
        """主题确定后自动给未命名会话起名（临时会话 → 可辨识的主题条目）。"""
        if not s.get("title") and s.get("topic"):
            t = str(s["topic"]).strip()
            s["title"] = t[:24] + ("…" if len(t) > 24 else "")
        # 起了名字就升级为主题空间（列表里归入【主题空间】组）
        if s.get("title") and s.get("kind") != "space":
            s["kind"] = "space"

    # ---- 会话管理（供 /chat/sessions 等接口）----
    def list_sessions(self, tenant="", limit=100):
        out = []
        try:
            names = os.listdir(self._dir)
        except Exception:
            names = []
        for n in names:
            if not n.endswith(".json"):
                continue
            sid = n[:-5]
            s = self._sessions.get(sid) or self._load(sid)
            if not s:
                continue
            if tenant and (s.get("tenant") or "") != tenant:
                continue
            msgs = s.get("messages") or []
            out.append({
                "session_id": s.get("id"),
                "title": s.get("title") or "",
                "kind": s.get("kind") or "temp",
                "pinned": bool(s.get("pinned")),
                "topic": s.get("topic") or "",
                "audience": s.get("audience") or "",
                "written_count": len(s.get("written") or []),
                "angle_count": len(s.get("angles") or []),
                "msg_count": len(msgs),
                "updated_at": s.get("last") or s.get("created") or 0,
                "created_at": s.get("created") or 0,
                "preview": (msgs[-1].get("payload") or {}).get("message", "")
                           if msgs and isinstance(msgs[-1].get("payload"), dict) else "",
            })
        # 排序：置顶 → 主题空间 → 最近更新
        out.sort(key=lambda x: (not x["pinned"],
                                0 if (x["kind"] == "space" or x["title"]) else 1,
                                -(x["updated_at"] or 0)))
        return out[:limit]

    def create_session(self, tenant="", title=""):
        s = self._get(uuid.uuid4().hex)
        s["tenant"] = tenant or ""
        s["title"] = (title or "").strip()
        s["kind"] = "space" if s["title"] else "temp"
        s["created"] = time.time()
        s["last"] = time.time()
        self._save(s)
        return {"session_id": s["id"], "title": s["title"], "kind": s["kind"]}

    def update_session(self, sid, title=None, kind=None, pinned=None):
        s = self._get(sid)
        if title is not None:
            s["title"] = str(title).strip()
        if kind in ("temp", "space"):
            s["kind"] = kind
        # 命名后自动升级为主题空间；清空名字则退回临时会话
        if title is not None:
            s["kind"] = "space" if s["title"] else "temp"
        if pinned is not None:
            s["pinned"] = bool(pinned)
        s["last"] = time.time()
        self._save(s)
        return {"ok": True, "session_id": s["id"], "title": s["title"],
                "kind": s["kind"], "pinned": s["pinned"]}

    def delete_session(self, sid):
        with self._lock:
            self._sessions.pop(sid, None)
        try:
            os.remove(self._path(sid))
        except Exception:
            pass
        return {"ok": True, "session_id": sid}

    def get_messages(self, sid, limit=300):
        s = self._get(sid)
        return {
            "session_id": s["id"],
            "title": s.get("title") or "",
            "kind": s.get("kind") or "temp",
            "topic": s.get("topic") or "",
            "audience": s.get("audience") or "",
            "count": int(s.get("count") or 0),
            "written_count": len(s.get("written") or []),
            "messages": (s.get("messages") or [])[-limit:],
        }

    # ---- 意图理解（LLM 解析，输出严格 JSON）----
    def _understand(self, s, message):
        have = []
        if s.get("topic"): have.append("主题")
        if s.get("audience"): have.append("受众")
        if s.get("requirement"): have.append("关键要求")
        missing_hint = ("已具备：" + ("、".join(have) if have else "暂无") +
                        "。缺：" + ("、".join(x for x in ["主题", "受众", "关键要求"] if x not in have) or "无") +
                        "（数量可选，缺则默认 5）。")
        # 上下文：真实近期对话（用户原话，让 AI 记得这个空间里聊过什么）+ 阶段摘要
        _recent = []
        for m in (s.get("messages") or [])[-12:]:
            if m.get("role") == "user":
                _c = str((m.get("payload") or {}).get("content", "")).strip()
                if _c:
                    _recent.append(_c[:100])
        _summ = "；".join(s["history"][-6:])
        hist = (("近期真实对话：" + " | ".join(_recent[-6:]) + "。") if _recent else "") + \
               ("阶段摘要：" + _summ if _summ else "")
        if s.get("written"):
            hist += f"（本空间已累计成稿 {len(s['written'])} 篇）"
        prompt = (
            MASTER_PROMPT + "\n\n" +
            "你现在是'对话出稿工作台'的意图调度器。用户想让你帮他生成一批财税短视频口播稿。\n"
            "你的唯一任务：读懂用户本轮说的这句话，输出一个 JSON，判定接下来要做什么。\n\n"
            "四要素定义：\n"
            "- topic 主题：讲什么（如'创业开公司注意什么''金税四期下的风险'）。\n"
            "- audience 受众：给谁看（如'刚开公司的小老板'）。\n"
            "- requirement 关键要求：调性/禁忌/要不要带案例等（如'讲人话别堆术语''带个真实案例'）。\n"
            "- count 数量：要几条/几期（整数，不填则为 0 表示由 AI 视主题定，用默认 5）。\n\n"
            "当前会话：\n" + missing_hint + "\n"
            "对话历史：" + (hist or "（无）") + "\n"
            "用户本轮：" + message + "\n\n"
            "请判断并只输出 JSON（不要任何其它文字/代码块）：\n"
            "{\n"
            "  \"extract\": {可选, 从本轮提取/更新的要素, 只放确有信息且与原值不同的字段: "
            "{\"topic\":\"\",\"audience\":\"\",\"requirement\":\"\",\"count\":0}},\n"
            "  \"action\": \"ask\" | \"propose\" | \"write\",\n"
            "  \"asked\": \"(action=ask 时，追问还缺的要素的话术，简短一句，专业不啰嗦)\",\n"
            "  \"pick\": (action=write 时，用户想写的角度。填整数下标【从0开始】或 'all' 或 'next'。否则 null)\n"
            "}\n\n"
            "判定规则：\n"
            "- 若用户是在补充信息/回答追问（如'给会计看''要3条''面向餐饮老板'）→ 提取进 extract。\n"
            "- 若用户给出或已齐 主题+受众+关键要求，且这是一次明确的'开始出/想做/来一批'信号或要素已齐，且当前 phase=collect → action=propose。\n"
            "- 若用户对已出的角度方案表 确认（如'可以''就按这个''认可'）→ action=write, pick='all'。\n"
            "- 若用户挑了具体某条（如'写第2条''第3个''就做第一个'）→ action=write, pick=对应下标。\n"
            "- 若用户在成稿阶段还要继续写下一条（如'继续''下一条'）→ action=write, pick='next'。\n"
            "- 若用户对【已写好的某篇成稿】提出修改意见（如'第2篇太长/换个口吻/加个案例/重写第3篇'），→ action=write, pick=对应篇的下标（0开始），同时把修改要求写进 extract.requirement（追加），让改写器按新要求重写该篇。\n"
            "- 若要素还缺（主题或受众或要求为空）且用户只是闲聊/开场 → action=ask，并在 asked 追问缺的那一项。\n"
        )
        cfg = self._cfg()
        raw = self._chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=60)
        if isinstance(raw, dict):
            raw = raw.get("content") or "{}"
        text = (raw or "").strip()
        if text.startswith("```"):
            text = text.strip("`")
            if text[:4].lower() == "json":
                text = text[4:]
            text = text.strip()
        try:
            obj = json.loads(text)
        except Exception:
            # 兜底：尝试截取第一个 { ... }
            try:
                i, j = text.find("{"), text.rfind("}")
                obj = json.loads(text[i:j + 1])
            except Exception:
                obj = {}
        return obj

    # ---- 执行 ----
    def _do_propose(self, s):
        industry = s.get("audience") or s.get("topic") or "中小企业"
        keywords = "；".join(x for x in [s.get("topic"), s.get("requirement")] if x)
        count = int(s.get("count") or 5)
        # 讨论中拍板的结论注入：出角度必须遵循，避免"聊完又回模板"
        if s.get("decisions"):
            d_hint = "\n\n【本空间讨论中已明确的结论】（必须遵守，这是用户拍板过的）：\n" + "\n".join(
                "- " + d for d in s["decisions"][-10:])
            keywords = (keywords + d_hint) if keywords else d_hint.lstrip()
        # 检索证据注入：让角度显式对应证据里的子主题，避免"模板角度"
        if s.get("search_refs"):
            items = s["search_refs"][:6]
            ref_hint = ("\n\n【已联网检索到的参考素材】（角度必须显式对应其中子主题，不是泛泛而谈）：\n"
                        + "\n".join(f"- {i+1}. {(it.get('title') or it.get('url') or '')[:80]}\n  摘要：{(it.get('content') or '')[:200]}"
                                    for i, it in enumerate(items)))
            keywords = (keywords + ref_hint) if keywords else ref_hint.lstrip()
        topics = self._ai_topic(industry=industry, keywords=keywords, count=count)
        s["angles"] = topics
        s["chosen"] = []
        return {
            "stage": "propose",
            "angles": topics,
            "tip": "以上是参照你的四要素、本空间已有的检索证据和写稿规范拆出的角度方案。"
                   "你可以说'就按这个全写'，或指定写某条（如'写第2条'）。"
                   "想看证据对应关系或调整方向，直接说。",
            "next": [
                {"id": "write", "name": "全部写成稿", "icon": "✍️", "cmd": "全写"},
                {"id": "tweak", "name": "重新拆角度", "icon": "🎯", "cmd": "重新拆角度"},
            ],
        }

    def _do_write(self, s, pick):
        angles = s.get("angles") or []
        if not angles:
            # 无已拆角度，先拆
            return self._do_propose(s)
        if pick == "all":
            targets = list(range(len(angles)))
        elif pick == "next":
            done_idx = set()
            for w in s.get("written", []):
                if "angle_idx" in w:
                    done_idx.add(w["angle_idx"])
            targets = [i for i in range(len(angles)) if i not in done_idx]
            if not targets:
                return {"stage": "done", "summary": "该批角度已全部写完。可换主题再开一批，或继续让我针对某条补细节。"}
        elif isinstance(pick, int):
            targets = [pick]
        else:
            return {"stage": "ask", "message": "请说明要写哪条，或回'全写'。"}
        out = []
        for i in targets:
            if i < 0 or i >= len(angles):
                continue
            a = angles[i]
            title = a.get("title") or ""
            angle = a.get("angle") or ""
            hook = a.get("hook") or ""
            # 喂给 ai_rewrite 的原稿：标题+切入角度+钩子，让它扩写成完整口播稿
            source = title
            if angle:
                source += "\n【切入角度】" + angle
            if hook:
                source += "\n【结尾留资钩子方向】" + hook
            res = self._ai_rewrite(
                source, "script",
                focus=s.get("requirement") or None,
                industry=(s.get("audience") or s.get("topic") or None),
            )
            rewritten = ""
            if isinstance(res, dict):
                rewritten = res.get("rewritten") or res.get("ok") and (res.get("text") or "") or res.get("script") or ""
            if not rewritten:
                rewritten = res.get("error") or "（改写失败）"
            entry = {"angle_idx": i, "title": title, "angle": angle, "script": rewritten}
            out.append(entry)
            s.setdefault("written", []).append(entry)
        s["history"].append("成稿")
        return {
            "stage": "written",
            "results": out,
            "next": [
                {"id": "video_render", "name": "做成片", "icon": "🎬", "cmd": "做成片"},
                {"id": "qc", "name": "文案质检", "icon": "🛡️", "cmd": "质检"},
                {"id": "publish_pack", "name": "打发布包", "icon": "📦", "cmd": "打成发布包"},
            ],
        }

    def _do_revise(self, s, u, pick):
        """用户对已写成的某篇提出修改意见 → 按新要求重写该篇（覆盖原稿，不新增）。"""
        written = s.get("written") or []
        if not written:
            return self._do_propose(s)
        try:
            idx = int(pick[3:])  # "rev0" -> 0
        except Exception:
            idx = -1
        if idx < 0 or idx >= len(written):
            return {"stage": "ask", "message": "没找到对应篇目。可回'重写第N篇'+你的修改要求，或'全写'继续下一批。"}
        target = written[idx]
        req_extra = ""
        ex = u.get("extract") or {}
        rv = ex.get("requirement") if isinstance(ex, dict) else None
        if rv and str(rv).strip():
            req_extra = str(rv).strip()
        # 追加修改要求进会话 requirement（保留原口径，只是补充）
        old_req = s.get("requirement") or ""
        if req_extra and req_extra not in old_req:
            s["requirement"] = (old_req + "；" + req_extra).strip("；")
        source = target.get("title") or ""
        if target.get("angle"):
            source += "\n【切入角度】" + target.get("angle")
        source += "\n【用户本次修改要求】" + (req_extra or "请按用户本轮表述重写")
        res = self._ai_rewrite(
            source, "script",
            focus=s.get("requirement") or None,
            industry=(s.get("audience") or s.get("topic") or None),
        )
        rewritten = ""
        if isinstance(res, dict):
            rewritten = res.get("rewritten") or res.get("ok") and (res.get("text") or "") or res.get("script") or ""
        if not rewritten:
            rewritten = res.get("error") or "（改写失败）"
        target["script"] = rewritten            # 原地覆盖
        target["revised"] = True
        s["history"].append("改写第" + str(idx + 1) + "篇")
        return {"stage": "written", "results": [target], "revised": True,
                "tip": "已按你的要求重写该篇。还要调整就说'改第N篇+要求'，或继续下一批。"}

    # ---- 联网检索问答（Tavily 真检索 + DeepSeek 基于结果正面回答）----
    # 说明：用户问"有没有/搜一下/参考XX"这类事实时，不再硬拽去出稿，
    # 而是先全网真实检索，再基于检索结果正面回答（可溯源、不编造）。
    _SEARCH_TRIGGERS = (
        "搜索", "搜一下", "搜搜", "搜一搜", "全网", "网上搜", "查一下", "检索",
        "找找", "参考一下", "参考下", "有没有人做", "有没有做", "有没有关于",
        # 元问题触发词：用户问"有没有/是不是/是否"时进入协作审查模式
        "有没有", "是不是", "是否",
        # 协作/接入类意图（用户问"怎么接入""怎么结合"也是元问题）
        "接入", "结合", "用了", "参考",
    )

    # 元问题关键词：与检索触发词共现，判定"协作审查"而非新检索
    _META_WORDS = ("结合", "参考", "用了", "接入", "对应", "考虑了", "按这个", "这个", "用了没")

    def _detect_search(self, s, message):
        """识别联网检索意图，返回要去搜的 query；元审查返回 ("__META__", msg)；不是检索意图则返回 None。"""
        msg = (message or "").strip()
        if not msg or not self._search:
            return None
        if not any(w in msg for w in self._SEARCH_TRIGGERS):
            return None
        # 元问题：本空间已有证据/角度/成稿，用户问"有没有结合XX" → 协作审查而非新检索
        if any(w in msg for w in self._META_WORDS) and \
           (s.get("search_refs") or s.get("angles") or s.get("written")):
            return ("__META__", msg)
        q = msg
        for w in ("帮我", "请", "能不能", "可以", "我想", "我要", "一下", "看看", "去"):
            q = q.replace(w, "")
        q = q.strip(" ，。？！、:：\"'")
        return q or msg

    def _do_search(self, s, query):
        """真实联网检索 + 基于检索结果正面回答。"""
        key = self._get_key("TAVILY_API_KEY")
        if not key:
            return {"stage": "search", "query": query, "sources": [],
                    "message": "未配置 TAVILY_API_KEY，暂时没法联网检索。你先告诉我方向，我按已有知识帮你拆角度。"}
        try:
            r = self._search(query, key, topic="general", days=365, max_results=6, timeout=15)
        except Exception as e:  # noqa: BLE001
            return {"stage": "search", "query": query, "sources": [],
                    "message": "联网检索失败（%s）。可能是网络或 key 额度问题，稍后再试；急的话你先给方向，我按已有知识先出。" % e}
        items = (r or {}).get("results") or []
        if not items:
            return {"stage": "search", "query": query, "sources": [],
                    "message": "全网没检索到「%s」的相关内容。换个人名/账号名或说个方向，我再帮你找。" % query}
        # 检索证据存进会话，后续出稿可参考
        s.setdefault("search_refs", []).extend(items[:6])
        ev = "\n".join(
            "- %s | %s\n  %s" % (i.get("title") or "", i.get("url") or "", (i.get("content") or "")[:300])
            for i in items[:6]
        )
        cfg = self._cfg()
        prompt = (
            MASTER_PROMPT
            + "\n\n用户的问题：" + query
            + "\n\n以下是全网真实检索到的结果（不要怀疑其真实性，也不要编造未出现的内容）：\n" + ev
            + "\n\n请基于以上真实检索结果，正面回答用户的问题。要求：\n"
            "1. 直接说检索到了什么（是谁/什么内容/什么平台/体量多大等事实）；\n"
            "2. 明确列出来源；\n"
            "3. 简要说这些内容对老张做注册公司/财税引流选题的参考价值；\n"
            "4. 检索结果里没有的东西不要编，直接说没查到。\n"
            "只输出回答正文，不要代码块、不要 JSON。"
        )
        try:
            ans = self._chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=60)
            if isinstance(ans, dict):
                ans = ans.get("content") or ""
        except Exception:  # noqa: BLE001
            ans = ""
        sources = [{"title": i.get("title") or "", "url": i.get("url") or ""}
                   for i in items[:6] if i.get("url")]
        return {
            "stage": "search", "query": query, "sources": sources,
            "message": ans or "已检索到相关内容，来源见下方链接。",
        }

    def _do_meta_review(self, s, msg):
        """协作审查：用户问'有没有结合XX/参考了XX'时，基于本空间已有证据+角度/成稿，正面回答并给推理链。

        关键区别于 _do_search：
        - 不再调 Tavily（证据已在 session.search_refs 里）
        - 不输出角度方案，而是输出"事实承认+对照表+差异化推理+协作选项+邀请决策"
        - 让用户看到 AI 的思考过程，并能参与决策——而不是被甩模板角度
        """
        refs = s.get("search_refs") or []
        angles = s.get("angles") or []
        written = s.get("written") or []

        parts = [MASTER_PROMPT]
        if refs:
            rlines = []
            for i, it in enumerate(refs[:10], 1):
                t = (it.get("title") or it.get("url") or "未命名")[:80]
                c = (it.get("content") or "")[:200]
                rlines.append(f"[{i}] {t}\n    摘要：{c}")
            parts.append("本空间已联网检索到的参考素材（这是讨论的事实基础）：\n" + "\n".join(rlines))
        else:
            parts.append("（本空间暂无联网检索证据——用户问的'有没有结合XX'要先承认这点）")
        if angles:
            alines = []
            for i, a in enumerate(angles, 1):
                alines.append(f"#{i} {a.get('title', '角度')}（切入：{(a.get('angle') or '')[:120]}）")
            parts.append("\n本空间已拆出的角度方案：\n" + "\n".join(alines))
        if written:
            wlines = []
            for i, w in enumerate(written, 1):
                scr = (w.get("script") or "")[:80]
                wlines.append(f"#{i} {w.get('title', '稿件')}（首段：{scr}…）")
            parts.append("\n本空间已写成的稿件：\n" + "\n".join(wlines))

        parts.append(
            "\n用户提问：" + msg + "\n\n"
            "你的任务：作为老张的财税策划搭档，做一次【协作审查】，不是出稿，不是给模板角度。\n"
            "请按以下结构正面回答（用 markdown 列表/小标题，让用户清楚看到你的推理过程）：\n\n"
            "1. **事实承认**（最重要）：\n"
            "   - 直接说本空间现有方案是否已结合了检索证据。\n"
            "   - 结合了哪些子主题（对应证据里哪几条）；没结合哪些（哪些证据里的内容被忽略）。\n"
            "   - 不要遮掩、不要堆话术；用户要的是事实。\n\n"
            "2. **对照表**：用 markdown 表格列出\n"
            "   - 左列：参考源（如小辉/某权威）的子主题；\n"
            "   - 中列：本空间角度/稿件是否覆盖；\n"
            "   - 右列：差异化空间（老张比参考源能多讲什么，如合规底线/稽查后果/历史补救/金额测算）。\n\n"
            "3. **差异化推理**：老张与参考源的护城河差异（合规底线/稽查后果/历史补救/金额测算 4 类）。\n\n"
            "4. **协作选项**：给用户 A/B/C/D 几种走法（如跟随不复制/跳出做护城河/双线并行/先打样 1 集），每条用一句话说适合的场景与代价。\n\n"
            "5. **邀请决策**：给用户 A/B/C/D 几种走法（如跟随不复制/跳出做护城河/双线并行/先打样 1 集），每条用一句话说适合的场景与代价。\n\n"
            "6. **【必须】反问用户**（这是最重要的一环）：\n"
            "   - 在正文全部写完后，另起一行输出分隔符 `<<<ASK>>>`，然后列出 **1-2 个反问**。\n"
            "   - 反问必须是你在上面的分析里**真正没搞清楚、且会改变结论**的问题，例如信息缺口、\n"
            "     方向分歧、产能约束、受众取舍。禁止客套话（'你觉得呢''还有什么想聊的吗'一律不要）。\n"
            "   - 每个反问要给用户可选项，让他一句话就能答，例如：\n"
            "     '你的主力客户是已开业1-3年的老板，还是准备注册的准老板？哪种多？'\n"
            "     'A 线要我先出注册环节还是先出公转私？（你手上哪类咨询最多，就先打哪类）'\n"
            "   - 一次最多 2 个。如果确实没有需要问的，就输出 `<<<ASK>>>` 后什么都不写。\n\n"
            "注意：\n"
            "- 这是策划讨论不是写稿，不要套'开口第一句'模板。\n"
            "- 不要把上面 1-6 当成题目复述给用户，自然写出来即可。\n"
            "- 字数 600-1200 字，太短说不清推理过程，太长用户读不动。\n"
            "- `<<<ASK>>>` 之后只写问题本身，一行一个，不要编号、不要加粗、不要解释。"
        )

        prompt = "\n\n".join(parts)
        try:
            cfg = self._cfg()
            ans = self._chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=90)
            if isinstance(ans, dict):
                ans = ans.get("content") or ""
        except Exception as e:  # noqa: BLE001
            return {"stage": "ask", "message": "协作审查生成失败（%s）。要重试吗？" % e}
        body, ask_back = self._split_ask(ans)
        s["pending_question"] = ask_back          # 记下反问，用户答复时走接续分支
        s["last_review"] = (body or "")[:1200]
        return {
            "stage": "review",
            "message": body or "暂时没生成出审查结论，要不要我换个方式重新看看？",
            "ask_back": ask_back,
            "ref_count": len(refs),
            "angle_count": len(angles),
            "written_count": len(written),
            "tip": ("上面我留了 %d 个问题，你答完我接着往下推；也可直接说走 A/B/C/D。" % len(ask_back))
                   if ask_back else "这是基于本空间已有证据+产出的协作审查。直接说走 A/B/C/D 或补充要求。",
        }

    # ---- 反问：生成、解析、答复接续 ----
    _ASK_SPLIT = "<<<ASK>>>"

    _PRODUCE_WORDS = (
        "写第", "全写", "都写", "出稿", "成稿", "开始写", "就写", "写吧", "出个稿",
        "改第", "重写第", "改写第", "出角度", "拆角度", "来几个角度", "出几个角度",
        "认可", "就按这个", "按这个", "确认", "同意", "就这个", "按这个全写",
    )

    def _is_produce_cmd(self, msg):
        """判断这条消息是不是"下指令干活"（出稿/拆角度/确认），是的话不走反问接续。"""
        m = (msg or "").strip()
        if not m:
            return False
        return any(w in m for w in self._PRODUCE_WORDS)

    def _split_ask(self, text):
        """把 LLM 输出拆成 (正文, 反问列表)。反问最多 2 条，过滤客套话。"""
        text = text or ""
        if self._ASK_SPLIT not in text:
            return text.strip(), []
        body, _, tail = text.partition(self._ASK_SPLIT)
        asks = []
        for line in tail.splitlines():
            t = line.strip(" \t-*·•0123456789.、)）(（")
            t = t.strip()
            if len(t) < 6:                       # 太短多半是碎屑/客套
                continue
            if any(w in t for w in ("你觉得呢", "有什么问题", "还有什么", "随时告诉我", "期待你的")):
                continue
            asks.append(t)
            if len(asks) >= 2:
                break
        return body.strip(), asks

    def _do_followup(self, s, msg):
        """用户在回答 AI 的反问：正面接住答复 → 基于答复推进分析 → 视情况再抛一个反问。

        区别于 _do_meta_review：这里以"用户刚说的话"为中心，先判断他的答复改变了什么，
        再给推进结论；如果结论已经清楚了，就给明确的下一步动作，不再反问（避免没完没了）。
        """
        pending = s.get("pending_question") or []
        refs = s.get("search_refs") or []
        angles = s.get("angles") or []
        written = s.get("written") or []
        decisions = s.get("decisions") or []

        parts = [MASTER_PROMPT]
        if refs:
            parts.append("本空间已联网检索到的参考素材（事实基础）：\n" + "\n".join(
                "[%d] %s\n    摘要：%s" % (i, (it.get("title") or it.get("url") or "未命名")[:80],
                                       (it.get("content") or "")[:160])
                for i, it in enumerate(refs[:8], 1)))
        if angles:
            parts.append("\n本空间已拆出的角度方案：\n" + "\n".join(
                "#%d %s" % (i, a.get("title", "角度")) for i, a in enumerate(angles, 1)))
        if written:
            parts.append("\n本空间已写成的稿件：\n" + "\n".join(
                "#%d %s" % (i, w.get("title", "稿件")) for i, w in enumerate(written, 1)))
        if decisions:
            parts.append("\n此前讨论中已明确的点：\n" + "\n".join("- " + d for d in decisions[-8:]))
        if s.get("last_review"):
            parts.append("\n我上一轮给出的分析要点：\n" + s["last_review"][:600])

        q_block = ("\n".join("Q%d. %s" % (i, q) for i, q in enumerate(pending, 1))) if pending \
            else "（本轮没有待答问题，用户是在补充信息或提出新想法）"

        parts.append(
            "\n我（AI）上一轮向用户提了这些问题：\n" + q_block + "\n\n"
            "用户这一轮的回答/补充是：\n「" + msg + "」\n\n"
            "你的任务：作为老张的财税策划搭档，接住他的回答，往下推进。\n"
            "按下面的顺序写（不要照抄小标题，自然写出即可）：\n\n"
            "1. **先接住**（1-3 句）：他这个回答意味着什么——是印证了你的判断、修正了你的假设，\n"
            "   还是暴露了新的信息缺口。如果他的选择和你想的不一样，明说你的不同意见和理由（要有观点，别附和）。\n"
            "2. **推进结论**（主体）：基于他的回答，方案该怎么调整——\n"
            "   具体说清楚调整了什么（如节奏、顺序、切入点、产品线对应），不要停留在'好的我记下了'。\n"
            "3. **下一步动作**：给一个明确的、他现在就能做的动作（如'那我先出注册环节前 3 集角度'）。\n\n"
            "4. **【视情况】反问**：如果还有会改变结论的未决问题，在正文后另起一行输出 `<<<ASK>>>`，\n"
            "   再列 **1 个**反问（最多 1 个，宁缺毋滥，禁止客套）。如果结论已经够清楚、可以直接干活了，\n"
            "   **就不要反问**，直接把下一步动作说死。\n\n"
            "注意：\n"
            "- 讲人话，不要打官腔，不要用'首先其次最后'这种八股连接词。\n"
            "- 有分歧就摆出来，老张要的是能吵架的搭档，不是应声虫。\n"
            "- 字数 300-700 字，别写长。\n"
            "- `<<<ASK>>>` 之后只写问题本身，不要编号、不要解释。"
        )
        prompt = "\n\n".join(parts)
        try:
            cfg = self._cfg()
            ans = self._chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=90)
            if isinstance(ans, dict):
                ans = ans.get("content") or ""
        except Exception as e:  # noqa: BLE001
            return {"stage": "ask", "message": "我这边生成时出了点问题（%s）。你再说一次？" % e}

        body, ask_back = self._split_ask(ans)
        # 用户的答复沉淀为"已明确的点"，后续拆角度/出稿会带上
        decisions.append(str(msg).strip()[:200])
        s["decisions"] = decisions[-20:]
        s["pending_question"] = ask_back
        s["last_review"] = (body or "")[:1200]
        return {
            "stage": "review",
            "message": body or "明白了，我记下了。要我现在就按这个方向出角度方案吗？",
            "ask_back": ask_back,
            "answered": True,
            "ref_count": len(refs),
            "angle_count": len(angles),
            "written_count": len(written),
            "tip": "讨论中定下的点我已经记进这个空间，后面出角度和成稿都会照着走。",
        }

    # ---- 对外入口：一次对话回合 ----
    def step(self, sid, message, tenant="", action=None):
        s = self._get(sid)
        if tenant and not s.get("tenant"):
            s["tenant"] = tenant
        self._touch(s)
        # 能力执行结果回灌（前端执行完 /studio/chat/action 后把结果送回来，让 AI 总结+引导）
        if isinstance(action, dict) and action.get("cap"):
            result = self._do_action_done(
                s, action.get("cap"),
                bool(action.get("ok")),
                action.get("data") or {},
            ) or {"stage": "ask", "message": "动作已完成。"}
            result["session_id"] = s["id"]
            self._record(s, "ai", result)
            self._save(s)
            return result
        # 用户原话入完整消息流（切回该空间时可原样回放）
        self._record(s, "user", {"content": message})
        result = self._step_core(s, message)
        result["session_id"] = s["id"]
        result["topic"] = s.get("topic") or ""
        result["audience"] = s.get("audience") or ""
        result["count"] = int(s.get("count") or 0)
        # AI 回复入完整消息流（原样存 result，前端可直接用同一渲染器回放）
        self._record(s, "ai", {k: v for k, v in result.items() if k != "session_id"})
        self._auto_title(s)          # 主题定下来后自动起名，避免列表里一片"未命名"
        result["title"] = s.get("title") or ""
        result["kind"] = s.get("kind") or "temp"
        self._save(s)                # 落盘：重启/超时都不丢
        return result

    # ---- 生产链动作引导（配音/出片/质检/发布 的"对话到确认点"衔接）----
    # 说明：出稿后用户说"配音/做成片/去发布"，编排器不越权自动执行长任务，
    # 而是给出可点的动作清单（stage=pipeline, actions[]），由前端渲染按钮引导到既有生产页。
    _ACTION_KEYWORDS = {
        "voice":  ["配音", "语音", "合成声音", "出音频", "生成声音", "去配音"],
        "render": ["出片", "做成片", "生成视频", "做视频", "渲染", "数字人", "出视频", "去出片"],
        "qc":     ["质检", "检查稿子", "查违禁词", "审稿", "看看有没有问题", "违规", "风险词"],
        "publish":["发布", "去发布", "发视频", "分发", "发到抖音"],
    }

    def _detect_pipeline(self, s, message):
        msg = (message or "").strip()
        if not msg:
            return None
        hits = {}
        for kind, words in self._ACTION_KEYWORDS.items():
            if any(w in msg for w in words):
                hits[kind] = True
        if not hits:
            return None
        written = s.get("written") or []
        angles = s.get("angles") or []
        if not written and not angles:
            return {
                "stage": "ask",
                "message": "还没有成稿可以进入下一步。先把主题、受众跟我说，出完稿我再帮你接配音/出片。",
            }
        actions = []
        # 配音/出片：只要有稿就能去 scroll 出片页（把稿带去）；会话稿存内存，跨页需经 session_id
        if hits.get("voice") or hits.get("render"):
            actions.append({
                "type": "goto", "label": "去出片/配音（图解短视频页）", "url": "/studio/scroll",
                "hint": "把当前这批发到「滚动视频出片」继续配音与合成",
            })
        if hits.get("qc"):
            actions.append({
                "type": "qc", "label": "对已写稿做质检（违禁词/风险自查）", "scope": "written",
                "hint": "用平台质检规则检查本批成稿",
            })
        if hits.get("publish"):
            actions.append({
                "type": "goto", "label": "去发布台（打包分发素材）", "url": "/studio/publish",
                "hint": "在发布页生成发布包/分发到各平台",
            })
        if not actions:
            return {
                "stage": "ask",
                "message": "你想把这批稿接着做视频对吗？我已记下你的方向。你可以说：配音、做成片、质检，或先去发布。",
            }
        summary = ""
        if written:
            summary = f"本批已成稿 {len(written)} 篇，可直接进入下一步。"
        elif angles:
            summary = f"已拆出 {len(angles)} 个角度方案，可先写成稿再进下一步。"
        return {
            "stage": "pipeline",
            "message": f"收到，当前会话：{s.get('topic') or '未定主题'}。{summary}下面是可继续的动作，你点一下或直接说：",
            "actions": actions,
            "has_written": bool(written),
            "has_angles": bool(angles),
        }

    # =====================================================================
    # 能力调度（对话驱动一切）
    #   stage:
    #     action_ask    还缺参数 → 前端渲染收集卡片
    #     action_ready  参数齐 → 前端调 /studio/chat/action 执行
    #     action_done   执行完回灌 → AI 总结 + 纠偏 + 下一步引导
    # =====================================================================

    # 关键词快匹配（命中即走能力，不劳 LLM，稳定且省 token）
    _CAP_KEYWORDS = {
        "video_render": ("出片", "生成视频", "做成视频", "做视频", "出视频", "渲染",
                         "拍成视频", "生成成片", "视频生成", "做个视频", "出成片",
                         "成片", "出成品", "做个视频"),
        "topic": ("选题", "给我选题", "出选题", "选几个题", "想几个选题", "找选题"),
        "hotspot": ("热点选题", "追热点", "热点话题", "最近热点"),
        "rewrite": ("二创", "改写", "改成我的", "爆改", "重写成", "改成"),
        "qc": ("质检", "违禁词", "检查违禁", "审一下", "能不能发", "查敏感"),
        "qc_video": ("成片质检", "视频质检", "检查成片"),
        "publish_pack": ("发布包", "素材包", "打包发布", "发布素材"),
        "xhs": ("小红书", "图文笔记", "小红书图文"),
        "dissect": ("拆解", "爆款拆解", "拆一下"),
        "footage_edit": ("素材剪辑", "剪辑素材"),
        "clone_voice": ("声音克隆", "克隆音色", "克隆我的声音"),
    }

    # 出稿链路专属词：命中说明用户是在聊"写稿"，不要误触发能力
    _CAP_EXCLUDE = ("角度", "口播稿", "成稿", "写稿", "出稿", "逐字稿", "标题怎么")

    def _match_capability(self, message):
        """关键词快匹配能力 id，命中不了返回 None（交给 LLM 判定）。"""
        if not _CAP or not message:
            return None
        m = str(message).strip()
        low = m.lower()
        # 「出片」等词若与出稿词共现（如"出稿后出片"），优先算能力；只在纯出稿语境排除
        if any(w in m for w in self._CAP_EXCLUDE) and not any(
                w in low for w in ("出片", "生成视频", "做成视频", "质检", "打包", "小红书")):
            return None
        for cid, words in self._CAP_KEYWORDS.items():
            for w in words:
                if w in low:
                    return cid
        return None

    def _ctx(self, s):
        """把会话上下文整理成能力参数可用的来源字典。"""
        last_text = ""
        for item in reversed(s.get("written") or []):
            t = (item.get("script") or item.get("text") or "")
            if t:
                last_text = t
                break
        if not last_text:
            for h in reversed(s.get("history") or []):
                if h.startswith("AI:") and len(h) > 120:
                    last_text = h[3:].strip()
                    break
        return {
            "topic": s.get("topic") or "",
            "audience": s.get("audience") or "",
            "requirement": s.get("requirement") or "",
            "count": str(s.get("count") or "") or "",
            "last_text": last_text,
            "last_job_id": s.get("last_job_id") or "",
        }

    def _do_capability(self, s, message, cap_id=None):
        """进入/推进一个能力：收参数 → 参数齐就交给前端执行。"""
        if not _CAP:
            return None
        pc = s.get("pending_cap") or {}
        cid = cap_id or pc.get("id")
        if not cid:
            return None
        cap = _CAP.get(cid)
        if not cap:
            return None

        vals = dict(pc.get("vals") or {})
        # 先用会话上下文预填（能自动带出来的不问）
        vals = dict(_CAP.fill_from_session(cid, self._ctx(s)) | vals)

        # 把用户这句里「触发词之外的内容」当作参数值（比如"出片 公转私那条"）
        if message:
            rest = str(message)
            for w in self._CAP_KEYWORDS.get(cid, ()):
                rest = rest.replace(w, "")
            rest = rest.strip(" ，。！？、:：\"'")
            if len(rest) >= 6:
                miss = _CAP.missing_params(cid, vals)
                for p in miss:
                    if p.get("type") in ("textarea", "text"):
                        vals[p["key"]] = rest
                        break

        miss = _CAP.missing_params(cid, vals)
        s["pending_cap"] = {"id": cid, "vals": vals}

        cap_info = {"id": cid, "name": cap["name"], "icon": cap.get("icon") or "▶️",
                    "desc": cap["desc"], "long": bool(cap.get("long"))}

        if miss:
            p = miss[0]
            total = len([x for x in cap["params"] if x.get("required")])
            done = total - len(miss)
            return {
                "stage": "action_ask",
                "cap": cap_info,
                "param": {
                    "key": p["key"], "label": p["label"], "type": p.get("type") or "text",
                    "options": p.get("options") or [], "hint": p.get("hint") or "",
                    "default": p.get("default") or "",
                },
                "vals": vals,
                "progress": "%d/%d" % (done, total),
                "message": "好，我来帮你**%s**。（%s）" % (cap["name"], cap["desc"]),
                "tip": "直接在下面填，或点下面的选项：",
            }

        # 参数齐 → 可执行
        s["pending_cap"] = None
        return {
            "stage": "action_ready",
            "cap": cap_info,
            "vals": vals,
            "message": "参数齐了，点「开始执行」我就去跑**%s**。" % cap["name"],
            "next": _CAP.next_suggestions(cid),
            "tip": "跑完我会告诉你结果，并提示下一步能做什么。",
        }

    def _do_action_done(self, s, cap_id, ok, data):
        """能力执行完回灌：AI 总结 + 纠偏提醒 + 下一步引导。"""
        if not _CAP:
            return None
        cap = _CAP.get(cap_id) or {}
        nxt = _CAP.next_suggestions(cap_id)
        brief = json.dumps(data or {}, ensure_ascii=False)[:1200]
        prompt = (
            MASTER_PROMPT + "\n\n"
            "用户刚在对话里执行了一个平台动作，现在你要用大白话跟他汇报。\n"
            "动作：%s（%s）\n执行结果：%s\n是否成功：%s\n\n"
            "请输出：\n"
            "1. 一句话说清结果（成了还是没成，给了什么东西）。\n"
            "2. 如果有需要他注意/可能要返工的点，直说（纠偏），别粉饰。\n"
            "3. 下一步建议：从下面这些里面挑最该做的，说明为什么现在做：\n%s\n\n"
            "要求：口语化、简短（200字内）、不客套、不重复结果里的原始数据。"
            % (cap.get("name") or cap_id, cap.get("desc") or "", brief,
               "成功" if ok else "失败",
               "\n".join("- %s %s：%s" % (n["icon"], n["name"], n["desc"]) for n in nxt) or "（无）")
        )
        try:
            ans = self._chat(prompt, self._cfg()[0], self._cfg()[1]) if callable(self._cfg) else None
        except Exception:  # noqa: BLE001
            ans = None
        return {
            "stage": "action_done",
            "cap": {"id": cap_id, "name": cap.get("name") or cap_id, "icon": cap.get("icon") or "▶️"},
            "ok": bool(ok),
            "data": data if isinstance(data, dict) else {},
            "message": ans or ("%s已完成。" % (cap.get("name") or cap_id)),
            "next": nxt,
            "tip": "点下面的卡片继续，或说你要做什么。",
        }

    def _step_core(self, s, message):
        s["history"].append(f"用户: {message}")
        if len(s["history"]) > 12:
            s["history"] = s["history"][-12:]

        # —— 能力调度（对话驱动一切）——
        # 1) 正在收集某个能力的参数：这句就是答案（除非他改主意要出稿/取消）
        pc = s.get("pending_cap") or {}
        if pc.get("id"):
            _m = str(message or "").strip()
            if _m in ("取消", "算了", "不用了", "退出", "不做了"):
                s["pending_cap"] = None
            elif not self._is_produce_cmd(_m) or len(_m) >= 6:
                r = self._do_capability(s, message, pc["id"])
                if r:
                    return r
        # 2) 这句话命中某个平台能力（出片/选题/质检/发布包…）→ 进入能力流程
        if not pc.get("id"):
            cid = self._match_capability(message)
            if cid:
                r = self._do_capability(s, message, cid)
                if r:
                    return r

        # 生产链动作意图（不依赖 LLM，关键词命中即响应）：对话出稿 → 引导走 配音/出片/质检/发布
        action_res = self._detect_pipeline(s, message)
        if action_res:
            return action_res
        # 联网检索意图：优先于出稿，正面回答"有没有/搜一下/参考XX"这类事实问题
        sq = self._detect_search(s, message)
        if sq:
            if isinstance(sq, tuple) and sq[0] == "__META__":
                return self._do_meta_review(s, sq[1])   # 协作审查：基于已有证据推理
            return self._do_search(s, sq)
        # 用户在回答我上一轮的反问 → 接续讨论（除非这条是在下出稿/拆解指令）
        if s.get("pending_question") and not self._is_produce_cmd(message):
            return self._do_followup(s, message)
        u = self._understand(s, message)
        ex = u.get("extract") or {}
        if isinstance(ex, dict):
            for k in ("topic", "audience", "requirement"):
                v = ex.get(k)
                if v and str(v).strip():
                    s[k] = str(v).strip()
            c = ex.get("count")
            if c is not None:
                try:
                    s["count"] = max(MIN_COUNT, min(MAX_COUNT, int(c)))
                except Exception:
                    pass
        action = u.get("action") or "ask"

        # 规则兜底：已出角度方案后，短确认词直接触发写稿，
        # 避免 LLM 把"认可/可以/就按这个"误判为"重新拆角度"(propose)，导致答非所问。
        if s.get("angles") and action != "write":
            _m = message.strip()
            _confirm = ("认可", "可以", "行", "好的", "没问题", "就按这个", "就这个",
                        "按这个", "确认", "同意", "可以了", "全写", "都写", "开始写",
                        "写吧", "出稿", "开始出稿", "对", "是的", "就写", "写全部")
            if _m in _confirm or any(k in _m for k in ("就这", "按这", "全写", "都写", "开始写", "出稿")):
                action = "write"
                u["pick"] = "all"

        # 阶段推进（优先尊重 LLM 判定的 action；只对"缺要素追问/待开始"做规则兜底）
        if action == "write":
            # 要素是否齐？若主题或受众仍缺，先补问而不是硬写
            if not (s.get("topic") and s.get("audience")):
                miss = "主题" if not s.get("topic") else "受众"
                return {"stage": "ask", "message": f"还差{miss}没定，我先跟你确认一下再开始写。"}
            pick = u.get("pick")
            # 用户对"已写成稿"提出修改（pick=revN）→ 重写 written[N]，不新增篇
            if isinstance(pick, str) and pick.startswith("rev"):
                return self._do_revise(s, u, pick)
            return self._do_write(s, pick)

        if action == "propose":
            # 要素齐才拆角度；否则先补齐要素
            missing = [lab for lab, k in (("主题", "topic"), ("受众", "audience")) if not s.get(k)]
            if missing:
                return {"stage": "ask", "message": f"先把{('、').join(missing)}定下来，我再帮你拆角度。"}
            return self._do_propose(s)

        # action == ask 或未识别：缺要素则追问，已齐则提示可开始
        missing = [lab for lab, k in (("主题", "topic"), ("受众", "audience"), ("关键要求", "requirement")) if not s.get(k)]
        if missing:
            asked = u.get("asked") or ""
            msg = asked or ("我们先对齐一下：还缺 " + "、".join(missing) + "，你补一下？")
            return {"stage": "ask", "message": msg, "missing": missing}
        return {
            "stage": "ask",
            "message": "四要素我已记下（主题、受众、关键要求都齐了）。跟我说：开始出稿，或做一批，我就按写稿规范帮你拆角度方案。",
        }

    def reset(self, sid):
        with self._lock:
            if sid:
                self._sessions.pop(sid, None)
        return {"stage": "reset"}
