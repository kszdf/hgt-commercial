# -*- coding: utf-8 -*-
"""
平台能力注册表（对话驱动一切的核心）。

设计目标
--------
用户愿景：平台首先是一个大对话页，所有功能后端在跑；只在需要做选择时才跳卡片；
每完成一步，AI 自动提示下一步该干什么——不懂提示词、不熟悉流程的人也能一路走到底。

因此这里用**声明式**把平台现有能力登记成表：
    id / 名称 / 一句话说明 / 分类 / 参数定义 / 产出 / next_steps（完成后建议的下一步）

编排器据此：
    1. 判定用户想用哪个能力
    2. 逐项收集参数（缺哪个问哪个，并把选项做成卡片给用户点）
    3. 参数齐 → 交给 Laravel 调度执行
    4. 执行完 → 按 next_steps 生成"下一步建议"，用户点一下继续

新增能力只要在这里加一条，不用改编排器和前端。

参数字段说明
------------
key      参数名（下发给后端的字段名）
label    给用户看的中文名
type     text | textarea | select | number | bool
options  可选项（select 时前端渲染成按钮/下拉，AI 也会念给用户听）
default  默认值（缺省时自动填上，不再追问）
required 是否必填
hint     追问时的白话提示（告诉用户填什么、为什么）
from     可从会话上下文自动取值的来源：topic | audience | requirement | count |
         last_text（最近成稿/改写稿）| last_job_id（最近一次出片 job）
"""

# ---------------------------------------------------------------------------
# 能力定义
# ---------------------------------------------------------------------------

CAPABILITIES = {
    # ============ 选题 ============
    "topic": {
        "name": "智能选题",
        "desc": "按行业和关键词，一次给你一批可拍的选题",
        "cat": "选题",
        "icon": "🎯",
        "params": [
            {"key": "industry", "label": "你的行业/领域", "type": "text",
             "required": True, "hint": "比如：财税咨询、建筑工程、电商", "from": "audience"},
            {"key": "keywords", "label": "想重点讲的方向", "type": "text",
             "required": False, "hint": "比如：公转私、社保入税、股权架构", "from": "topic"},
            {"key": "count", "label": "要几个选题", "type": "select",
             "options": ["5", "8", "10"], "default": "5", "required": False, "from": "count"},
            {"key": "platform", "label": "发到哪个平台", "type": "select",
             "options": ["不限", "抖音", "视频号", "小红书", "快手", "公众号"],
             "default": "不限", "required": False},
            {"key": "hook", "label": "钩子类型", "type": "select",
             "options": ["不限", "痛点", "反常识", "案例", "数字", "提问"],
             "default": "不限", "required": False},
        ],
        "output": "一批带切入点的选题",
        "next": ["rewrite", "video_render"],
    },
    "hotspot": {
        "name": "热点选题",
        "desc": "抓当下财税/行业热点，直接变成你的选题",
        "cat": "选题",
        "icon": "🔥",
        "params": [
            {"key": "industry", "label": "你的行业/领域", "type": "text",
             "required": True, "hint": "比如：财税咨询", "from": "audience"},
        ],
        "output": "近期热点与可写的角度",
        "next": ["rewrite", "video_render"],
    },
    "dissect": {
        "name": "爆款拆解",
        "desc": "丢一条爆款文案进来，拆出它为什么火",
        "cat": "选题",
        "icon": "🔍",
        "params": [
            {"key": "text", "label": "爆款原文", "type": "textarea",
             "required": True, "hint": "把爆款文案或口播稿粘进来"},
        ],
        "output": "结构拆解 + 可复用的套路",
        "next": ["rewrite", "video_render"],
    },

    # ============ 写稿 ============
    "rewrite": {
        "name": "二创改写",
        "desc": "把别人的爆款改成你的口径，自动过违禁词",
        "cat": "写稿",
        "icon": "✍️",
        "params": [
            {"key": "text", "label": "要改写的原文", "type": "textarea",
             "required": True, "hint": "把原文粘进来", "from": "last_text"},
            {"key": "focus", "label": "想突出什么（选填）", "type": "text",
             "required": False, "hint": "比如：突出稽查风险、突出金额测算、改成老板听得懂的话"},
        ],
        "output": "改写后的口播稿（已过违禁词）",
        "next": ["video_render", "qc"],
    },
    # 注：deai（去AI味）/ moment（朋友圈）在 8500 已标记 DEPRECATED（Laravel 侧功能下线），
    #     故不纳入对话能力，避免用户点了拿到失败结果。

    # ============ 出片 ============
    "video_render": {
        "name": "生成视频",
        "desc": "把口播稿做成成品视频（配音+字幕+画面）",
        "cat": "出片",
        "icon": "🎬",
        "params": [
            {"key": "dialogue", "label": "口播稿", "type": "textarea",
             "required": True, "hint": "要念的文案；没有就先出稿", "from": "last_text"},
            {"key": "mode", "label": "视频形式", "type": "select",
             "options": [
                 "scroll:单字幕滚动", "avatar:数字人出镜",
                 "motion:动态图文", "manga:漫剧", "whiteboard:白板手绘",
             ], "default": "scroll:单字幕滚动", "required": True},
            {"key": "title", "label": "封面主标题", "type": "text",
             "required": False, "hint": "不超过 10 个字最好"},
            {"key": "subtitle", "label": "封面副标题", "type": "text",
             "required": False, "hint": "补充说明，不超过 20 字"},
            {"key": "voice_form", "label": "配音形式", "type": "select",
             "options": ["mono:单人独白", "dialogue:双人对谈", "male_mono:男声独白", "female_mono:女声独白"],
             "default": "mono:单人独白", "required": False},
        ],
        "output": "成品视频（需要等几分钟渲染）",
        "next": ["qc_video", "publish_pack"],
        "long": True,          # 长任务：提交后返回 job_id，需要轮询进度
    },

    # ============ 质检 ============
    "qc": {
        "name": "文案质检",
        "desc": "发之前查一遍：违禁词、敏感表述、逻辑漏洞",
        "cat": "质检",
        "icon": "🛡️",
        "params": [
            {"key": "text", "label": "要检查的稿子", "type": "textarea",
             "required": True, "hint": "把稿子粘进来", "from": "last_text"},
            {"key": "platform", "label": "发到哪个平台", "type": "select",
             "options": ["抖音", "视频号", "小红书", "快手"],
             "default": "抖音", "required": False},
        ],
        "output": "质检报告 + 修改建议",
        "next": ["video_render"],
    },
    "qc_video": {
        "name": "成片质检",
        "desc": "技术体检：有没有黑屏、没声音、字幕压字",
        "cat": "质检",
        "icon": "🔬",
        "params": [
            {"key": "job_id", "label": "要检查的视频", "type": "text",
             "required": True, "hint": "一般自动带出上一个成片", "from": "last_job_id"},
        ],
        "output": "技术质检报告",
        "next": ["publish_pack"],
    },

    # ============ 发布 ============
    "publish_pack": {
        "name": "发布素材包",
        "desc": "成片 + 封面 + 标题 + 文案，打包好直接发",
        "cat": "发布",
        "icon": "📦",
        "params": [
            {"key": "job_id", "label": "哪个视频", "type": "text",
             "required": True, "hint": "一般自动带出上一个成片", "from": "last_job_id"},
        ],
        "output": "发布包（封面/标题/文案/话题）",
        "next": ["xhs"],
    },
    "xhs": {
        "name": "小红书图文",
        "desc": "同一内容改成小红书图文笔记，一鱼多吃",
        "cat": "发布",
        "icon": "📕",
        "params": [
            {"key": "topic", "label": "图文主题", "type": "text",
             "required": True, "hint": "比如：公司利润怎么拿最省税", "from": "topic"},
        ],
        "output": "小红书图文（封面+内页图+文案）",
        "next": [],
    },
    # ============ 素材 ============
    "footage_edit": {
        "name": "素材剪辑",
        "desc": "对已有素材做剪辑处理",
        "cat": "素材",
        "icon": "✂️",
        "params": [
            {"key": "job_id", "label": "要处理的素材", "type": "text",
             "required": True, "hint": "素材任务 id", "from": "last_job_id"},
        ],
        "output": "剪辑后的素材",
        "next": ["video_render"],
    },
    "clone_voice": {
        "name": "声音克隆",
        "desc": "录一段你的声音，后面配音都用你的音色",
        "cat": "素材",
        "icon": "🎙️",
        "params": [
            {"key": "audio_path", "label": "音频文件路径", "type": "text",
             "required": True, "hint": "一段 10 秒以上的干净录音"},
        ],
        "output": "你的专属音色",
        "next": ["video_render"],
    },
}


