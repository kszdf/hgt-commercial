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
import re
import time
import uuid
import threading

try:
    import capabilities as _CAP
except Exception:  # noqa: BLE001
    _CAP = None

# 人设与品牌统一从 brand_defaults 读取（对外给同行使用时改配置即可，无需改代码）
try:
    from brand_defaults import EXPERT_NAME, EXPERT_YEARS
except Exception:  # noqa: BLE001
    EXPERT_NAME, EXPERT_YEARS = "本机构顾问", ""

# 主提示词（写稿规范的轻量化固化，注入到意图理解里让 AI 保持一致口径）
MASTER_PROMPT = (
    "你是一名财税与股权专家助理，服务「%s」——"
    "具备税务稽查与历史遗留问题处理背景的专家。你在帮其运营面向中小企业老板的财税短视频。\n"
    % (EXPERT_NAME,)
    + "写稿铁律（成稿时交给改写器遵守，你在意图判断时也要据此理解用户意图）：\n"
    "1. 面向受众：初次创业及已有规模的中小老板（决策者，不是会计）。\n"
    "2. 口吻：冷静、专业、权威、讲人话；禁情感语气词与网络流行语（'打肿脸充胖子''真金白银'等）。\n"
    "3. ★悬念必须落在口播正文的开口第一句，不放标题；标题只作提示语。\n"
    "4. 不教逃税、不提供规避监管的'技巧'；数据/法条必须可溯源、不编造。\n"
    "5. 收尾给合规建议或专业咨询引导，不硬塞营销话术。\n"
    "6. ★严禁自我标榜资历：正文不得出现「深耕财税X年」「从业X年」「做了X年财税」等年限表述，"
    "也不得用「资深」「老法师」抬自己；客户想了解资历看账号简介即可，正文只讲事。"
)

# 常量：可接受的数量范围
MIN_COUNT, MAX_COUNT = 1, 10


