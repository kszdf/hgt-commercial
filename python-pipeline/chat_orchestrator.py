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
会话一期存内存 dict（服务重启即清），key=session_id，由前端 Laravel 传回。
"""
import json
import time
import uuid
import threading

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
    def __init__(self, ai_topic_fn, ai_rewrite_fn, deepseek_chat_fn, text_cfg_fn):
        self._ai_topic = ai_topic_fn
        self._ai_rewrite = ai_rewrite_fn
        self._chat = deepseek_chat_fn
        self._cfg = text_cfg_fn
        self._sessions = {}
        self._lock = threading.Lock()

    # ---- 会话生命周期 ----
    def _get(self, sid):
        with self._lock:
            if sid and sid in self._sessions:
                return self._sessions[sid]
            s = {
                "id": sid or uuid.uuid4().hex,
                "topic": "", "audience": "", "requirement": "", "count": 0,
                "phase": "collect",
                "angles": [],            # list[dict] 拆出的角度方案
                "chosen": [],            # list[dict] 用户选定待成稿的角度
                "written": [],           # list[dict] 已写完的口播稿
                "history": [],           # 供 LLM 理解的简短对话历史
                "created": time.time(),
                "last": time.time(),
            }
            self._sessions[s["id"]] = s
            return s

    def _touch(self, s):
        s["last"] = time.time()
        # 简单上限防内存膨胀
        with self._lock:
            if len(self._sessions) > 200:
                now = time.time()
                for k in [k for k, v in self._sessions.items() if now - v["last"] > 3600]:
                    self._sessions.pop(k, None)

    # ---- 意图理解（LLM 解析，输出严格 JSON）----
    def _understand(self, s, message):
        have = []
        if s.get("topic"): have.append("主题")
        if s.get("audience"): have.append("受众")
        if s.get("requirement"): have.append("关键要求")
        missing_hint = ("已具备：" + ("、".join(have) if have else "暂无") +
                        "。缺：" + ("、".join(x for x in ["主题", "受众", "关键要求"] if x not in have) or "无") +
                        "（数量可选，缺则默认 5）。")
        hist = "；".join(s["history"][-6:])
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
        topics = self._ai_topic(industry=industry, keywords=keywords, count=count)
        s["angles"] = topics
        s["chosen"] = []
        return {
            "stage": "propose",
            "angles": topics,
            "tip": "以上是参照你的四要素和写稿规范拆出的角度方案。你可以说'就按这个全写'，或指定写某条（如'写第2条'）。",
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
        return {"stage": "written", "results": out}

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

    # ---- 对外入口：一次对话回合 ----
    def step(self, sid, message):
        s = self._get(sid)
        self._touch(s)
        result = self._step_core(s, message)
        result["session_id"] = s["id"]
        result["topic"] = s.get("topic") or ""
        result["audience"] = s.get("audience") or ""
        result["count"] = int(s.get("count") or 0)
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

    def _step_core(self, s, message):
        s["history"].append(f"用户: {message}")
        if len(s["history"]) > 12:
            s["history"] = s["history"][-12:]
        # 生产链动作意图（不依赖 LLM，关键词命中即响应）：对话出稿 → 引导走 配音/出片/质检/发布
        action_res = self._detect_pipeline(s, message)
        if action_res:
            return action_res
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