# ---------------------------------------------------------------------------
# 供编排器 / 前端使用的辅助方法
# ---------------------------------------------------------------------------

def get(cap_id):
    """按 id 取能力定义。"""
    return CAPABILITIES.get(cap_id)


def list_all():
    """列出所有能力（给 LLM 判定用，精简字段避免 prompt 过长）。"""
    out = []
    for cid, c in CAPABILITIES.items():
        out.append({
            "id": cid,
            "name": c["name"],
            "desc": c["desc"],
            "cat": c.get("cat") or "",
            "params": [p["key"] for p in c["params"]],
        })
    return out


def prompt_for_llm():
    """渲染成给 LLM 看的能力清单文本（判定用）。"""
    lines = []
    for cid, c in CAPABILITIES.items():
        lines.append("- %s（id=%s，%s）：%s" % (c["name"], cid, c.get("cat") or "通用", c["desc"]))
    return "\n".join(lines)


def param_def(cap_id, key):
    """取某个能力的某个参数定义。"""
    c = CAPABILITIES.get(cap_id) or {}
    for p in c.get("params", []):
        if p["key"] == key:
            return p
    return None


def fill_from_session(cap_id, ctx):
    """用会话上下文自动预填参数（能自动带出来的就不再问用户）。

    ctx 形如 {"topic":..., "audience":..., "requirement":..., "count":...,
              "last_text":..., "last_job_id":...}
    """
    c = CAPABILITIES.get(cap_id) or {}
    vals = {}
    for p in c.get("params", []):
        src = p.get("from")
        if src and ctx.get(src):
            vals[p["key"]] = ctx[src]
        elif p.get("default") and not p.get("required"):
            vals[p["key"]] = p["default"]
        elif p.get("default") and p.get("required"):
            # 必填但有默认值：也自动带上，避免无谓追问
            vals[p["key"]] = p["default"]
    return vals


def missing_params(cap_id, vals):
    """返回还缺的必填参数定义列表。"""
    c = CAPABILITIES.get(cap_id) or {}
    out = []
    for p in c.get("params", []):
        if not p.get("required"):
            continue
        v = (vals or {}).get(p["key"])
        if v is None or (isinstance(v, str) and not v.strip()):
            out.append(p)
    return out


def next_suggestions(cap_id):
    """该能力完成后，建议的下一步能力列表（含名称，供前端渲染卡片）。"""
    c = CAPABILITIES.get(cap_id) or {}
    out = []
    for nid in (c.get("next") or []):
        n = CAPABILITIES.get(nid)
        if n:
            out.append({
                "id": nid,
                "name": n["name"],
                "icon": n.get("icon") or "▶️",
                "desc": n["desc"],
            })
    return out