class ChatOrchestrator:
    def __init__(self, ai_topic_fn, ai_rewrite_fn, deepseek_chat_fn, text_cfg_fn,
                 search_fn=None, get_key_fn=None, planning_cfg_fn=None):
        self._ai_topic = ai_topic_fn
        self._ai_rewrite = ai_rewrite_fn
        self._chat = deepseek_chat_fn
        self._cfg = text_cfg_fn
        self._plan_cfg = planning_cfg_fn or text_cfg_fn   # 规划层模型配置（可 env 覆盖）
        self._plan_model = ""            # 由 server 注入 env PLANNING_MODEL；空=默认 flash
        self._search = search_fn            # 联网检索（tavily_search），未注入则检索能力关闭
        self._get_key = get_key_fn or (lambda n: None)
        self._sessions = {}
        self._lock = threading.Lock()
        # 异步长任务进度：sid -> {"phase","msg","start"}；由 server 后台线程 + 前台 /chat/status 读写
        self._prog = {}
        self._prog_lock = threading.Lock()
        # 会话落盘目录：服务重启后空间与历史对话仍在
        self._dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data", "chat_sessions")
        try:
            os.makedirs(self._dir, exist_ok=True)
        except Exception:
            pass
        # 定时任务落盘目录：data/schedules/<user>.json + data/schedule_outputs/<user>/<产物>.json
        self._sched_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data", "schedules")
        self._sched_out_root = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data", "schedule_outputs")
        try:
            os.makedirs(self._sched_dir, exist_ok=True)
            os.makedirs(self._sched_out_root, exist_ok=True)
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

    # ---- 异步长任务进度（供 server 后台线程回写、/chat/status 读取）----
    def _set_progress(self, sid, phase, msg):
        try:
            with self._prog_lock:
                p = self._prog.setdefault(sid, {"phase": phase, "msg": msg, "start": time.time()})
                p["phase"] = phase
                p["msg"] = msg
                p["ts"] = time.time()
        except Exception:
            pass

    def progress(self, sid):
        """返回 sid 当前进度；无记录返回 None。带 __len__ 惰性清除很久前的记录防泄漏。"""
        try:
            with self._prog_lock:
                p = self._prog.get(sid)
                if not p:
                    return None
                # 超过 2 小时且已无引用则清掉，防长时间累积
                if time.time() - p.get("start", 0) > 7200:
                    self._prog.pop(sid, None)
                    return None
                return dict(p)
        except Exception:
            return None

    def clear_progress(self, sid):
        try:
            with self._prog_lock:
                self._prog.pop(sid, None)
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
            "你现在是'线上自媒体内容获客工作台'的意图调度器。本平台帮用户做【公众号文章、小红书图文、各类短视频、朋友圈文案】来获客，"
            "以及这些内容的选题/写稿/改写/出片/质检/发布/拆解/素材/声音。\n"
            "你的唯一任务：读懂用户本轮说的这句话，输出一个 JSON，判定接下来要做什么。\n\n"
            "当前可调用能力（只列本期已接通的，未接通的不要填；用户想用未接通能力时在 out_reply 如实说明）：\n"
            + (_CAP.prompt_for_llm() if _CAP else "") + "\n\n"
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
            "  \"cap\": (用户想调用上面某个能力时填其 id，否则填 null。只在明确要做该动作时才填),\n"
            "  \"cap_confidence\": (0-1 的小数，你对 cap 判断的把握程度；cap 为 null 时填 0),\n"
            "  \"extract\": {可选, 从本轮提取/更新的要素, 只放确有信息且与原值不同的字段: "
            "{\"topic\":\"\",\"audience\":\"\",\"requirement\":\"\",\"count\":0}},\n"
            "  \"action\": \"ask\" | \"answer\" | \"propose\" | \"write\",\n"
            "  \"asked\": \"(action=ask 时，追问还缺的要素的话术，简短一句，专业不啰嗦)\",\n"
            "  \"answer_ctx\": \"(action=answer 时填：用户真正在问的问题是什么——他是想了解某个财税知识/政策/流程/业务判断，不是在要你写稿。把问题本质概括成一句)\",\n"
            "  \"answer\": \"(action=answer 时填：你作为财税顾问的正面回答正文，结论先行、讲人话、不堆术语、不反问'拍给谁看'，可直接用；非 answer 动作时填空字符串)\",\n"
            "  \"pick\": (action=write 时，用户想写的角度。填整数下标【从0开始】或 'all' 或 'next'。否则 null)\n"
            "  \"in_scope\": (true/false：本条是否属于本平台工作范围——线上自媒体内容获客，见上方范围定义；拿不准就填 true),\n"
            "  \"out_reply\": \"(in_scope=false 时填写：礼貌说明本平台是做什么的、这条不归我们管，一两句、语气友好、不冷硬；否则空串)\"\n"
            "}\n\n"
            "★工作范围纠偏（最高优先级，先判这条）：本平台只做【线上自媒体内容获客】——公众号文章、小红书图文、各类短视频、朋友圈文案，"
            "以及这些内容的选题/写稿/改写/出片/质检/发布/拆解/素材/声音；财税知识/政策/业务问答（顾问身份，也是内容素材）也算范围内。\n"
            "  - 凡属以上，都要积极承接（in_scope=true），不要推。\n"
            "  - 下列属于【超出工作范围】，in_scope 必须=false，并在 out_reply 里礼貌说明本平台是做什么的、这条不归我们管（语气友好、不冷硬、一两句即可，不要过度道歉）：\n"
            "    ① 实际代理记账、报税、做账、税务筹划/注销/变更的落地执行（不是'内容'）；\n"
            "    ② 法律诉讼、合同代写、工商注册落地办理（除非是'讲注册的内容'）；\n"
            "    ③ 与内容获客完全无关的生活/技术/闲聊：订餐、查天气、写无关代码、陪聊、医疗/健身/装修等其它行业的落地咨询（除非用户是要'为那个领域做内容'）。\n"
            "  - 区分示例：'公转私有啥风险''怎么注册公司'是财税问答（in_scope=true，answer）；'帮我报个税''帮我注册个公司落地'是落地服务（in_scope=false）。\n"
            "  - 用户要某个【已规划但本期还没接通】的能力（矩阵分发/多平台群发、CRM客户档案、1v1视频诊断预约、AI客服自动接待、爆款数据看板、7x24 AI财税顾问）"
            "→ in_scope=true（属内容获客范畴），但如实说'这个能力我还没接上、现在做不到'，并主动给一个现在能做的替代，不要说成超出范围。\n"
            "判定规则（先判断意图，再决定动作）：\n"
            "- ★最重要：先判断用户到底想要什么。用户可能是在【问一个财税/业务问题】（想知道政策、法规、流程、某做法合不合规、业务怎么开展、某件事怎么处理），"
            "而不是在【让你写口播稿】。\n"
            "- 若用户是在问问题、要解释、要建议、要讨论（哪怕是闲聊里带出'注册公司''注销''免税''风险''怎么获客'这类词）→ action=answer。"
            "此时【绝对不要】追问'拍给哪类老板看'，也不要去拆角度。你要像一个财税顾问一样正面回答他。\n"
            "- ★action=answer 时，必须同时在 answer 字段写出【完整、正面、专业的回答正文】（先给结论再讲依据，讲人话、不堆术语、不反问'拍给谁看'），"
            "不要只写'这个问题需要查一下'之类推脱——用户要的就是这个回答，把它直接生成出来，系统会复用、不再二次调用模型。\n"
            "- 判断是否'要写稿'的硬信号：用户明确说了要做内容/视频/口播/文案/选题/脚本（如'写条口播''做成视频''出几个选题''帮我写个文案'），"
            "或本会话正处于出稿流程中（已有主题/受众、在拆角度/改稿阶段）且这句是对该流程的推进。\n"
            "- 若用户是在补充信息/回答追问（如'给会计看''要3条''面向餐饮老板'）→ 提取进 extract（不适用 answer）。\n"
            "- ★受众常由主题自带，能推断就不要问：例如主题是'注册公司和个体户怎么选''注册资本写多少'→ 受众明显是【还没注册、正在筹划的创业者】，"
            "你要把这个推断写进 extract.audience，绝不要反问'给刚注册的还是已有规模的老板看'——那种问题本身就不合逻辑（已有规模的人不会纠结注册选择题）。"
            "凡主题含 注册/创业/新办/个体户/注册资本/要不要开公司 → audience 推断为'准备注册或刚注册的创业者'；含 经营/开票/利润/成本 → '已注册在经营的中小老板'；含 建筑/挂靠 → '建筑老板'。\n"
            "- 若用户明确要出内容且给出或已齐 主题+受众+关键要求 → action=propose。\n"
            "- 若用户要求【重新拆角度 / 换个角度 / 再拆一次 / 角度不够再来】（即对已有方案不满意，想再出一版角度）→ action=propose，直接重新拆角度。\n"
            "- 若用户对已出的角度方案表 确认（如'可以''就按这个''认可'）→ action=write, pick='all'。\n"
            "- 若用户挑了具体某条（如'写第2条''第3个''就做第一个'）→ action=write, pick=对应下标。\n"
            "- 若用户在成稿阶段还要继续写下一条（如'继续''下一条'）→ action=write, pick='next'。\n"
            "- 若用户对【已写好的某篇成稿】提出修改意见（如'第2篇太长/换个口吻/加个案例/重写第3篇'），→ action=write, pick=对应篇的下标（0开始），同时把修改要求写进 extract.requirement（追加），让改写器按新要求重写该篇。\n"
            "- 若要素还缺（主题或受众或要求为空）且用户明确要出稿但没说全 → action=ask，并在 asked 追问缺的那一项。\n"
            "- ★能力判定负约束（极重要，防误触发，逐条对照后再填 cap）：\n"
            "  ①用户只是在【问问题/要解释/要建议/要讨论】（哪怕话里带'注册公司''注销''免税''公转私''股权''金税四期'等业务词）→ cap 必须 null，action=answer；\n"
            "  ②用户要的是【口播稿/逐字稿/角度/出稿】（如'写条口播''出稿''给几个角度''改成我的口径'）→ cap 必须 null，走 propose/write，不要填 rewrite；\n"
            "  ③只有用户明确要做【出片/选题/热点/获客评分/质检/公众号文章/小红书/发布包/素材剪辑/声音克隆/爆款拆解】这类具体动作时，才填对应 cap；\n"
            "  ④拿不准就填 null。填 null 最坏是走普通对话，填错 cap 会打断用户正在做的事。\n"
            "- ★状态问句（最高优先级，逐字对照）：用户用'…写了吗/出了没/做好了没/完成没/有没有做/是不是出了'这类句式"
            "询问某件事【是否已完成/是否已做】，这是状态询问，不是下达指令。此时 action 必须='answer'、cap 必须=null，"
            "并在 answer_ctx 写明'用户在问<某对象>是否已完成'。绝不可因为句里含'公众号文章/视频/小红书'等词就填 cap——"
            "那会变成'让他现在去写一篇'，答非所问。例如'今天的微信公众号文章写了吗'→ action=answer（回答'还没写，要现在写吗'），cap=null。\n"
            "铁律：宁可多判 answer 也不要机械地当写稿指令——答非所问是最大的失败。用户问问题，你就 answer；用户要内容，你才 propose/write。\n"
            "★关于 cap 的三条硬约束（违反会直接造成答非所问，务必遵守）：\n"
            "1. 用户只是在【问问题、要解释、要建议、要讨论】（哪怕句里带'注册公司''注销''免税''公转私'等词）"
            "→ cap 必须填 null，action=answer。\n"
            "2. 用户要的是【口播稿、逐字稿、角度方案】（如'写条口播''出稿''来几个角度'）"
            "→ cap 必须填 null，走 propose/write 出稿链路，不要填成别的能力。\n"
            "3. 但用户明确是在对【已有稿子/逐字稿/原文】做改写/改编/二创/改成自己口径/改成自己风格时"
            "（如'我给你个逐字稿，帮我改编''这段口播稿改一下''把别人的爆款改成我的口吻'）"
            "→ cap 必须填 'rewrite'，action=ask；如果用户已经把原文贴在消息里了，把原文放进 answer 或 asked 让用户确认/下一步。"
            "只有用户明确要【做】一件具体的事（出片、质检、选题、公众号文章、小红书图文、爆款拆解、"
            "发布素材包、克隆声音、素材剪辑、给选题打分）时，才填对应的 cap。\n"
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

    # ---- 主动规划模式：用户要"排期/策划/一周内容"时，AI 主动甩出 7 天排期 ----
    _PLAN_WORDS = ("帮我规划", "规划一下", "规划本周", "规划下周", "规划这周", "排期", "排个期",
                   "策划一下", "策划本周", "策划下周", "一周内容", "下周内容", "本周内容",
                   # 2026-09-18 真机踩坑补词："帮我做本周公众号选题规划，每天1篇"原话一个词都
                   # 不命中，被 LLM 主判误路由成 article 能力卡（conf=0.72 过阈值），规划流程没跑。
                   "选题规划", "规划选题", "选题排期", "选题计划", "周选题", "做规划", "周规划",
                   "内容排期", "帮我排", "选题方案", "出个方案", "排个计划", "计划一下",
                   "给我排", "内容规划", "帮我安排", "安排一下内容", "排一周", "排期表")
    # 排期批量出稿触发词：一次把本周 7 天都写成稿（区别于"全写"=把当前角度全写）
    _PLAN_WRITE_WORDS = ("本周都写", "这周都写", "一周都写", "本周全写", "这周全写", "一周全写",
                         "本周写完", "一周写完", "整周写", "七天都写", "七天全写",
                         "本周都出稿", "把这周都写", "把一周都写", "全部写出来", "批量出稿")
    _WEEK_PILLARS = [
        ("周一", "创业起步财税"), ("周二", "日常财税合规"), ("周三", "稽查应对手记"),
        ("周四", "历史遗留稽查"), ("周五", "股权设计"), ("周六", "电商专题"), ("周日", "政策速递"),
    ]

    @staticmethod
    def _parse_json_array(raw):
        """从 LLM 输出里尽可能解析出 JSON 数组；失败返回 None。"""
        import re as _re
        if raw is None:
            return None
        if isinstance(raw, list):
            return raw
        if isinstance(raw, dict):
            return [raw]
        txt = str(raw).strip()
        if txt.startswith("```"):
            txt = txt.strip("`")
            if txt[:4].lower() == "json":
                txt = txt[4:]
            txt = txt.strip()
        try:
            obj = json.loads(txt)
            if isinstance(obj, list):
                return obj
            if isinstance(obj, dict):
                return [obj]
        except Exception:
            pass
        m = _re.search(r"\[.*\]", txt, _re.S)
        if m:
            try:
                obj = json.loads(m.group(0))
                if isinstance(obj, list):
                    return obj
            except Exception:
                pass
        m = _re.search(r"\{.*\}", txt, _re.S)
        if m:
            try:
                obj = json.loads(m.group(0))
                if isinstance(obj, dict):
                    return [obj]
            except Exception:
                pass
        return None

    def _chat_plan(self, prompt, timeout=90):
        """规划层专用 LLM 调用：默认 flash（2 秒级）。PLANNING_MODEL 可覆盖，但勿再设 deepseek-v4-pro（2026-09-14 已下线，请求会被路由到 V4.1 Flash）。"""
        cfg = self._plan_cfg() if callable(self._plan_cfg) else self._cfg()
        if not isinstance(cfg, dict):
            cfg = self._cfg()
        model = self._plan_model or cfg.get("model") or "deepseek-v4-flash"
        # 护栏：V4 Pro 已于 2026-09-14 下线，任何 pro 引用一律回退 flash
        if "pro" in str(model).lower():
            model = cfg.get("model") or "deepseek-v4-flash"
        thinking = "disabled" if self._plan_model else None
        return self._chat(prompt, model, cfg.get("key"), cfg.get("base_url"), timeout=timeout, thinking=thinking)

    def _detect_plan(self, message):
        m = str(message or "").strip().lower()
        return any(w in m for w in self._PLAN_WORDS)

    def _detect_plan_write(self, s, message):
        """是否要把本周排期 7 天全部写成稿（需 session 已有 plan_days）。"""
        if not s.get("plan_days"):
            return False
        m = str(message or "").strip()
        return any(w in m for w in self._PLAN_WRITE_WORDS)

    @staticmethod
    def _extract_plan_topic(message):
        """规划卡片专用：识别【规划选题】<topic>　受众：<audience> 直通 propose。"""
        import re as _re
        m = _re.search(r"【规划选题】(.+?)\s*受众[:：]\s*(.+)", str(message or ""))
        if m:
            return m.group(1).strip(), m.group(2).strip()
        return None

    def _do_plan(self, s, message):
        if s.get("id"):
            self._set_progress(s["id"], "thinking", "正在结合七支柱排期为你规划下周内容…")
        pref = ""
        if "建筑" in message or "工程" in message:
            pref = "（建筑深度内容建议走「慧根堂建筑财税」专号；这里以通用号老张号为主，建筑仅作钩子导流）"
        prompt = (
            "你是「昆山老张讲财税」的内容策划搭档，服务对象是中小企业老板（创业/经营/稽查/股权/电商）。\n"
            "请基于以下「七支柱日更排期」为下一周（周一到周日）规划 7 天短视频选题，每天 1 条，覆盖全部 7 个支柱：\n"
            + "\n".join("- %s：%s" % (d, p) for d, p in self._WEEK_PILLARS) + "\n"
            "要求：\n"
            "1. 每条必须是该支柱下的真实财税痛点，戳老板刚需（如公转私、虚开发票、金税四期、个税、股权架构等），不空泛。\n"
            "2. 选题要能挂留资钩子（引导评论/私信），且尽量命中微信搜一搜真实搜索词。\n"
            "3. 为每条标注：建议形式（从 数字人出镜/幕后音·动态画面/幕后音·滚动字幕/AI漫剧/AI白板图解/图解版·信息卡片 中选最合适的一种）、一句话推荐理由。\n"
            "4. 有老张实战视角（稽查后果/合规底线/金额测算），不抄爆款模板。\n"
            + pref + "\n"
            "严格输出 JSON 数组（不要任何解释/代码块标记）：\n"
            '[{"day":"周一","pillar":"创业起步财税","topic":"选题标题(≤18字,戳痛点)","form":"建议形式","why":"一句话推荐理由"}]\n'
            "顺序必须按周一到周日，共 7 条。"
        )
        try:
            raw = self._chat_plan(prompt, timeout=90)
        except Exception as e:  # noqa: BLE001
            return {"stage": "ask", "message": "规划生成失败（%s）。稍后点「重新规划」再试？" % e}
        topics = self._parse_json_array(raw)
        if not isinstance(topics, list) or not topics:
            return {"stage": "ask", "message": "规划时模型返回格式异常，没生成出排期。点下面「重新规划」再试一次。",
                    "next": [{"id": "plan", "name": "重新规划", "icon": "📅", "cmd": "重新规划本周内容"}]}
        days = []
        for t in topics[:7]:
            if not isinstance(t, dict):
                continue
            days.append({
                "day": (t.get("day") or "").strip(),
                "pillar": (t.get("pillar") or "").strip(),
                "topic": (t.get("topic") or "").strip(),
                "form": (t.get("form") or "").strip(),
                "why": (t.get("why") or "").strip(),
            })
        if not days:
            return {"stage": "ask", "message": "规划结果解析为空，点下面「重新规划」再试一次。",
                    "next": [{"id": "plan", "name": "重新规划", "icon": "📅", "cmd": "重新规划本周内容"}]}
        s["plan_days"] = days
        return {
            "stage": "plan",
            "plan": {"days": days},
            "message": "我帮你把下周 7 天内容排好了（覆盖全部七支柱）。点任一天直接开写；也可点「本周 7 条全写」一次全部出稿，或点「重新规划」换一批。",
            "next": [
                {"id": "plan_write", "name": "本周 7 条全写", "icon": "🚀", "cmd": "本周都写出来"},
                {"id": "plan", "name": "重新规划", "icon": "📅", "cmd": "重新规划本周内容"},
            ],
        }

    def _plan_angles_batch(self, s, days):
        """一次 LLM 调用，为本周每天的主题定一个最佳切入角度；失败返回 {}（降级用原主题写）。"""
        listing = "\n".join("%d. 【%s·%s】%s" % (i + 1, d.get("day") or "", d.get("pillar") or "", d.get("topic") or "")
                            for i, d in enumerate(days))
        prompt = (
            "你是「昆山老张讲财税」的爆款选题搭档，服务中小企业老板。\n"
            "下面是下周 7 天的选题排期，请为每一天定一个最抓人的【切入角度标题】"
            "（≤16字，必须有悬念/冲突/金额感，站老板视角，不要平铺直叙），并给一句【结尾留资钩子方向】。\n"
            + listing + "\n"
            "严格输出 JSON 数组（不要任何解释/代码块标记），顺序与上面完全一致，共 %d 条：\n" % len(days)
            + '[{"topic":"原主题(照抄)","title":"切入角度标题","hook":"结尾钩子方向"}]'
        )
        try:
            raw = self._chat_plan(prompt, timeout=90)
        except Exception:  # noqa: BLE001
            return {}
        arr = self._parse_json_array(raw)
        m = {}
        if isinstance(arr, list):
            for i, it in enumerate(arr):
                if i >= len(days):
                    break
                if isinstance(it, dict):
                    tp = (days[i].get("topic") or "").strip()
                    if tp:
                        m[tp] = {"title": (it.get("title") or "").strip(),
                                 "angle": (it.get("angle") or "").strip(),
                                 "hook": (it.get("hook") or "").strip()}
        return m

    def _do_plan_write(self, s):
        """本周排期批量出稿：一次为 7 天各定角度，再并发写稿（单篇失败不中断整批）。
        并发度 4 —— 实测串行 7 篇需 210s（逼近 240s 看门狗），并发后约 50-70s。"""
        from concurrent.futures import ThreadPoolExecutor, as_completed
        days = s.get("plan_days") or []
        if not days:
            return self._do_plan(s, "帮我规划本周财税内容")
        sid = s.get("id") or ""
        total = len(days)
        if sid:
            self._set_progress(sid, "thinking", "正在为本周 %d 条各定最佳切入角度…" % total)
        angle_map = self._plan_angles_batch(s, days)

        def _write_one(d):
            topic = (d.get("topic") or "").strip()
            if not topic:
                return None
            a = angle_map.get(topic) or {}
            title = a.get("title") or topic
            angle = a.get("angle") or ""
            hook = a.get("hook") or ""
            source = title
            if angle:
                source += "\n【切入角度】" + angle
            if hook:
                source += "\n【结尾留资钩子方向】" + hook
            try:
                res = self._ai_rewrite(
                    source, "script",
                    focus=s.get("requirement") or None,
                    industry=(s.get("audience") or (d.get("pillar") or "").strip() or None),
                )
            except Exception as e:  # noqa: BLE001
                res = {"error": str(e)}
            rewritten = ""
            if isinstance(res, dict):
                rewritten = res.get("rewritten") or (res.get("ok") and (res.get("text") or "")) or res.get("script") or ""
            return {"day": (d.get("day") or "").strip(), "pillar": (d.get("pillar") or "").strip(),
                    "title": title, "angle": angle, "script": rewritten,
                    "form": d.get("form") or ""}

        results = [None] * total
        done = 0
        with ThreadPoolExecutor(max_workers=4) as ex:
            futs = {ex.submit(_write_one, d): i for i, d in enumerate(days)}
            for fut in as_completed(futs):
                i = futs[fut]
                try:
                    results[i] = fut.result()
                except Exception as e:  # noqa: BLE001
                    results[i] = {"day": (days[i].get("day") or ""), "pillar": "", "title": "",
                                  "angle": "", "script": "", "form": "", "error": str(e)}
                done += 1
                if sid:
                    self._set_progress(sid, "writing", "本周已完成 %d/%d 篇…" % (done, total))

        # 按排期顺序汇总（并发完成顺序是乱的），并统一编号。
        # widx = 该篇在 session['written'] 里的真实下标（失败篇为 None），供前端"改这篇"精确定位，
        # 避免存在失败篇时 results 与 written 下标错位、改错稿。
        out = []
        for e in results:
            if not e:
                continue
            if e.get("script"):
                e["angle_idx"] = len(s.get("written") or [])
                e["widx"] = e["angle_idx"]
                out.append(e)
                s.setdefault("written", []).append(e)
            else:
                e["angle_idx"] = -1
                e["widx"] = None
                out.append(e)
        s["history"].append("本周批量成稿")
        ok = sum(1 for x in out if x.get("script"))
        return {
            "stage": "written",
            "batch": True,
            "results": out,
            "message": "本周 %d 天内容已全部写成稿（成功 %d 篇，失败 %d 篇）。可逐篇点「改这篇」微调。"
                       % (len(out), ok, len(out) - ok),
            "next": [
                {"id": "video_render", "name": "做成片", "icon": "🎬", "cmd": "做成片"},
                {"id": "qc", "name": "文案质检", "icon": "🛡️", "cmd": "质检"},
                {"id": "publish_pack", "name": "打发布包", "icon": "📦", "cmd": "打成发布包"},
            ],
        }

    # ---- 执行 ----
    def _do_propose(self, s):
        industry = s.get("audience") or s.get("topic") or "中小企业"
        keywords = "；".join(x for x in [s.get("topic"), s.get("requirement")] if x)
        count = int(s.get("count") or 8)   # 默认一次拆 8 条，前 3 条标🔥推荐，减少多次往返
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
        if s.get("id"):
            self._set_progress(s["id"], "thinking", "正在结合主题与证据拆角度方案…")
        topics = self._ai_topic(industry=industry, keywords=keywords, count=count)
        if not topics:
            return {
                "stage": "ask",
                "message": "这次拆角度时模型返回格式异常，没拆出可用方案。你可以换个说法重说主题，或把要求拆成两句再试。",
                "next": [
                    {"id": "tweak", "name": "重新拆角度", "icon": "🎯", "cmd": "重新拆角度"},
                ],
            }
        # 给前 3 条标🔥推荐位（爆款潜力更高，前端可高亮），其余不标
        if isinstance(topics, list):
            for idx, t in enumerate(topics):
                if isinstance(t, dict):
                    t["hot"] = idx < 3
        s["angles"] = topics
        s["chosen"] = []
        s.pop("_await_video_confirm", None)
        return {
            "stage": "propose",
            "angles": topics,
            "tip": "以上参照你的四要素、本空间已有的检索证据和写稿规范一次拆出 %d 个角度，前 3 条（标🔥）为高热度推荐。"
                   "你说'就按这个全写'我直接干，或指定写某条（如'写第2条'）。想看证据对应或调整方向，直接说。" % len(topics),
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
        total = len(targets)
        sid = s.get("id") or ""
        for seq, i in enumerate(targets, 1):
            if i < 0 or i >= len(angles):
                continue
            a = angles[i]
            title = a.get("title") or ""
            angle = a.get("angle") or ""
            hook = a.get("hook") or ""
            # 异步进度：让前端能看到"正在写第 seq/N 篇"
            if sid:
                self._set_progress(sid, "writing",
                                   "正在生成第 %d/%d 篇：%s" % (seq, total, (title or "口播稿")[:24]))
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
                err = res.get("error") if isinstance(res, dict) else None
                return {"stage": "error",
                        "error": (err or "改写服务没有返回内容，可能是模型超时。请刷新页面后重试，或换个说法再发一次。"),
                        "session_id": sid}
            entry = {"angle_idx": i, "title": title, "angle": angle, "script": rewritten}
            out.append(entry)
            # widx = 该篇在 session["written"] 中的真实下标；前端「改这篇」据此定位，
            # 避免单条写稿时因缺 widx 被前端判为"失败篇"而不渲染按钮。
            _wl = s.setdefault("written", [])
            entry["widx"] = len(_wl)
            _wl.append(entry)
            if sid:
                self._set_progress(sid, "writing", "已完成第 %d/%d 篇，继续…" % (seq, total))
        s["history"].append("成稿")
        s["_await_video_confirm"] = True
        return {
            "stage": "written",
            "results": out,
            "message": "口播稿已经写好了。你先看看内容，想改字数、时长、表述直接说；满意了就点「做成片」出视频，也可以先「二创改写」。",
            "next": [
                {"id": "video_render", "name": "做成片", "icon": "🎬", "cmd": "做成片"},
                {"id": "rewrite", "name": "二创改写", "icon": "✍️", "cmd": "二创改写"},
                {"id": "qc", "name": "文案质检", "icon": "🛡️", "cmd": "质检"},
                {"id": "publish_pack", "name": "打发布包", "icon": "📦", "cmd": "打成发布包"},
            ],
        }

    def _adjust_last_script_words(self, s, n):
        """写稿后用户说"改成N字/缩短到N字"→ 重写最新一篇到目标字数（不新增篇、不打断流程）。"""
        written = s.get("written") or []
        if not written:
            return None
        entry = written[-1]
        src = entry.get("title") or s.get("topic") or ""
        if entry.get("angle"):
            src += "\n【切入角度】" + entry["angle"]
        if entry.get("hook"):
            src += "\n【结尾留资钩子方向】" + entry["hook"]
        focus = (s.get("requirement") or "")
        focus += ("；全文严格控制在%d字左右（±10%%）" % n)
        try:
            res = self._ai_rewrite(
                src, "script",
                focus=focus or None,
                industry=(s.get("audience") or s.get("topic") or None),
            )
        except Exception as e:  # noqa: BLE001
            self._chat_log(s.get("id"), "WARN | _adjust_last_script_words 改写异常：%s" % e)
            return {"stage": "ask", "message": "字数调整没成功（模型调用异常），你再说一次或手动改下。"}
        rewritten = ""
        if isinstance(res, dict):
            rewritten = res.get("rewritten") or (res.get("ok") and (res.get("text") or "")) or res.get("script") or ""
        if not rewritten:
            return {"stage": "ask", "message": "字数调整没成功（模型没有返回内容，可能是超时），你再说一次或手动改下。"}
        entry["script"] = rewritten
        entry["widx"] = len(written) - 1
        self._chat_log(s.get("id"), "OUT | adjust_last_script_words -> %d 字" % n)
        s["_await_video_confirm"] = True
        return {
            "stage": "written",
            "results": [entry],
            "message": "已按 %d 字重写这一篇，你看看顺不顺。还想改字数、时长、表述继续说；满意了点「做成片」出视频。" % n,
            "next": [
                {"id": "video_render", "name": "做成片", "icon": "🎬", "cmd": "做成片"},
                {"id": "rewrite", "name": "二创改写", "icon": "✍️", "cmd": "二创改写"},
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
        target["widx"] = idx                    # 原地覆盖，下标不变；显式带给前端「改这篇」
        s["history"].append("改写第" + str(idx + 1) + "篇")
        s["_await_video_confirm"] = True
        return {"stage": "written", "results": [target], "revised": True,
                "message": "已按你的要求重写该篇。还想改字数、时长、表述继续说；满意了点「做成片」出视频。",
                "next": [
                    {"id": "video_render", "name": "做成片", "icon": "🎬", "cmd": "做成片"},
                    {"id": "rewrite", "name": "二创改写", "icon": "✍️", "cmd": "二创改写"},
                    {"id": "qc", "name": "文案质检", "icon": "🛡️", "cmd": "质检"},
                ]}

    # ---- 口播稿修改（字数/时长/表述）----
    # 写稿后、出片前，用户可能要求调整。这里统一识别并处理，避免误走能力卡片或答非所问。
    _MODIFY_WORDS = ("改字数", "改时长", "改表述", "换一种说法", "重写", "改写", "重写成",
                     "缩短", "加长", "扩写", "精简", "调整", "字数", "时长", "表述")

    def _is_script_modify_request(self, msg):
        """判断用户是否在要求修改已写好的口播稿（字数/时长/表述）。"""
        m = str(msg or "").strip()
        if not m:
            return False
        # 必须同时命中"改/调整/缩短/加长"类动词 + "字数/时长/表述/说法"等对象
        has_verb = any(w in m for w in ("改", "换", "重写", "改写", "调整", "缩短", "加长", "扩写", "精简"))
        has_obj = any(w in m for w in ("字数", "字", "时长", "秒", "表述", "说法", " wording", "这段"))
        return has_verb and has_obj

    def _handle_script_modify(self, s, message):
        """处理写稿后的修改请求：字数/时长/表述。返回 None 表示不是修改请求，交给后续流程。"""
        written = s.get("written") or []
        if not written:
            return None
        m = str(message or "").strip()
        if not m:
            return None
        if not self._is_script_modify_request(m):
            return None
        # 1) 字数调整（含 "改成N字/缩短到N字"）
        _wc = re.search(r"(\d{3,5})\s*字", m)
        if _wc and any(w in m for w in ("改", "字数", "缩短", "加长", "扩写", "精简", "调")):
            _n = int(_wc.group(1))
            if 300 <= _n <= 5000:
                return self._adjust_last_script_words(s, _n)
        # 2) 时长调整：按中文口播约 3.5 字/秒换算成字数，再调用字数调整
        _sec = re.search(r"(\d{1,3})\s*秒", m)
        if _sec and any(w in m for w in ("改", "时长", "缩短", "加长", "控制", "调")):
            sec = int(_sec.group(1))
            if 10 <= sec <= 300:
                target_words = int(sec * 3.5)
                # 把时长要求也写进会话 requirement，让 _adjust_last_script_words  focus 生效
                old_req = s.get("requirement") or ""
                dur_req = "全文时长控制在%d秒左右（约%d字）" % (sec, target_words)
                if dur_req not in old_req:
                    s["requirement"] = (old_req + "；" + dur_req).strip("；")
                return self._adjust_last_script_words(s, target_words)
        # 3) 纯 "改字数" 但没给数字 → 追问
        if any(w in m for w in ("字数", "字")) and not _wc:
            return {"stage": "ask", "message": "想改成多少字？直接说'改成800字'或'缩短到600字'。"}
        # 4) 纯 "改时长" 但没给秒数 → 追问
        if any(w in m for w in ("时长", "秒")) and not _sec:
            return {"stage": "ask", "message": "想控制在多少秒？直接说'缩短到30秒'或'控制在60秒'。"}
        # 5) 改表述/换一种说法/重写 → 用 _do_revise 重写最后一篇
        if any(w in m for w in ("表述", "说法", " wording", "这段", "重写", "改写")):
            u = {"extract": {"requirement": m}}
            return self._do_revise(s, u, "rev%d" % (len(written) - 1))
        return None

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
        # ★写稿指令保护（2026-09-19）：明确"围绕…生成…稿"这类出稿要求，
        # 哪怕句中带"是不是/有没有/会不会"（用户在陈述写稿要求，不是在问协作审查），
        # 也绝不能被检索/元审查抢走——曾致"请围绕以上内容生成文稿"被误路由成协作审查。
        if self._looks_like_write_req(msg):
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
        if s.get("id"):
            self._set_progress(s["id"], "searching", "正在全网检索「%s」…" % (query or "")[:24])
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

    def _chat_log(self, sid, msg):
        """对话运行日志：便于定位'没反应'到底是前端没渲染、请求超时还是 LLM 异常。"""
        try:
            import os, datetime
            d = datetime.date.today().isoformat()
            os.makedirs("data/chat_logs", exist_ok=True)
            ts = datetime.datetime.now().strftime("%H:%M:%S")
            with open(f"data/chat_logs/{d}.log", "a", encoding="utf-8") as f:
                f.write(f"[{ts}] sid={sid} | {msg}\n")
        except Exception:  # noqa: BLE001
            pass

    def _do_general_answer(self, s, question):
        """通用问答兜底：不限于财税。任何非出稿意图的对话（技术/运营/常识/闲聊/其它业务），
        都调大模型正面回答，避免'没反应'或'答非所问'。失败时也绝不静默。"""
        if s.get("id"):
            self._set_progress(s["id"], "thinking", "正在思考…")
        cfg = self._cfg()
        prompt = (
            "你是一个知识渊博、逻辑清晰的中文智能助手，在财税领域尤具专长，也能回答其它领域的问题。\n"
            "用户说：" + question + "\n\n"
            "要求：\n"
            "1. 先给结论/直接回应，再展开要点；讲人话、不堆术语、不端着；\n"
            "2. 若是财税/合规/工商类问题，按资深财税顾问口径答，注意时效与依据，拿不准明说'以最新官方口径/当地税务机关为准'；\n"
            "3. 若是技术/运营/管理/其它领域问题，用对应领域专业但通俗的方式回答；\n"
            "4. 不要反问'拍给谁看'，不要硬往出稿/写稿流程带；除非用户明确想做成内容，否则不主动推写稿；\n"
            "5. 只输出回答正文，不要代码块标记、不要 JSON。"
        )
        try:
            ans = self._chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=90)
            if isinstance(ans, dict):
                ans = ans.get("content") or ""
        except Exception:  # noqa: BLE001
            ans = ""
        if not ans:
            return {"stage": "answer",
                    "message": "我这边一下没接住，你再发一次、或者换个说法试试？我重新接。"}
        return {"stage": "answer", "message": ans}

    # ---- 对话纠偏：三类边界回复（超出范围 / 能力未接通 / 朋友圈文案） ----
    def _do_scope_decline(self, s, u, message, reason=None):
        """礼貌说明本平台只做线上自媒体内容获客，这条不归我们管；语气友好、不冷硬。"""
        reply = ""
        if isinstance(u, dict):
            reply = (u.get("out_reply") or "").strip()
        if not reply:
            if reason == "service":
                reply = ("我是专门帮你做线上获客内容的助手——公众号文章、小红书图文、短视频、朋友圈文案，"
                         "以及这些内容的选题、写稿、出片、发布。\n"
                         "你这条更像是落地服务（报税 / 做账 / 注册办理这类），不在我工作范围内，我接了怕说不准、反而误事。"
                         "不过你要是想知道这类事的【要点 / 风险 / 流程】，我可以当成内容给你讲清楚；真要办，建议找对应的专业机构。")
            else:
                reply = ("我是专门帮你做线上获客内容的助手——公众号文章、小红书图文、短视频、朋友圈文案，"
                         "以及这些内容的选题、写稿、改写、出片、质检、发布。\n"
                         "你这条不太在我工作范围内，我怕接了说不准。它更适合对应的专业渠道去办；"
                         "如果你是想把这件事【做成一篇能发出的内容】（文章 / 口播 / 图文），告诉我就行，那正好是我擅长的。")
        self._chat_log(s.get("id"), "OUT | scope-decline (reason=%s)" % (reason or "llm"))
        return {"stage": "answer", "message": reply, "scope_declined": True}

    def _do_cap_unavailable(self, s, cap_id, message):
        """已规划但本期未接通的能力：如实说还没接上 + 给一个现在能做的替代，绝不瞎答应。"""
        alt = self._HIDDEN_CAP_ALT.get(cap_id, "这个能力我这边还没接上，暂时做不到。")
        cap_name = self._HIDDEN_CAP_NAME.get(cap_id) or (
            (_CAP.get(cap_id) or {}).get("name", cap_id) if _CAP else cap_id)
        self._chat_log(s.get("id"), "OUT | cap-unavailable | %s" % cap_id)
        return {"stage": "answer",
                "message": "「%s」这个能力我这边还没接上系统，现在还做不到。\n%s" % (cap_name, alt)}

    def _do_moment(self, s, message):
        """朋友圈文案：轻量生成（文字给你复制去发，自动发布到微信的接口尚未接通）。"""
        if s.get("id"):
            self._set_progress(s["id"], "thinking", "正在帮你写朋友圈文案…")
        topic = self._extract_topic_from_msg(message) or message
        for w in ("朋友圈文案", "朋友圈", "文案", "帮我写", "帮我", "请给我", "写一条",
                  "写个", "来一条", "配一段", "配文", "发一条", "发个"):
            topic = topic.replace(w, "")
        topic = topic.strip(" ，。！？、:：\"'").strip()
        ctx_topic = s.get("topic") or topic or "财税干货 / 老板痛点"
        cfg = self._cfg()
        prompt = (
            "你是「慧根堂财税」的朋友圈文案助手。用户想发一条朋友圈获客文案。\n"
            "主题：" + ctx_topic + "\n\n"
            "要求：\n"
            "1. 产出 1-2 条朋友圈文案，每条 3-6 行、口语、像老板自己发的，不端着；\n"
            "2. 戳中小老板痛点或给一个马上能用的财税提醒，结尾轻带一句互动（评论 / 私信），不硬广；\n"
            "3. 不教逃税、不编造数据；可附 1-2 个 #话题；\n"
            "4. 不要标题、不要 markdown 列表、不要代码块。\n"
            "只输出文案正文。"
        )
        try:
            ans = self._chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=60)
            if isinstance(ans, dict):
                ans = ans.get("content") or ""
        except Exception:  # noqa: BLE001
            ans = ""
        if not ans:
            ans = "（刚没接住，你把想发的方向再讲一句，我马上写。）"
        note = ("\n\n— 说明：朋友圈文案我现在能帮你写好文字，但自动发到微信的接口还没接通，你复制去发就行；"
                "要出成片 / 长文我也能做。")
        self._chat_log(s.get("id"), "OUT | moment copy generated")
        return {"stage": "answer", "message": ans + note, "moment": True}

    # ===================== 定时任务子系统（对话内设定时内容生产 + 落盘提醒） =====================
    # 定位：本平台只做「线上自媒体内容获客」，不代发（微信无朋友圈发布 API）。
    # 定时任务 = 用户说一句"每周一9点帮我备好朋友圈文案" → 到点自动产出内容存盘 + 提醒用户去发。
    # MVP 实做：朋友圈文案（moment，同步轻量）。口播稿/公众号/规划属重生产，设定时给诚实提示待接。
    _SCHED_KIND_NAME = {"moment": "朋友圈文案", "write": "短视频口播稿",
                         "article": "公众号文章", "plan": "内容规划"}
    _SCHED_WEEKDAYS = {"周一": 0, "周二": 1, "周三": 2, "周四": 3, "周五": 4, "周六": 5, "周日": 6,
                        "一": 0, "二": 1, "三": 2, "四": 3, "五": 4, "六": 5, "日": 6,
                        "mon": 0, "tue": 1, "wed": 2, "thu": 3, "fri": 4, "sat": 5, "sun": 6}

    def _detect_schedule(self, message):
        """识别定时任务类意图：set/list/cancel/pending。命中返回意图类型，否则 None。"""
        m = str(message or "").strip()
        freq_words = ("每天", "每日", "每周", "每星期", "每个", "定时", "到点", "隔天", "每隔",
                      "每天早上", "每天晚上", "每周一", "每周二", "每周三", "每周四", "每周五",
                      "每周六", "每周日", "周日", "周一", "周二", "周三", "周四", "周五", "周六")
        set_words = ("备好", "写好", "写条", "写一", "生成", "产出", "帮我写", "帮我备", "帮我做",
                     "推送", "发一条", "发个", "准备", "安排", "设定", "设置", "建个", "提醒我", "到点")
        if any(w in m for w in freq_words) and any(w in m for w in set_words):
            return "set"
        # ★cancel 必须先于 list：否则"取消定时任务1"因含 list 词"定时任务"被误判 list
        if any(w in m for w in ("取消定时", "关掉定时", "删除定时", "停掉定时", "去掉定时", "取消任务")):
            return "cancel"
        if any(w in m for w in ("我的定时任务", "查看定时任务", "定时任务列表", "有哪些定时", "看看定时", "定时任务")):
            return "list"
        if any(w in m for w in ("待发", "有什么待发", "待发的", "备好的内容", "还没发的", "待发送", "我有什么")):
            return "pending"
        return None

    @staticmethod
    def _parse_hhmm(t):
        import re as _re
        mt = _re.search(r"(\d{1,2})[:：](\d{2})", str(t))
        if mt:
            return (max(0, min(23, int(mt.group(1)))), max(0, min(59, int(mt.group(2)))))
        mt2 = _re.search(r"(\d{1,2})\s*点", str(t))
        if mt2:
            return (max(0, min(23, int(mt2.group(1)))), 0)
        return (9, 0)

    def _parse_schedule_regex(self, m):
        hh, mm = self._parse_hhmm(m)
        freq = "daily" if any(w in m for w in ("每天", "每日", "每天早上", "每天晚上", "隔天", "每隔一天")) else "weekly"
        weekday = None
        for w, d in self._SCHED_WEEKDAYS.items():
            if w in m:
                weekday = d
                freq = "weekly"
                break
        kind = "moment"
        for kw, k in (("朋友圈", "moment"), ("口播", "write"), ("短视频", "write"),
                      ("公众号", "article"), ("文章", "article"), ("规划", "plan")):
            if kw in m:
                kind = k
                break
        import re as _re
        prompt = m
        for w in ("帮我", "帮我写", "帮我备", "帮我做", "备好", "写好", "生成", "产出", "推送",
                  "设定", "设置", "建个", "提醒我", "到点", "每天", "每日", "每周", "每个", "定时",
                  "早上", "晚上", "点", "分", "条", "篇", "个", "一", "内容", "文案",
                  "朋友圈", "口播", "短视频", "公众号", "文章", "规划"):
            prompt = prompt.replace(w, " ")
        prompt = _re.sub(r"\d{1,2}[:：]\d{2}", " ", prompt)
        prompt = _re.sub(r"[周一二三五六日]", " ", prompt)
        prompt = prompt.strip(" ，。！？、:：\"'").strip()
        return {"freq": freq, "weekday": weekday, "time": "%02d:%02d" % (hh, mm),
                "kind": kind, "prompt": prompt or "财税干货 / 老板痛点"}

    def _parse_schedule(self, message):
        """LLM 解析定时任务参数（优先），失败回退正则。返回 {freq,weekday,time,kind,prompt}。"""
        import re as _re
        m = str(message or "").strip()
        try:
            cfg = self._cfg()
            prompt = (
                "你是一个定时内容生产任务的参数解析器。用户想设定一个【定时自动产出内容】的任务"
                "（注：微信朋友圈没有自动发布接口，系统只负责到点把内容写好存好，由用户复制去发）。\n"
                "请只输出 JSON：\n"
                "{\n"
                '  "freq": "daily" 或 "weekly",\n'
                '  "weekday": (weekly 时为 0-6 的整数，周一=0；daily 时为 null),\n'
                '  "time": "HH:MM" (24小时制，从用户话里提取，没有就默认 "09:00"),\n'
                '  "kind": "moment"(朋友圈文案) / "write"(短视频口播稿) / "article"(公众号文章) / "plan"(内容规划),\n'
                '  "prompt": "内容主题与要求（去掉频率/时间/类型词后的纯净主题，如\'公转私风险提醒\'）"\n'
                "}\n"
                "用户原话：" + m + "\n"
            )
            raw = self._chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=40)
            if isinstance(raw, dict):
                raw = raw.get("content") or "{}"
            t = (raw or "").strip()
            if t.startswith("```"):
                t = t.strip("`")
                if t[:4].lower() == "json":
                    t = t[4:]
                t = t.strip()
            obj = json.loads(t)
            freq = obj.get("freq") or "daily"
            if freq not in ("daily", "weekly"):
                freq = "daily"
            weekday = obj.get("weekday")
            if weekday is not None:
                try:
                    weekday = int(weekday)
                except Exception:
                    weekday = None
            hh, mm = self._parse_hhmm(obj.get("time") or "09:00")
            kind = obj.get("kind") or "moment"
            if kind not in self._SCHED_KIND_NAME:
                kind = "moment"
            prompt_clean = (obj.get("prompt") or "").strip() or "财税干货 / 老板痛点"
            return {"freq": freq, "weekday": weekday, "time": "%02d:%02d" % (hh, mm),
                    "kind": kind, "prompt": prompt_clean}
        except Exception:
            pass
        return self._parse_schedule_regex(m)

    @staticmethod
    def _next_run_str(freq, weekday, t):
        from datetime import datetime as _dt, timedelta as _td
        hh, mm = (int(x) for x in str(t).split(":"))
        now = _dt.now()
        cand = now.replace(hour=hh, minute=mm, second=0, microsecond=0)
        if freq == "daily":
            if cand <= now:
                cand += _td(days=1)
        else:
            wd = 0 if weekday is None else int(weekday)
            days = (wd - now.weekday()) % 7
            if days == 0 and cand <= now:
                days = 7
            cand += _td(days=days)
        return cand.strftime("%Y-%m-%d %H:%M:%S")

    def _schedule_file(self, user_id):
        return os.path.join(self._sched_dir, "%s.json" % (user_id or "default"))

    def _load_schedules(self, user_id):
        try:
            with open(self._schedule_file(user_id), encoding="utf-8") as f:
                return json.load(f)
        except Exception:
            return []

    def _save_schedules(self, user_id, tasks):
        try:
            tmp = self._schedule_file(user_id) + ".tmp"
            with open(tmp, "w", encoding="utf-8") as f:
                json.dump(tasks, f, ensure_ascii=False, indent=2)
            os.replace(tmp, self._schedule_file(user_id))
        except Exception:
            pass

    def _sched_out_dir(self, user_id):
        d = os.path.join(self._sched_out_root, str(user_id or "default"))
        try:
            os.makedirs(d, exist_ok=True)
        except Exception:
            pass
        return d

    def _do_schedule_set(self, s, message, user_id):
        p = self._parse_schedule(message)
        kind_name = self._SCHED_KIND_NAME.get(p["kind"], "内容")
        if p["kind"] != "moment":
            when = ("每天 %s" % p["time"]) if p["freq"] == "daily" \
                else ("每%s %s" % (["周一", "周二", "周三", "周四", "周五", "周六", "周日"][p["weekday"] or 0], p["time"]))
            return {"stage": "answer", "message":
                "定时任务我接住了（%s，%s）。\n目前到点自动生产我只做好了【朋友圈文案】——到点直接帮你写好文字存好、提醒你去发；"
                "口播稿 / 公众号 / 规划这几类重生产我还在接，你先手动让我出，我尽快把自动版补上。" % (kind_name, when)}
        task = {
            "id": "sch_%d" % int(time.time() * 1000),
            "freq": p["freq"], "weekday": p["weekday"], "time": p["time"],
            "kind": "moment", "prompt": p["prompt"],
            "created_at": time.strftime("%Y-%m-%d %H:%M:%S"),
            "last_run": None,
            "next_run": self._next_run_str(p["freq"], p["weekday"], p["time"]),
            "enabled": True,
        }
        tasks = self._load_schedules(user_id)
        tasks.append(task)
        self._save_schedules(user_id, tasks)
        when = ("每天 %s" % p["time"]) if p["freq"] == "daily" \
            else ("每%s %s" % (["周一", "周二", "周三", "周四", "周五", "周六", "周日"][p["weekday"] or 0], p["time"]))
        return {"stage": "answer", "message":
            "✅ 定时任务设好了：%s，自动帮你写好朋友圈文案存好、提醒你去发。\n"
            "主题方向：「%s」\n下次执行：%s\n\n"
            "查看任务列表说「我的定时任务」；取消说「取消定时任务」。\n"
            "（说明：微信朋友圈没有自动发布接口，所以到点产出文案后需你复制去发——这是平台能力边界，不是偷懒。）" %
            (when, p["prompt"], task["next_run"])}

    def _do_schedule_list(self, user_id):
        tasks = self._load_schedules(user_id)
        if not tasks:
            return {"stage": "answer", "message": "你还没有设定任何定时任务。说一句「每周一9点帮我备好朋友圈文案」就能设好。"}
        lines = []
        for i, t in enumerate(tasks):
            when = ("每天 %s" % t["time"]) if t["freq"] == "daily" \
                else ("每%s %s" % (["周一", "周二", "周三", "周四", "周五", "周六", "周日"][t.get("weekday") or 0], t["time"]))
            lines.append("%d. %s → %s（%s）%s" % (
                i + 1, when, self._SCHED_KIND_NAME.get(t["kind"], t["kind"]),
                t.get("prompt"), "" if t.get("enabled", True) else " [已停用]"))
        return {"stage": "answer", "message": "你的定时任务：\n" + "\n".join(lines) +
                "\n\n取消某个说「取消定时任务N」（N 是序号）。"}

    def _do_schedule_cancel(self, s, message, user_id):
        import re as _re
        tasks = self._load_schedules(user_id)
        if not tasks:
            return {"stage": "answer", "message": "你还没有定时任务，没得取消。"}
        mm = _re.search(r"(\d+)", str(message))
        idx = (int(mm.group(1)) - 1) if mm else None
        if idx is None or not (0 <= idx < len(tasks)):
            return {"stage": "answer", "message": "没找到这个序号。说「我的定时任务」看下序号，再「取消定时任务N」。"}
        removed = tasks.pop(idx)
        self._save_schedules(user_id, tasks)
        return {"stage": "answer", "message": "已取消定时任务：%s。" %
                self._SCHED_KIND_NAME.get(removed["kind"], removed["kind"])}

    def _do_schedule_pending(self, user_id):
        import glob as _glob
        d = self._sched_out_dir(user_id)
        files = sorted(_glob.glob(os.path.join(d, "*.json")))
        items = []
        for fp in files:
            try:
                with open(fp, encoding="utf-8") as f:
                    it = json.load(f)
                if not it.get("read", False):
                    items.append(it)
                    it["read"] = True
                    with open(fp, "w", encoding="utf-8") as f:
                        json.dump(it, f, ensure_ascii=False, indent=2)
            except Exception:
                pass
        if not items:
            return {"stage": "answer", "message": "暂时没有待发内容。设定时任务后，到点产出的文案会存在这里等你来取。"}
        blocks = ["【%d】%s\n%s" % (i, it.get("prompt", ""), it.get("content", "")) for i, it in enumerate(items, 1)]
        return {"stage": "answer",
                "message": "待发内容（%d 条，点开即可复制去发）：\n\n%s" % (len(items), "\n\n".join(blocks)),
                "pending": items}

    def _notify_feishu(self, text):
        """可选提醒：env 配了 FEISHU_WEBHOOK 才发（8500 引擎无 lark-cli，直接 POST 自定义机器人）。失败静默。"""
        try:
            wh = os.environ.get("FEISHU_WEBHOOK") or self._get_key("FEISHU_WEBHOOK")
            if not wh:
                return
            import urllib.request as _u
            data = json.dumps({"msg_type": "text", "content": {"text": text}}).encode("utf-8")
            req = _u.Request(wh, data=data, headers={"Content-Type": "application/json"}, method="POST")
            _u.urlopen(req, timeout=10)
        except Exception:
            pass

    def _run_scheduled_task(self, user_id, task):
        """调度线程到点调用：复用 _do_moment 产出朋友圈文案 → 落盘 data/schedule_outputs/<user>/ → 更新 next_run。"""
        try:
            sys_s = {"id": "sched_%s" % task["id"], "topic": task.get("prompt") or "财税干货"}
            content = ""
            if task.get("kind") == "moment":
                res = self._do_moment(sys_s, task.get("prompt") or "帮我写条朋友圈文案")
                content = res.get("message", "") if isinstance(res, dict) else str(res)
            else:
                content = "（该类型定时生产还在接入中，暂未产出）"
            out_dir = self._sched_out_dir(user_id)
            ts = time.strftime("%Y%m%d_%H%M%S")
            fp = os.path.join(out_dir, "%s_%s.json" % (task["id"], ts))
            with open(fp, "w", encoding="utf-8") as f:
                json.dump({"task_id": task["id"], "kind": task.get("kind"), "prompt": task.get("prompt"),
                           "content": content, "created_at": time.strftime("%Y-%m-%d %H:%M:%S"), "read": False},
                          f, ensure_ascii=False, indent=2)
            task["last_run"] = time.strftime("%Y-%m-%d %H:%M:%S")
            task["next_run"] = self._next_run_str(task.get("freq", "daily"), task.get("weekday"), task.get("time", "09:00"))
            tasks = self._load_schedules(user_id)
            for i, t in enumerate(tasks):
                if t.get("id") == task["id"]:
                    tasks[i] = task
                    break
            self._save_schedules(user_id, tasks)
            self._notify_feishu("【定时任务产出】%s\n%s" % (task.get("prompt"), content[:300]))
            self._chat_log(None, "OK | scheduled task run: %s" % task["id"])
        except Exception as e:  # noqa: BLE001
            self._chat_log(None, "ERR | scheduled task failed: %s | %s" % (task.get("id"), e))

    def _route_answer(self, s, u, message, question=None):
        """问答路由收口：合并意图识别与回答生成，避免每轮双调用模型。

        - u 为 _understand 的返回（可能已含 answer 字段）；
        - 非搜索类问题且 u.answer 已生成 → 直接复用，省掉第二次模型请求；
        - 搜索类（需联网核口径）或 u.answer 缺失 → 走 _do_answer 原高质量路径。
        """
        q = (question or (u.get("answer_ctx") if isinstance(u, dict) else None) or message).strip() or message
        need_search = self._answer_needs_search(message)
        prepared = (u.get("answer") if isinstance(u, dict) else None) if not need_search else None
        return self._do_answer(s, q, need_search=need_search, prepared=prepared)

    def _do_answer(self, s, question, need_search=False, prepared=None):
        """正面回答用户的财税/业务问题（不写稿、不追问受众），像资深顾问一样答到点子上。

        - 政策/法规有时效或拿不准 → need_search=True 走联网检索再答；
        - 知识/经验类 → 直接用专家口径答，末尾轻带一句"要不要我把这条做成内容"；
        - prepared 非空：直接复用意图识别阶段已生成的回答（合并调用优化，省掉第二次模型请求）。
        """
        if s.get("id"):
            phase = "searching" if need_search else "thinking"
            msg = ("正在核对最新政策口径…" if need_search else "正在想这个问题…")
            self._set_progress(s["id"], phase, msg)
        # 需要核实时效 → 联网检索作为事实基础（此路径仍需独立生成，保证口径准确）
        if need_search:
            key = self._get_key("TAVILY_API_KEY")
            if key and self._search:
                try:
                    r = self._search(question, key, topic="general", days=365, max_results=5, timeout=15)
                    items = (r or {}).get("results") or []
                    if items:
                        s.setdefault("search_refs", []).extend(items[:5])
                        ev = "\n".join(
                            "- %s | %s\n  %s" % (i.get("title") or "", i.get("url") or "", (i.get("content") or "")[:300])
                            for i in items[:5]
                        )
                        src = [{"title": i.get("title") or "", "url": i.get("url") or ""}
                               for i in items[:5] if i.get("url")]
                        cfg = self._cfg()
                        prompt = (
                            MASTER_PROMPT
                            + "\n\n用户在问一个财税/业务问题：" + question
                            + "\n\n以下是联网检索到的官方/资讯内容（作为核实时效的依据，不要编造未出现的内容）：\n" + ev
                            + "\n\n请以财税顾问的口吻正面回答用户。要求：\n"
                            "1. 先直接给结论（政策现在是否适用/流程大致如何/该注意什么）；\n"
                            "2. 引用依据时标注来源（可提'据检索到的最新口径'），拿不准就明说'建议以当地税务机关为准'；\n"
                            "3. 讲人话，别堆术语；必要时分点；\n"
                            "4. 末尾自然带一句：要不要我把这条整理成给老板看的口播/图文（如果用户只是问问，不强推）。\n"
                            "只输出回答正文，不要代码块、不要 JSON。"
                        )
                        try:
                            ans = self._chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=90)
                            if isinstance(ans, dict):
                                ans = ans.get("content") or ""
                        except Exception:  # noqa: BLE001
                            ans = ""
                        if ans:
                            return {"stage": "answer", "message": ans, "sources": src}
                except Exception:  # noqa: BLE001
                    pass  # 检索失败降级纯知识回答
        # 纯知识/经验回答：优先复用意图识别阶段已生成的答案（合并成 1 次模型调用），否则现调
        if prepared:
            ans = prepared
        else:
            cfg = self._cfg()
            prompt = (
                MASTER_PROMPT
                + "\n\n用户在问一个财税/业务问题（不是在让你写稿）：" + question
                + "\n\n请以财税顾问的身份，正面、直接地回答他。要求：\n"
                "1. 先给结论，再讲依据/要点，讲人话、不堆术语；\n"
                "2. 像同行聊天一样自然，不要机械、不要套模板、不要反问'拍给谁看'；\n"
                "3. 涉及具体数字/法条/政策时效，若不能百分百确定就说明'以最新官方口径/当地税务机关为准'，不要编造；\n"
                "4. 有不同情形（如注册公司 vs 个体户、老板 vs 会计）就分情形说清，体现真在思考他的处境；\n"
                "5. 末尾可以自然带一句是否要把它做成口播/图文，一句带过即可。\n"
                "只输出回答正文，不要代码块、不要 JSON。"
            )
            try:
                ans = self._chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=90)
                if isinstance(ans, dict):
                    ans = ans.get("content") or ""
            except Exception:  # noqa: BLE001
                ans = ""
        return {"stage": "answer", "message": ans or "我正想呢，你再发一次这条、或者多带一句背景，我重新接？"}

    def _do_meta_review(self, s, msg):
        """协作审查：用户问'有没有结合XX/参考了XX'时，基于本空间已有证据+角度/成稿，正面回答并给推理链。
        关键区别于 _do_search：

        关键区别于 _do_search：
        - 不再调 Tavily（证据已在 session.search_refs 里）
        - 不输出角度方案，而是输出"事实承认+对照表+差异化推理+协作选项+邀请决策"
        - 让用户看到 AI 的思考过程，并能参与决策——而不是被甩模板角度
        """
        refs = s.get("search_refs") or []
        angles = s.get("angles") or []
        written = s.get("written") or []
        if s.get("id"):
            self._set_progress(s["id"], "reviewing", "正在基于本空间证据做协作审查…")

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
        "重新拆", "重新出角度", "换个角度", "再拆一次", "再拆一遍", "角度不够", "再来一次", "不要这些",
        "认可", "就按这个", "按这个", "确认", "同意", "就这个", "按这个全写",
    )

    def _is_produce_cmd(self, msg):
        """判断这条消息是不是"下指令干活"（出稿/拆角度/确认），是的话不走反问接续。"""
        m = (msg or "").strip()
        if not m:
            return False
        return any(w in m for w in self._PRODUCE_WORDS)

    # 写稿要求句式：与检索触发词（是不是/有没有/会不会）共现时，优先判"写稿指令"而非协作审查
    _WRITE_REQ_FRAMES = ("围绕", "按以上", "按上述", "根据以上", "根据上述", "以下内容")

    def _looks_like_write_req(self, msg):
        """这句是不是明确的写稿要求（如"请围绕以上内容生成不超过一分半钟的文稿"）。

        与 _is_produce_cmd 的区别：那边认"写第1条/全写"这类短指令，这边认
        "围绕（以上/给定）内容 + 生成/写 + 稿类词"的长要求句——用户一口气把
        内容要点讲完再让出稿，句中常带"是不是/有没有"（在讲业务判断），
        不能据此误判成问句/协作审查。
        """
        m = (msg or "").strip()
        if not m:
            return False
        has_frame = any(w in m for w in self._WRITE_REQ_FRAMES)
        has_genre = ("稿" in m or "脚本" in m or "文案" in m or "文章" in m)
        if has_frame and has_genre:
            return True
        return ("生成" in m or "写成" in m) and has_genre

    # 明确"要写内容"但没给主题的拦截词：命中且提取不出主题 → 自然反问方向，
    # 不甩带占位符的通用模板、也不一次性甩"主题+受众"两个问题（受众按话题自动推断）。
    _BLANK_PRODUCE_WORDS = (
        "口播", "脚本", "文案", "视频", "图文", "选题", "拆角度", "出稿",
        "写一条", "写个", "写成", "写一篇", "写篇", "出一条", "出一篇",
        "做一条", "来一条", "写一份", "出个", "出条", "做个", "来个", "出片", "做视频",
        "写稿", "整篇", "整一个", "写一段", "来一篇", "来一条",
        "文章", "公众号", "推文", "小红书",
    )

    # 空白写稿判定的二次清洗词：去掉动作/类型/域修饰词后若什么都不剩，才算"没给主题"
    _BLANK_STRIP = (
        "帮我", "请", "给我", "我", "来", "出", "做", "写", "生成", "整",
        "一个", "一篇", "一段", "一条", "一期", "一则", "这个", "那个", "那篇",
        "条", "份", "个", "篇", "的", "关于", "讲讲", "财税", "税务",
        "工商", "老板", "企业", "内容", "视频", "脚本", "文案", "口播",
        "图文", "选题", "公众号", "文章", "稿", "出片", "成片", "小红书", "推文",
    )

    def _is_blank_produce_request(self, msg):
        """用户说'要写内容'但没给主题 → 应当自然反问方向，而不是甩模板或堆两问。

        判定：去掉动作词 + 内容类型词 + 常见域修饰词（出个/财税/老板…）后，
        若什么都不剩，才算"没给主题"。这样"给我写一篇XX的公众号文章"会留下
        XX 不被误判，而"出个财税视频脚本"会被正确识别为空白请求。
        """
        m = (msg or "").strip()
        if not m:
            return False
        # ★动作词保护（2026-09-16）：「下一步」卡片按钮发送的是「用+能力名」（如"用生成视频"），
        # 这类含 出片/生成视频/做成片/配音/质检/发布 的消息是生产链动作指令，绝不能被空白写稿
        # 反问劫持（"用生成视频"剥掉"生成/视频"只剩一个"用"字→被误判成"没给主题"→答非所问）。
        # 直接放行，交回能力路由 / _detect_pipeline 处理。
        for _words in self._ACTION_KEYWORDS.values():
            if any(w in m for w in _words):
                return False
        if not any(w in m for w in self._BLANK_PRODUCE_WORDS):
            return False
        left = m
        for w in self._BLANK_STRIP:
            left = left.replace(w, "")
        left = left.strip(" ，。！？、:：\"'\u3000")
        return len(left) < 2

    # 用户抱怨/求助："没反应/卡了/不动/问细些才回"——这种话要先接住，避免被误判成能力触发
    _COMPLAINT_WORDS = ("没反应", "没响应", "不动了", "卡了", "卡住了", "问细些", "问细一点",
                        "怎么不回", "不回我", "没动静", "没声了", "点不动", "没反应了", "又不反应",
                        "又问", "还要问", "还要我说", "没听懂", "听不懂")

    def _looks_like_complaint(self, msg):
        m = str(msg or "").strip()
        if not m:
            return False
        return any(w in m for w in self._COMPLAINT_WORDS)

    # —— 状态问句识别：用户是在"问某事做完没"（X写了吗 / X出了没 / X做好了没），不是在下达指令 ——
    #   这是"像人一样对话"最关键的一环：人听到"今天的文章写了吗"会先回答"写了/没写"，
    #   而不是把它理解成"现在去写一篇"。之前引擎因为有"公众号文章"关键词就误触发成写稿指令，答非所问。
    _STATUS_VERBS = ("写", "做", "出", "搞", "弄", "完", "生成", "排", "剪", "录", "配",
                     "拍", "渲染", "发布", "发", "生产", "交付", "上线", "准备")
    # 强状态句式：动作动词 + (了/好/完/出来) + (吗/没)，如"写了吗""出完了没""准备好了没"
    _STATUS_STRONG = re.compile(
        r"(?:写|做|出|搞|弄|完|生成|排|剪|录|配|拍|渲染|发布|发|生产|交付|上线|准备)"
        r"(?:好了?|完了?|成了?|出来了?|了没|了吗|了没有|好没|成没|出来没)", re.U)
    # 弱状态句式：有没有/是不是 + 动作动词（+了/好/完）
    _STATUS_WEAK = re.compile(
        r"有没有.*(?:写|做|出|搞|弄|生成|排|剪|录|配|拍|渲染|发布|发|生产|交付|上线)"
        r"|是不是.*(?:写|做|出|搞|弄|生成|排|剪|录|配|拍|渲染|发布|发|生产|交付|上线).*(?:了|好|完)",
        re.U)

    def _is_status_inquiry(self, msg):
        m = (msg or "").strip()
        if not m or len(m) > 60:   # 太长基本是正文/段落，不是一句状态问话
            return False
        if not re.search(r"[吗沒没呢？\?]", m):
            return False
        if not re.search(r"(?:写|做|出|搞|弄|完|生成|排|剪|录|配|拍|渲染|发布|发|生产|交付|上线|准备)", m):
            return False
        if self._STATUS_STRONG.search(m):
            return True
        if self._STATUS_WEAK.search(m):
            return True
        return False

    _STATUS_OBJ_MAP = (
        (("公众号", "文章", "推文", "微信"), "article"),
        (("小红书", "笔记", "种草"), "xhs"),
        (("视频", "短片", "片子", "口播视频", "数字人", "图解", "漫画", "白板", "滚动", "头像", "成片"), "video"),
        (("口播", "稿", "脚本", "逐字稿", "文案"), "script"),
        (("选题", "角度", "规划", "排期"), "propose"),
    )

    def _status_object(self, m):
        for kws, obj in self._STATUS_OBJ_MAP:
            if any(w in m for w in kws):
                return obj
        return "generic"

    # ---- 模型驱动的"自然回复"生成（状态问/重复问的话术不再写死，交由模型） ----
    def _gen_reply(self, instruction, facts="", timeout=45):
        """单次模型调用，生成一段自然的对话回复。

        代码只负责给出【真实事实】(facts) 和【本轮任务】(instruction)，
        具体怎么说得自然、口语、不套模板，全部交给模型，杜绝写死中文分支。
        返回纯文本；模型失败/为空时返回空串（由调用方兜底）。
        """
        cfg = self._cfg()
        prompt = (
            MASTER_PROMPT + "\n\n"
            "你是'对话出稿工作台'的财税顾问助手。说话要像真人顾问：口语化、讲人话、不堆术语、"
            "不套模板、不反问用户'拍给谁看'。你只负责把回复说自然、说准确；所有事实依据都已由系统查好"
            "写在下面，不要编造没发生的事，也不要自行启动任何写稿/出片动作。\n"
        )
        if facts:
            prompt += "【当前真实状态（据此如实回答，不要夸大或虚构）】\n" + facts + "\n\n"
        prompt += (
            "【本轮任务】\n" + instruction + "\n\n"
            "只输出回复正文，不要代码块、不要 JSON、不要前缀标签，一两句即可。"
        )
        try:
            ans = self._chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=timeout)
            if isinstance(ans, dict):
                ans = ans.get("content") or ""
            ans = (ans or "").strip()
            if ans.startswith("```"):
                ans = ans.strip("`")
                if ans[:4].lower() == "json":
                    ans = ans[4:]
                ans = ans.strip()
            return ans
        except Exception:  # noqa: BLE001
            return ""

    def _gather_facts(self, s):
        """代码查真实状态，喂给模型当事实依据（不掺杂话术）。"""
        facts = []
        written = s.get("written") or []
        if written:
            facts.append("本空间今天已经累计成稿 %d 篇口播稿。" % len(written))
        else:
            facts.append("本空间今天还没有成稿。")
        lar = s.get("last_action_ready") or {}
        if lar.get("cap_id") and time.time() - lar.get("ts", 0) < 600:
            cap_name = (_CAP.get(lar["cap_id"]) or {}).get("name", lar["cap_id"]) if _CAP else lar["cap_id"]
            facts.append("最近已经为用户准备好一张「%s」的执行卡片，参数已齐，点开始就能生成。" % cap_name)
        return "\n".join(facts) if facts else "（暂无已产出的内容）"

    def _do_status_inquiry(self, s, message):
        """自然回答"X 做了没"：模型驱动，如实说状态 + 给出下一步，绝不误开能力卡片。"""
        m = str(message or "").strip()
        obj = self._status_object(m)
        label = {"article": "公众号文章", "xhs": "小红书图文", "video": "视频",
                 "script": "口播稿", "propose": "选题/排期"}.get(obj, "内容")
        facts = self._gather_facts(s)
        repeat_note = ""
        if s.get("_is_repeat"):
            repeat_note = "用户刚才已经问过一模一样的问题，你可以温和地提一句'刚说过'，但结论不变，依旧如实回答。\n"
        instruction = (
            "用户问：'%s'（他在问%s这件事做到没）。\n" % (m, label)
            + repeat_note
            + "请如实说明现在的状态（做了/没做/做了多少），然后自然地问问用户要不要现在就做这件事；"
            + "如果他问的对象已经有准备好的执行卡片，提醒他直接点卡片上的「开始执行」即可。保持一句到两句，口语、干脆。"
        )
        ans = self._gen_reply(instruction, facts, timeout=45)
        if not ans:
            # 兜底：模型失败时给一句事实陈述，绝不空白
            ans = "今天这个空间里%s还没做呢。要现在做吗？把主题和给谁看告诉我，我马上来。" % label
        self._chat_log(s.get("id"), "OUT | status-inquiry(model) | obj=%s" % obj)
        return {"stage": "answer", "message": ans}


    # 求知/问句特征：问"政策/流程/风险/怎么办/怎么/是否/能不能/多少钱/要不要/行不行"类
    _Q_WORDS = ("吗", "么", "？", "?", "怎么", "如何", "是否", "能不能", "可不可以", "行不行",
                "要不要", "是什么", "有哪些", "有什么", "什么", "区别", "流程", "步骤", "多少钱",
                "合规", "风险", "政策", "规定", "处罚", "怎么办", "如何处理", "怎样", "为啥", "为什么")
    # 明确"要内容"的信号词：命中则不判为纯提问（避免"写条讲XX政策的口播"被拦成问答）
    _PRODUCE_HINT = ("口播", "脚本", "文案", "视频", "图文", "选题", "拆角度", "出稿", "写一条", "写个", "写成")

    def _looks_like_question(self, msg):
        """兜底：这条消息是否更像"在问事情"而不是"让干活"。问句/求知词命中且无"要内容"信号 → True。"""
        m = (msg or "").strip()
        if not m:
            return False
        # 明确要内容（写口播/文案/拆角度）→ 不是纯问答
        if any(w in m for w in self._PRODUCE_HINT):
            return False
        # 本空间正在出稿流程中、用户在推进流程（如"继续""下一条""这篇呢"）→ 不拦
        if self._is_produce_cmd(m):
            return False
        q_hits = sum(1 for w in self._Q_WORDS if w in m)
        # 问号或 ≥1 个求知词命中即视为提问
        return q_hits >= 1 or ("？" in m) or ("?" in m)

    # 涉及政策/时效/外部事实的关键词 → 回答前联网核对，避免讲错已变的口径
    _SEARCH_NEED_WORDS = ("政策", "规定", "税率", "免税", "减税", "新政", "截止", "有效期", "2025",
                          "2026", "最新", "是否适用", "现行", "细则", "公告", "条例", "办法", "优惠")
    def _answer_needs_search(self, msg):
        return any(w in msg for w in self._SEARCH_NEED_WORDS)

    # ---- 本地意图兜底：LLM 解析失败时纯规则判定，杜绝"说啥都没反应" ----
    def _local_intent_fallback(self, s, message):
        """LLM 解析不到意图时的硬规则兜底（不依赖大模型）。

        返回：'write'（进写稿流程）/ 'ask_topic'（连主题都没有要问）/
              'answer'（像在问问题）/ None（真判断不出，交回默认 ask）。
        """
        import re
        m = str(message or "").strip()
        # 出稿类信号：明确写稿词，或含 第N条/写/稿/角度/拆 等
        if self._is_produce_cmd(m) or any(w in m for w in
                                         ("写", "稿", "出", "角度", "第", "条", "篇",
                                          "做一批", "来几个", "拆", "全写", "都写")):
            # 若这句明确命中某个平台能力（如"公众号文章"），让 _match_capability 调度能力卡片，
            # 不要兜底成普通写稿，避免"有时走能力、有时走普通稿"的体验分裂。
            cap_hit = self._match_capability(m)
            if cap_hit:
                return None
            if s.get("topic"):
                return "write"
            # ★LLM 失败时仍尝试从请求句里抓主题，避免明确指令被反问"先讲主题"。
            t = self._extract_topic_from_msg(m)
            if t and len(t) >= 2:
                s["topic"] = t
                return "write"
            return "ask_topic"
        # 纯问题（问句/求知词）→ 正面回答
        if self._looks_like_question(m):
            return "answer"
        return None

    def _pick_from_msg(self, message):
        """从'写第N条/全写/继续'这类话里解析出 pick 值（下标/ 'all' / 'next'）。"""
        import re
        m = str(message or "").strip()
        if any(w in m for w in ("全写", "都写", "全部", "all", "all写")):
            return "all"
        if any(w in m for w in ("继续", "下一条", "下一篇", "再写", "接着", "next", "第几", "下一个")):
            return "next"
        mm = re.search(r"第\s*(\d+)\s*[条个篇集]", m)
        if mm:
            return max(0, int(mm.group(1)) - 1)
        # 含"写"但没说哪条 → 默认接着写未完成的
        if "写" in m or "稿" in m:
            return "next"
        return "next"

    # ---- 从用户请求句里清洗出真实主题（避免"给我写一篇XX的公众号文章"把整句当主题） ----
    # 旧正则用非贪婪捕获，会把"微信公众号文章"拆成主题="微信"+后缀"公众号文章"，
    # 导致用户只说了能力名、没给主题时，把"微信"当主题硬填进去。新方案只切掉动作前缀，
    # 主题用 noise 清洗，"写一篇微信公众号文章"正确返回空，"写一篇关于公转私的公众号文章"正确返回"公转私"。
    _TOPIC_PREFIX_PAT = re.compile(
        r"^(?:帮我|请给我|请|给我|给|来)?(?:我)?"
        r"(?:写个|做个|来个|来一个|写一篇|做一篇|写|做|来|生成|整)"
        r"(?:一篇|一个|一段|一条|一期|一则)?[的]?",
        re.UNICODE,
    )

    @staticmethod
    def _jaccard(a, b):
        """简单 Jaccard 相似度：用于判断用户是否重复发送同一请求。"""
        sa = set(a)
        sb = set(b)
        if not sa and not sb:
            return 1.0
        inter = len(sa & sb)
        union = len(sa | sb)
        return inter / union if union else 0.0

    # 主题提取后若只剩这些词，说明用户其实没给主题，只是触发了能力名
    _TOPIC_RESIDUE_WORDS = {
        "微信", "公众", "公众号", "小红书", "图文", "笔记", "视频", "文章",
        "稿", "文案", "推文", "脚本", "方案", "内容", "个", "的", "关于",
    }

    def _extract_topic_from_msg(self, message):
        """从'给我写一篇XX的公众号文章'里提取出'XX'；没提取到（或只剩能力残留词）返回空字符串。"""
        m = str(message or "").strip()
        if not m:
            return ""
        # 1. 切掉动作前缀（"请给我写一篇""帮我写个"等）
        m = self._TOPIC_PREFIX_PAT.sub("", m)
        # 2. 去掉结尾的内容类型后缀（按长度降序，避免"微信公众号文章"被拆成"微信"）
        content_suffixes = (
            "微信公众号文章", "公众号文章", "公众号推文", "公众号文案", "公众号",
            "微信文章", "微信", "小红书图文", "小红书笔记", "小红书",
            "口播稿", "口播", "图文", "视频", "文章", "稿", "文案", "推文", "脚本", "方案", "内容",
        )
        t = m
        for suf in content_suffixes:
            if t.endswith(suf):
                t = t[:-len(suf)]
                break
        # 3. 去掉常见介词/量词前缀（保留"个"，避免误伤"个人卡"这类主题；量词已被前缀吃掉）
        t = re.sub(r"^(?:的|关于|讲讲|一下|一个|一篇|这个|那个)\s*", "", t)
        # 4. 清理首尾标点和末尾"的"
        t = t.rstrip("的 ")
        t = t.strip(" ，。！？、:：\"'\u3000")
        # 5. 过滤：空/单字/只剩能力残留词 → 认为没给主题
        if not t or len(t) < 2:
            return ""
        stripped = t
        for w in sorted(self._TOPIC_RESIDUE_WORDS, key=len, reverse=True):
            stripped = stripped.replace(w, "")
        if not stripped.strip():
            return ""
        return t

    # ---- 受众推断：话题往往自带受众，能推理出来就不该反问用户 ----
    # 关键词 → (受众, 一句话理由)。按话题阶段匹配：注册/成立/创业 → 准备中的创业者。
    _AUDIENCE_RULES = (
        # 注册/个体户/公司选择/注册资本/新办 —— 还在"要不要开、怎么开"阶段
        (("注册公司", "注册个体户", "公司还是个体户", "个体户还是公司", "注册什么", "注册资本",
          "注册资金", "认缴", "实缴", "新办", "新注册", "刚注册", "要不要注册", "想开公司",
          "想创业", "准备创业", "第一次开公司", "开办公司", "成立公司", "创业者", "创业初期",
          "还没注册", "没注册", "营业执照", "核名", "经营范围怎么写"),
         "准备注册/刚开始的准创业者（还没注册或刚注册，正在做选择题）"),
        # 经营中/已有企业 —— 已在经营阶段的决策
        (("经营中", "已注册", "在营业", "开票", "进项", "销项", "公转私", "分红", "利润", "成本",
          "费用", "报销", "工资", "社保", "个税申报", "企业所得税汇算"),
         "已注册、正在经营的中小老板"),
        # 建筑行业专有
        (("挂靠", "建筑", "工程", "农民工", "甲供", "异地预缴", "分包", "总包", "清包工"),
         "建筑行业的中小老板/包工头"),
        # 电商
        (("电商", "淘宝", "抖音小店", "拼多多", "直播卖货", "网店"),
         "电商卖家（多为个体户/小公司老板）"),
        # 高净值/股权
        (("股权", "减持", "合伙", "对赌", "并购", "融资", "股权架构", "上市"),
         "已有规模、涉及股权架构的企业主"),
        # 财税从业者/会计
        (("会计", "财务人员", "代账", "做账", "报税"), "中小企业财务/会计人员"),
    )

    def _infer_audience(self, s, message):
        """根据会话主题+历史+本条消息推断目标受众；推不出返回 None（才需要问）。"""
        ctx = " ".join([
            str(s.get("topic") or ""),
            str(s.get("audience") or ""),
            str(s.get("requirement") or ""),
            str(message or ""),
            *[str(m.get("payload", {}).get("content", ""))[:60] for m in (s.get("messages") or [])[-4:]
              if m.get("role") == "user"],
        ])
        for words, aud in self._AUDIENCE_RULES:
            if any(w in ctx for w in words):
                return aud
        return None

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
        s["_last_norm"] = self._norm_msg(message)
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
        # 记录"最近回答"到会话缓存，供重复提问时复用(记忆) + 提示
        if result.get("stage") == "answer" and result.get("message"):
            cache = s.setdefault("_answered_cache", {})
            clean = result["message"]
            if s.get("_last_norm"):
                cache[s["_last_norm"]] = clean
                if len(cache) > 12:
                    for k in list(cache.keys())[:len(cache) - 12]:
                        cache.pop(k, None)
        s["_is_repeat"] = False
        self._save(s)                # 落盘：重启/超时都不丢
        return result

    # ---- 生产链动作引导（配音/出片/质检/发布 的"对话到确认点"衔接）----
    # 说明：出稿后用户说"配音/做成片/去发布"，编排器不越权自动执行长任务，
    # 而是给出可点的动作清单（stage=pipeline, actions[]），由前端渲染按钮引导到既有生产页。
    _ACTION_KEYWORDS = {
        "voice":  ["配音", "语音", "合成声音", "出音频", "生成声音", "去配音"],
        # ★"成片/出成品/做个视频"等也归到 render 动作词里，由对话编排器统一控制流程，
        # 避免绕过"写稿→改稿→确认"直接进能力卡片。
        "render": ["出片", "做成片", "生成视频", "做视频", "渲染", "数字人", "出视频", "去出片",
                   "成片", "出成品", "做个视频"],
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

        # ★【出片流程硬规则】必须按"选题→写口播稿→改稿（字数/时长/表述）→确认→出片"走，
        # 绝不允许没稿就直接弹 video_render 卡片，更不允许把用户的随口话当成脚本。
        if hits.get("render"):
            if not written:
                if not s.get("topic"):
                    s["awaiting_topic_for_write"] = True
                    self._chat_log(s.get("id"), "OUT | render-without-topic -> ask topic first")
                    return {
                        "stage": "ask",
                        "message": "好，出片前先写口播稿。告诉我这条视频讲什么主题，我出完稿后你改字数、时长、表述都可以，确认后再出片。",
                    }
                # 有主题但没稿：直接拆角度，进入"选题→写稿"流程；写完后才给出片确认
                self._chat_log(s.get("id"), "OUT | render-with-topic -> propose angles for video")
                return self._do_propose(s)
            # 有稿：先展示口播稿，让用户确认是否改字数/时长/表述，满意了再点做成片
            last = written[-1]
            s["_await_video_confirm"] = True
            # ★已出过片（2026-09-16）：不再假装"稿子刚准备好"，如实告知成片在右侧产物区，
            # 把 成片质检/发布素材包 推到前面，重出一条放最后（本会话出过片才有 last_job_id）。
            if s.get("last_job_id"):
                self._chat_log(s.get("id"), "OUT | render-with-script+rendered -> point artifact & qc/publish")
                return {
                    "stage": "written",
                    "message": "这条稿子已经出过片了，成片就在右侧「产物」区，点开就能播放或下载。接下来最顺的是给成片做个质检（黑屏/没声音/字幕压字），或者直接打包发布素材；刚改过稿想重出一条的话，满意了点「做成片」。",
                    "results": [last],
                    "next": [
                        {"id": "qc_video", "name": "成片质检", "icon": "🔬", "cmd": "成片质检"},
                        {"id": "publish_pack", "name": "发布素材包", "icon": "📦", "cmd": "发布素材包"},
                        {"id": "video_render", "name": "做成片", "icon": "🎬", "cmd": "做成片"},
                    ],
                }
            self._chat_log(s.get("id"), "OUT | render-with-script -> show script & ask modify/confirm")
            return {
                "stage": "written",
                "message": "口播稿已准备好。你看看要不要调整——改字数、改时长、改表述都可以；满意了再点「做成片」出视频。",
                "results": [last],
                "next": [
                    {"id": "video_render", "name": "做成片", "icon": "🎬", "cmd": "做成片"},
                    {"id": "rewrite", "name": "二创改写", "icon": "✍️", "cmd": "二创改写"},
                    {"id": "qc", "name": "文案质检", "icon": "🛡️", "cmd": "质检"},
                ],
            }

        if not written and not angles:
            return {
                "stage": "ask",
                "message": "还没有成稿可以进入下一步。先把主题、受众跟我说，出完稿我再帮你接配音/出片。",
            }
        actions = []
        # 配音：只要有稿就能去 scroll 出片页（把稿带去）；会话稿存内存，跨页需经 session_id
        if hits.get("voice"):
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
    # ★注意：video_render（生成视频/出片）不在关键词列表里——它必须从"选题→写口播稿→改稿→确认"
    # 的完整对话流程中触发，绝不能在聊天里被一句"生成视频"直接唤起，否则会把用户随口一句话当成脚本，
    # 出现"没有口播稿内容就开始生成视频"的荒唐结果。
    _CAP_KEYWORDS = {
        "topic": ("选题", "给我选题", "出选题", "选几个题", "想几个选题", "找选题"),
        "hotspot": ("热点选题", "追热点", "热点话题", "最近热点"),
        # ★"改成"裸词严禁入表：用户说"滞纳金改成迟纳金""个税改成季报"是在陈述主题(A改成B)，
        # 不是要把稿子改成自己的口径。真改写说法必须带"我的/口径/风格/重写成"等稿件语境。
        "rewrite": ("二创", "改写", "改编", "改成我的", "改成我的口径", "改成我的风格", "爆改", "重写成"),
        "article": ("公众号文章", "公众号长文", "公众号推文", "公众号文案", "篇公众号",
                    "写成文章", "发公众号", "推文", "写篇长文", "篇长文", "公众号"),
        "strategist": ("获客军师", "选题打分", "评估选题", "这个选题值不值",
                       "能不能带来客户", "钩子建议", "获客潜力"),
        "qc": ("质检", "违禁词", "检查违禁", "审一下", "能不能发", "查敏感"),
        "qc_video": ("成片质检", "视频质检", "检查成片"),
        "publish_pack": ("发布包", "素材包", "打包发布", "发布素材"),
        "xhs": ("小红书", "图文笔记", "小红书图文"),
        "dissect": ("拆解", "爆款拆解", "拆一下"),
        "footage_edit": ("素材剪辑", "剪辑素材", "实拍素材", "剪停顿", "删停顿", "加字幕", "实拍出片", "剪这段素材"),
        "clone_voice": ("声音克隆", "克隆音色", "克隆我的声音"),
        # —— v2.0 P2 留资 / P3 增值（CRM/1v1/AI客服/矩阵/看板）2026-09-18 已随
        #    capabilities.py 从能力表彻底移除、暂不开发；用户问到走 _match_hidden_capability
        #    的"能力诚实层"如实说明，不再作为可触发能力。
    }

    # 出稿链路专属词：命中说明用户是在聊"写稿"，不要误触发能力
    _CAP_EXCLUDE = ("角度", "口播稿", "成稿", "写稿", "出稿", "逐字稿", "标题怎么")

    # —— 对话纠偏：工作范围 + 能力诚实边界 ——
    # 已规划但本期未接通的能力：用户要时用 _do_cap_unavailable 如实说明 + 给替代
    # 未开发能力的中文名（已从能力表移除，get() 拿不到了，这里兜底）
    _HIDDEN_CAP_NAME = {
        "matrix_publish": "矩阵分发·多平台",
        "crm_record": "客户画像·CRM",
        "consult_1v1": "专家 1v1 视频诊断",
        "auto_reception": "AI 客服自动接待",
        "data_dashboard": "爆款数据看板",
        "advisor_chat": "AI 财税顾问·7×24",
    }
    _HIDDEN_CAP_WORDS = {
        "matrix_publish": ("矩阵分发", "矩阵群发", "多平台群发", "一键多发", "同时发到", "多平台分发"),
        "crm_record": ("客户档案", "crm", "客户crm", "客户画像系统", "线索档案", "客户线索"),
        "consult_1v1": ("1v1", "一对一诊断", "视频诊断", "抢约诊断", "预约诊断", "诊断预约"),
        "auto_reception": ("自动接待", "ai客服", "ai 客服", "自动回复客户", "私信自动", "客服接待"),
        "data_dashboard": ("数据看板", "爆款看板", "数据排名", "哪个最爆", "运营数据看板"),
        "advisor_chat": ("7x24顾问", "ai财税顾问", "智能顾问", "财税顾问机器人", "顾问在吗"),
    }
    _HIDDEN_CAP_ALT = {
        "matrix_publish": "多平台矩阵群发这个接口我这边还没接通，暂时做不到自动发。不过我可以先帮你把内容出好、配齐「发布素材包」（封面+标题+文案），你手动分发到各平台就行。",
        "crm_record": "客户 CRM 档案这块我还没接上系统，暂时登记不了。不过你可以把线索信息告诉我，我先帮你记在这段对话里；或者我先把获客内容做好，线索来了自然有入口。",
        "consult_1v1": "1v1 视频诊断预约这套流程我还没接通，暂时约不了。但你有什么财税问题直接问我，我以顾问身份先帮你分析；真要深度诊断的，可以走线下。",
        "auto_reception": "AI 客服自动接待我还没接上，暂时做不到自动回私信。但你能把常见客户问题告诉我，我帮你拟一套问答话术 / 自动回复草稿。",
        "data_dashboard": "爆款数据看板我还没接上数据回流，暂时出不了排名。不过你可以把已发内容的数据告诉我，我帮你人工分析哪条值得复盘。",
        "advisor_chat": "7x24 AI 财税顾问这个独立模块我还没接进对话，但你现在的任何财税问题我都能直接以顾问身份回答，效果一样。",
    }
    # 超出工作范围的落地服务类（精确命中，且必须是'要办'而非'在问'）
    _SERVICE_OUT_WORDS = ("帮我报税", "帮我报个税", "代理记账", "帮我做账", "帮我记账",
                          "代办注册公司", "代办注册", "帮我写合同", "代写合同", "法律诉讼",
                          "打官司", "帮我注销公司", "代办注销公司", "代办注销")

    def _match_capability(self, message):
        """关键词快匹配能力 id，命中不了返回 None（交给 LLM 判定）。"""
        if not _CAP or not message:
            return None
        m = str(message).strip()
        low = m.lower()
        # ★特例：用户有逐字稿/口播稿/原文/文案/脚本，并要求改写/改编/二创/改成自己口径时，
        # 必须进 rewrite 能力，不要被 _CAP_EXCLUDE 里的"逐字稿/口播稿"误拦截成"写新稿"。
        rewrite_words = self._CAP_KEYWORDS.get("rewrite", ())
        script_indicators = ("逐字稿", "口播稿", "原文", "稿子", "文案", "脚本", "来稿", "这篇稿")
        has_rewrite = any(w in m for w in rewrite_words)
        has_script = any(w in m for w in script_indicators)
        if has_rewrite and has_script:
            return "rewrite"
        # 「出片」等词若与出稿词共现（如"出稿后出片"），优先算能力；只在纯出稿语境排除
        if any(w in m for w in self._CAP_EXCLUDE) and not any(
                w in low for w in ("出片", "生成视频", "做成视频", "质检", "打包", "小红书")):
            return None
        # ★成片质检优先（2026-09-16）："对刚成片做质检/检查一下这个视频有没有问题"同时带
        # "片/视频"和 质检/检查 语境，必须路由到 qc_video——qc 的裸"质检"在词表里排前面会把
        # 它抢走，用户拿到的是稿子质检报告而不是成片体检（真机踩坑：用户原话"对刚成片做质检"被误路由到 qc）。
        if ("质检" in m or "检查" in m or "审一下" in m) and any(
                w in m for w in ("成片", "视频", "片子")):
            return "qc_video"
        for cid, words in self._CAP_KEYWORDS.items():
            if _CAP.is_hidden(cid):      # 本期隐藏的能力不参与关键词路由
                continue
            for w in words:
                if w in low:
                    return cid
        return None

    # —— 对话纠偏·关键词护栏（LLM 之外的硬兜底，保证不瞎答应）——
    def _match_hidden_capability(self, message):
        """命中已规划未接通的能力词 → 返回其 id（交给 _do_cap_unavailable 如实说明）。"""
        m = str(message or "").strip().lower()
        for cid, words in self._HIDDEN_CAP_WORDS.items():
            if any(w in m for w in words):
                return cid
        return None

    def _is_moment_request(self, message):
        """用户要朋友圈文案（不是视频）→ 走 _do_moment 轻量生成。"""
        m = str(message or "").strip()
        if "朋友圈" not in m:
            return False
        if "视频" in m:        # 朋友圈能发的短视频 → 走视频链路，不算纯文案
            return False
        return any(w in m for w in ("文案", "写", "发", "配", "帮我", "来一条", "一条", "段", "句", "内容"))

    def _out_of_scope_guard(self, message):
        """精确命中'落地服务'祈使句（报税/做账/注册落地/代写合同/打官司）→ 超出范围。
        带疑问/讨论词（吗/怎么/讲讲/风险/坑…）的视为在问，交问答，不拦。"""
        m = str(message or "").strip()
        if not m:
            return False
        if any(w in m for w in ("吗", "？", "?", "怎么", "为什么", "啥", "讲讲", "说说",
                                "区别", "风险", "坑", "流程", "步骤", "要点")):
            return False
        for w in self._SERVICE_OUT_WORDS:
            if w in m:
                return True
        return False

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

    # —— 贴稿改写：用户贴一段成稿/长文并要求改写/修改/润色/调整/改口语 → 直接改写，绝不问主题 ——
    # ★09-16 修：用户用「修改/润色/调整/改口语化」等动词（不在 rewrite 词表），引擎原会把它当
    #   「写新稿」需求缺主题反问。这里单独识别「贴了长成稿 + 改写类动词」，直接调 _ai_rewrite 出稿。
    _REWRITE_PHRASES = (
        "帮我改写", "请改写", "帮我改编", "请改编", "二创改写", "重新修改", "请修改", "修改一下",
        "改一下", "改写一下", "润色一下", "请润色", "优化一下", "改口语化", "更口语化",
        "口语化一点", "改得更口语", "顺一下", "调整一下", "重新写", "重写一篇", "重新生成",
        "改成我的口径", "改成我的风格", "改成我的", "改我的口吻", "像专家", "像财税专家",
        "改得口语", "重新改写", "再改改", "帮我改", "请帮我改", "把这段改",
    )
    _POLITE_PREFIX = ("请", "帮我", "麻烦你", "麻烦", "我想", "我要", "可以", "能")

    def _looks_like_draft(self, text):
        """粗略判断一段文字像不像成稿（口播稿/逐字稿）。"""
        t = (text or "").strip()
        if len(t) < 80:
            return False
        if '【' in t and '】' in t:
            return True
        if t.count('。') + t.count('！') + t.count('？') >= 3:
            return True
        if any(k in t for k in ('钩子', '正文', '口播', '逐字稿')):
            return True
        return False

    def _clean_req(self, a):
        a = (a or "").strip().strip("：:").strip()
        for p in self._POLITE_PREFIX:
            if a.startswith(p):
                a = a[len(p):].strip()
        return a.strip(" ，。！？、:：\"' \n")

    def _split_pasted_source(self, message):
        """从消息拆出『被改写的原文』和『改写要求』。原文=最长且像成稿的块；其余短句=要求。"""
        m = (message or "").strip().strip('"').strip("'").strip()
        if not m:
            return "", ""
        # 1) 换行分隔：指令在首行或末行（短），稿在长块。优先于冒号，避免稿内自带冒号被误切。
        lines = [l for l in m.split("\n") if l.strip()]
        if len(lines) >= 2:
            if len(lines[0].strip()) < 40 and self._looks_like_draft("\n".join(lines[1:])):
                return "\n".join(lines[1:]).strip(), self._clean_req(lines[0])
            if len(lines[-1].strip()) < 40 and self._looks_like_draft("\n".join(lines[:-1])):
                return "\n".join(lines[:-1]).strip(), self._clean_req(lines[-1])
        # 2) 冒号分隔：仅当短边(<40字)像指令、长边像稿（稿内自带冒号不误切）
        for sep in ('：', ':'):
            if sep in m:
                a, b = m.split(sep, 1)
                if len(a.strip()) < 40 and len(b) >= 80 and self._looks_like_draft(b):
                    return b.strip(), self._clean_req(a)
                if len(b.strip()) < 40 and len(a) >= 80 and self._looks_like_draft(a):
                    return a.strip(), self._clean_req(b)
        # 3) 无分隔符：去掉改写类短语，剩余长块当原文
        rest = m
        req = ""
        for p in self._REWRITE_PHRASES:
            if p in rest:
                rest = rest.replace(p, "")
                if len(p) > len(req):
                    req = p
        rest = rest.strip(" ，。！？、:：\"' \n")
        if len(rest) >= 80 and self._looks_like_draft(rest):
            return rest, (self._clean_req(req) if req else "")
        # 4) 兜底：整段够长就当原文（这种情况通常用户没带改写动词，调用方会再判）
        if len(m) >= 120 and self._looks_like_draft(m):
            return m, ""
        return "", ""

    def _is_paste_rewrite(self, message):
        """用户贴了一段成稿/长文，并表达要改写/修改/润色/调整/改口语等 → True。"""
        m = (message or "").strip()
        if len(m) < 80:
            return False
        src, _ = self._split_pasted_source(m)
        if not src:
            return False
        return any(p in m for p in self._REWRITE_PHRASES)

    def _do_rewrite_pasted(self, s, message):
        """贴稿改写：直接按用户要求改写所贴文本，绝不问主题、不弹确认卡。"""
        src, req = self._split_pasted_source(str(message))
        if not src:
            return None
        sid = s.get("id") or ""
        if sid:
            self._set_progress(sid, "writing", "正在按你的要求改写这段口播稿…")
        source = src
        if req:
            source += "\n【用户本次修改要求】" + req
        try:
            res = self._ai_rewrite(source, "script", focus=(req or None))
        except Exception as e:  # noqa: BLE001
            traceback.print_exc()
            res = {"error": str(e)}
        rewritten = ""
        if isinstance(res, dict):
            rewritten = res.get("rewritten") or (res.get("ok") and (res.get("text") or "")) or res.get("script") or ""
        if not rewritten:
            err = res.get("error") if isinstance(res, dict) else None
            return {"stage": "error",
                    "error": (err or "改写服务没有返回内容，可能是模型超时。请刷新页面后重试，或换个说法再发一次。"),
                    "session_id": sid}
        entry = {"title": "改写稿", "angle": "", "script": rewritten, "is_paste_rewrite": True}
        _wl = s.setdefault("written", [])
        entry["widx"] = len(_wl)
        _wl.append(entry)
        s["last_text"] = src  # 存源稿，便于后续「二创改写」能力从 last_text 取原文
        s["history"].append("改写贴稿")
        s["_await_video_confirm"] = True
        s["pending_cap"] = None  # 清掉待填卡，避免后续消息被吞
        return {
            "stage": "written",
            "results": [entry],
            "message": "已按你的要求把这段口播稿改好了——保留你的三个观点，表述更口语、更像专家聊观点、结尾邀大家讨论。看看满不满意，要再调直接说；满意就点「做成片」。",
            "next": [
                {"id": "video_render", "name": "做成片", "icon": "🎬", "cmd": "做成片"},
                {"id": "rewrite", "name": "二创改写", "icon": "✍️", "cmd": "二创改写"},
                {"id": "qc", "name": "文案质检", "icon": "🛡️", "cmd": "质检"},
            ],
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
                # 优先填 text 类参数，其次才 textarea；且必须跳过 no_autofill 的。
                # 原因：不区分类型时，随口说的主题（如"关于公转私"）会被误填进靠前的
                # textarea 参数（如"参考源稿"），导致后续生成方向整个跑偏。
                target = None
                for _t in ("text", "textarea"):
                    for p in miss:
                        if p.get("type") == _t and not p.get("no_autofill"):
                            target = p
                            break
                    if target:
                        break
                if target:
                    # 主题/标题/关键词类参数必须做清洗，避免"给我写一篇XXX的公众号文章"
                    # 把整个脏句填进去（如"给我写一篇个人卡收款严重性的"）。
                    if target["key"] in ("topic", "kw_main", "title", "keywords", "industry"):
                        extracted = self._extract_topic_from_msg(rest)
                        if extracted:
                            vals[target["key"]] = extracted
                        # 没提取到真实主题：不填，让 missing_params 继续追问
                    else:
                        # ★改写能力 guard：用户说"我有逐字稿，帮我改编"时，去掉关键词后的余句
                        # 只是请求话术，不是真正的原文。除非余句足够长（>=80 字）且像正文，否则不自动填 text。
                        if cid == "rewrite" and target["key"] == "text":
                            _request_tail = ("你可以帮我吗", "我可以", "你能", "请帮我", "帮我一下",
                                               "你可以帮我", "你可以帮我改编", "你可以帮我改写")
                            if len(rest) >= 80 and not any(t in rest for t in _request_tail):
                                vals[target["key"]] = rest
                        else:
                            vals[target["key"]] = rest

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
        # 记录当前能力卡状态，方便识别后续"开始执行/确认"类消息
        s["last_action_ready"] = {"cap_id": cid, "vals": vals, "ts": time.time(),
                                  "msg": str(message or "")[:120]}
        res = {
            "stage": "action_ready",
            "cap": cap_info,
            "vals": vals,
            "message": "参数齐了，点「开始执行」我就去跑**%s**。" % cap["name"],
            "next": _CAP.next_suggestions(cid),
            "tip": "跑完我会告诉你结果，并提示下一步能做什么。",
        }
        self._chat_log(s.get("id"), "OUT | action_ready cap=%s vals_keys=%s" % (cid, ",".join(vals.keys())))
        return res

    def _apply_param_change(self, s, lar, message):
        """能力卡片已 ready 后，用户说"改个字数/风格/地区"等 → 更新 vals 并返回新卡片。"""
        cid = lar.get("cap_id")
        cap = _CAP.get(cid) if _CAP else None
        if not cap:
            return {"changed": False}
        params = cap.get("params") or []
        vals = dict(lar.get("vals") or {})
        changed = False
        reply = []
        m = str(message or "").strip()

        # ---- 硬规则：快速命中常见参数（省一次 LLM，又快又稳） ----
        for p in params:
            key = p["key"]
            typ = p.get("type") or "text"
            opts = p.get("options") or []
            if typ == "select" and opts:
                for opt in opts:
                    opt = str(opt)
                    if ":" in opt:
                        _ov, ol = opt.split(":", 1)
                    else:
                        _ov = ol = opt
                    # 用户说"1500字"要能命中"1500:1500字"
                    if ol in m or opt in m or _ov in m:
                        if vals.get(key) != opt:
                            vals[key] = opt
                            changed = True
                            reply.append("%s改为%s" % (p.get("label", key), ol))
                        break
            elif typ == "text" and key == "region":
                for r in ("苏州", "昆山", "江苏", "上海", "安徽", "浙江", "全国", "本地"):
                    if r in m:
                        if vals.get(key) != r:
                            vals[key] = r
                            changed = True
                            reply.append("地域改为%s" % r)
                        break
            elif typ == "text" and key == "year":
                mm = re.search(r"(20\d{2})年?", m)
                if mm:
                    y = mm.group(1)
                    if vals.get(key) != y:
                        vals[key] = y
                        changed = True
                        reply.append("年份改为%s" % y)
            elif typ == "bool":
                pos = any(w in m for w in ("要", "开", "是", "启用", "打开", "生成后", "true"))
                neg = any(w in m for w in ("不要", "不用", "不需要", "不想", "别", "关", "否", "关闭", "false"))
                # 同时出现或只有否定 → 按否定处理；只有肯定 → 开启
                if pos or neg:
                    v = False if neg else True
                    old = vals.get(key)
                    # None 与 False 视为相同（默认值未显式改动过）
                    if not (old is None and v is False) and old != v:
                        vals[key] = v
                        changed = True
                        reply.append("%s%s" % (p.get("label", key), "开启" if v else "关闭"))
            elif key == "words":
                mm = re.search(r"(\d+)\s*[字kK]", m)
                if mm:
                    n = int(mm.group(1))
                    best = None
                    best_diff = None
                    for opt in opts:
                        opt = str(opt)
                        if ":" not in opt:
                            continue
                        try:
                            on = int(opt.split(":", 1)[0])
                        except Exception:
                            continue
                        diff = abs(on - n)
                        if best_diff is None or diff < best_diff:
                            best_diff = diff
                            best = opt
                    if best and vals.get(key) != best:
                        vals[key] = best
                        changed = True
                        reply.append("字数改为%s" % best.split(":", 1)[1])

        # ---- 硬规则没中 → 用 LLM 轻量解析 ----
        if not changed:
            param_desc = []
            for p in params:
                opts = p.get("options") or []
                opt_txt = ""
                if opts:
                    opt_txt = "，可选：" + " / ".join(str(o).split(":")[-1] for o in opts)
                param_desc.append("- %s（%s%s）当前=%r" % (
                    p["key"], p.get("label", ""), opt_txt, vals.get(p["key"])))
            prompt = (
                "你是对话工作台的参数修改识别器。用户看到一张能力参数卡片后，回复了一句话。"
                "请判断他是否在修改/补充某个参数，并输出 JSON（不要任何其它文字）。\n\n"
                "能力：%s\n当前参数：\n%s\n\n用户回复：%s\n\n"
                "只输出 JSON：\n"
                "{\n"
                '  "changed": true/false,\n'
                '  "changes": [{"key": "参数key", "value": "新值"}],\n'
                '  "reply": "一句话告知用户已改/未改"\n'
                "}\n"
                "规则：\n"
                "1. 只在确实修改某个参数时 changed=true；闲聊/确认/开始执行等无关内容 changed=false。\n"
                    "2. select 类型必须严格使用可选值里完整的一项（如 '1500:1500字'）。\n"
                    "3. bool 类型 value 用 true/false。\n"
                    "4. 不新增 params 列表里没有的 key。\n"
                    "5. reply 用自然口语，不超过 30 字。" % (cap["name"], "\n".join(param_desc), m)
            )
            try:
                cfg = self._cfg()
                raw = self._chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=30)
                if isinstance(raw, dict):
                    raw = raw.get("content") or ""
                parsed = None
                text = (raw or "").strip()
                if text:
                    # 尝试从 ```json ... ``` 中提取
                    if "```" in text:
                        for block in re.findall(r"```(?:json)?\s*([\s\S]*?)```", text):
                            try:
                                parsed = json.loads(block.strip())
                                break
                            except Exception:
                                pass
                    if not parsed:
                        try:
                            parsed = json.loads(text)
                        except Exception:
                            # 找第一个 { ... }
                            mm = re.search(r"\{[\s\S]*\}", text)
                            if mm:
                                try:
                                    parsed = json.loads(mm.group(0))
                                except Exception:
                                    parsed = None
            except Exception:
                parsed = None
            if parsed and parsed.get("changed") and isinstance(parsed.get("changes"), list):
                valid_keys = {pp["key"] for pp in params}
                _has = False
                for ch in parsed["changes"]:
                    k = ch.get("key")
                    v = ch.get("value")
                    if k in valid_keys:
                        vals[k] = v
                        changed = True
                        _has = True
                if _has:
                    reply.append(parsed.get("reply", "已按你的要求调整参数。"))

        if not changed:
            return {"changed": False}

        s["last_action_ready"] = {"cap_id": cid, "vals": vals, "ts": time.time(),
                                  "msg": lar.get("msg", "")}
        return {"changed": True, "vals": vals,
                "reply": "、".join(reply) if reply else "已按你的要求调整参数。"}

    def _do_action_done(self, s, cap_id, ok, data):
        """能力执行完回灌：AI 总结 + 纠偏提醒 + 下一步引导。"""
        if not _CAP:
            return None
        cap = _CAP.get(cap_id) or {}
        # ★记录最近成片 job_id（2026-09-16）：此前 last_job_id 只在 _ctx 里被读、从没人写入，
        # 导致 成片质检/发布素材包 的 job_id 永远带不出来、要用户手填。视频任务回灌自带 job_id。
        if cap_id == "video_render" and ok and isinstance(data, dict) and data.get("job_id"):
            s["last_job_id"] = str(data.get("job_id"))
        nxt = _CAP.next_suggestions(cap_id)
        # ★状态感知引导（2026-09-16）：本会话已出过片时，文案质检完成不再推荐"生成视频"
        # 重复出片，改推 成片质检 → 发布素材包（与 next_suggestions("video_render") 同链）。
        if cap_id == "qc" and s.get("last_job_id"):
            nxt = _CAP.next_suggestions("video_render")
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
            _cfg = self._cfg()
            ans = self._chat(prompt, _cfg["model"], _cfg["key"], _cfg.get("base_url"), timeout=90) \
                if callable(self._cfg) else None
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

    # —— 重复提问识别（记忆提示）——
    # 同一句话再说一遍：①照样答(复用/重算，绝不 busy/空白)；②若之前答过，让模型自然提示"刚答过"并引导新问题。
    # 话术不再写死，交由模型生成；这里只保留一个模型失败时的安全兜底。
    _REPEAT_FALLBACK = "（这句刚才回答过啦，结论不变。还想聊点别的吗？）"

    def _norm_msg(self, msg):
        m = (msg or "").strip().lower()
        m = m.strip("。？?!！，,.~～；;：:、 ")
        m = re.sub(r"\s+", "", m)
        return m

    def check_repeat(self, sid, message):
        """供 server 在 busy 闸门之前调用。
        返回:
          ("answer", text) -> 非状态类重复，复用上次真实答案、由模型自然重述(记忆)，server 同步返回，绝不进 busy/异步
          ("status", None)  -> 状态问句重复，标记后走正常同步流程重算(保证时效)，由 step 的 _do_status_inquiry 模型生成带提示回复
          None              -> 非重复，正常流程
        """
        s = self._get(sid)
        if not s:
            return None
        norm = self._norm_msg(message)
        if not norm:
            return None
        cache = s.get("_answered_cache") or {}
        if norm in cache and cache[norm]:
            if self._is_status_inquiry(message):
                s["_is_repeat"] = True
                return ("status", None)
            # 非状态类重复：用上次真实答案当事实，模型自然重述 + 引导下一步（话术不写死）
            s["_is_repeat"] = True
            facts = "用户又把刚才的问题原样问了一遍。你刚才的回答要点是：\n" + cache[norm]
            instruction = ("用户重复提问，请自然地告诉用户你刚才已经回答过这个问题，把结论再说一遍，"
                          "并顺势引导他聊点别的，或者让你直接动手做点什么（写稿/出片/发文都行）。不要生硬。")
            ans = self._gen_reply(instruction, facts, timeout=45)
            if not ans:
                ans = cache[norm] + self._REPEAT_FALLBACK
            return ("answer", ans)
        return None

    def _step_core(self, s, message):
        sid = s.get("id")
        self._chat_log(sid, f"IN  | {str(message)[:80]}")
        if sid:
            self._set_progress(sid, "thinking", "正在理解你的意图…")
        s["history"].append(f"用户: {message}")
        if len(s["history"]) > 12:
            s["history"] = s["history"][-12:]
        _u = None  # 2026-09-18 反转路由：LLM 主判结果，提前算、下游复用，避免重复调模型（_understand 在"2"块赋值）

        # —— 0) 用户反馈"没反应/卡了/不动"时的紧急引导 ——
        # 排最前：避免 LLM 或关键词匹配把它误当成"开始某个能力"。
        if self._looks_like_complaint(message):
            lar = s.get("last_action_ready") or {}
            if lar.get("cap_id") and time.time() - lar.get("ts", 0) < 600:
                cap_name = (_CAP.get(lar["cap_id"]) or {}).get("name", lar["cap_id"])
                self._chat_log(sid, "OUT | complaint-with-ready | %s" % lar["cap_id"])
                return {
                    "stage": "action_ready",
                    "cap": {"id": lar["cap_id"], "name": cap_name},
                    "vals": lar.get("vals") or {},
                    "message": "我在。参数已经齐了，点卡片里的「开始执行」我就跑%s；如果卡片没显示，刷新一下页面。" % cap_name,
                    "tip": "不用重复发，点「开始执行」即可。",
                }
            self._chat_log(sid, "OUT | complaint-no-ready")
            return {"stage": "ask",
                    "message": "我在。刚才没接上，你直接说想做什么，比如'给我写一篇个人卡收款严重性的公众号文章'。"}

        # —— 0.4) 重复提问识别（记忆提示）——
        # 与 server 层 check_repeat 互补：保证无论入口(直连 step / 经 server)都能接住重复，绝不 blank/答非所问。
        # 非状态类 → 复用上次真实答案、由模型自然重述；状态类(写了吗/出了没) → 走正常流程重算时效，由 _do_status_inquiry 模型生成带提示回复。
        _rn = s.get("_last_norm")
        _rc = s.get("_answered_cache") or {}
        _is_rep = bool(_rn and _rn in _rc and _rc[_rn])
        if _is_rep:
            s["_is_repeat"] = True
            if not self._is_status_inquiry(message):
                cached = _rc[_rn]
                facts = "用户又把刚才的问题原样问了一遍。你刚才的回答要点是：\n" + cached
                instruction = ("用户重复提问，请自然地告诉用户你刚才已经回答过这个问题，把结论再说一遍，"
                              "并顺势引导他聊点别的，或者让你直接动手做点什么（写稿/出片/发文都行）。不要生硬。")
                ans = self._gen_reply(instruction, facts, timeout=45)
                if not ans:
                    ans = cached + self._REPEAT_FALLBACK
                self._chat_log(sid, "OUT | repeat reuse (in-step, model)")
                return {"stage": "answer", "message": ans}
            # 状态类重复：继续往下走 _do_status_inquiry，由模型生成带提示回复

        # —— 0.5) 口播稿已备好，用户确认出片 → 直接弹出 video_render 卡片 ——
        #   前提：必须已有 written 稿；否则走 _detect_pipeline 的"先写稿"分支。
        #   "做成片"是明确确认；"出片/生成视频"若紧接着刚展示的口播稿（_await_video_confirm 为 True），
        #   也视为确认。其他情况（如用户还没看过稿就说"生成视频"）先展示稿、问是否修改。
        #   这里同时接住写稿后的"改字数/时长/表述"请求，避免被误判成新指令。
        written = s.get("written") or []
        if written and not (s.get("pending_cap") or {}).get("id"):
            _m = str(message or "").strip()
            explicit_confirm = any(w in _m for w in ("做成片", "开始出片", "确认出片"))
            intent_confirm = s.get("_await_video_confirm") and any(w in _m for w in ("出片", "生成视频", "做成片", "开始出片", "确认出片"))
            if (explicit_confirm or intent_confirm) and not self._looks_like_complaint(_m):
                self._chat_log(sid, "OUT | video-render confirm -> render card")
                s["_await_video_confirm"] = False
                return self._do_capability(s, "", "video_render")
            if self._is_script_modify_request(_m):
                mod = self._handle_script_modify(s, _m)
                if mod:
                    self._chat_log(sid, "OUT | script-modify handled")
                    return mod

        # —— 0.50) 旧"二创改写"卡片还挂着等原文，但用户这句是新的干活指令（短句+出稿词）
        #   → 视为改主意，清掉旧卡片，落到后面 0.51/写稿链路正常路由。
        #   背景（09-15 修）：旧会话挂着 rewrite 待填卡片时，"那给生成一条关于滞纳金改成迟纳金的
        #   短视频…"被当成参数值吞掉、永远追问"要改写的原文"（真机复现 stage=action_ask cap=rewrite）。
        #   真贴原文的特征是长文本；短句+出稿动词=新指令，绝不吞。
        if (s.get("pending_cap") or {}).get("id") == "rewrite":
            _m0 = str(message or "").strip()
            if len(_m0) < 80 and "【" not in _m0 and (
                    any(w in _m0 for w in self._BLANK_PRODUCE_WORDS)
                    or self._is_produce_cmd(_m0)):
                s["pending_cap"] = None
                self._chat_log(sid, "OUT | stale rewrite card cleared by new produce cmd(0.50)")

        # —— 0.51) "生成/做/来一条…关于X的短视频" → 有主题的出片诉求：先拆角度。
        #   出片铁律：选题→拆角度→写稿→确认→出片，绝不直接弹片。
        #   背景（09-15 修）：'生成一条关于滞纳金改成迟纳金的短视频'里"A改成B"是主题陈述，
        #   曾被 rewrite 裸词"改成"抢走、追问"要改写的原文"，答非所问。
        if not (s.get("pending_cap") or {}).get("id") and not written:
            _m = str(message or "").strip()
            if any(w in _m for w in ("短视频", "视频")) and any(
                    w in _m for w in ("生成", "做一条", "来一条", "写一条", "出一条",
                                      "整一条", "做一版", "做一段", "来一段")):
                _tm = re.search(r"关于(.{2,24}?)的", _m)
                _t = _tm.group(1).strip(" ，。、") if _tm else ""
                if _t and not any(w in _t for w in ("视频", "生成", "做", "写", "我")):
                    s["topic"] = _t
                    self._chat_log(sid, "OUT | video-with-topic(0.51) -> propose (topic=%s)" % _t)
                    return self._do_propose(s)

        # —— 0.52) 用户明确要"重新拆角度"（或同义表达）且本空间已有主题 → 直接重拆，不绕 LLM 意图识别。
        #   避免模型把"重新拆角度"误判为 answer/ask，或觉得"已经拆过/已写稿"而卡住。
        #   关键修复（P2）：无论是否已写稿都重拆——写稿后说"换个角度/重新拆"也应重出角度方案，
        #   而不是被当成"稿已写好"的提示语回掉。旧稿保留在 session.written（右栏产物仍在），不静默丢弃，
        #   _do_propose 内部会 pop("_await_video_confirm")，不会残留出片确认状态。
        if not (s.get("pending_cap") or {}).get("id") and s.get("topic"):
            _m = str(message or "").strip()
            if any(w in _m for w in ("重新拆", "重新出角度", "换个角度", "再拆一次", "再拆一遍",
                                      "角度不够", "再来一次", "不要这些")):
                self._chat_log(sid, "OUT | explicit re-propose (written=%s) -> _do_propose" % bool(written))
                return self._do_propose(s)

        # —— 0.54) 用户贴了一段成稿/长文，并要求改写/修改/润色/调整/改口语 → 直接改写所贴文本，
        #   绝不问主题、不弹确认卡。覆盖「修改/润色/调整/改口语化」等不在 rewrite 词表的动词
        #   （原会被当「写新稿」缺主题反问）。0.53 之前执行，贴稿改写走直达通道。
        if not (s.get("pending_cap") or {}).get("id") and self._is_paste_rewrite(message):
            self._chat_log(sid, "OUT | paste-rewrite detected -> direct rewrite (no theme ask)")
            _r = self._do_rewrite_pasted(s, message)
            if _r:
                return _r

        # —— 0.53) 用户有逐字稿/口播稿/原文，要求改写/改编/改成自己口径 → 直接进二创改写能力。
        #   避免被 _CAP_EXCLUDE 的"逐字稿/口播稿"误拦截成"写新稿/拆角度"。
        if not (s.get("pending_cap") or {}).get("id") and not written:
            _m = str(message or "").strip()
            _rewrite_words = ("改写", "改编", "改成我的", "改成我的口径", "改成我的风格",
                              "爆改", "重写成", "二创")
            _script_markers = ("逐字稿", "口播稿", "原文", "稿子", "文案", "脚本", "来稿", "这篇稿")
            if any(w in _m for w in _rewrite_words) and any(w in _m for w in _script_markers):
                self._chat_log(sid, "OUT | explicit rewrite request -> rewrite cap")
                _r = self._do_capability(s, message, "rewrite")
                if _r:
                    return _r

        # —— 0.55) 上一轮在等"写什么主题" → 这一句就是主题，直接进入写稿 ——
        #   让"想写点什么？"→"公转私"或"个体户怎么报税"的来回像真人对话一样连贯，
        #   不再甩占位模板，也不再追问受众（按话题自动推断）。
        if not (s.get("pending_cap") or {}).get("id") and s.get("awaiting_topic_for_write"):
            _m = str(message or "").strip()
            if self._is_blank_produce_request(_m):
                # 又发了空指令：重新自然问一次（落到下面 0.6）
                pass
            else:
                s["awaiting_topic_for_write"] = False
                topic = self._extract_topic_from_msg(_m) or _m
                s["topic"] = topic
                # 受众按话题自动推断，推不出才留空（后续写稿时再问，不堆两个问题）
                if not s.get("audience"):
                    inferred = self._infer_audience(s, _m)
                    if inferred:
                        s["audience"] = inferred
                self._chat_log(sid, "IN | awaiting_topic_for_write -> topic=%s" % topic)
                return self._do_propose(s)

        # —— 能力调度（对话驱动一切）——
        # 1) 正在收集某个能力的参数：这句就是答案（除非他改主意要出稿/取消）
        pc = s.get("pending_cap") or {}
        # —— 0.7) 状态问句识别：用户是在"问某事做完没"（X写了吗/X出了没），不是在下指令 ——
        #   必须排在能力匹配 / LLM 2.5 分类之前：否则"今天的公众号文章写了吗"会被
        #   "公众号文章"关键词误触发成写稿指令，答非所问。像人一样先回答"做了/没做"。
        if not pc.get("id") and self._is_status_inquiry(message):
            return self._do_status_inquiry(s, message)
        if pc.get("id"):
            _m = str(message or "").strip()
            # ★表单收集中若用户抛来一个"真实问题"（不是填参/确认/取消）→ 先正面回答，
            #   不把它当成参数值吞掉（否则表现就是答非所问）。pending_cap 保留，用户答完可继续填表。
            _is_form_ctrl = any(w in _m for w in
                                ("开始执行", "开始", "执行", "确认", "跑", "走起", "取消", "算了", "不用了", "go"))
            if self._looks_like_question(_m) and not self._is_produce_cmd(_m) and not _is_form_ctrl:
                _FISCAL = ("税", "票", "账", "股东", "股权", "注册", "个体", "公司", "稽查", "合规",
                           "公转私", "老板", "政策", "法规", "申报", "财务", "会计", "企业", "经营",
                           "利润", "成本", "建筑", "挂靠", "发票", "纳税", "风险", "注销", "合伙")
                if any(w in _m for w in _FISCAL):
                    self._chat_log(sid, "OUT | pending_cap + 财税问答 -> 先答后留表")
                    return self._do_answer(s, _m, need_search=self._answer_needs_search(_m))
                self._chat_log(sid, "OUT | pending_cap + 通用问答 -> 先答后留表")
                return self._do_general_answer(s, _m)
            if _m in ("取消", "算了", "不用了", "退出", "不做了"):
                s["pending_cap"] = None
                self._chat_log(sid, "OUT | pending_cap cleared by cancel")
                return {"stage": "ask", "message": "已取消，继续说你要做什么。"}
            # 参数还没齐时用户就说「开始执行/开始」→ 给出明确反馈，避免像"没反应"
            if any(w in _m for w in ("开始执行", "开始", "执行", "确认", "跑", "走起", "go")):
                cap_info = {"id": pc["id"], "name": (_CAP.get(pc["id"]) or {}).get("name", pc["id"])}
                self._chat_log(sid, "OUT | pending_cap early-confirm hint")
                return {
                    "stage": "action_ready",
                    "cap": cap_info,
                    "vals": pc.get("vals") or {},
                    "message": "已收到确认，参数齐了立刻跑；现在可以先补全卡片里的参数，或点「开始执行」。",
                    "tip": "如果卡片没显示，刷新一下页面。",
                }
            if not self._is_produce_cmd(_m) or len(_m) >= 6:
                r = self._do_capability(s, message, pc["id"])
                if r:
                    return r
        # 1.6) 能力参数已齐、卡片已展示后：用户说「开始/确认」或重复发送同样请求 →
        #      8500 侧不直接执行能力（执行端点是 Laravel /studio/chat/action），但给出明确反馈，
        #      绝不像"没反应"一样空白。
        if not pc.get("id"):
            lar = s.get("last_action_ready") or {}
            if lar.get("cap_id") and time.time() - lar.get("ts", 0) < 600:
                _m = str(message or "").strip()
                if any(w in _m for w in ("开始执行", "开始", "执行", "确认", "跑", "走起", "go")):
                    cap_info = {"id": lar["cap_id"],
                                "name": (_CAP.get(lar["cap_id"]) or {}).get("name", lar["cap_id"])}
                    self._chat_log(sid, "OUT | last_action_ready confirm hint")
                    return {
                        "stage": "action_ready",
                        "cap": cap_info,
                        "vals": lar.get("vals") or {},
                        "message": "已收到确认，点卡片里的「开始执行」我立刻就跑。",
                        "tip": "如果卡片没显示，刷新一下页面。",
                    }
                last_msg = lar.get("msg") or ""
                # ★规划请求优先于"重复"判定（2026-09-18）：用户重发规划诉求是要重跑规划，
                #   不能被"参数已经准备好了"的防重复提示挡住（真机踩坑：选题规划连发两次被吞）。
                if last_msg and not self._detect_plan(_m) and (
                        _m == last_msg or _m in last_msg or last_msg in _m or
                        self._jaccard(_m, last_msg) > 0.6):
                    cap_info = {"id": lar["cap_id"],
                                "name": (_CAP.get(lar["cap_id"]) or {}).get("name", lar["cap_id"])}
                    self._chat_log(sid, "OUT | last_action_ready repeated request")
                    return {
                        "stage": "action_ready",
                        "cap": cap_info,
                        "vals": lar.get("vals") or {},
                        "message": "参数已经准备好了，点下方卡片里的「开始执行」即可运行；无需重复发送。",
                        "tip": "如果卡片没显示，刷新一下页面。",
                    }
                # 1.65) 用户想修改/补充已 ready 卡片的参数（如"字数改成1500"）
                applied = self._apply_param_change(s, lar, _m)
                if applied.get("changed"):
                    cap_info = {"id": lar["cap_id"],
                                "name": (_CAP.get(lar["cap_id"]) or {}).get("name", lar["cap_id"])}
                    self._chat_log(sid, "OUT | last_action_ready param changed")
                    return {
                        "stage": "action_ready",
                        "cap": cap_info,
                        "vals": applied.get("vals") or lar.get("vals") or {},
                        "message": "%s，点「开始执行」我就按这个跑。" % applied.get("reply", "已调整参数"),
                        "tip": "跑完我会告诉你结果，并提示下一步能做什么。",
                    }
                # 不是确认、不是重复、也不是改参数：不再用 idle 提示吞掉这句——
                # 否则卡片展示后用户说"配音/去发布/随便问个问题"会全被卡死在这里。
                # 直接放行，让后续管线/检索/问答分支正常接手（卡片本身仍停在屏幕上）。
                self._chat_log(sid, "OUT | last_action_ready fall-through (no confirm/repeat/param-change)")

        # 0.7)【定时任务意图】设定/查看/取消/待发——最高优先级，先于规划/纠偏/能力/写稿
        #   用户说"每周一9点帮我备好朋友圈文案"这类，直接进定时任务子系统，不绕去写稿/能力。
        if not pc.get("id"):
            _sched_intent = self._detect_schedule(message)
            if _sched_intent:
                # MVP 单用户工作台：定时任务统一绑 default，与前端 /chat/schedules?user_id=default 一致
                # （多租户隔离后续接登录态再做，避免 user_id 为空时前后端查不到）
                _uid = "default"
                if _sched_intent == "set":
                    return self._do_schedule_set(s, message, _uid)
                if _sched_intent == "list":
                    return self._do_schedule_list(_uid)
                if _sched_intent == "cancel":
                    return self._do_schedule_cancel(s, message, _uid)
                if _sched_intent == "pending":
                    return self._do_schedule_pending(_uid)

        # 1.5)【主动规划意图】"规划/排期/策划/一周内容" → 生成 7 天内容排期
        #     【排期卡片点击直通】【规划选题】<主题>　受众：<受众> → 直接拆角度
        #     两者都必须排在能力关键词匹配之前：否则"帮我规划本周内容""公转私…选题"
        #     会被「智能选题」能力抢走，用户点了没反应。
        if not pc.get("id"):
            # 排期批量出稿：用户点「本周 7 条全写」或说"本周都写出来" → 7 天各写一篇
            if self._detect_plan_write(s, message):
                return self._do_plan_write(s)
            _pt = self._extract_plan_topic(message)
            if _pt:
                s["topic"] = _pt[0]
                s["audience"] = _pt[1] or s.get("audience") or "已注册、正在经营的中小老板"
                return self._do_propose(s)
            if self._detect_plan(message):
                return self._do_plan(s, message)
        # 0.8) 对话纠偏：朋友圈文案 / 已规划未接通能力 / 超出范围的落地服务
        #    放在能力关键词匹配之前：用户要朋友圈文案直接生成；要未接通能力如实说还没接上；
        #    要报税/做账等落地服务礼貌说明超出范围。都不依赖 LLM，硬兜底不瞎答应。
        if not pc.get("id"):
            if self._is_moment_request(message):
                return self._do_moment(s, message)
            _hid = self._match_hidden_capability(message)
            if _hid:
                return self._do_cap_unavailable(s, _hid, message)
            if self._out_of_scope_guard(message):
                return self._do_scope_decline(s, None, message, reason="service")
        # 2) 这句话命中某个平台能力（出片/选题/质检/发布包…）→ 进入能力流程
        # ★写稿后"改成N字/缩短到N字"等字数调整：直接重写最新一篇，不打断流程去走二创能力
        if not pc.get("id") and (s.get("written") or []):
            _m = str(message).strip()
            _wc = re.search(r"(\d{3,5})\s*字", _m)
            if _wc and any(w in _m for w in ("改", "字数", "缩短", "加长", "扩写", "精简", "调")):
                _n = int(_wc.group(1))
                if 300 <= _n <= 5000:
                    _adj = self._adjust_last_script_words(s, _n)
                    if _adj:
                        return _adj
        if not pc.get("id"):
            # ★反转路由（2026-09-18）：LLM 主判优先于关键词。先把 _understand 算出来让模型"听懂人话"，
            #   关键词 _match_capability 仅作 LLM 不确定时的安全兜底，且绝不对"在问问题"的消息误开能力。
            try:
                _u = self._understand(s, message)
            except Exception:  # noqa: BLE001
                _u = None
            # 0.8) 对话纠偏：LLM 判定超出工作范围 → 礼貌说明，不瞎答应（先于能力/写稿分发）
            if isinstance(_u, dict) and _u.get("in_scope") is False:
                return self._do_scope_decline(s, _u, message)
            _cap_llm = (_u or {}).get("cap")
            _act_llm = str((_u or {}).get("action") or "")
            try:
                _conf_llm = float((_u or {}).get("cap_confidence") or 0)
            except Exception:  # noqa: BLE001
                _conf_llm = 0.0
            # LLM 明确想调用某能力（且不是在回答问题）→ 直接走能力，模型说了算
            if _cap_llm and _act_llm != "answer" and _CAP.get(_cap_llm) \
                    and not _CAP.is_hidden(_cap_llm) and _conf_llm >= 0.7:
                self._chat_log(sid, "OUT | llm-cap primary | %s (conf=%.2f)" % (_cap_llm, _conf_llm))
                r = self._do_capability(s, message, _cap_llm)
                if r:
                    return r
            # 关键词兜底：仅在 LLM 没给 cap、且这句话不像"在问问题"时才用
            # （避免"公转私有啥风险"这类带业务词的问句被误路由成写稿/能力，答非所问）
            if not _cap_llm:
                cid = self._match_capability(message)
                if cid and not self._looks_like_question(message):
                    self._chat_log(sid, "OUT | kw-cap fallback | %s" % cid)
                    r = self._do_capability(s, message, cid)
                    if r:
                        return r
            # 0.6) 空白写稿诉求 → 用模型自然反问方向，不再 canned "好嘞想写点什么" 自说自话
            if self._is_blank_produce_request(message):
                s["awaiting_topic_for_write"] = True
                self._chat_log(sid, "OUT | blank-produce | natural-ask")
                nat = self._gen_reply(
                    "用户说想写点财税内容但还没给主题。自然地问他想讲什么方向，给 1-2 个接地气的例子"
                    "（如公转私风险、个体户怎么报税、股东借款那点事），一句带过，别啰嗦，别用'好嘞'开头。",
                    timeout=30)
                if not nat:
                    nat = "想讲点什么方向？比如公转私有啥风险、个体户怎么报税、股东借款那点事——你说主题，我直接搭骨架出成稿。"
                return {"stage": "ask", "message": nat}

        # 2.5)（2026-09-18 反转后废弃：LLM 主判已上移到"2"块，_u 在此处直接复用，不再二次调用模型）

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
        # 用户在回答我上一轮的反问 → 接续讨论（除非这条是在下出稿/拆解指令或明确写稿要求）
        if s.get("pending_question") and not self._is_produce_cmd(message) \
                and not self._looks_like_write_req(message):
            return self._do_followup(s, message)
        # ★受众追问兜底捕获：上一轮系统问了"主要给谁看"（missing=['受众']），这句若不是新指令，
        #   就直接当作受众答案接纳——避免 LLM 把"给中小老板看"这种短回答误判成 action=answer、
        #   不写回 s["audience"]，导致用户被卡在"主要给谁看？"死循环（写稿永不推进）。
        _aud_captured = False
        if s.get("_await_audience"):
            if self._is_produce_cmd(message):
                s["_await_audience"] = False  # 用户换了新指令，放弃受众追问
                s.pop("_await_audience_pick", None)
            else:
                _ans = message.strip()
                if _ans and 0 < len(_ans) <= 40:
                    s["audience"] = _ans
                    s["_await_audience"] = False
                    _aud_captured = True
                    self._chat_log(s.get("id"), "OUT | 受众答案已捕获 -> %s" % _ans[:20])
                elif _ans and self._looks_like_write_req(_ans):
                    # ★长回答但句式是明确写稿要求（如"请围绕以上内容生成…文稿"）：
                    # 不再死等受众——受众取通用默认（用户可后改），整段要求进 requirement 直接开写，
                    # pick 沿用存下的 _await_audience_pick（写哪条角度）。
                    s["_await_audience"] = False
                    if not s.get("audience"):
                        s["audience"] = "已注册、正在经营的中小老板"
                    _old_req = s.get("requirement") or ""
                    s["requirement"] = (_old_req + "；" + _ans).strip("；")
                    _aud_captured = True
                    self._chat_log(s.get("id"), "OUT | 长写稿要求直写（受众默认），req=%s" % _ans[:30])
        # 复用 2.5 已算好的结果，避免重复调 LLM（关键词命中时 _u 为 None，这里现算）
        # ★防御：LLM 调用异常绝不能冒泡导致整条请求失败（前端表现为"没反应"），必须兜回 None 走硬规则
        if _aud_captured:
            u = {"action": "write", "pick": s.pop("_await_audience_pick", None) or "all"}
        else:
            try:
                u = _u if isinstance(_u, dict) else self._understand(s, message)
            except Exception:  # noqa: BLE001
                u = None
                self._chat_log(s.get("id"), f"WARN | _understand 异常降级硬规则 | {str(message)[:60]}")
        # 0.8) 对话纠偏（兜底路径）：LLM 重算后仍判定超出范围 → 礼貌说明
        if isinstance(u, dict) and u.get("in_scope") is False:
            return self._do_scope_decline(s, u, message)
        # —— 本地兜底：LLM 解析失败（返回空/无 action）→ 硬规则判定，杜绝"说啥都没反应" ——
        if not isinstance(u, dict) or not u.get("action"):
            fb = self._local_intent_fallback(s, message)
            if fb == "write":
                u = {"action": "write", "pick": self._pick_from_msg(message)}
            elif fb == "answer":
                u = {"action": "answer"}
            elif fb == "ask_topic":
                return {"stage": "ask", "message": "先告诉我这条想讲什么主题，我再帮你写。"}
            else:
                u = u if isinstance(u, dict) else {}
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
            # 要素是否齐？主题缺 → 先补主题；受众缺 → 先尝试从话题推断，推不出才问
            if not s.get("topic"):
                return {"stage": "ask", "message": "先告诉我这条想讲什么主题，我再帮你写。"}
            if not s.get("audience"):
                inferred = self._infer_audience(s, message)
                if inferred:
                    s["audience"] = inferred
                else:
                    # 给基于话题的合理选项（不是跑题的"刚注册还是有规模"）
                    hint = "这条主要是给谁看的？"
                    if s.get("topic"):
                        hint = f"「{s['topic'][:30]}」这条，主要给谁看？可点下面的，或直接说："
                    # 记下"在等受众答案"，并把本次写稿意图(pick)存下来，用户补完受众直接接着写
                    s["_await_audience"] = True
                    s["_await_audience_pick"] = (u.get("pick") if isinstance(u, dict) else None) or "all"
                    return {"stage": "ask", "message": hint, "missing": ["受众"],
                            "options": ["准备注册/刚注册的创业者", "已注册、正在经营的中小老板",
                                        "建筑行业的老板/包工头", "企业财务/会计人员"]}
            pick = u.get("pick")
            # 用户对"已写成稿"提出修改（pick=revN）→ 重写 written[N]，不新增篇
            if isinstance(pick, str) and pick.startswith("rev"):
                return self._do_revise(s, u, pick)
            res = self._do_write(s, pick)
            # ★不再自动接 video_render 卡片：出片必须等用户看过口播稿、确认不改了再点"做成片"，
            # 避免"没稿就生成视频"或跳过"改字数/时长/表述"中间环节。
            return res

        if action == "propose":
            # 要素齐才拆角度；否则先补齐要素（受众可推断就不问）
            if not s.get("topic"):
                return {"stage": "ask", "message": "先告诉我这条想讲什么主题，我再帮你拆角度。"}
            if not s.get("audience"):
                inferred = self._infer_audience(s, message)
                if inferred:
                    s["audience"] = inferred
                else:
                    hint = "这条内容主要给谁看？"
                    if s.get("topic"):
                        hint = f"「{s['topic'][:30]}」这条，主要给谁看？可点下面的，或直接说："
                    # 记下"在等受众答案"，并把本次写稿意图(pick)存下来，用户补完受众直接接着写
                    s["_await_audience"] = True
                    s["_await_audience_pick"] = (u.get("pick") if isinstance(u, dict) else None) or "all"
                    return {"stage": "ask", "message": hint, "missing": ["受众"],
                            "options": ["准备注册/刚注册的创业者", "已注册、正在经营的中小老板",
                                        "建筑行业的老板/包工头", "企业财务/会计人员"]}
            return self._do_propose(s)

        # ★正面回答：用户在问财税/业务问题（LLM 判定 answer，或兜底规则命中）。
        #   规则兜底：问句/求知句且不是明确的出稿指令 → 答，不追问"拍给谁看"。
        if action == "answer":
            return self._route_answer(s, u, message)
        if self._looks_like_question(message) and not self._is_produce_cmd(message):
            return self._route_answer(s, u, message, question=message)

        # ★关键修复（根治"要问细些才能回话"）：
        #   不要对"随便问问/闲聊/说不清"的短消息追问主题。
        #   只有用户明确想出内容（命中出稿信号），或本空间已在出稿流程中（已有主题/受众），
        #   才追问缺的要素；否则一律当作问答/闲聊，直接调大模型正面回应——
        #   绝不再甩"还缺主题，你补一下""你补充点背景"这类逼用户问细的话。
        _wants_content = bool(
            s.get("topic") or s.get("audience")
            or self._is_produce_cmd(message)
            or any(w in message for w in self._PRODUCE_HINT)
            or any(w in message for w in ("出片", "出稿", "选题", "公众号", "小红书",
                                          "内容", "做一批", "来几个", "写一条", "写个", "写成"))
        )
        if not _wants_content:
            # 财税/工商类问题走专业顾问口径（必要时联网核口径），其余走通用问答。
            _FISCAL_KW = ("税", "票", "账", "公户", "私户", "股东", "分红", "股权", "注册", "个体",
                          "公司", "申报", "稽查", "合规", "财务", "会计", "增值税", "所得", "印花",
                          "企业", "经营", "利润", "成本", "报销", "工薪", "社保", "建筑", "挂靠",
                          "预缴", "发票", "纳税", "风险", "处罚", "政策", "法规", "执照", "注销",
                          "清算", "审计", "汇算", "公转私", "个独", "合伙", "老板")
            if any(w in message for w in _FISCAL_KW):
                self._chat_log(s.get("id"), "GEN | 无出稿意图·财税问答兜底")
                return self._route_answer(s, u, message, question=message)
            self._chat_log(s.get("id"), "GEN | 无出稿意图·通用问答兜底（不再追问主题）")
            return self._do_general_answer(s, message)
        # action == ask 或未识别：只有主题/受众缺失才追问（"关键要求"是可选项，缺失不追问）
        missing = []
        if not s.get("topic"):
            missing.append("主题")
        if not s.get("audience"):
            inferred = self._infer_audience(s, message)
            if inferred:
                s["audience"] = inferred
            else:
                missing.append("受众")
        if missing:
            asked = u.get("asked") or ""
            msg = asked or ("我们先对齐一下：还缺 " + "、".join(missing) + "，你补一下？")
            return {"stage": "ask", "message": msg, "missing": missing}
        # 主题+受众已齐 → 用户这句若含出稿意图直接拆角度，不再让用户多说一句"开始出稿"
        if self._is_produce_cmd(message) or any(w in message for w in
                                                ("角度", "做一批", "来几个", "开始写", "开始出",
                                                 "继续", "下一条", "这篇", "那条", "拆角度")):
            return self._do_propose(s)
        # ★通用问答兜底：非出稿意图且主题受众已齐的对话（技术/运营/常识/闲聊等），
        #   直接调大模型正面回答，不再甩"说开始出稿"这种答非所问的话。
        self._chat_log(s.get("id"), f"GEN | 通用问答兜底 | {str(message)[:60]}")
        return self._do_general_answer(s, message)

    def reset(self, sid):
        with self._lock:
            if sid:
                self._sessions.pop(sid, None)
        return {"stage": "reset"}
