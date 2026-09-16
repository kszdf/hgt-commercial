#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
追梦商用平台 · 视频出片微服务（滚动字幕卡 + 本地数字人出镜）
零三方依赖（仅 Python 标准库），包装 gpt_sovits 下的出片脚本。

运行（Windows 宿主，复用既有 PY310 环境 + ffmpeg + 音色密钥）:
    D:/heygem/py310/Scripts/python.exe server.py
监听 0.0.0.0:8500

接口:
    GET  /health                     -> {"status":"ok"}
    POST /generate                   -> {"job_id","status":"queued"}
         body: {
           "mode": "scroll" | "avatar" | "motion" | "manga" | "whiteboard" | "card",
           "dialogue": "...(女：/男： 对话体)",
           "title": "...", "subtitle": "...",
           "bg": "可选背景图",
           "dry_tts": false,            # true=静音占位(仅验画面)；false=真实 CosyVoice 配音
           "male_voice": "voice_id",    # 可选，覆盖默认男声（avatar 独白/男：行、scroll 男：行）
           "female_voice": "voice_id",  # 可选，覆盖默认女声（avatar 女：行、scroll 女：行）
           "subtitle_style": "dynamic", # 可选：dynamic(默认卡拉OK)/minimal(纯净)/bubble(气泡)
           "edit_style": "fast",        # 可选：对成片做嵌套自动剪辑 fast/artistic/vlog（不传则不出）
           "name_tag": "追梦 · 数字人",  # 可选：vlog 风格左下角姓名条文字
           "overlay": "/path/clip.mp4",  # 可选：pip 画中画副视频（出镜数字人片段等）
           "cover": true,               # 可选：基于成片自动生成品牌封面 cover.jpg
           "platform": "douyin",        # 可选：封面画幅适配（douyin/video=4:5, xhs/red=3:4）
           # 方言不分设选项：音色用什么口音录，出来就是什么方言（租客克隆音色自带；老张/江老师为普通话预设）
           "auto_publish": ["douyin","shipinhao","xiaohongshu"]  # 可选：done 后后台自动分发到这些平台
         }
    GET  /status/{job_id}            -> {"job_id","status","result","error"}
    GET  /download/{job_id}          -> video/mp4 流
    GET  /oauth/authorize/{platform} -> OAuth2 授权跳转（douyin/xiaohongshu，?account_id= 可选）
    GET  /oauth/callback/{platform}  -> OAuth2 授权回调（换 token 并落账号级缓存）
    GET  /oauth/status/{platform}    -> 授权态查询（?account_key= 可选，公众号 client_credential 走 env）
    # POST /publish 已恢复：自动发布按平台适配器真实/模拟透明分发（见 publishers/）。
    POST /strategist                 -> P4 获客军师：{"potential_score","level","hook_suggest","industry_fit","improvements"}
         body: {"title","script","industry":"可选","platform":"可选"}
    POST /deai                       -> P4 去AI痕迹：{"rewritten","changes","removed_markers"}
         body: {"text"}
    GET  /versions/{job_id}          -> P3 版本列表：{"versions":[{v,out,ts,tag,snapshot}]}
    GET  /preview/{job_id}           -> P4 实时预览帧：渲染中返回进度，done 返回 jpeg 抽帧
    GET  /hooks                      -> P4 留资钩子库（?type=咨询引导 可选筛选）
    GET  /stats                      -> P4 数据看板聚合：{total_jobs,by_status,by_platform,published_jobs}
    GET  /hotspots                   -> P4 热点追踪（?platform=douyin 可选筛选）

说明:
    - dry_tts=false（默认）走真实 TTS，需 model_keys.env 中的 dashscope key 与联网。
  - scroll 模式：多声（女：/男：）滚动字幕卡，不出镜。
  - motion 模式：幕后音·动态画面（对标视频号「建筑财税张老师」风格）——男声/女声/男女对话配音 +
    底部大字字幕 + 动态GIF/生图场景，不出镜。
  - card 模式：图解版（信息卡片解说）——稿子 → AI 分屏（哪几句合成一屏/用哪种卡片）→
    米色卡片 + 元素跟口播逐条浮现 + 底部大字字幕 + 单声配音，不出镜。
    与 motion 的区别：画面不是生图/GIF，而是把讲的话变成看得见的信息卡（数字/法条/对比）。
  - avatar 模式：单人独白（统一单声线，取消男女对话）；数字人形象为单人出镜，「女：/男：」对话前缀会被自动忽略，整稿用所选单一声线配音。
    - Laravel 容器经 host.docker.internal:8500 调用本服务，服务本身不对外暴露。
"""

import http.server
import json
import os
import re
import datetime
import shutil
import subprocess
import sys
import threading
import time
import traceback
import uuid
from concurrent.futures import ThreadPoolExecutor, as_completed, wait
from urllib.parse import urlparse, parse_qs, quote

GPT_SOVITS = r"D:/heygem_data/gpt_sovits"
# 复用 gpt_sovits 侧已验证的 DeepSeek 写稿封装与违禁词库（key 不进 Laravel，仅本机 model_keys.env）
sys.path.insert(0, GPT_SOVITS)
from model_providers import get_text_config, deepseek_chat, ensure_env, tavily_search, get_key, get_planning_config  # noqa: E402
from asset_fetcher import fetch_policy_asset  # noqa: E402  （政策原文素材采集器）
import forbidden_words  # noqa: E402
# 本地 ASR（FunASR）：tools/asr 无 __init__.py，用 sys.path 注入目录后直接 import；
# 模型缺失时 only_asr=None，仅 /transcribe 受影响，不拖垮其它端点。
sys.path.insert(0, os.path.join(GPT_SOVITS, "tools", "asr"))
try:
    from funasr_asr import only_asr  # noqa: E402
except Exception:  # noqa: BLE001
    try:
        from whisper_asr import only_asr  # noqa: E402  （faster-whisper 兜底，模型已下载）
    except Exception:  # noqa: BLE001
        only_asr = None
# 自动发布模块：平台适配器（抖音/视频号/小红书优先，B站/YouTube 顺延）
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from publishers.registry import get_publisher, supported_platforms  # noqa: E402
from publishers.base import PublishRequest, PublishStatus  # noqa: E402
# 品牌与人设默认值（单一配置源，对外给同行使用时改 .env 即可，见文件内说明）
from brand_defaults import BRAND_FALLBACK, EXPERT_NAME, EXPERT_YEARS, expert_years_clause  # noqa: E402
from publishers._token_cache import set_oauth_token, get_oauth_token  # noqa: E402
import matrix_publish  # noqa: E402
from metrics_adapter import fetch_batch  # noqa: E402
from footage_edit import edit_footage  # noqa: E402  （真人素材自动精剪：去气口/停顿/重复+字幕+封面）
import requests  # noqa: E402
import secrets  # noqa: E402

# OAuth2 授权码模式（抖音/小红书）回调基地址。
# 抖音开放平台要求回调地址为「已备案域名（https）」，不接受 IP+端口形式；
# zmgen.cn 已由云 nginx 把 /oauth/* 转发到本服务（实测 200），故默认用它。
# 可用 env OAUTH_REDIRECT_BASE 覆盖。
OAUTH_REDIRECT_BASE = os.environ.get("OAUTH_REDIRECT_BASE", "https://zmgen.cn")
_OAUTH_STATES = {}  # state -> {"platform": str, "exp": float} 防 CSRF 重放
_OAUTH_STATE_TTL = 600  # state 有效期 10 分钟

PY310 = r"D:/heygem/py310/Scripts/python.exe"
FFMPEG = r"D:/ffmpeg/ffmpeg-8.1.2-full_build/bin/ffmpeg.exe"
FFPROBE = r"D:/ffmpeg/ffmpeg-8.1.2-full_build/bin/ffprobe.exe"
# HEYGEM 数据根（宿主 d:/heygem_data/face2face 挂容器 /code/data）；用户自传模特存 uploads/ 下
FAC2FACE = r"d:/heygem_data/face2face"
# 项目 storage（宿主项目目录，bind mount 进 Laravel 容器，用于预览/管理）
PROJECT_STORAGE = r"D:/heygem_data/hgt-commercial/storage/app"
SCRIPT_SCROLL = os.path.join(GPT_SOVITS, "make_scroll_video.py")
SCRIPT_EDIT = os.path.join(GPT_SOVITS, "auto_edit.py")
SCRIPT_COVER = os.path.join(GPT_SOVITS, "make_cover.py")
SCRIPT_AVATAR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "make_avatar_from_dialogue.py")
SCRIPT_MOTION = os.path.join(GPT_SOVITS, "make_motion_video_v4.py")

# 字幕字体：前端传 key，映射为 Windows 系统字体路径（渲染脚本跑在本机，可直接读取）
FONT_MAP = {
    "hei": r"C:/Windows/Fonts/simhei.ttf",
    "yahei": r"C:/Windows/Fonts/msyh.ttc",
    "kaiti": r"C:/Windows/Fonts/kaiti.ttf",
    "song": r"C:/Windows/Fonts/simsun.ttc",
    "fangsong": r"C:/Windows/Fonts/simfang.ttf",
}
def resolve_font(key):
    p = FONT_MAP.get(key)
    if p and os.path.exists(p):
        return p
    return FONT_MAP["hei"]
JOBS_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "jobs")
os.makedirs(JOBS_DIR, exist_ok=True)

# 默认音色（与 gpt_sovits 定稿一致）；租户可在请求里覆盖
DEFAULT_MALE = ""   # 新租户初始无自带声音；须由租户克隆/选择后显式传入，禁止静默回退到特定克隆音
DEFAULT_FEMALE = ""
# 声音克隆目标模型（与 clone_cjps_v2.py 一致）
MODEL_CLONE = "cosyvoice-v3-plus"

# 数字人模特注册表：前端/请求可传「友好名或裸文件名」，统一解析为 HEYGEM 容器内完整路径。
# 必须用 *_silent.mp4（静音音轨），否则 HEYGEM 会对原声做嘴型对齐导致原声污染。
DEFAULT_AVATAR_MODEL = "/code/data/BGZSP20260721_t18_silent.mp4"
MODEL_REGISTRY = {
    "bgzsp": DEFAULT_AVATAR_MODEL,
    "BGZSP20260721_t18_silent.mp4": DEFAULT_AVATAR_MODEL,
    "BGZSP20260721.mp4": "/code/data/BGZSP20260721.mp4",
    "szrsp": "/code/data/szrsp_silent.mp4",
    "szrsp_silent.mp4": "/code/data/szrsp_silent.mp4",
    "szrsp.mp4": "/code/data/szrsp.mp4",
    "yxszr": "/code/data/YXSZR.mp4",
    "yxszr1": "/code/data/YXSZR1.mp4",
    "cjps": "/code/data/cjps.mp4",
    "zmszr": "/code/data/zmszr20260727.mp4",
}

# ---- 方言策略（2026-08-04 终版）----
# 方言不分设预设库、不做下拉选择。CosyVoice 克隆音色天然继承参考音频的口音/方言：
# 租客用方言参考音克隆出的 voice_id 出片即为该方言；老张/江老师为普通话参考音，默认普通话。
# 因此方言完全由「租客所选音色」决定，无需独立 dialect 参数。

# ---- 留资钩子库（P4：可复用、行业中性化钩子模板）----
HOOK_LIBRARY = [
    {"id": "consult", "type": "咨询引导", "text": "想落地的朋友，评论区留行业，我帮你出具体思路", "fit": "专业服务型"},
    {"id": "checklist", "type": "资料引流", "text": "我把这套避坑清单整理成了文档，需要的扣「清单」", "fit": "干货教程型"},
    {"id": "audit", "type": "诊断引流", "text": "不确定自己家有没有类似风险的，发情况我帮你看看", "fit": "风险提醒型"},
    {"id": "case", "type": "案例预约", "text": "同类型案例我处理过不少，想对标的私信我", "fit": "案例拆解型"},
    {"id": "tool", "type": "工具引流", "text": "自测表格放评论区了，先测一下再决定怎么动", "fit": "自测工具型"},
    {"id": "policy", "type": "政策提醒", "text": "新规落地前这三类动作建议先停，细则我持续更新", "fit": "政策解读型"},
    {"id": "compare", "type": "对比引导", "text": "两种做法差别很大，想知道选哪条路的后留言", "fit": "方法对比型"},
    {"id": "course", "type": "系统学习", "text": "这块系统讲要讲三集，关注不迷路，下条更干", "fit": "系列教学型"},
]

# ---- 热点种子池（P4 热点追踪：dry 兜底，无 LLM 时也能返回）----
# 通用行业维度，不限定具体行业（守通用行业铁律）。
HOTSPOT_SEED = [
    {"topic": "老板最易踩的合规红线", "angle": "高频违规动作盘点", "heat": "高", "platform": "douyin"},
    {"topic": "一笔账算错多交的冤枉钱", "angle": "成本/税费差异测算", "heat": "高", "platform": "shipinhao"},
    {"topic": "新人入职必做的三件事", "angle": "流程规范化", "heat": "中", "platform": "xiaohongshu"},
    {"topic": "同行都在用的提效工具", "angle": "效率对比", "heat": "中", "platform": "douyin"},
    {"topic": "政策变了要不要跟", "angle": "政策解读视角", "heat": "高", "platform": "shipinhao"},
    {"topic": "客户问得最多的十个问题", "angle": "答疑合集", "heat": "中", "platform": "xiaohongshu"},
]

# ============ AI 文本能力（选题 / 二创，复用 gpt_sovits 的 DeepSeek + 违禁词）============
def _extract_json_array(text):
    """从文本中提取第一个 JSON 数组；找不到返回 None。容忍 ``` 包裹、字符串化 JSON、前后多余文本。"""
    if not text:
        return None
    text = text.strip()
    # 模型有时会返回被转义的字符串，如 '"[{...}]"'；先尝试 json.loads，如果是 str 再解一次
    try:
        obj = json.loads(text)
        if isinstance(obj, list):
            return obj
        if isinstance(obj, str):
            text = obj.strip()
            try:
                obj2 = json.loads(text)
                if isinstance(obj2, list):
                    return obj2
                if isinstance(obj2, dict):
                    return [obj2]
            except Exception:
                pass
        if isinstance(obj, dict):
            for key in ("topics", "data", "list", "items", "results", "angles"):
                if isinstance(obj.get(key), list):
                    return obj[key]
            return [obj]
    except Exception:
        pass
    start = text.find("[")
    if start == -1:
        return None
    depth = 0
    in_str = False
    escape = False
    for i in range(start, len(text)):
        ch = text[i]
        if escape:
            escape = False
            continue
        if ch == "\\":
            escape = True
            continue
        if ch == '"':
            in_str = not in_str
            continue
        if in_str:
            continue
        if ch == "[":
            depth += 1
        elif ch == "]":
            depth -= 1
            if depth == 0:
                try:
                    obj = json.loads(text[start:i + 1])
                    if isinstance(obj, list):
                        return obj
                except Exception:
                    pass
                return None
    return None


# ---- 全网财税热点选题（复用 tavily_search 真实时 + deepseek_chat 生成角度）----
HOTSPOT_PROMPT = """你是一位资深的财税短视频选题策划，服务对象是中小企业老板与企业主。
任务：基于提供的财税热点，产出可直接用于短视频创作的选题卡片。
输出严格的 JSON 数组，每个元素形如：
{
  "title": "热点选题标题（口语化、抓老板痛点，15字内最佳）",
  "summary": "话题摘要（2-3句，点出老板为何关心、风险或机会）",
  "tags": ["财税子领域标签1", "标签2"],
  "heat_score": 数值(1-1000的整数，代表热度指数，可估算),
  "published_at": "该热点的大致时间或'近期'",
  "hook": "留资钩子方向（1句，引导老板咨询/留资，结合该热点痛点）",
  "source_url": "若该选题直接基于上方某条真实热点，请原样填写其 url 字段；否则留空字符串",
  "angles": [
    {
      "name": "创作角度名称（如：老板视角/案例警示/政策解读）",
      "suggestion": "针对该角度的具体拍摄建议（1-2句，写清钩子与核心信息）",
      "form": "呈现形式，取值必须为以下之一：avatar(单人数字人出镜) / motion(幕后音·动态画面) / scroll(幕后音·滚动字幕) / manga(AI漫剧) / whiteboard(AI白板图解) / card(图解版·信息卡片解说)"
    }
  ]
}
要求：
- 每个热点给 2-3 个创作角度，角度须差异化（如一个严肃解读、一个案例警示、一个留资钩子）。
- 每个选题必须包含 hook（留资钩子方向）与 source_url（基于真实热点的原文链接，原样填写上方给出的 url，无则留空字符串）字段。
- form 必须且只能是上述四个枚举值之一，不要自创。
- 合规底线：只做政策解读与风险提示，不得教人逃税或违规。
- 只输出 JSON 数组，不要任何额外解释文字。
- **强相关性约束**：本次用户关注的财税子领域为 {{SUBFIELDS}}。每个选题必须紧扣这些子领域：
  - title 必须直接或间接点题（如"税务稽查"可用"稽查/税务检查/涉税检查/税务执法"；"个人所得税"可用"个税/个人所得税汇算/个税申报"）。
  - tags 第一项必须是当前子领域或其同义词。
  - 与这些子领域无关的外贸、国际税收、出海、股市反弹、宏观经济、楼市、房价等内容一律不要输出，即使检索结果里出现了也不要采纳。
- **禁止硬编**：如果检索结果中确实没有与所选子领域直接相关的热点，请诚实减少输出数量（甚至可以输出空数组 []），严禁把无关热点强行包装成该领域热点。质量优先于数量。
"""


# 财税子领域同义词表（小写）
HOTSPOT_SUBFIELD_SYNONYMS = {
    "税务稽查": ["税务稽查", "稽查", "税务检查", "涉税检查", "税收检查", "税务执法", "税务查处", "税务审计", "税务核查", "查税"],
    "税务合规": ["税务合规", "合规", "税务风险", "税收风险", "涉税风险", "税务自查"],
    "税务筹划": ["税务筹划", "税收筹划", "节税", "合理避税", "税务优化", "税负优化"],
    "个人所得税": ["个人所得税", "个税", "个税汇算", "个税申报", "个税退税", "综合所得"],
    "企业所得税": ["企业所得税", "企税", "所得税汇算", "企业所得税申报"],
    "增值税": ["增值税", "增值税发票", "进项抵扣", "销项税额", "留抵退税"],
    "发票管理": ["发票管理", "发票", "虚开发票", "电子发票", "全电发票", "发票风险"],
    "税收优惠": ["税收优惠", "税收减免", "减税降费", "税费优惠", "税收红利", "退税", "免税"],
    "社保公积金": ["社保", "公积金", "五险一金", "社保缴费", "社保合规"],
    "公转私": ["公转私", "公私转账", "股东借款", "分红个税"],
}


def _topic_match(topic, selected_subs):
    """判断选题是否与所选子领域强相关：检查 title/summary/tags。
    支持同义词表匹配；若 selected 为空则放行。返回 (matched, reason)。
    """
    if not selected_subs:
        return True, "no subs (pass)", ""
    selected = [str(s).strip() for s in selected_subs if str(s).strip()]
    title = str(topic.get("title") or "").lower()
    summary = str(topic.get("summary") or "").lower()
    tags = [str(t).lower().strip() for t in (topic.get("tags") or []) if str(t).strip()]
    text = title + " " + summary + " " + " ".join(tags)

    # 通用负面词：命中这些说明模型在硬编无关热点
    negative = ["股市反弹", "政策红包", "市场反弹", "反弹来了", "政策主题", "宏观经济", "出海", "外贸", "国际税收", "楼市", "房价", "股市"]
    for neg in negative:
        if neg in text:
            return False, f"negative hit: {neg}", ""

    # 命中任一子领域的同义词即通过
    for sel in selected:
        synonyms = HOTSPOT_SUBFIELD_SYNONYMS.get(sel, [sel.lower()])
        for syn in synonyms:
            if syn in text:
                return True, f"syn hit: {syn}", sel
        # 兜底：原词或其去"税务/税收"后的核心词直接出现
        core = sel.lower().replace("税务", "").replace("税收", "").strip()
        if core and len(core) >= 2 and (core in title or core in summary or any(core in t for t in tags)):
            return True, f"core hit: {core}", sel
    return False, "no match", ""


def _hotspot_debug(lines):
    """把调试信息追加写到 server.py 同目录的 hotspot_debug.txt（不影响主逻辑）。"""
    try:
        dbg_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "hotspot_debug.txt")
        with open(dbg_path, "a", encoding="utf-8") as _f:
            _f.write("\n".join(lines) + "\n")
    except Exception:  # noqa: BLE001
        pass


def _chat_debug(lines):
    """把对话编排器的调试/错误信息追加写到 chat_debug.txt（不影响主逻辑）。"""
    try:
        dbg_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "chat_debug.txt")
        with open(dbg_path, "a", encoding="utf-8") as _f:
            _f.write("[%s] %s\n" % (datetime.datetime.now().isoformat(), "\n".join(lines)))
    except Exception:  # noqa: BLE001
        pass


def ai_hotspot(days, subfields):
    """抓取真实财税热点 + 生成创作角度建议。

    有 TAVILY_API_KEY：按所选子领域分别检索 Tavily，合并去重后生成选题；
    无 key（或检索失败）：降级为 deepseek_chat 基于模型知识生成（realtime=False）。
    返回 {"realtime": bool, "topics": [ ... ], "filtered": bool}。
    """
    ensure_env()
    subs = subfields or []
    dbg = ["=== %s days=%s subs=%s ===" % (str(datetime.datetime.now()), days, subs)]
    realtime = False
    raw_items = []
    fail_count = 0
    auth_fail = False
    tavily_degraded = False
    tavily_message = ""
    tavily_key = get_key("TAVILY_API_KEY")
    dbg.append("tavily_key_present=%s" % bool(tavily_key))
    if tavily_key:
        # 按每个子领域分别检索，避免一个宽泛 query 被 Tavily 带偏到近期热门但无关领域
        seen_urls = set()
        candidates = []
        queries = []
        if subs:
            # 限制活跃子领域数，避免调用过多导致响应过慢
            active_subs = subs[:5]
            for s in active_subs:
                queries.append(f"{s} 税务 政策 热点 案例")
        else:
            queries.append("财税 税务 政策 稽查 优惠 热点")

        def _fetch_one(q):
            try:
                # 只用 general，中文财税热点召回率最高；timeout 10s 防止网络 hang 住
                sr = tavily_search(q, tavily_key, topic="general", days=days, max_results=6, timeout=10)
                return (q, True, sr.get("results") or [], None, False)
            except Exception as e:  # noqa: BLE001
                err = str(e)
                # 鉴权类错误（401/403/expired/key invalid）强烈提示 key 失效；其余归为网络/接口异常
                auth = any(k in err.lower() for k in ("401", "403", "unauthor", "api key", "api_key", "expired", "forbidden", "invalid key"))
                return (q, False, [], err, auth)

        # 并发检索，整体最多等 20 秒；超时也保留已完成结果，不整批丢弃
        with ThreadPoolExecutor(max_workers=min(len(queries), 4)) as executor:
            future_to_q = {executor.submit(_fetch_one, q): q for q in queries}
            done, not_done = wait(future_to_q, timeout=20)
            fail_count = 0
            auth_fail = False
            for future in done:
                q, ok, results, err, auth = future.result()
                if ok:
                    dbg.append("tavily ok query=%s results=%s" % (q, len(results)))
                    for it in results:
                        url = it.get("url") or it.get("link") or ""
                        if not url or url in seen_urls:
                            continue
                        seen_urls.add(url)
                        candidates.append(it)
                else:
                    dbg.append("tavily fail query=%s err=%s" % (q, err))
                    fail_count += 1
                    if auth:
                        auth_fail = True
            if not_done:
                dbg.append("tavily timeout unfinished=%s (kept completed=%s)" % (len(not_done), len(done)))
        # 按时间倒排，保留前 8 条（控制 prompt 长度，降低 DeepSeek 超时概率）
        raw_items = sorted(
            candidates,
            key=lambda x: str(x.get("published_date") or ""),
            reverse=True,
        )[:8]
        realtime = len(raw_items) > 0

        # ---- Tavily 失效降级检测 ----
        # 有 key 但所有子领域检索均失败 → 标记降级；命中鉴权错误则强烈提示 key 失效。
        tavily_degraded = bool(tavily_key) and len(raw_items) == 0 and fail_count > 0
        tavily_auth_error = bool(tavily_key) and auth_fail
        tavily_message = ""
        if tavily_degraded:
            if tavily_auth_error:
                tavily_message = "Tavily API Key 可能已失效或过期（返回鉴权错误），热点已降级处理。"
            else:
                tavily_message = "Tavily 实时检索暂不可用（网络或接口异常），热点已降级处理。"
            # 回退到上次成功缓存的热点，保证页面不空（同时以红字提示）
            cached = _load_hotspot_cache()
            if cached and cached.get("topics"):
                _hotspot_debug(dbg + ["tavily degraded -> serve cached hotspots count=%s" % len(cached["topics"])])
                return {
                    "realtime": True,
                    "topics": cached["topics"],
                    "filtered": False,
                    "tavily_degraded": True,
                    "tavily_message": tavily_message + "（当前展示的是上次成功获取的缓存热点，可能偏旧）",
                    "from_cache": True,
                }

    _hotspot_debug(dbg + ["after tavily: realtime=%s raw_items=%s" % (realtime, len(raw_items))])

    subfields_text = "、".join(subs) if subs else "不限（通用财税热点）"
    prompt_base = HOTSPOT_PROMPT.replace("{{SUBFIELDS}}", subfields_text)

    if realtime and raw_items:
        items_text = "\n".join(
            f"- 标题：{it.get('title', '')}\n  摘要：{(it.get('content', '') or '')[:180]}\n  时间：{it.get('published_date', '')}\n  url：{it.get('url', '')}"
            for it in raw_items[:8]
        )
        prompt = prompt_base + f"\n\n【检索到的真实财税热点（近 {days} 天）】\n" + items_text + \
                 f"\n\n请基于以上 {len(raw_items)} 条真实热点，产出 3-6 个选题卡片（JSON 数组）。"
    else:
        # 【合规硬约束】无实时检索数据时，禁止让模型凭记忆"生成近期热点"。
        # 财税政策时效性极强（税率/起征点/优惠口径/征管口径频繁调整），模型基于训练数据
        # 编造的"近期热点"极可能是不实政策，属垂类最致命风险。宁可返回空，绝不能幻觉。
        _hotspot_debug(dbg + ["BLOCKED: no realtime data, refuse model-generated hotspots"])
        return {
            "realtime": False,
            "topics": [],
            "total": 0,
            "returned": 0,
            "filtered": False,
            "tavily_degraded": True,
            "tavily_message": (
                "热点功能暂不可用：未配置实时检索（TAVILY_API_KEY）或检索无结果且无可用缓存。"
                "财税政策时效性强，为避免生成不实政策内容，已禁用基于模型记忆的热点生成。"
            ),
            "from_cache": False,
            "blocked": True,
        }

    cfg = get_text_config()
    _hotspot_debug(dbg + ["before deepseek prompt_len=%s" % len(prompt)])
    deepseek_failed = False
    try:
        content = deepseek_chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=45)
        _hotspot_debug(["deepseek ok content_len=%s" % len(content)])
    except Exception as e:  # noqa: BLE001
        _hotspot_debug(["deepseek fail: " + str(e)])
        deepseek_failed = True
        content = ""
    obj = _extract_json_array(content)
    topics = obj if isinstance(obj, list) else []

    # DeepSeek 调用失败/超时且已有真实检索结果时，直接基于 raw_items 生成兜底选题，
    # 避免用户长时间等待后得到空结果。
    if deepseek_failed and realtime and raw_items:
        fallback_topics = []
        for idx, it in enumerate(raw_items[:6], 1):
            title = str(it.get("title") or "").strip()
            summary = str(it.get("content") or "").strip()[:120]
            if not title:
                continue
            fallback_topics.append({
                "title": title[:40],
                "summary": summary or title,
                "tags": subs[:2] or ["财税热点"],
                "heat_score": 500,
                "published_at": str(it.get("published_date") or "近期"),
                "hook": "",
                "source_url": it.get("url") or "",
                "matched_sub": subs[0] if subs else "",
                "angles": [
                    {"name": "政策解读", "suggestion": "从政策背景切入，点明对企业老板的影响与应对建议。", "form": "scroll_male"},
                    {"name": "案例警示", "suggestion": "结合行业案例，突出风险点与合规动作。", "form": "avatar"},
                ],
            })
        topics = fallback_topics
        _hotspot_debug(["fallback from raw_items count=%s" % len(topics)])
    out = []
    dbg = []
    dbg.append("=== %s days=%s subs=%s ===" % (str(datetime.datetime.now()), days, subs))
    dbg.append("realtime=%s raw_items_count=%s" % (realtime, len(raw_items)))
    for it in raw_items:
        dbg.append("  raw: %s" % str(it.get("title", ""))[:80])
    dbg.append("deepseek_content_len=%s generated_count=%s" % (len(content), len(topics)))
    for t in topics:
        if not isinstance(t, dict):
            dbg.append("  SKIP non-dict")
            continue
        angles = []
        for a in (t.get("angles") or []):
            if not isinstance(a, dict):
                continue
            f = a.get("form") or ""
            if f not in ("avatar", "motion", "scroll", "manga", "whiteboard", "card",
                         "scroll_male", "scroll_female", "scroll_dual"):  # 兼容旧值
                f = "motion"
            angles.append({
                "name": str(a.get("name") or ""),
                "suggestion": str(a.get("suggestion") or ""),
                "form": f,
            })
        tags = [str(x) for x in (t.get("tags") or []) if str(x).strip()][:6]
        # 强过滤：与所选子领域无关的选题不要返回（检查 title/summary/tags，并拦截硬编）
        matched, reason, msub = _topic_match(t, subs)
        dbg.append("  topic matched=%s reason=%s title=%s tags=%s" % (
            matched, reason, str(t.get("title", ""))[:60], tags[:3]))
        if subs and not matched:
            continue
        out.append({
            "title": str(t.get("title") or ""),
            "summary": str(t.get("summary") or ""),
            "tags": tags,
            "heat_score": t.get("heat_score") if isinstance(t.get("heat_score"), (int, float)) else "",
            "published_at": str(t.get("published_at") or ""),
            "hook": str(t.get("hook") or ""),
            "source_url": str(t.get("source_url") or ""),
            "matched_sub": msub,
            "angles": angles,
        })
    # 写调试文件（不影响线上返回）
    try:
        with open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "hotspot_debug.txt"), "a", encoding="utf-8") as _f:
            _f.write("\n".join(dbg) + "\n")
    except Exception:  # noqa: BLE001
        pass
    # filtered 仅表示"本次结果经过子领域过滤"：
    # - 当原始生成数量 > 0 且过滤后数量减少时，标记 filtered=True
    # - 即使只剩 1-2 条也返回，不再强制清空，避免误杀相关选题
    filtered = bool(subs) and len(topics) > 0 and len(out) < len(topics)

    # 实时检索成功时缓存一份热点，供 Tavily 失效时回退展示（避免页面空白）
    if realtime and out:
        _save_hotspot_cache({
            "topics": out,
            "saved_at": str(datetime.datetime.now()),
            "days": days,
            "subs": list(subs),
        })

    return {
        "realtime": realtime,
        "topics": out,
        "total": len(topics),
        "returned": len(out),
        "filtered": filtered,
        "tavily_degraded": tavily_degraded,
        "tavily_message": tavily_message,
        "from_cache": False,
    }


# ---- 热点缓存（Tavily 失效时回退，避免页面空白）----
_HOTSPOT_CACHE_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "hotspot_cache.json")

def _save_hotspot_cache(payload):
    try:
        with open(_HOTSPOT_CACHE_PATH, "w", encoding="utf-8") as _f:
            json.dump(payload, _f, ensure_ascii=False)
    except Exception:  # noqa: BLE001
        pass

def _load_hotspot_cache():
    try:
        if os.path.exists(_HOTSPOT_CACHE_PATH):
            with open(_HOTSPOT_CACHE_PATH, "r", encoding="utf-8") as _f:
                return json.load(_f)
    except Exception:  # noqa: BLE001
        pass
    return None


# ---- 视频/音频转文字（本地 FunASR，封装 ffmpeg 抽音轨）----
def ai_transcribe(source, language="zh"):
    """视频/音频转文字：ffmpeg 抽音轨 → FunASR only_asr。
    source: dict，含 video_b64 / video_url / file_path 之一。
    返回 {ok, text, duration_sec, mode, chars} 或 {ok:False, error}。
    """
    global only_asr
    if only_asr is None:
        return {"ok": False, "error": "ASR 模型未加载（funasr_asr 不可用），请联系运维"}
    import base64
    tmp_dir = JOBS_DIR
    os.makedirs(tmp_dir, exist_ok=True)
    video_path = None
    wav_path = None
    try:
        if source.get("video_b64"):
            video_path = os.path.join(tmp_dir, "transcribe_%s.mp4" % uuid.uuid4().hex)
            with open(video_path, "wb") as _f:
                _f.write(base64.b64decode(source["video_b64"]))
        elif source.get("video_url"):
            video_path = os.path.join(tmp_dir, "transcribe_%s.mp4" % uuid.uuid4().hex)
            resp = requests.get(source["video_url"], timeout=60)
            resp.raise_for_status()
            with open(video_path, "wb") as _f:
                _f.write(resp.content)
        elif source.get("file_path"):
            video_path = source["file_path"]
        else:
            return {"ok": False, "error": "video_b64 / video_url / file_path 至少一项"}
        if not os.path.exists(video_path):
            return {"ok": False, "error": "视频文件不存在"}
        # 抽单声道 16k 音轨
        wav_path = os.path.join(tmp_dir, "transcribe_%s.wav" % uuid.uuid4().hex)
        subprocess.run(
            [FFMPEG, "-y", "-i", video_path, "-vn", "-ac", "1", "-ar", "16000", "-f", "wav", wav_path],
            capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=120,
        )
        if not os.path.exists(wav_path):
            return {"ok": False, "error": "音轨提取失败"}
        # 时长
        duration_sec = 0.0
        try:
            prob = subprocess.run(
                [FFPROBE, "-v", "error", "-show_entries", "format=duration", "-of", "json", video_path],
                capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=30)
            duration_sec = float(json.loads(prob.stdout).get("format", {}).get("duration", 0) or 0)
        except Exception:  # noqa: BLE001
            pass
        text = (only_asr(wav_path, language) or "").strip()
        return {"ok": True, "text": text, "duration_sec": duration_sec, "mode": "fun-asr-nano", "chars": len(text)}
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "error": str(e)}
    finally:
        for p in (video_path, wav_path):
            try:
                if p and p.startswith(tmp_dir) and os.path.exists(p):
                    os.remove(p)
            except Exception:  # noqa: BLE001
                pass


# ---- 爆款结构拆解（纯文案结构分析，DeepSeek，不依赖视频视觉理解）----
DISSECT_PROMPT = """你是一位资深短视频内容拆解师，同时是财税行业顾问。
任务：把一段爆款短视频的逐字稿，拆解出可复用的内容结构骨架，供创作者学习其「结构、节奏、选题角度」，从而产出自己的原创版本。
严格输出 JSON：
{
  "hook_type": "痛点直击 | 悬念提问 | 反常识 | 身份共鸣 | 数据冲击 | 利益承诺",
  "pain_points": ["命中的老板/用户痛点1", "..."],
  "case_evidence": ["用到的案例或数据证据1", "..."],
  "emotion_rhythm": [{"sec":0,"emotion":"紧张/焦虑","note":"开头抛风险"}],
  "structure": [{"sec":0,"content":"该时间段对应的文案要点","emotion":"焦虑","camera_hint":"近景怼脸/字幕强调风险点（仅作运镜建议）"}],
  "reusable_parts": ["可学习的结构部分1", "..."],
  "must_replace": ["原视频人物/肖像", "原配音声线", "原客户具体名称与隐私数据", "原平台水印/logo"],
  "rewrite_suggestions": ["二创方向建议1", "..."]
}
要求：
- 只输出 JSON，不要任何解释文字。
- sec 为相对视频起点的秒数估算（按语速≈4-5字/秒粗算，允许区间）。
- camera_hint 一律表述为「运镜/景别/字幕建议」，不承诺动作 1:1 像素复制。
- must_replace 必须包含「人物/肖像、配音声线、具体客户名称与隐私数据、平台水印/logo」四类，作为合规硬约束。
- 合规底线：仅做结构学习参考，不鼓励照搬原视频画面、声音或具体客户隐私。
"""


def ai_dissect(text, platform=None, industry=None):
    """爆款结构拆解：纯文案结构分析（DeepSeek），不依赖视频视觉理解。
    返回结构化 dict（见 DISSECT_PROMPT）。无 key 时降级 rule_dry。"""
    cfg = get_text_config()
    text = (text or "").strip()
    # 规则降级（无 LLM key）
    if not cfg.get("key"):
        return {
            "ok": True, "mode": "rule_dry",
            "hook_type": "待分析", "pain_points": [], "case_evidence": [],
            "emotion_rhythm": [], "structure": [],
            "reusable_parts": ["开头钩子结构", "痛点→解法→案例→留资的叙事节奏"],
            "must_replace": ["原视频人物/肖像", "原配音声线", "原客户具体名称与隐私数据", "原平台水印/logo"],
            "rewrite_suggestions": ["用你的行业案例替换原案例", "保留结构、换成本行业痛点", "结尾留资话术本地化"],
        }
    prompt = DISSECT_PROMPT
    if industry:
        prompt += f"\n行业背景：{industry}。\n"
    if platform:
        prompt += f"发布平台：{platform}。\n"
    prompt += f"\n【待拆解逐字稿】\n{text}\n\n请基于以上逐字稿输出拆解 JSON。"
    try:
        content = deepseek_chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=90)
        content = content.strip()
        if content.startswith("```"):
            content = content.split("```")[1]
        obj = json.loads(content)
        obj["ok"] = True
        obj["mode"] = "llm"
        for k in ("hook_type", "pain_points", "case_evidence", "emotion_rhythm",
                  "structure", "reusable_parts", "must_replace", "rewrite_suggestions"):
            obj.setdefault(k, "" if k == "hook_type" else [])
        return obj
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "mode": "rule_fallback", "error": str(e),
                "hook_type": "待分析", "pain_points": [], "case_evidence": [],
                "emotion_rhythm": [], "structure": [],
                "reusable_parts": [], "must_replace": [], "rewrite_suggestions": []}


def ai_follow_hot(text, platform=None, industry=None):
    """对标爆款 → 财税仿写：拆解对标文案结构，产出原创财税口播稿。
    返回 {"ok": True, "dissect": {...}, "rewrite": {...}} 或 {"ok": False, "error"}。"""
    text = (text or "").strip()
    if not text:
        return {"ok": False, "error": "对标文案为空"}
    cfg = get_text_config()
    if not cfg.get("key"):
        return {"ok": False, "error": "未配置文本模型 key"}

    # 1) 拆解对标结构
    dissect = ai_dissect(text, platform, industry)

    # 2) 财税仿写（结构照搬 + 案例替换 + 违禁词红线）
    try:
        fb = forbidden_words.build_guidance()
    except Exception:  # noqa: BLE001
        fb = ""
    ind = f"行业背景：{industry or '财税税务咨询'}。\n"
    plat = f"发布平台：{platform}。\n" if platform else ""
    prompt = (
        "你是财税行业短视频仿写专家。基于对标爆款文案的结构骨架，产出一篇我们自己的原创财税口播稿。\n"
        + ind + plat
        + "仿写原则：\n"
        "- 结构照搬对标（钩子类型、节奏、段落结构），内容 100% 原创，不照抄原文\n"
        "- 案例换成我们自己的财税场景（虚开发票/金税四期/个人卡收款/挂靠经营/稽查应对等）\n"
        "- 语气像跟老板聊天，不居高临下，口语化但财税术语准确\n"
        "- 结尾带留资钩子（引导评论/私信，自然不生硬）\n"
        + fb + "\n"
        "严格输出 JSON（不要解释、不要 markdown 代码块）：\n"
        '{"title":"标题(≤18字,戳痛点)","hook_type":"痛点直击/悬念提问/反常识/身份共鸣/数据冲击/利益承诺",'
        '"opening":"开头(1-2句钩子)","body":"正文(3-5句,一句一意)","ending":"结尾(留资钩子1-2句)",'
        '"topics":["话题1","话题2","话题3"]}\n\n'
        f"【对标文案】\n{text[:800]}\n\n"
        f"【对标结构拆解参考】hook={dissect.get('hook_type', '')} "
        f"痛点={', '.join(dissect.get('pain_points', [])[:3])}\n"
        f"可复用结构={', '.join(dissect.get('reusable_parts', [])[:4])}\n"
    )
    try:
        content = deepseek_chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=90)
        content = (content or "").strip()
        if content.startswith("```"):
            content = content.strip("`")
            if content[:4].lower() == "json":
                content = content[4:]
            content = content.strip()
        obj = json.loads(content)
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "error": "仿写失败：" + str(e)[:160], "dissect": dissect}

    return {"ok": True, "dissect": dissect, "rewrite": obj}


def ai_topic(industry, keywords, count, platform=None, hotness=None, hook=None, form=None):
    """智能选题：用 DeepSeek 生成短视频选题建议列表。返回 list[dict]。
    支持维度筛选：platform(平台)/hotness(热度)/hook(钩子类型)/form(呈现形式)。行业为通用维度。"""
    cfg = get_text_config()
    cnt = max(1, min(10, int(count or 5)))
    # 维度约束（用户在前端筛选，强约束 AI 输出方向）
    dim_hints = []
    if platform:
        dim_hints.append(f"目标平台：{platform}（据此调整钩子话术与呈现节奏，贴合该平台调性）")
    if hotness:
        dim_hints.append(f"热度取向：{hotness}（优先选取{'当下高热度/热议' if '高' in hotness else '常规稳健可复用'}的痛点方向）")
    if hook:
        dim_hints.append(f"钩子类型：{hook}（每条选题结尾的 hook 必须围绕「{hook}」设计）")
    if form:
        dim_hints.append(f"呈现形式：{form}（每条选题的 form 字段固定为「{form}」）")
    dim_block = "\n".join(f"- {h}" for h in dim_hints) if dim_hints else ""
    prompt = (
        f"你是财税短视频选题策划，服务对象是「{industry or '中小企业'}」老板/企业主。\n"
        f"结合关键词「{keywords or '该行业老板的真实经营场景、财税痛点'}」，"
        f"生成 {cnt} 个面向该行业老板的财税垂直选题。\n"
        "硬性要求：\n"
        "- 选题必须与财税直接相关（税务/发票/成本/利润/合规/稽查/社保/个税/现金流等），围绕该行业老板的真实经营场景；\n"
        "- 选题语气像给老板提醒风险或讲清楚一件事，不空泛、不脱离财税；\n"
        + (f"- 维度约束（必须满足）：\n{dim_block}\n" if dim_block else "")
        + "每个选题严格按 JSON 数组输出，元素结构：\n"
        '{"title":"标题(吸睛、戳老板痛点,≤18字)","angle":"切入角度/财税痛点","potential":"爆款潜力理由","hook":"结尾留资钩子建议","form":"建议形式，取值：avatar(数字人)/motion(幕后音·动态画面)/scroll(幕后音·滚动字幕)/manga(AI漫剧)/whiteboard(AI白板图解)/card(图解版·信息卡片解说)"}\n'
        "只输出 JSON 数组，不要任何解释或代码块标记。"
    )
    raw = deepseek_chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=90)
    # 归一化：deepseek_chat 正常返回 str；个别分支可能返回 dict，统一成 str
    if isinstance(raw, dict):
        raw = raw.get("content") or json.dumps(raw, ensure_ascii=False)
    content = (raw or "").strip()
    # 去除 ```json ... ``` 代码块包裹
    if content.startswith("```"):
        content = content.strip("`")
        if content[:4].lower() == "json":
            content = content[4:]
        content = content.strip()
    # 从模型输出中提取 JSON 数组（容忍前后多余文本 / 包装对象）
    arr = _extract_json_array(content)
    if arr is None:
        return None   # 让上层感知失败，避免把"解析失败"当成正常角度塞给用户
    # 归一化为 list[dict]，保证前端安全遍历
    topics = []
    for item in arr:
        if isinstance(item, dict):
            topics.append({
                "title": str(item.get("title", "") or "")[:60] or "未命名选题",
                "angle": str(item.get("angle", "") or "")[:200],
                "potential": str(item.get("potential", "") or "")[:200],
                "hook": str(item.get("hook", "") or "")[:200],
                "form": str(item.get("form", form or "短视频") or "")[:20] or (form or "短视频"),
            })
        elif isinstance(item, str) and item.strip():
            topics.append({"title": item.strip()[:60], "angle": "", "potential": "", "hook": "", "form": form or "短视频"})
    if not topics:
        return None
    return topics[:cnt]


def _build_role_instruction(role_mode, role_note, keep_manual_roles, mode):
    """根据用户选择的角色/声音分配生成 prompt 角色指令。

    核心对齐规则：
    - 单人呈现形式（单人数字人出镜 / 男声幕后音 / 女声幕后音）属于「单声线独白」，
      不需要「男：/女：」角色前缀，否则会让数字人看起来在念台词、或与所选呈现形式矛盾。
    - 只有男女对话幕后音才需要用「男：」「女：」前缀区分对话角色。
    """
    if keep_manual_roles:
        return ("原稿中已包含「男：」「女：」等对话角色标注，请严格保留这些前缀，只改写前缀后的内容。"
                "每行仍必须保留原有角色前缀，不要新增或删除前缀。")

    rm = (role_mode or "").strip() or "auto"
    if rm == "custom" and role_note and str(role_note).strip():
        return (f"请按以下角色分配进行改写：\n{role_note.strip()}\n"
                "注意：单一句子不要拆成多行，每行是一句完整的角色台词。")

    # single_* / narrator_* 统一要求：不加任何角色前缀，输出纯口播稿。
    # 声线由后续配音环节（voice_form）控制，而不是靠文本前缀。
    single_male_inst = (
        "单人口播，全程由男声讲述。这是单人独白稿，不要输出任何「男：」「女：」角色前缀，"
        "直接输出自然流畅的口播内容。"
    )
    single_female_inst = (
        "单人口播，全程由女声讲述。这是单人独白稿，不要输出任何「男：」「女：」角色前缀，"
        "直接输出自然流畅的口播内容。"
    )
    narrator_male_inst = (
        "男声幕后音解说，以客观口吻讲述。这是单声线幕后音稿，不要输出任何「男：」「女：」角色前缀，"
        "直接输出讲述内容。"
    )
    narrator_female_inst = (
        "女声幕后音解说，以客观口吻讲述。这是单声线幕后音稿，不要输出任何「男：」「女：」角色前缀，"
        "直接输出讲述内容。"
    )

    mapping = {
        "single_male": single_male_inst,
        "single_female": single_female_inst,
        "dual_female_lead": (
            "男女双声对话，**永远女问男答**：女声只负责开场问好、提问、抛场景、追问确认，绝不解答专业问题；"
            "所有专业解答、法条引用、结论建议一律由男声（张老师，财税专家）给出，体现男声的专家形象，"
            "但男声不得自报从业年限、不得自我标榜资历。"
            "女声可以称呼男声为「张老师」（如「张老师，我有个事想问您」「张老师您看这样行吗」），"
            "开头或关键处称呼即可，不必每轮都叫。"
            "女声提问/承接可带自然语气词（「哦，这样啊」「明白了」），显得像真在听；"
            "**男声解答直接切入正题，不要刻意加'嗯''好的''对的''是的''行'等应答语气词**（刻意加显得闷、像背词），"
            "仅句间自然转折需要时才用一个连接词（「那」「其实」「关键在」）。"
            "每行以「女：」或「男：」开头，交替自然。"
        ),
        "dual_male_lead": (
            "男女双声对话，男声（张老师，财税专家）开口引出话题，女声提问/补充，男声解答。"
            "所有专业解答一律由男声给出，女声只提问与承接，可以称呼「张老师」。"
            "男声解答直接切入正题，不刻意加'嗯/好的/对的'等应答语气词。"
            "每行以「男：」或「女：」开头，交替自然。"
        ),
        "narrator_male": narrator_male_inst,
        "narrator_female": narrator_female_inst,
        "auto": "请根据内容自动判断：若适合男女对话，用「男：」「女：」前缀区分角色；若适合单人讲述，直接输出纯口播稿，不要默认加「男：」「女：」角色前缀。",
    }
    base = mapping.get(rm, mapping["auto"])

    # role_mode 未指定时，回退兼容旧 mode 语义
    if rm == "auto":
        if mode == "single":
            base = single_male_inst
        elif mode == "dual":
            base = mapping["dual_female_lead"]
        elif mode == "script":
            base = narrator_male_inst
    return base


def _compress_to_target(text, max_chars, cfg=None):
    """
    把 text 压缩到 max_chars 字以内。先用 LLM 压缩一次；若仍超则规则化截断。
    保留开头钩子、核心观点/案例/数据句、结尾钩子。
    """
    if cfg is None:
        cfg = get_text_config()
    prompt = (
        f"你是短视频脚本编辑。下面稿子共 {len(text)} 字，请严格精简到 {max_chars} 字以内（含标点），"
        f"只输出稿子本身，不要解释、不要标题。\n"
        f"保留要求：开头吸引点、1-2 个核心观点/真实案例/关键数据、结尾行动钩子。\n"
        f"删除要求：重复解释、客套话、过渡铺垫、抽象大道理。\n\n{text}"
    )
    try:
        compressed = deepseek_chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=60)
        compressed = compressed.strip()
        if compressed.startswith("```"):
            compressed = compressed.split("```")[1]
        compressed = compressed.strip()
        if len(compressed) <= max_chars and len(compressed) >= max_chars * 0.3:
            return compressed
    except Exception as e:
        traceback.print_exc()

    # LLM 压缩失败或仍超：规则化截断
    import re
    # 按句拆分（保留标点在句内）
    sents = re.split(r"(?<=[。！？；.!?;])", text.replace("\n", ""))
    sents = [s.strip() for s in sents if s.strip()]
    if not sents:
        return text[:max_chars]

    # 保留首句和尾句
    kept = [sents[0]]
    if len(sents) > 1:
        kept.append(sents[-1])
    budget = max_chars - sum(len(s) for s in kept)

    # 中间句按重要性打分
    keywords = set(["税", "风险", "稽查", "处罚", "罚款", "案例", "老板", "个人卡", "虚开", "发票", "合规", "节税", "成本", "利润", "被查", "滞纳金", "刑事责任", "金税", "数据", "%", "万", "元"])
    scored = []
    for s in sents[1:-1]:
        score = 0
        for kw in keywords:
            if kw in s:
                score += 2
        # 含数字加分
        if re.search(r"\d", s):
            score += 3
        # 句子本身不能太长
        score -= max(0, len(s) - 60) * 0.05
        scored.append((score, s))
    scored.sort(key=lambda x: x[0], reverse=True)

    body = []
    used = 0
    for score, s in scored:
        if used + len(s) <= budget:
            body.append(s)
            used += len(s)
        else:
            break
    # 按原文顺序拼接
    body_set = set(body)
    result = kept[0]
    for s in sents[1:-1]:
        if s in body_set:
            result += s
            body_set.discard(s)
    result += kept[-1]
    # 兜底硬截断
    if len(result) > max_chars:
        result = result[:max_chars]
        # 截到最近一个句号
        last_dot = max(result.rfind("。"), result.rfind("！"), result.rfind("？"))
        if last_dot > max_chars * 0.7:
            result = result[:last_dot + 1]
    return result


def ai_rewrite(text, mode, focus=None, target_duration=None, preserve=None,
               role_mode=None, role_note=None, keep_manual_roles=None, industry=None):
    """智能二创：多模式改写 + 角色/声音分配 + 违禁词标红/清洗。返回含元数据的完整结果。"""
    cfg = get_text_config()

    # 行业背景（选题带行业 → 二创保持同一口径：餐饮老板/电商老板…）
    ind_hint = ""
    if industry and str(industry).strip():
        ind_hint = (f"\n【行业背景】：这是面向「{str(industry).strip()}」老板的财税口播稿，"
                    f"请贴合该行业老板的真实经营场景与财税痛点（如该行业的收款方式、发票、成本、用工等），"
                    f"例子与措辞用该行业老板听得懂的话，但保持财税专业准确、不编造数据。\n")

    # 风格基调（由 mode 控制）
    # v4 定稿(2026-08-27): 专家风格 + 适当语气词 + 快慢高低结合; 严禁网红化/过度口语表述
    EXPERT_TONE = ("财税专家/实战顾问的口播风格：专业但不能端着，像老师傅把一件事给老板讲明白；"
                   "可适当带语气词（'啊''呢''吧''嘛'少量点缀）增强交流感；"
                   "语速快慢结合——重点警示、结论处放慢加重，铺垫衔接处自然加快；"
                   "音调有高低起伏，避免平铺直叙的播音腔；说话干脆利落、不拖长音。")
    NO_VULGAR = ("严禁出现以下网红化/过度口语/不正规表述：'说句大实话''掏心窝子话''不要找我哭''找我哭''老铁''家人们'"
                 "'姐妹们''宝子们''绝绝子''YYDS''划重点'等；若原文含有此类表述，一律替换为专业稳妥的说法"
                 "（如'到那时候后悔就晚了'）；保持专业可信，可以接地气但不能掉价。")
    # 叙事化铁律 v2(2026-08-27 用户定调): 稿子必须是"讲故事"不是"念文件"——
    # 书面腔在源头就锁死自然度; 但财税涉及法律引用, 口语化必须守专业边界:
    # 亲切是语气, 严谨是内容——"讲得动听"不能变成"讲得不准"
    NARRATIVE_RULE = (
        "【口播分寸铁律——讲故事，但守专业】\n"
        "- 整体用'讲真事'的自然口吻，像财税顾问/律师给老板讲事，亲切但不失专业，不是段子手；\n"
        "- 结构按起承转合：起(抛出一个老板熟悉的场景或问题)→承(展开讲清楚)→转(风险/关键转折，制造一点紧张感)"
        "→合(给结论和明确的行动建议)；\n"
        "- 允许用第一人称叙事增加个人色彩('我见过''我处理过''有老板问过我')，口吻真实可信、不吹牛；\n"
        "- 语气词('啊''呢''吧''嘛')、口语、个人色彩**只用于**：抛场景、拉家常、做提醒"
        "('有老板问我啊''说白了''你说值吗')；\n"
        "- **法律/政策部分必须原样准确、严肃规范，严禁口语化改写或简化失真**：\n"
        "  ①法条与罪名(如'刑法第二百零五条''虚开增值税专用发票罪')、政策与系统名称(如'金税四期')"
        "一律准确引用，不改写、不用黑话替代；\n"
        "  ②法律后果(刑期、罚款倍数、补缴范围)准确表述，不夸大、不缩水；\n"
        "  ③法条中的数字必须原样保留语义：刑期、罚金数额、'以上/以下/以下/以上'的边界"
        "一律写全称（如'二万元以上二十万元以下罚金'），严禁简化为区间缩写（如'2-20万'）；\n"
        "  ④专业术语保留原词，必要时加一句通俗解释帮老板听懂，但术语本身不变；\n"
        "- 严禁书面腔连接词：'综上所述''此外''值得注意的是''换言之''首先其次最后'等；改用口语连接"
        "('说白了''关键在哪''这里要提醒你')；\n"
        "- 句子长短交替：铺垫可稍长，关键警示句短促有力。\n"
        + expert_years_clause() +
        "- **忠实原稿精神**：原稿的核心观点与**合法**的业务建议一律保留并照实表达，保持原稿的逻辑顺序；\n"
        "- **合规底线（硬约束，优先级高于忠实原稿）**：\n"
        "  ①原稿若涉及实务中的变通做法、灰色操作或疑似规避纳税义务的表述，**必须补上风险边界与适用条件**，"
        "并说明该操作的法律后果与稽查风险；\n"
        "  ②严格区分「合法筹划」与「违规操作」：合法筹划可以讲并注明政策依据；"
        "**违规操作（虚开、阴阳合同、私户隐匿收入、虚假申报、买卖发票等）一律拒绝生成**，不做任何变通表述；\n"
        "  ③涉及具体金额、比例、扣除标准的表述，必须附「具体以主管税务机关口径为准」；\n"
        "  ④严禁出现「包过」「零风险」「绝对安全」「保证不退查」等绝对化承诺；\n"
        "  ⑤明确的刑事犯罪红线（虚开增值税专用发票罪、逃税罪、诈骗罪等）必须保留风险警示与法条引用。\n"
        "- **上下文连贯**：严格保持原稿的逻辑顺序（先讲什么、后讲什么不调换），"
        "重要的因果、转折、衔接信息不丢；删减以'合并同类、去重复'为主，不能删掉上下文的承接关系。\n"
    )
    if mode == "single":
        style = ("单人口播。\n" + EXPERT_TONE)
    elif mode == "script":
        style = ("单人数字人出镜口播稿（保留行业术语与权威感，结构清晰、重点突出，适合直接配音）。\n" + EXPERT_TONE)
    elif mode == "content":
        # 2026-08-31 内容规整模式（AI 漫剧/白板图解用）：
        # 产出"财税内容稿"（事件/数据/要点完整、逻辑清晰、无口播腔），供漫剧分镜/白板提炼直接使用。
        style = ("「内容规整稿」——不是口播稿，而是给下游 AI 出片（AI漫剧分镜 / AI白板图解提炼）直接消费的财税内容文本：\n"
                 "- 保留原稿的全部事实要素：人物/事件/金额/天数/流程/后果/结论，不删减关键数据；\n"
                 "- 语言平实准确、条理清晰，按『背景→问题→风险→建议』组织，可用分点/短段落；\n"
                 "- 不添加口播语气词、不做悬念钩子、不写'老板们注意了'式开场白；\n"
                 "- 法条/政策/数字保持原样准确，严禁简化或失真（如'二万元以上二十万元以下罚金'不缩写为'2-20万'）；\n"
                 "- 结尾给一句明确结论或行动建议。\n" + EXPERT_TONE)
    else:
        style = ("双声对话：**永远女问男答**——女声(亲切提问/抛场景/称呼'张老师')，"
                 "男声(财税专家'张老师'，负责所有专业解答，可信但不自夸)；"
                 "男声解答直接切入正题，**不刻意加'嗯/好的/对的/是的'应答语气词**(刻意加显得闷)；"
                 "女声提问可带自然语气词。\n"
                 + EXPERT_TONE)

    role_instruction = _build_role_instruction(role_mode, role_note, keep_manual_roles, mode)

    focus_hint = ""
    if focus and isinstance(focus, str) and focus.strip():
        focus_hint = f"\n【用户指定的重点方向】：{focus.strip()} — 请在改写中特别强化这个方向的内容比重与表达力度。\n"

    # 目标时长约束：130–160 字/分 ≈ 2.17–2.67 字/秒；预估按 2.4 字/秒
    dur_hint = ""
    chars_low = None
    chars_high = None
    if target_duration is not None:
        try:
            secs = int(target_duration)
            if secs > 0:
                chars_low = max(30, round(secs * 130 / 60))   # 慢速约 130字/分
                chars_high = round(secs * 160 / 60)             # 快速约 160字/分
                dur_hint = (f"\n【目标时长约束】：用户要求改写稿严格控制在 {secs} 秒的视频长度，"
                            f"字数必须落在 {chars_low}–{chars_high} 字之间（按中文口播 130–160 字/分钟）。"
                            f"这是硬性要求：若初稿超过 {chars_high} 字必须删减冗余、精简表达；"
                            f"若不足 {chars_low} 字必须补充细节或案例；绝不允许超出该范围。\n")
        except (ValueError, TypeError):
            pass

    # 保留要素约束
    preserve_hint = ""
    if preserve and isinstance(preserve, str) and preserve.strip():
        items = [line.strip() for line in preserve.strip().splitlines() if line.strip()]
        if items:
            preserve_hint = ("\n【必须保留的要素】（以下内容在改写时绝对不能删除、替换或改写，"
                             "必须原样保留在输出稿中）：\n" +
                             "\n".join(f"  • {item}" for item in items) + "\n")

    # content 模式（AI漫剧/白板图解）：不套口播叙事铁律与角色分配，产出内容规整稿
    if mode == "content":
        prompt = (
            f"你是财税内容编辑。请把下面的内容整理为一份「内容规整稿」，供 AI 出片系统直接消费。\n"
            f"{ind_hint}"  # 行业背景（选题行业贯穿到二创）
            f"{focus_hint}{preserve_hint}"
            f"{style}"  # content 的完整规则在 style 中
            "要求：保留全部事实要素与关键数据，条理清晰，语言准确平实，不添加口播语气词；"
            "只输出整理后的内容本身，不要解释、不要标题、不要代码块。\n\n"
            "原内容：\n" + text
        )
    else:
        prompt = (
            f"你是资深短视频脚本编辑。请把下面的稿子改写为「{style}」的自然口播稿。\n"
            f"{dur_hint}"  # 目标时长约束放在最前面、最显眼
            f"{ind_hint}"  # 行业背景（选题行业贯穿到二创）
            f"{NARRATIVE_RULE}"  # 叙事化铁律(起承转合/第一人称/语气词/禁书面腔)
            f"{NO_VULGAR}\n"
            f"【角色与声音分配】\n{role_instruction}\n"
            f"{focus_hint}{preserve_hint}"
            "要求：彻底去除AI机械感与书面腔，但保持专业准确性、不编造数据、不改原意；"
            "保留原意与关键结论；长短句结合、自然停顿；语气词适度点缀（每句至多一两个'啊/呢/吧'），不堆砌；"
            "对话感来自内容互动而非语气词；说话干脆直给。\n"
            "特别注意：结尾的警示/呼吁必须用专业提醒语气（如'到那时候后悔就晚了''早做打算才是上策'），"
            "严禁'哭''求'等人身化、夸张化表述。\n"
            "只输出改写后的稿子本身，不要解释、不要标题、不要代码块。\n\n"
            "原稿：\n" + text
        )
    rewritten = deepseek_chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=90)
    rewritten = rewritten.strip()
    if rewritten.startswith("```"):
        rewritten = rewritten.split("```")[1]

    # 目标时长硬闸：AI 返回后若仍超字数，自动压缩到目标范围
    if target_duration is not None and chars_high is not None and chars_high > 0:
        raw_chars = len(rewritten.replace(" ", "").replace("\n", ""))
        if raw_chars > chars_high:
            rewritten = _compress_to_target(rewritten, chars_high, cfg)

    hits = forbidden_words.scan(rewritten)
    cleaned = forbidden_words.clean_script(rewritten)
    # 二创专用硬替换: 网红化/人身化表述(LLM 对'哭'收尾执念强, prompt 约束不住 → 正则硬洗)
    for pat, rep in (("来找我哭|找我哭|再来哭|再哭", "到那时候后悔就晚了"),
                     ("说句大实话", "说句实在话"),
                     ("掏心窝子话", "推心置腹地讲")):
        cleaned = re.sub(pat, rep, cleaned)

    # 元数据：字数 + 预估时长（中文约 2.4 字/秒 ≈ 145 字/分钟，含自然停顿；与目标时长 130–160 字/分对齐）
    orig_chars = len(text.replace(" ", "").replace("\n", ""))
    clean_chars = len(cleaned.replace(" ", "").replace("\n", ""))
    est_sec = max(1, round(clean_chars / 2.4))

    return {
        "ok": True,
        "rewritten": rewritten,
        "hits": hits,
        "cleaned": cleaned,
        "meta": {
            "orig_chars": orig_chars,
            "clean_chars": clean_chars,
            "char_delta": clean_chars - orig_chars,
            "duration_est_sec": est_sec,
            "duration_fmt": f"{est_sec // 60}分{est_sec % 60}秒" if est_sec >= 60 else f"约{est_sec}秒",
            "hit_count": len(hits),
            "high_risk_count": len([h for h in hits if h.get("level") == "high"]),
        },
    }


def ai_qc(text, platform=None):
    """智能质检：违禁词扫描 + 时长预估 + 风险等级。返回 dict。"""
    hits = forbidden_words.scan(text, platform)
    chars = len(text)
    est_sec = max(1, round(chars / 2.4))  # 中文约 2.4 字/秒 ≈ 145 字/分钟（含停顿）
    high = [h for h in hits if h.get("level") == "high"]
    risk = "high" if high else ("medium" if hits else "low")
    return {
        "ok": True,
        "hits": hits,
        "chars": chars,
        "duration_est_sec": est_sec,
        "risk_level": risk,
        "suggestions": [h.get("suggest", "") for h in hits if h.get("suggest")],
    }


# ==================== 公众号长文（内置搜一搜 SEO 优化） ====================
# SEO 规则依据：微信搜一搜排名因素 + 微信 AI 搜索/元宝引用偏好(GEO)。
# 重要认知：百度/谷歌不收录公众号（mp.weixin.qq.com robots.txt 为 Disallow: /），
# 所以此处的 SEO = 微信站内搜索排名 + 被 AI 搜索引用，不是传统百度 SEO。
ARTICLE_PROMPT = (
    "你是财税顾问兼微信公众号主编，服务对象是中小企业老板与企业主。\n"
    "现在写一篇能被微信「搜一搜」搜到、并被微信 AI 搜索优先引用的公众号长文。\n\n"
    "【SEO 前提认知】\n"
    "- 百度/谷歌不收录公众号（mp.weixin.qq.com 的 robots.txt 为 Disallow: /），\n"
    "  所以这里的 SEO = 微信站内搜索排名 + 被 AI 搜索引用，不是百度 SEO，不要按百度套路堆词\n\n"
    "【标题规则】\n"
    "- 先给 3 个备选标题，每个一律 12-16 字；第 1 条为正式采用标题，同样 12-16 字\n"
    "- 主关键词必须落在标题前 12 字内\n"
    "- 主关键词全标题只出现 1 次，第二个位置留给修饰词（年份/地域/人群）\n"
    "- 句式五选一：数字+痛点+方案 / 疑问式 / 地域+事项+年份 / 对比式 / 人群+结果\n"
    "- 政策、税率、额度类标题必须带 4 位年份；非时效的干货类标题不要硬加年份\n"
    "- 标题禁感叹号（! 和 ！都不行），全文感叹号总共不超过 1 个\n"
    "- 标题禁词（绝对禁用，命中会被拦下重改）：怎么办、亏大了、血亏、躲坑、被骗、雷区、\n"
    "  彻底、这招、不好使、蒙混过关、红线变了、重大调整、一文读懂、深度解析、大量、惊呆、\n"
    "  刷屏、震惊、吓死、必须知道；以及一切标题党：紧急通知、不看后悔、国家刚宣布、重磅、\n"
    "  速看、内部消息、删前速看、必看\n\n"
    "【摘要规则】\n"
    "- 55-80 字\n"
    "- 主关键词出现 1 次，再埋 1 个次级搜索词（与核心词不同，取长尾词里的第一个）\n"
    "- 第一句直接给结论（微信 AI 搜索优先抽取摘要段，不要写悬念式废话）\n\n"
    "【正文规则】\n"
    "- 前 100-200 字内主关键词出现 1-2 次，并直接给出全文核心答案\n"
    "- 主关键词在【全正文】出现 3-5 次为佳，上限 8 次；超过 8 次算堆砌，会被搜一搜降权。\n"
    "  以整篇出现的总次数为准，不要自己去估算占比\n"
    "- 长尾词（比主词更具体更长的搜索词）每个【全篇只出现 1-2 次】；\n"
    "  第 3 次起必须换说法，用「这类情况」「上述情形」「该处理」「这笔业务」等代词或更短的表述替代；\n"
    "  同一长尾词重复 3 次以上会被搜一搜判为堆砌并降权——这是实测中最常见的失分点，\n"
    "  写的时候心里给每个长尾词记个数，写完通读一遍确认没有哪个词反复出现\n"
    "- 小标题 3-6 个，其中【至少 2 个必须写成用户会搜的问句】（含 ？/吗/怎么/多少/哪些/如何/为什么/啥/几）\n"
    "  ✅「甲供工程简易计税废止了吗」「清包工还能按3%吗」；❌「一、政策背景」「二、实务要点」\n"
    "  第一个小标题必须直接回答标题提出的问题\n"
    "- 每 300-500 字标注 1 处配图位置，写成【配图建议：画面描述】，描述要能被设计师直接执行\n"
    "- 篇幅严格按目标字数执行，误差控制在 ±15% 以内；宁可写少，绝不能写超\n\n"
    "【话题标签】\n"
    "- 正文最后必须单独一行输出 3-5 个话题标签，格式：#关键词 #关键词（井号后无空格）\n"
    "- 必须填用户真会搜的词，禁用品牌词（#慧根堂 #老张说税 这类搜不到，纯自嗨，会被拦下）\n"
    "- 第一个是精准长尾词，最后一个放大词（如 #财税干货），每个不超过 10 字\n"
    "- 这一行必须有，不能省略\n\n"
    "【排版硬约束】\n"
    "- 段首空两格，用全角空格（　）实现，不要用半角空格凑\n"
    "- 单段不超过 5 行，段与段之间空一行\n"
    "- 段落末行不要只剩 1-2 个字（孤行），微信排版里非常扎眼\n"
    "- 禁止 Markdown 列表（以 -、*、1. 开头的行）：微信编辑器会把它渲染成空黑点或孤立序号。\n"
    "  所有「三件事/四个要点/五步」一律改纯段落自然语句，用「第一件/第二件」「一是/二是」「首先/其次」连写\n"
    "- 小标题必须单独一行且以 `## ` 开头（例如「## 甲供工程简易计税废止了吗」）；\n"
    "  这是结构标记，系统会转成公众号排版，不会出现在成稿里，**必须写，否则 SEO 检测不到小节**\n"
    "- 除小标题的 `## ` 外，正文不要使用 **、-、> 等 Markdown 符号，纯文本分段、段首两个全角空格\n"
    "- emoji 全文不超过 2 个\n"
    "- 税率、金额、比例、日期一律用阿拉伯数字：13%、500万元、3000元、5月31日、2026年8月26日；\n"
    "  成语与固定搭配（一分为二、三令五申）里的汉字数字保留，法条原文照抄不改\n\n"
    "【内容铁律】\n"
    "- 不教逃税；严格区分「合法筹划」与「违规操作」，后者（虚开、阴阳合同、私户隐匿收入、\n"
    "  买卖发票、虚假申报）一律拒绝生成，不做任何变通表述\n"
    "- 政策、金额、比例可溯源；不确定就写'以主管税务机关口径为准'\n"
    "- 严禁编造政策文号、文件名称与生效日期\n"
    "- 不做硬广、不诱导：不出现价格、不出现加微信、不出现扫码、不出现联系方式，\n"
    "  不写「转发朋友圈」「集赞」「关注我们」这类诱导分享与关注的话术\n"
    "- 禁用第一人称'我'做人设（与口播稿规则相反，这是公众号专属），改用'我们''很多老板''实务中'\n"
    "- 严禁自我标榜资历：不写「深耕财税X年」「从业X年」「20多年经验」，也不用「资深」「权威」抬自己\n"
    "  ——读者想了解资历看账号简介即可，正文只讲事、不讲资历\n"
    "- 专业术语后跟一句大白话解释，让非财务出身的老板看得懂\n"
    "- 涉及金额、比例、扣除标准处，附'具体以主管税务机关口径为准'\n\n"
    "【时效与地域】\n"
    "- 政策类正文首段固定写：政策依据：XXX（财税〔YYYY〕X号），自 YYYY-MM-DD 起施行\n"
    "- 给了地域词就按链路铺：标题 1 次 → 摘要 1 次 → 首段 1 次 → 至少 1 个小标题 1 次\n\n"
    "【文章结构】\n"
    "1) 3 个备选标题（见输出格式）\n"
    "2) 开篇钩子：一个老板熟悉的真实场景，150 字内，让人对号入座\n"
    "3) 3-5 个带小标题的分节，逐层把问题讲透（是什么 / 为什么 / 怎么应对 / 注意什么）\n"
    "4) 结论段 + 结尾引导（CTA），CTA 只用给定的那一种，不要自创\n"
)


def _strip_model_meta(body):
    """清掉模型混进正文里的「自我纠正独白」与残留标记。

    实测案例：模型先输出一版，接着自己点评（"这段写得太像引用？…非常重要。
    现在用最终内容…重新输出正文如下"），再写第二遍，中间还夹着裸的【正文】标记。
    这类元叙述一旦进正文，成稿直接废掉，且人工不易发现。
    这里做三件事：删强特征元指令段、删正文内残留的【xx】标记行、去掉整段被引号包裹的外层引号。
    """
    if not body:
        return body
    # 这些词在财税正文里几乎不可能出现，误伤风险极低；限长 120 字避免误删长段落
    meta_pat = re.compile(
        r"重新输出|非常重要|不得再|上面我|这段写得|现在用最终|以下为最终|修正如下|"
        r"请忽略以上|重新生成|正文不要|不要加引号|保留首行|重新写一遍|我重新|"
        r"注意正文|注意不要|上面加了|实际上输出|开篇段落")
    out = []
    for line in body.splitlines():
        s = line.strip()
        if not s:
            continue
        # 正文里残留的裸标记行（如第二遍开头又写了个【正文】）
        if re.match(r"^【[^】]{1,8}】\s*$", s):
            continue
        if len(s) < 120 and meta_pat.search(s):
            continue
        # 整段被中文引号包裹时去掉外层引号，但保留原有缩进
        if len(s) > 20 and s.startswith("“") and s.endswith("”"):
            indent = line[:len(line) - len(line.lstrip())]
            line = indent + s[1:-1]
        out.append(line.rstrip())
    return "\n".join(out)


def _article_to_html(body):
    """把带 `## ` 小标题的纯文本正文转成公众号可用的 HTML。

    只处理两件事：`## 小标题` → <h2>，其余非空行 → <p>（段首全角空格原样保留）。
    刻意不做完整 Markdown 解析——正文里本就不该有其它标记，真遇到了也原样输出，
    避免解析器把内容当语法吃掉。
    """
    try:
        from html import escape as _esc
    except Exception:  # noqa: BLE001
        def _esc(s):
            return (s or "").replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
    out = []
    for line in (body or "").splitlines():
        s = line.strip()
        if not s:
            continue
        m = re.match(r"^#{2,3}\s+(.*)$", s)
        if m:
            out.append('<h2 style="margin:20px 0 10px;">%s</h2>' % _esc(m.group(1).strip()))
        else:
            # 段首缩进必须用 text-indent 样式：微信编辑器会折叠 HTML 里的普通空格，
            # 单纯保留段首全角空格不可靠。纯文本版 content 仍保留全角空格。
            out.append('<p style="text-indent:2em;margin:0 0 12px;line-height:1.75;">%s</p>'
                       % _esc(s))
    return "\n".join(out)


def ai_article(topic, kw_main="", kw_long="", region="全国", year="", words="2000",
               style="干货科普", cta="评论区留言", source=""):
    """生成公众号长文（SEO 原生内置）。

    返回 {ok,title,titles,digest,content,tags,seo,hits,hit_count,
          high_risk_count,auto_cleaned,meta}；
    seo_score / seo_report / word_count / target_words 为兼容旧前端保留的同源字段。

    全程 try/except：任何异常都转成 {"ok": False, "error": ...}，不向上抛。
    """
    try:
        return _ai_article_inner(topic, kw_main, kw_long, region, year,
                                 words, style, cta, source)
    except Exception as e:  # noqa: BLE001
        traceback.print_exc()
        return {"ok": False, "error": str(e)}


def _ai_article_inner(topic, kw_main, kw_long, region, year, words, style, cta, source):
    """ai_article 的实现主体（异常由外层统一兜底）。"""
    cfg = get_text_config() or {}
    if not cfg.get("key"):
        return {"ok": False, "error": "未配置文本模型"}

    topic = str(topic or "").strip()
    if not topic:
        return {"ok": False, "error": "缺少文章主题"}

    # words 可能是 1500/2000/2500/3000，也可能带单位（"2000字"）或"1500-2000"
    try:
        words = int(str(words).split(":")[0].split("-")[0].strip())
    except Exception:  # noqa: BLE001
        words = 2000
    words = max(800, min(4000, words))
    region = str(region or "全国").strip()
    kw_main = str(kw_main or "").strip() or topic[:12]
    kw_long = str(kw_long or "").strip()
    year = str(year or "").strip()
    style = str(style or "干货科普").strip() or "干货科普"
    cta = str(cta or "评论区留言").strip() or "评论区留言"

    # 违禁词前置引导（与口播稿同一套词库，但长文只硬拦 high 级，见下方后置处理）
    try:
        guidance = forbidden_words.build_guidance() or ""
    except Exception:  # noqa: BLE001
        guidance = ""

    src_part = ""
    if str(source or "").strip():
        src_part = "\n\n【参考源稿】（只作素材参考，不要照抄，可取其观点与案例）：\n" + str(source)[:3000]

    prompt = (
        ARTICLE_PROMPT + "\n\n"
        "【本次任务】\n"
        "- 主题：%s\n"
        "- 主关键词：%s（必须出现在标题前 12 字内）\n"
        "- 长尾词：%s\n"
        "- 地域词：%s\n"
        "- 时效年份：%s\n"
        "- 目标字数：%d 字【硬约束：实际字数必须接近这个数，宁可写少绝不能写超；"
        "写超 40%% 以上视为不合格】\n"
        "- 文章结构：%s\n"
        "- 结尾引导：%s\n"
        "%s%s\n\n"
        "请严格按以下四段输出。重要：只给最终成稿，不要输出任何思考过程、自我检查、\n"
        "修改说明，也不要把同一篇正文写两遍。不要代码块、不要多余说明：\n"
        "【标题】\n（3 个备选标题，一行一个；第 1 条为正式采用标题）\n\n"
        "【摘要】\n（55-80 字）\n\n"
        "【正文】\n（全文；小标题用 `## ` 开头单独成行，另含配图建议位与结尾引导）\n\n"
        "【标签】\n（3-5 个，形如 #关键词 #关键词）\n"
        % (topic, kw_main, kw_long or "（未提供，请你自行补充 2-3 个）",
           region or "全国（不强调地域）", year or "（按当前年份）",
           words, style, cta, src_part, ("\n" + guidance if guidance else ""))
    )

    try:
        raw = deepseek_chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=180)
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "error": "模型调用失败：%s" % e}
    if isinstance(raw, dict):
        raw = raw.get("content") or ""
    text = str(raw or "").strip()
    if not text:
        return {"ok": False, "error": "模型返回为空"}

    # —— 四段切分：标题 / 摘要 / 正文 / 标签 ——
    # 切不出来时不报错，整体当正文兜底（长文宁可少个摘要，也不能白跑一次 LLM）。
    title, digest, body, tag_text = "", "", text, ""
    m = re.search(r"【标题】\s*(.*?)\s*【摘要】", text, re.S)
    if m:
        title = m.group(1).strip()
    m = re.search(r"【摘要】\s*(.*?)\s*【正文】", text, re.S)
    if m:
        digest = m.group(1).strip()
    # 模型偶尔会先写一版、再自我点评一番（实测出现过"这段写得太像引用…重新输出正文如下"），
    # 然后输出第二遍。取【最后一个】【正文】块，避免独白和重复内容进正文。
    _parts = re.split(r"【正文】", text)
    _body_src = _parts[-1] if len(_parts) > 1 else text
    m = re.search(r"(.*?)\s*【标签】", _body_src, re.S)
    if m:
        body = m.group(1).strip()
    else:
        body = _body_src.strip()
    body = _strip_model_meta(body)
    m = re.search(r"【标签】\s*(.*)", text, re.S)
    if m:
        tag_text = m.group(1).strip()
        # 模型偶尔把标签直接续在正文末尾（没有【标签】段），这里把尾部标签段摘出正文
        body = re.sub(r"#\s*[^\s#]{1,10}(\s*#\s*[^\s#]{1,10})*\s*$", "", body).rstrip()

    # 标题：3 个备选，第 1 条为正式采用标题
    # 只去掉"1. ""2、""-"这类枚举前缀，不能用 strip("0123456789.")，
    # 否则"2026年苏州公司注销…"开头的年份会被削掉。
    titles = []
    for _t in title.splitlines():
        _t = re.sub(r"^\s*(?:\d{1,2}\s*[.、)]|[-—•*])\s*", "", _t).strip(" 　\t#").strip()
        if _t:
            titles.append(_t)
    titles = titles[:3]
    if not titles:
        titles = [(topic[:24] or "财税实务解读")]
    title = titles[0][:60]

    # 标签：【标签】段优先，兜底从正文里扫
    tags = re.findall(r"#\s*([^\s#,，、]{1,10})", tag_text or "")
    if not tags:
        tags = re.findall(r"#\s*([^\s#,，、]{1,10})", body)
    tags = tags[:5]

    # 摘要超 80 字主动截断：微信 digest 与搜一搜都只取前 80 字左右，
    # 让模型自由发挥经常写到 90+，被平台截断后会剩半句话，不如这里截到句读处
    if len(digest) > 80:
        digest = digest[:79].rstrip("，。、；：,;:　 ") + "…"

    # —— 违禁词后置处理：长文只硬拦 high 级 ——
    # 长文（1500-3500 字）里"最/第一/免费"这类弱词命中率远高于口播稿，
    # 照搬口播稿的"命中即清洗"会把文章改得面目全非，所以：
    #   high 级 → clean_script() 二次清洗 + auto_cleaned=True
    #   need_human 的弱命中 → 只统计不改写
    hits, auto_cleaned = [], False
    try:
        _scan = forbidden_words.scan(body)
        if isinstance(_scan, (list, tuple)):
            hits = [h for h in _scan if h]
        elif isinstance(_scan, dict):
            hits = list(_scan.get("hits") or [])
    except Exception:  # noqa: BLE001
        hits = []
    high = [h for h in hits
            if isinstance(h, dict) and h.get("level") == "high" and not h.get("need_human")]
    if high:
        try:
            cleaned = forbidden_words.clean_script(body)
            if cleaned:
                body = cleaned
                auto_cleaned = True
        except Exception:  # noqa: BLE001
            pass

    # —— 字数超标自动压缩 ——
    # 长文模型最常见的失败点是"写超"（目标 1500 实测能写到 3800+）。
    # 只压一次，且设两道闸门：超过目标 40% 才触发；压完若反而低于目标 60%
    # 说明信息损失过大，弃用压缩稿保留原文——宁可长一点，也不能把干货压没。
    word_count_before = len(body)
    compressed = False
    word_note = ""
    if word_count_before > int(words * 1.4):
        cut_prompt = (
            "下面是一篇财税公众号文章，目标 %d 字，但当前写了 %d 字，严重超标。\n"
            "请压缩到 %d 字左右（允许上下浮动 15%%）。要求：\n"
            "1) 保留全部小标题骨架（含 `## ` 标记）与核心结论，只删冗余论述、重复举例和口水句；\n"
            "2) 主关键词「%s」每千字仍需出现 5-8 次，不许为了省字把关键词删掉；\n"
            "3) 保留结尾引导语和所有政策文号、年份；\n"
            "4) 保留段首两个全角空格的排版；\n"
            "5) 只输出压缩后的正文全文，不要任何说明、不要代码块、不要 Markdown 标记。\n\n"
            "【原文】\n%s"
            % (words, word_count_before, words, kw_main, body)
        )
        try:
            cut_raw = deepseek_chat(cut_prompt, cfg["model"], cfg["key"],
                                    cfg.get("base_url"), timeout=180)
            if isinstance(cut_raw, dict):
                cut_raw = cut_raw.get("content") or ""
            cut = re.sub(r"^```[a-zA-Z]*\s*|\s*```$", "", str(cut_raw or "").strip()).strip()
            if cut and len(cut) < word_count_before and len(cut) >= int(words * 0.6):
                body = cut
                compressed = True
                word_note = "原稿 %d 字超出目标 %d 字 40%% 以上，已自动压缩至 %d 字" % (
                    word_count_before, words, len(body))
            elif cut and len(cut) < int(words * 0.6):
                word_note = ("原稿 %d 字超标；自动压缩后仅剩 %d 字，信息损失过大，"
                             "已保留原稿，建议手动删减" % (word_count_before, len(cut)))
            else:
                word_note = ("原稿 %d 字超出目标 %d 字 40%% 以上，自动压缩未成功，"
                             "建议手动删减" % (word_count_before, words))
        except Exception as e:  # noqa: BLE001
            word_note = "原稿 %d 字超标，自动压缩调用失败（%s），建议手动删减" % (
                word_count_before, e)
    elif word_count_before > int(words * 1.15):
        word_note = ("成稿 %d 字，超出目标 %d 字 15%% 以上但未达自动压缩线，"
                     "如需更精炼可手动删减" % (word_count_before, words))
    elif word_count_before < int(words * 0.6):
        word_note = "成稿 %d 字，明显少于目标 %d 字，建议补充案例或法条依据" % (
            word_count_before, words)

    # —— 话题标签回填正文末尾 ——
    # 上面的切分会把模型写在正文末尾的 #标签 摘走（存进独立的 tags 字段），
    # 但搜一搜与公众号推荐看的是正文里的话题标签，这里补回文末。
    body_md = body
    if tags:
        body_md = body_md.rstrip() + "\n" + " ".join("#" + t for t in tags)

    # —— SEO 自检（复用 seo_check 的纯规则实现，不二次调 LLM） ——
    # 压缩后的正文才做 SEO 评分，否则评分的是被丢弃的旧稿，前端会看到对不上的分数。
    # 必须传 body_md（保留 ## 与标签），否则 R12 数不到小标题、R15 数不到标签。
    seo = {}
    try:
        import seo_check
        seo = seo_check.check(title, digest, body_md, kw_main, kw_long, region, year) or {}
    except Exception:  # noqa: BLE001
        seo = {}

    # content = 去掉 ## 的纯文本版（人工编辑/纯文本场景用）
    # content_html = 带 <h2>/<p> 的 HTML（推送公众号用，Laravel 侧直接取这个字段）
    content_plain = re.sub(r"^\s*#{2,3}\s+", "", body_md, flags=re.M)
    content_html = _article_to_html(body_md)
    word_count = len(content_plain)
    return {
        "ok": True,
        "title": title,
        "titles": titles,
        "digest": digest,
        "content": content_plain,
        "content_html": content_html,
        "tags": tags,
        "hits": hits[:30],
        "hit_count": len(hits),
        "high_risk_count": len(high),
        "auto_cleaned": auto_cleaned,
        "seo": seo,
        # —— 以下为兼容旧前端的同源字段 ——
        "seo_score": int(seo.get("score") or 0),
        "seo_report": seo,
        "word_count": word_count,
        "target_words": words,
        "compressed": compressed,
        "word_count_before": word_count_before,
        "word_note": word_note,
        "meta": {
            "word_count": word_count,
            "target_words": words,
            "word_count_before": word_count_before,
            "compressed": compressed,
            "word_note": word_note,
            "model": cfg.get("model") or "",
            "kw_main": kw_main, "kw_long": kw_long,
            "region": region, "year": year,
            "style": style, "cta": cta,
        },
    }


# 关键词候选的违规词黑名单（LLM 侧已约束，这里再硬过滤一道，防止漏网）
_KW_BLACKLIST = ("避税", "逃税", "包过", "包通过", "100%通过", "百分百通过",
                 "零风险", "无风险", "绝对安全", "绝对", "稳赚", "包过审")


def ai_article_keywords(topic, region="", count=30):
    """关键词研究：无 5118/站长 API 时，用 LLM 按维度笛卡尔展开候选词并自评。

    返回 {"ok": True, "keywords": [{"kw","intent","value","why"}, ...]}，最多 count 条。
    """
    try:
        return _ai_article_keywords_inner(topic, region, count)
    except Exception as e:  # noqa: BLE001
        traceback.print_exc()
        return {"ok": False, "error": str(e)}


def _ai_article_keywords_inner(topic, region, count):
    cfg = get_text_config() or {}
    if not cfg.get("key"):
        return {"ok": False, "error": "未配置文本模型"}
    topic = str(topic or "").strip()
    if not topic:
        # 空主题必须早退：否则会带着空主题去调一次 LLM，白烧一次调用还让用户干等 20s+
        return {"ok": False, "error": "缺少文章主题"}
    try:
        count = max(1, min(30, int(count)))
    except Exception:  # noqa: BLE001
        count = 30
    prompt = (
        "你是财税内容营销的关键词策划。请围绕主题「%s」%s，\n"
        "按【主体（公司/个体户/老板/个人）× 业务（注册/记账/报税/注销/社保/股权/发票）× "
        "地域 × 疑问词（怎么/多少/要不要/哪个/风险）× 年份】做笛卡尔展开，\n"
        "产出候选搜索词。要求：\n"
        "1. 模拟中小企业老板的真实搜索口语，不要书面语\n"
        "2. 剔除违规词：避税、逃税、包过、100%%通过、零风险、绝对安全\n"
        "3. 每个词标注意图（只能是 信息/对比/交易/本地 四者之一）与商业价值 1-5 分\n"
        "4. 按商业价值降序，只输出前 %d 个\n\n"
        "只输出 JSON 数组，每项：{\"kw\":\"\",\"intent\":\"\",\"value\":5,\"why\":\"\"}\n"
        % (topic, ("，地域限定「%s」" % region if region else ""), count)
    )
    try:
        raw = deepseek_chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=90)
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "error": "模型调用失败：%s" % e}
    if isinstance(raw, dict):
        raw = raw.get("content") or ""
    text = str(raw or "").strip()
    if text.startswith("```"):
        text = text.strip("`")
        if text[:4].lower() == "json":
            text = text[4:]
        text = text.strip()
    try:
        obj = json.loads(text)
    except Exception:  # noqa: BLE001
        try:
            i, j = text.find("["), text.rfind("]")
            obj = json.loads(text[i:j + 1])
        except Exception:  # noqa: BLE001
            obj = None
    if not isinstance(obj, list):
        return {"ok": False, "error": "模型返回格式无法解析"}

    # 归一化：补 value / 清洗 intent / 剔除违规词与空词
    out = []
    for it in obj:
        if not isinstance(it, dict):
            continue
        kw = str(it.get("kw") or "").strip()
        if not kw or any(b in kw for b in _KW_BLACKLIST):
            continue
        val = it.get("value", it.get("score"))
        try:
            val = max(1, min(5, int(val)))
        except Exception:  # noqa: BLE001
            val = 3
        intent = str(it.get("intent") or "信息").strip()
        if intent not in ("信息", "对比", "交易", "本地"):
            intent = "信息"
        out.append({"kw": kw, "intent": intent, "value": val,
                    "why": str(it.get("why") or "")[:60]})
    out.sort(key=lambda x: -x["value"])
    return {"ok": True, "keywords": out[:count], "count": len(out[:count])}


def ai_strategist(title, script, industry, platform=None):
    """P4 获客军师：对选题/逐字稿给出爆款潜力评估 + 留资钩子建议 + 行业适配 + 改进建议。
    返回结构化 dict。无 LLM key 时降级为规则评分（dry）。"""
    cfg = get_text_config()
    title = (title or "").strip()
    script = (script or "").strip()
    industry = (industry or "").strip()
    # 规则兜底评分（长度/钩子/痛点密度）
    chars = len(script) or len(title)
    has_hook = any(k in (script + title) for k in ("关注", "评论", "私信", "扣", "留", "清单", "资料"))
    has_pain = any(k in (script + title) for k in ("坑", "风险", "错", "亏", "罚", "雷", "别"))
    rule_score = 60
    if chars >= 80:
        rule_score += 10
    if has_hook:
        rule_score += 15
    if has_pain:
        rule_score += 15
    rule_score = min(95, rule_score)
    if not cfg.get("key"):
        return {
            "ok": True, "mode": "rule_dry", "potential_score": rule_score,
            "level": "高潜力" if rule_score >= 80 else ("中等" if rule_score >= 65 else "待优化"),
            "hook_suggest": "结尾加一句明确留资动作（评论区留行业 / 扣资料）" if not has_hook else "钩子已具备，可强化紧迫感",
            "industry_fit": f"「{industry or '通用'}」行业：建议用真实案例+具体数字增强可信度",
            "improvements": ["开头3秒直戳痛点", "中段给一个可操作动作", "结尾明确留资路径"]
            + (["补充留资钩子"] if not has_hook else []),
        }
    prompt = (
        f"你是资深短视频获客军师。面向「{industry or '通用'}」行业。\n"
        f"标题：{title or '（无）'}\n"
        f"逐字稿：{script or title or '（无）'}\n"
        "请评估该内容的爆款潜力与获客能力，严格按 JSON 输出：\n"
        '{"potential_score":0-100,"level":"高潜力/中等/待优化","hook_suggest":"结尾留资钩子优化建议",'
        '"industry_fit":"行业适配点评","improvements":["3条以内具体改进点"]}\n'
        "只输出 JSON，不要解释。"
    )
    try:
        content = deepseek_chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=90)
        content = content.strip()
        if content.startswith("```"):
            content = content.split("```")[1]
        return {"ok": True, "mode": "llm", **json.loads(content)}
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "mode": "rule_fallback", "error": str(e),
                "potential_score": rule_score, "level": "待优化",
                "hook_suggest": "无法调用模型，建议手动加留资钩子", "improvements": []}


def ai_deai(text):
    """P4 去AI痕迹：把书面/AI腔逐字稿改写为口语化、像真人聊天的版本，并标出主要改动类型。
    复用 DeepSeek；无 key 时降级为基于规则的轻量改写（去高频AI词）。"""
    cfg = get_text_config()
    ai_markers = ["首先", "其次", "最后", "综上所述", "总而言之", "此外", "与此同时",
                  "值得注意的是", "毋庸置疑", "显而易见", "在这个快节奏的时代",
                  "在当今社会", "我们需要", "可以说", "不难看出"]
    if not cfg.get("key"):
        out = text
        removed = []
        for m in ai_markers:
            if m in out:
                out = out.replace(m, "")
                removed.append(m)
        # 补口语连接
        out = out.replace("。", "。")
        return {"ok": True, "mode": "rule_dry", "original": text, "rewritten": out.strip(),
                "removed_markers": removed,
                "note": "无 LLM key，已做基于规则的基础去AI词处理；接 key 后可得更自然口语化改写"}
    prompt = (
        "你是去AI痕迹专家。把下面的逐字稿改写成像真人在面对面聊天的口语版本："
        "去掉「首先/其次/最后/综上所述/值得注意的是」等AI高频词，删生硬排比，加自然口语连接，"
        "保留全部事实与专业信息不变。\n原文：\n" + text + "\n\n"
        '严格 JSON 输出：{"rewritten":"去AI后的口语稿","changes":["改动点简述"]}。只输出 JSON。'
    )
    try:
        content = deepseek_chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=90)
        content = content.strip()
        if content.startswith("```"):
            content = content.split("```")[1]
        res = json.loads(content)
        res["ok"] = True
        res["mode"] = "llm"
        res["original"] = text
        return res
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "mode": "rule_fallback", "error": str(e),
                "original": text, "rewritten": text, "changes": []}


# ---------------------------------------------------------------------------
# v2.0 P2/P3 新能力 · 轻量实现（对话里点「开始执行」走这里）
# ---------------------------------------------------------------------------

# 数据落盘目录（与 chat_sessions 同级，服务重启不丢）
_V2_DATA_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data")

_V2_ADVISOR_PROMPT = (
    "你是「慧根堂」AI 财税顾问，背靠一位具备税务稽查与历史遗留问题"
    "处理背景的专家（老张）。面向中小企业老板（决策者，不是会计）做咨询初答。\n"
    "回答铁律：\n"
    "1. 先给结论，再讲依据；口吻冷静专业、讲人话，不堆术语，不客套。\n"
    "2. 法条/政策引用必须真实可溯源（不确定就说不确定，绝不编造文号和数字）。\n"
    "3. 不教逃税、不给规避监管的'技巧'；结尾给合规正解或建议进一步做专项诊断。\n"
    "4. 严禁自我标榜资历：不写「深耕财税X年」「从业X年」「20多年经验」，也不用「资深」抬自己；\n"
    "   客户想了解资历看账号简介即可，正文只讲事、不讲资历。\n"
    "5. 控制在 400 字内，分点；最后加一行：'建议带着账面数据做一次 1v1 视频诊断'。"
)


def advisor_answer(topic, industry="", urgency=""):
    """AI 财税顾问·7×24 初答（LLM，无 key 降级提示）。"""
    cfg = get_text_config()
    q = "咨询问题：%s" % topic
    if industry:
        q += "\n所在行业：%s" % industry
    if urgency:
        q += "\n紧急程度：%s" % urgency
    if not cfg.get("key"):
        return {"ok": False, "error": "未配置 LLM key，AI 顾问暂不可用（model_keys.env）",
                "topic": topic}
    prompt = _V2_ADVISOR_PROMPT + "\n\n" + q
    try:
        ans = deepseek_chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=120)
        return {"ok": True, "topic": topic, "industry": industry,
                "urgency": urgency, "answer": ans.strip(),
                "disclaimer": "AI 初答仅供参考，涉税决策请以专业意见为准"}
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "error": str(e), "topic": topic}


def _v2_load(name):
    """读一个 JSON 落盘文件，不存在返回 []。"""
    p = os.path.join(_V2_DATA_DIR, name)
    try:
        with open(p, "r", encoding="utf-8") as f:
            return json.load(f)
    except Exception:  # noqa: BLE001
        return []


def _v2_save(name, obj):
    p = os.path.join(_V2_DATA_DIR, name)
    os.makedirs(_V2_DATA_DIR, exist_ok=True)
    tmp = p + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(obj, f, ensure_ascii=False, indent=1)
    os.replace(tmp, p)


def crm_upsert(vals):
    """客户画像·CRM：线索入档（内存同 key 去重），返回列表与本次记录。"""
    rec = {
        "id": "lead_%d" % int(time.time() * 1000),
        "ts": time.strftime("%Y-%m-%d %H:%M:%S"),
        "name": (vals.get("name") or "").strip(),
        "contact": (vals.get("contact") or "").strip(),
        "stage": vals.get("stage") or "线索",
        "industry": vals.get("industry") or "",
        "source": vals.get("source") or "",
        "note": vals.get("note") or "",
    }
    if not rec["name"]:
        return {"ok": False, "error": "客户姓名/昵称必填"}
    rows = _v2_load("crm_leads.json")
    # 同名+同联系方式视为同一条 → 升级阶段，不重复入档
    for r in rows:
        if r.get("name") == rec["name"] and (r.get("contact") or "") == rec["contact"]:
            r["stage"] = rec["stage"]
            r["ts"] = rec["ts"]
            if rec["industry"]:
                r["industry"] = rec["industry"]
            if rec["note"]:
                r["note"] = rec["note"]
            _v2_save("crm_leads.json", rows)
            return {"ok": True, "updated": True, "record": r, "total": len(rows)}
    rows.insert(0, rec)
    _v2_save("crm_leads.json", rows)
    return {"ok": True, "updated": False, "record": rec, "total": len(rows)}


def crm_list():
    rows = _v2_load("crm_leads.json")
    by_stage = {}
    for r in rows:
        by_stage[r.get("stage") or "线索"] = by_stage.get(r.get("stage") or "线索", 0) + 1
    return {"ok": True, "total": len(rows), "by_stage": by_stage, "records": rows[:50]}


def booking_create(vals):
    """老张 1v1 视频诊断·限抢：每月限 30 单，返回排队位次与剩余名额。"""
    monthly_cap = 30
    rec = {
        "id": "bk_%d" % int(time.time() * 1000),
        "ts": time.strftime("%Y-%m-%d %H:%M:%S"),
        "month": time.strftime("%Y-%m"),
        "topic": (vals.get("topic") or "").strip(),
        "industry": vals.get("industry") or "",
        "annual_revenue": vals.get("annual_revenue") or "",
        "contact": (vals.get("contact") or "").strip(),
        "status": "排队中",
    }
    if not rec["topic"] or not rec["contact"]:
        return {"ok": False, "error": "诊断问题与联系方式必填"}
    rows = _v2_load("bookings_1v1.json")
    this_month = [r for r in rows if r.get("month") == rec["month"]]
    if len(this_month) >= monthly_cap:
        return {"ok": False, "error": "本月 30 个名额已抢完，可留下联系方式候补（有人改期自动递补）",
                "waitlist": True, "topic": rec["topic"]}
    rows.insert(0, rec)
    _v2_save("bookings_1v1.json", rows)
    pos = len([r for r in rows if r.get("month") == rec["month"] and r.get("status") == "排队中"])
    return {"ok": True, "booking": rec, "queue_position": pos,
            "remaining_this_month": monthly_cap - len(this_month) - 1,
            "note": "排到队会用留下的联系方式通知，拉飞书群视频 30 分钟"}


def reception_config_save(vals):
    """AI 客服自动接待：v1 先保存配置（诚实返回——真实接待需平台授权后联调）。"""
    cfg = {
        "ts": time.strftime("%Y-%m-%d %H:%M:%S"),
        "platform": vals.get("platform") or "全平台",
        "industry": vals.get("industry") or "",
        "greeting": vals.get("greeting") or "",
        "transfer_rule": vals.get("transfer_rule") or "意向客户才转(AI 评分≥7)",
    }
    _v2_save("reception_config.json", cfg)
    return {"ok": True, "config": cfg,
            "status": "配置已保存。真实接待需先在「发布渠道」页完成对应平台的 OAuth 授权，"
                      "授权联调后自动生效；当前平台 API 仅开放部分私信通道，未开放的平台会以"
                      "公众号留言/评论区自动回复兜底。"}


def matrix_config_save(vals):
    """矩阵分发：v1 保存策略（真实分发走已有 /publish 的平台适配器）。"""
    cfg = {
        "ts": time.strftime("%Y-%m-%d %H:%M:%S"),
        "platforms": vals.get("platforms") or "视频号+抖音+小红书",
        "schedule_offset": vals.get("schedule_offset") or "间隔 1 小时",
        "auto_optimize": bool(vals.get("auto_optimize", True)),
        "job_id": vals.get("job_id") or "",
    }
    _v2_save("matrix_config.json", cfg)
    return {"ok": True, "config": cfg,
            "status": "分发策略已保存。出片完成后在「发布助手」页一键分发（复用已授权渠道）；"
                      "错峰间隔由平台按本策略执行。"}


def probe_video(path):
    """用 ffprobe 取视频元信息，失败返回 None。"""
    try:
        out = subprocess.run(
            [FFPROBE, "-v", "error", "-show_format", "-show_streams", "-of", "json", path],
            capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=30,
        ).stdout
        return json.loads(out)
    except Exception:  # noqa: BLE001
        return None


def _streams(probe):
    video = audio = None
    for s in probe.get("streams", []):
        if s.get("codec_type") == "video" and video is None:
            video = s
        elif s.get("codec_type") == "audio" and audio is None:
            audio = s
    dur = float(probe.get("format", {}).get("duration", 0) or 0)
    return video, audio, dur


def _summarize(issues):
    high = [i for i in issues if i["level"] == "high"]
    level = "high" if high else ("medium" if issues else "low")
    status = "blocked" if high else ("warned" if issues else "passed")
    score = max(0, 100 - len(issues) * 15)
    return level, status, score


def _detect_mid_silence(path, min_dur=2.5, noise="-35dB"):
    """用 ffmpeg silencedetect 检测中段长静音（疑似 TTS 掉字/音频断续）。
    仅统计落在 [2s, dur-2s] 中段区间、时长 >= min_dur 的静音段。
    返回 {"found":bool,"longest":float,"segments":[...]}。"""
    try:
        proc = subprocess.run(
            [FFMPEG, "-hide_banner", "-i", path,
             "-af", f"silencedetect=noise={noise}:d={min_dur}", "-f", "null", "-"],
            capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=120,
        )
        txt = (proc.stderr or "")
    except Exception:  # noqa: BLE001
        return {"found": False, "longest": 0.0, "segments": []}
    dur = 0.0
    try:
        p2 = subprocess.run(
            [FFPROBE, "-v", "error", "-show_entries", "format=duration",
             "-of", "default=nw=1:nk=1", path],
            capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=30,
        )
        dur = float((p2.stdout or "").strip() or 0)
    except Exception:  # noqa: BLE001
        dur = 0.0
    segs = []
    starts = []
    cur_start = None
    for line in txt.splitlines():
        if "silence_start" in line:
            try:
                cur_start = float(line.split("silence_start:")[-1].strip())
                starts.append(cur_start)
            except Exception:  # noqa: BLE001
                cur_start = None
        elif "silence_duration" in line and cur_start is not None:
            try:
                sd = float(line.split("silence_duration:")[-1].strip().split()[0])
                segs.append({"start": cur_start, "duration": sd})
            except Exception:  # noqa: BLE001
                pass
            cur_start = None
    # 仅保留中段静音（静音"开始时间"须落在 [2s, dur-2s] 中段区间内），
    # 避开片头品牌卡(约3s静音)与片尾留白——此前用"结束时间>2"会把片头静音误判为中段。
    mid_lo, mid_hi = 2.0, max(0.0, dur - 2.0)
    mid = [s for s in segs
           if s["duration"] >= min_dur
           and s["start"] >= mid_lo
           and s["start"] + s["duration"] <= mid_hi]
    longest = max([s["duration"] for s in mid], default=0.0)
    return {"found": bool(mid), "longest": longest, "segments": mid}


def ai_qc_video(path, platform=None, rules=None):
    """出片产物技术质检：视频流/音轨/画幅/时长/中段静音。返回 dict。"""
    rules = rules or {}
    probe = probe_video(path)
    issues = []
    if not probe:
        issues.append({"code": "probe_fail", "level": "high", "message": "无法解析视频文件"})
    else:
        video, audio, dur = _streams(probe)
        if video is None:
            issues.append({"code": "no_video", "level": "high", "message": "缺少视频流"})
        else:
            w = int(video.get("width", 0))
            h = int(video.get("height", 0))
            if h > 0 and w > 0 and w >= h:
                issues.append({"code": "not_portrait", "level": "medium",
                               "message": f"画幅非竖屏（{w}x{h}），建议 9:16"})
        if audio is None:
            issues.append({"code": "no_audio", "level": "high", "message": "缺少音轨"})
        else:
            # 中段长静音：疑似 TTS 掉字/音频断续
            sil = _detect_mid_silence(path, min_dur=2.5)
            if sil["found"]:
                issues.append({"code": "mid_silence", "level": "high",
                               "message": f"中段检测到 {sil['longest']:.1f}s 长静音（疑似音频断续/掉字）"})
        maxd = rules.get("max_duration_sec", 180)
        if dur > maxd:
            issues.append({"code": "too_long", "level": "medium",
                           "message": f"时长 {dur:.0f}s 超过上限 {maxd}s"})
    level, status, score = _summarize(issues)
    return {"ok": True, "kind": "video", "issues": issues,
            "score": score, "level": level, "status": status, "duration": dur}


def ai_qc_asset(path, rules=None):
    """用户上传模特素材质检：竖屏/时长/音轨（原声污染预警）。返回 dict。"""
    rules = rules or {}
    probe = probe_video(path)
    issues = []
    if not probe:
        issues.append({"code": "probe_fail", "level": "high", "message": "无法解析素材文件"})
    else:
        video, audio, dur = _streams(probe)
        w = h = 0
        if video is None:
            issues.append({"code": "no_video", "level": "high", "message": "缺少视频流"})
        else:
            w = int(video.get("width", 0))
            h = int(video.get("height", 0))
            if not (h > w):
                issues.append({"code": "not_portrait", "level": "high",
                               "message": f"必须竖屏 9:16，当前 {w}x{h}"})
        if audio is not None:
            issues.append({"code": "has_audio", "level": "medium",
                           "message": "素材含音轨，出片前将自动静音化（避免原声污染）"})
        dmin = rules.get("min_duration_sec", 3)
        dmax = rules.get("max_duration_sec", 30)
        if dur < dmin or dur > dmax:
            issues.append({"code": "duration_out", "level": "medium",
                           "message": f"时长 {dur:.0f}s，建议 {dmin}-{dmax}s"})
    level, status, score = _summarize(issues)
    resol = f"{w}x{h}" if (w and h) else None
    return {"ok": True, "kind": "asset", "issues": issues,
            "score": score, "level": level, "status": status,
            "duration": dur, "resolution": resol}


def process_asset(raw_path, tenant_id):
    """用户上传模特素材处理：
    1) 转码 H.264 + 自动静音化（加 anullsrc 静音音轨，杜绝原声污染）
    2) 写入 HEYGEM 可读路径 face2face/uploads/{tenant}/{id}.mp4（容器 /code/data/uploads/...）
    3) 同步副本到项目 storage/app/models/{tenant}/ 供 Laravel 预览
    4) 跑 asset QC，返回结果与各路径
    """
    tenant_id = str(tenant_id)
    render_dir = os.path.join(FAC2FACE, "uploads", tenant_id)
    preview_dir = os.path.join(PROJECT_STORAGE, "models", tenant_id)
    os.makedirs(render_dir, exist_ok=True)
    os.makedirs(preview_dir, exist_ok=True)
    rid = uuid.uuid4().hex
    render_path = os.path.join(render_dir, rid + ".mp4")
    preview_path = os.path.join(preview_dir, rid + ".mp4")

    cmd = [
        FFMPEG, "-y", "-i", raw_path,
        "-f", "lavfi", "-i", "anullsrc=r=22050:cl=mono",
        "-c:v", "libx264", "-preset", "veryfast", "-pix_fmt", "yuv420p",
        "-c:a", "aac", "-shortest", "-movflags", "+faststart",
        render_path,
    ]
    proc = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=300)
    if proc.returncode != 0 or not os.path.exists(render_path):
        tail = (proc.stderr or proc.stdout or "")[-2000:]
        raise RuntimeError("转码失败：" + tail)
    shutil.copy(render_path, preview_path)

    qc = ai_qc_asset(render_path)
    container_path = render_path.replace(FAC2FACE, "/code/data")
    return {
        "ok": True,
        "qc": qc,
        "file_path": render_path,
        "preview_path": preview_path,
        "container_path": container_path,
        "duration": qc.get("duration"),
        "resolution": qc.get("resolution"),
    }



# ===== 多租户稳定性护栏（可在环境变量覆盖；默认值基于本地 GPU/CPU 共享瓶颈）=====
GLOBAL_MAX_JOBS = int(os.environ.get("PIPELINE_GLOBAL_MAX_JOBS", "3"))       # 全局同时渲染上限
TENANT_MAX_JOBS = int(os.environ.get("PIPELINE_TENANT_MAX_JOBS", "2"))       # 单租户同时渲染上限
HARD_TIMEOUT = int(os.environ.get("PIPELINE_HARD_TIMEOUT", "2100"))          # 单任务硬超时 35 分钟（防僵尸）
REGEN_TIMEOUT = int(os.environ.get("PIPELINE_REGEN_TIMEOUT", "900"))           # 自动重渲染硬超时 15 分钟（兜底重试，远短于主渲染 35 分钟，避免 QC 重试卡死数十分钟）
MAX_DURATION_SEC = int(os.environ.get("PIPELINE_MAX_DURATION_SEC", "1800"))  # 单次生成时长上限 30 分钟
# 图解版长片分段（2026-09-11 方案2）：单段渲染安全时长（秒）。成片预估超过它就自动切成多段，
# 每段各自成一个独立渲染单元（每段耗时远小于 HARD_TIMEOUT）→ 绕开"30 分钟成片需渲 1 小时、
# 必撞 35 分钟硬超时"的死结；段成品落盘可续跑，全部完成后拼接成整片。
CARD_SEG_SAFE_SEC = int(os.environ.get("PIPELINE_CARD_SEG_SEC", "360"))

jobs = {}          # job_id -> {"status","out","error","tenant_id","start_ts","step"}
lock = threading.Lock()
render_lock = threading.Lock()   # HEYGEM 单 GPU 串行渲染锁：同一时刻仅一个视频在渲染，杜绝多任务抢 GPU 互相拖慢
active_total = 0              # 全局在跑任务数（用于并发护栏）
active_by_tenant = {}         # tenant_id -> 在跑任务数

# ===== 对话出稿·异步长任务（B 版：让"全写N篇/检索/审查"后台跑，前端轮询进度）=====
_chat_running = {}     # sid -> True（该会话正有一个 step 后台线程在跑）
_chat_done = {}        # sid -> 最近一次完成的完整 result（供 /chat/status 返回）
_chat_running_lock = threading.Lock()


# ===== 任务状态持久化 + 自愈（P0：崩了不丢状态、重启可恢复、卡死可回收）=====
def _job_meta_path(job_id):
    return os.path.join(JOBS_DIR, job_id, "job.json")

def _save_job(job_id, meta):
    """把 job 元信息落盘（每次状态变更后调用，内存只做热缓存）。"""
    try:
        with open(_job_meta_path(job_id), "w", encoding="utf-8") as f:
            json.dump(meta, f, ensure_ascii=False)
    except Exception:  # noqa: BLE001
        pass

def _set_job(job_id, **fields):
    """线程安全地更新内存 job 并落盘。"""
    with lock:
        j = jobs.get(job_id)
        if j is None:
            j = {}
            jobs[job_id] = j
        j.update(fields)
        _save_job(job_id, j)
    return j


def _merge_job(job_id, key, subkey, value):
    """线程安全地合并嵌套字段（如 publish[platform] = {...}），避免覆盖其他平台状态。"""
    with lock:
        j = jobs.setdefault(job_id, {})
        d = j.get(key) or {}
        d[subkey] = value
        j[key] = d
        _save_job(job_id, j)
    return j


def _is_cancelled(job_id):
    """任务是否已被用户中止（前端 /cancel 标记）。"""
    with lock:
        j = jobs.get(job_id)
        return bool(j and j.get("cancelled"))




def _append_version(job_id, out_path, payload, tag=""):
    """P3 版本管理：把一次成功渲染产物登记为一个版本，写入 job.versions。
    版本号自动递增；记录输出路径、参数快照、时间戳、标签（如 regen/初始）。"""
    import datetime as _dt
    snap = {k: payload.get(k) for k in
            ("mode", "edit_style", "title", "subtitle", "subtitle_style",
             "natural", "platform", "overlay", "bg", "dialogue")}
    entry = {
        "v": 0,  # 占位，下面算
        "out": out_path,
        "ts": _dt.datetime.now().isoformat(timespec="seconds"),
        "tag": tag or "初始",
        "snapshot": snap,
    }
    with lock:
        j = jobs.setdefault(job_id, {})
        vers = j.get("versions") or []
        entry["v"] = len(vers) + 1
        vers.append(entry)
        j["versions"] = vers
        _save_job(job_id, j)
    return entry


def _publish_job(job_id, platforms, data):
    """模块级发布核心：逐平台调适配器发布指定 job 的成片，回写 job 元数据。

    支持两种作品：
      - 视频笔记：需 job_id 指向 done 状态的成片（原逻辑）。
      - 图文笔记（mode="image"）：不依赖视频 job，直接拿 data["image_paths"] 发布。
    供 POST /publish 端点与 run_job 的 auto_publish 后台线程共用。
    返回 results 列表（每平台一条）；出错时返回单元素错误列表。
    """
    mode = str(data.get("mode") or "video").lower()
    supported = set(supported_platforms())

    # ---- 图文笔记：不依赖视频 job ----
    if mode == "image":
        image_paths = data.get("image_paths") or []
        if not image_paths:
            return [{"error": "image_paths required for mode=image"}]
        missing = [ip for ip in image_paths if not os.path.exists(ip)]
        if missing:
            return [{"error": f"image file missing: {missing}"}]
        unknown = [p for p in platforms if p not in supported]
        if unknown:
            return [{"error": f"unsupported platform(s): {unknown}", "supported": supported_platforms()}]
        tenant_id = str(data.get("tenant_id") or "default")
        title = str(data.get("title") or "图文笔记")
        desc = str(data.get("description") or "")
        tags = data.get("tags") or []
        cred_ref = data.get("credential_ref")

        def _cb_i(platform, jk, status, detail):
            _merge_job(job_id, "publish", platform, {"status": status.value, "detail": detail})

        results = []
        account_key = str(data.get("account_key") or "")
        # 账号级 OAuth token 仅抖音/小红书需要（授权码模式）；其余平台直接进适配器：
        #   wechat 走 extra 的 client_credential，shipinhao 返回 MANUAL_REQUIRED
        oauth_token_platforms = ("douyin", "xiaohongshu")
        for p in platforms:
            if account_key and p in oauth_token_platforms:
                tok = matrix_publish.get_account_token(p, account_key)
                if not tok:
                    results.append({"platform": p, "status": "published", "post_id": "",
                                    "url": "", "error": "no_token_for_account",
                                    "simulated": True})
                    continue
                set_oauth_token(p, tok.get("access_token", ""),
                                tok.get("refresh_token"),
                                int(tok.get("expires_at", time.time() + 7200) - time.time()),
                                tok.get("open_id"))
            try:
                pub = get_publisher(p, status_callback=_cb_i)
                req = PublishRequest(
                    tenant_id=tenant_id, platform=p,
                    image_paths=image_paths, title=title, description=desc,
                    tags=tags, credential_ref=cred_ref,
                    extra=data.get("extra") or {},
                )
                res = pub.publish(req, job_id)
                raw = res.raw or {}
                dry = bool(raw.get("dry"))
                results.append({
                    "platform": p,
                    "status": "simulated" if dry else res.status.value,
                    "post_id": "" if dry else res.platform_post_id,
                    "url": "" if dry else res.platform_url,
                    "error": res.error_message,
                    "simulated": dry or bool(raw.get("simulated")),
                })
            except Exception as exc:  # noqa: BLE001
                results.append({"platform": p, "status": "failed", "error": str(exc)})
        if job_id:
            _set_job(job_id, publish_results=results)
        return results

    # ---- 公众号图文文章：标题 + 正文 + 封面 → draft/add 入草稿箱（不依赖视频 job） ----
    if mode == "article":
        title = str(data.get("title") or "")
        content = str(data.get("description") or data.get("content") or "")
        if not content.strip():
            return [{"error": "content required for mode=article"}]
        content_html = str(data.get("content_html") or "")
        cover = data.get("cover_path") or ""
        # 封面兜底：未提供封面时自动生成黑金横版封面，保证 draft/add 不缺 thumb_media_id 而失败；
        # 用户可在公众号后台草稿箱里替换成自己设计的封面。
        if not cover:
            try:
                cover = _wechat_article_cover(title, "慧根堂财税")
            except Exception:
                cover = ""
        unknown = [p for p in platforms if p not in supported]
        if unknown:
            return [{"error": f"unsupported platform(s): {unknown}", "supported": supported_platforms()}]
        tenant_id = str(data.get("tenant_id") or "default")
        cred_ref = data.get("credential_ref")

        def _cb_a(platform, jk, status, detail):
            _merge_job(job_id, "publish", platform, {"status": status.value, "detail": detail})

        results = []
        for p in platforms:
            try:
                pub = get_publisher(p, status_callback=_cb_a)
                req = PublishRequest(
                    tenant_id=tenant_id, platform=p,
                    title=title, description=content, cover_path=cover,
                    content_html=content_html,
                    credential_ref=cred_ref, extra=data.get("extra") or {},
                )
                res = pub.publish(req, job_id)
                raw = res.raw or {}
                dry = bool(raw.get("dry"))
                results.append({
                    "platform": p,
                    "status": "simulated" if dry else res.status.value,
                    "post_id": "" if dry else res.platform_post_id,
                    "url": "" if dry else res.platform_url,
                    "error": res.error_message,
                    "simulated": dry or bool(raw.get("simulated")),
                })
            except Exception as exc:  # noqa: BLE001
                results.append({"platform": p, "status": "failed", "error": str(exc)})
        if job_id:
            _set_job(job_id, publish_results=results)
        return results

    # ---- 视频笔记：需 done 状态成片 ----
    j = jobs.get(job_id)
    if not j:
        return [{"error": "job not found"}]
    if j.get("status") != "done":
        return [{"error": f"job not done: {j.get('status')}"}]
    out_path = j.get("out")
    if not out_path or not os.path.exists(out_path):
        return [{"error": "video file missing"}]
    unknown = [p for p in platforms if p not in supported]
    if unknown:
        return [{"error": f"unsupported platform(s): {unknown}", "supported": supported_platforms()}]
    tenant_id = str(data.get("tenant_id") or j.get("tenant_id") or "default")
    title = str(data.get("title") or j.get("title") or "短视频")
    desc = str(data.get("description") or "")
    tags = data.get("tags") or []
    cover = j.get("cover")
    cred_ref = data.get("credential_ref")

    def _cb(platform, jk, status, detail):
        _merge_job(job_id, "publish", platform, {"status": status.value, "detail": detail})

    account_key = str(data.get("account_key") or "")
    # 账号级 OAuth token 仅抖音/小红书需要（授权码模式）；其余平台直接进适配器：
    #   wechat 走 extra 的 client_credential，shipinhao 返回 MANUAL_REQUIRED
    oauth_token_platforms = ("douyin", "xiaohongshu")

    results = []
    for p in platforms:
        if account_key and p in oauth_token_platforms:
            tok = matrix_publish.get_account_token(p, account_key)
            if not tok:
                results.append({"platform": p, "status": "published", "post_id": "",
                                "url": "", "error": "no_token_for_account",
                                "simulated": True})
                continue
            set_oauth_token(p, tok.get("access_token", ""),
                            tok.get("refresh_token"),
                            int(tok.get("expires_at", time.time() + 7200) - time.time()),
                            tok.get("open_id"))
        try:
            pub = get_publisher(p, status_callback=_cb)
            req = PublishRequest(
                tenant_id=tenant_id, platform=p, video_path=out_path,
                title=title, description=desc, tags=tags,
                cover_path=cover, credential_ref=cred_ref,
                extra=data.get("extra") or {},
            )
            res = pub.publish(req, job_id)
            raw = res.raw or {}
            dry = bool(raw.get("dry"))
            results.append({
                "platform": p,
                "status": "simulated" if dry else res.status.value,
                "post_id": "" if dry else res.platform_post_id,
                "url": "" if dry else res.platform_url,
                "error": res.error_message,
                "simulated": dry or bool(raw.get("simulated")),
            })
        except Exception as exc:  # noqa: BLE001
            results.append({"platform": p, "status": "failed", "error": str(exc)})
    _set_job(job_id, publish_results=results)
    return results

# 终态集合：已明确结束的 job，无需回收；其余一律视为重启前未完成（渲染线程已死）
_TERMINAL_STATUS = ("done", "failed", "cancelled")


def recover_jobs():
    """启动自愈：扫描 JOBS_DIR 把磁盘上的 job 状态恢复到内存，使 /status、/download
    重启后仍可用，且前端轮询不再因内存清空而"永远查不到"。

    处理规则：
      - 终态(done/failed/cancelled)：原样载入内存；若 done 但成片文件已丢失，
        降级为 failed 避免前端下载 404。
      - 非终态(渲染/编辑/精修等中断)：渲染线程随进程死亡必中断，标 failed 并提示重提，
        同时释放其占用的并发槽（active_total / active_by_tenant），避免僵尸占坑导致
        新任务被"并发已满"拒绝。
    重启后进程内无任何渲染线程在跑，故 active_total 统一归零兜底。
    """
    global active_total
    loaded = 0
    interrupted = 0
    for name in sorted(os.listdir(JOBS_DIR)):
        mpath = os.path.join(JOBS_DIR, name, "job.json")
        if not os.path.isfile(mpath):
            continue
        try:
            with open(mpath, encoding="utf-8") as f:
                meta = json.load(f)
        except Exception as e:  # noqa: BLE001
            print(f"[pipeline] recover_jobs: skip unreadable {name}/job.json: {e}")
            continue
        st = meta.get("status")
        with lock:
            if st in _TERMINAL_STATUS:
                # done 但产物缺失：降级，避免下载 404 / 无限"已完成"
                if st == "done" and not (meta.get("out") and os.path.exists(meta.get("out"))):
                    meta["status"] = "failed"
                    meta["error"] = "restart_recovered: 成片文件缺失，请重新提交"
                    _save_job(name, meta)
                    interrupted += 1
                jobs[name] = meta
            else:
                # 非终态：重启后必然中断，标 failed + 释放并发槽
                meta["status"] = "failed"
                meta["step"] = "failed"
                meta["error"] = "restart_recovered: 服务重启导致任务中断，请重新提交"
                _save_job(name, meta)
                jobs[name] = meta
                tid = meta.get("tenant_id") or "default"
                if active_by_tenant.get(tid, 0) > 0:
                    active_by_tenant[tid] -= 1
                    if active_by_tenant[tid] <= 0:
                        del active_by_tenant[tid]
                interrupted += 1
            loaded += 1
    # 重启后无进程在跑，全局在跑计数归零（防御僵尸占坑）
    active_total = 0
    print(f"[pipeline] recover_jobs: loaded {loaded} jobs, interrupted(marked failed) {interrupted}")
    return interrupted

def watchdog_loop():
    """卡死看门狗：每 60s 扫描一次，超过 HARD_TIMEOUT+120s 仍 rendering 的 job 强制回收。"""
    while True:
        time.sleep(60)
        try:
            now = time.time()
            with lock:
                snapshot = list(jobs.items())
            for jid, j in snapshot:
                if j.get("status") == "rendering" and j.get("step") != "queued":
                    st = j.get("start_ts") or 0
                    if now - st > HARD_TIMEOUT + 120:
                        with lock:
                            j["status"] = "failed"
                            j["error"] = "watchdog: 渲染卡死超过硬超时，已强制回收并释放资源"
                            _save_job(jid, j)
                            # 释放并发槽（锁内联，避免嵌套锁）
                            global active_total
                            active_total = max(0, active_total - 1)
                            tid = j.get("tenant_id") or "default"
                            if active_by_tenant.get(tid, 0) > 0:
                                active_by_tenant[tid] -= 1
                                if active_by_tenant[tid] <= 0:
                                    del active_by_tenant[tid]
        except Exception:  # noqa: BLE001
            pass


def estimate_duration_sec(dialogue):
    """从对话稿粗略估算 TTS 时长（中文约 4.5 字/秒，去除 女：/男： 前缀）。"""
    chars = 0
    for line in (dialogue or "").splitlines():
        line = line.strip()
        if not line:
            continue
        if line[:2] in ("女：", "女:", "男：", "男:"):
            line = line[2:]
        chars += len(line.replace(" ", ""))
    return max(1, round(chars / 4.5))


def _split_script_for_card(dialogue, seg_sec=None):
    """图解版长片切段：把口播稿按「句末标点」切成若干段，每段预估 TTS 时长 ≤ seg_sec。
    返回 [(seg_index, seg_text), ...]（按顺序）；稿子不超长时返回单段。
    切句保留标点，段内以换行连接（分屏器按换行分段、按句末标点切句，行为一致）。"""
    seg_sec = seg_sec or CARD_SEG_SAFE_SEC
    text = re.sub(r'^\s*(?:女|男|旁白)[:：]\s*', '', dialogue or '', flags=re.M)
    sents = []
    for para in [p.strip() for p in text.splitlines() if p.strip()]:
        buf = ""
        for ch in para:
            buf += ch
            if ch in "。！？!?；;":
                if buf.strip():
                    sents.append(buf.strip())
                buf = ""
        if buf.strip():
            sents.append(buf.strip())
    if not sents:
        return [(0, dialogue or "")]
    limit = max(60, int(seg_sec * 4.5))   # 中文约 4.5 字/秒（与 estimate_duration_sec 同口径）
    groups, cur, cur_len = [], [], 0
    for s in sents:
        ln = len(s.replace(" ", ""))
        if cur and cur_len + ln > limit:
            groups.append(cur)
            cur, cur_len = [], 0
        cur.append(s)
        cur_len += ln
    if cur:
        groups.append(cur)
    return [(i, "\n".join(g)) for i, g in enumerate(groups)]


def _child_env_with_proxy():
    """构造子进程环境：继承当前 env，并显式注入本机代理（127.0.0.1:7897 等），
    保证以 LocalSystem 运行的 8500 服务 spawn 的 python 子进程也能走代理访问外网。
    背景：dashscope TTS 走 WebSocket 流式，LocalSystem 无用户代理配置 → WS 连接失败
    → call() 返回 None → 服务端任务 TTS 必失败（本机手动跑因当前用户有代理而成功）。"""
    import copy
    env = copy.copy(os.environ)
    # 从当前用户注册表读代理（服务是 LocalSystem，读不到 HKCU）
    proxy = ""
    try:
        import winreg
        with winreg.OpenKey(winreg.HKEY_CURRENT_USER,
                            r"Software\Microsoft\Windows\CurrentVersion\Internet Settings") as k:
            enabled, _ = winreg.QueryValueEx(k, "ProxyEnable")
            server, _ = winreg.QueryValueEx(k, "ProxyServer")
            if enabled and server:
                proxy = server if "://" in server else f"http://{server}"
    except Exception:  # noqa: BLE001
        pass
    if not proxy:
        proxy = os.environ.get("HTTPS_PROXY") or os.environ.get("HTTP_PROXY") or ""
    if proxy:
        env.setdefault("HTTP_PROXY", proxy)
        env.setdefault("HTTPS_PROXY", proxy)
        env.setdefault("http_proxy", proxy)
        env.setdefault("https_proxy", proxy)
    # ★ 子脚本统一 UTF-8 输出：Windows 控制台/管道默认 GBK，print 含 emoji(⚠/✅)/生僻字
    #   会 UnicodeEncodeError 直接把渲染脚本打崩（2026-09-11 avatar 长稿分段即此因）。
    #   用 setdefault，不覆盖用户显式配置。改这里需 Restart-Service HGTCommercial8500 才生效。
    env.setdefault("PYTHONIOENCODING", "utf-8")
    env.setdefault("PYTHONUTF8", "1")
    return env


def run_with_timeout(args, cwd, timeout, log_path=None):
    """Windows 安全的带超时进程运行；输出落盘而非缓冲在内存（防长任务内存膨胀）。
    超时杀进程树，防 ffmpeg 等子进程变孤儿。返回 (rc, out, err)。"""
    logf = None
    if log_path:
        try:
            # 二进制模式写日志，避免 subprocess reader thread 用系统默认编码(GBK)解码失败
            logf = open(log_path, "wb")
        except Exception:  # noqa: BLE001
            logf = None
    stdout_t = logf if logf else subprocess.DEVNULL
    proc = subprocess.Popen(
        args, cwd=cwd, stdout=stdout_t, stderr=subprocess.STDOUT,
        env=_child_env_with_proxy(),
        creationflags=subprocess.CREATE_NEW_PROCESS_GROUP,
    )
    try:
        proc.wait(timeout=timeout)
        rc = proc.returncode
    except subprocess.TimeoutExpired:
        # 杀整个进程树（taskkill /T 递归），避免 PY310→ffmpeg 链变僵尸
        try:
            subprocess.run(["taskkill", "/F", "/T", "/PID", str(proc.pid)],
                           capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=10)
        except Exception:  # noqa: BLE001
            try:
                proc.kill()
            except Exception:  # noqa: BLE001
                pass
        try:
            proc.wait(timeout=5)
        except Exception:  # noqa: BLE001
            pass
        rc = 124
    finally:
        if logf is not None:
            try:
                logf.close()
            except Exception:  # noqa: BLE001
                pass
    # 读取日志尾部作为 err 返回（供失败诊断），不再把整段输出驻留内存
    err = ""
    if log_path and os.path.exists(log_path):
        try:
            with open(log_path, "r", encoding="utf-8", errors="replace") as f:
                f.seek(0, os.SEEK_END)
                sz = f.tell()
                f.seek(max(0, sz - 8000))
                err = f.read()
        except Exception:  # noqa: BLE001
            err = ""
    return rc, "", err


def _parse_cover_qc(log_text):
    """从 make_cover --json 日志中解析质检报告，返回 (qc_fail:bool, report:dict|None)。"""
    if not log_text:
        return False, None
    for line in log_text.splitlines():
        if line.startswith("__COVER_JSON__"):
            try:
                man = json.loads(line[len("__COVER_JSON__"):])
                return bool(man.get("qc_fail")), man
            except Exception:  # noqa: BLE001
                return False, None
    return False, None


def _post_process(job_id, payload, out_path, job_dir, edit_style):
    """成片后处理：嵌套自动剪辑（片头卡真实标题 + Ken Burns + 转场）+ 智能封面（含 QC 门禁）。
    返回最终 out_path。封面 QC 不达标时不挂次品封面，仅记警告，交由质量门禁裁决。"""
    # —— 嵌套自动剪辑：片头卡烧入真实标题/副标题，强化专业观感 ——
    if edit_style:
        edited_path = os.path.join(job_dir, "out.edited.mp4")
        _set_job(job_id, step="editing")
        edit_args = [PY310, SCRIPT_EDIT, "--input", out_path,
                     "--output", edited_path, "--edit-style", str(edit_style)]
        # 数字人/幕后音成片已自带片头：跳过 auto_edit 片头卡，避免双重片头
        # （保留 CTA 留资片尾 + 转场）
        edit_args += ["--no-intro"]
        # 所有形式均已烧录字幕：跳过 Ken Burns 缩放（缩放会裁切字幕，QC 误判贴边）
        edit_args += ["--no-kenburns"]
        # 轻 BGM（自研合成，版权安全）：低音量混入成片
        _bgm = os.path.join(GPT_SOVITS, "static", "bgm_default.wav")
        if os.path.exists(_bgm):
            edit_args += ["--bgm", _bgm]
        nt = payload.get("name_tag")
        if nt:
            edit_args += ["--name-tag", str(nt)]
        else:
            edit_args += ["--name-tag", "昆山老张讲财税"]
        ov = payload.get("overlay")
        if ov:
            edit_args += ["--overlay", str(ov)]
        et = payload.get("title")
        if et:
            edit_args += ["--title", str(et)]
        es = payload.get("subtitle")
        if es:
            edit_args += ["--subtitle", str(es)]
        edit_log = os.path.join(job_dir, "edit.log")
        erc, _, eerr = run_with_timeout(edit_args, GPT_SOVITS, HARD_TIMEOUT, log_path=edit_log)
        if erc == 0 and os.path.exists(edited_path):
            out_path = edited_path
        else:
            _set_job(job_id, error="剪辑后处理失败，已退回原始成片：" + ((eerr or "")[:300]))

    # —— 本地智能封面（P3b）：基于最终成片抽帧合成品牌封面，内置 QC 门禁 ——
    if payload.get("cover"):
        cover_path = os.path.join(job_dir, "cover.jpg")
        cover_args = [PY310, SCRIPT_COVER, "--input", out_path,
                      "--output", cover_path,
                      "--title", str(payload.get("title") or "短视频"),
                      "--platform", str(payload.get("platform") or ""), "--json"]
        csub = payload.get("subtitle")
        if csub:
            cover_args += ["--subtitle", str(csub)]
        cover_log = os.path.join(job_dir, "cover.log")
        crc, _, cerr = run_with_timeout(cover_args, GPT_SOVITS, HARD_TIMEOUT, log_path=cover_log)
        cover_text = ""
        if os.path.exists(cover_log):
            try:
                with open(cover_log, "r", encoding="utf-8", errors="replace") as f:
                    cover_text = f.read()
            except Exception:  # noqa: BLE001
                cover_text = ""
        qc_fail, _ = _parse_cover_qc(cover_text)
        if crc == 0 and os.path.exists(cover_path) and not qc_fail:
            _set_job(job_id, cover=cover_path)
        elif qc_fail:
            # 质量门禁：Top3 候选帧均未达清晰度/亮度门槛，不挂次品封面，记录待重生成
            _set_job(job_id, cover=None,
                     qc_cover="qc_fail: 候选帧未达清晰度/亮度门槛，已不挂封面，建议重生成")
        else:
            _set_job(job_id, error=(jobs.get(job_id, {}).get("error") or "")
                     + " | 封面生成失败：" + ((cerr or "")[:200]))
    return out_path


def _render_with_lock(job_id, args, log_path, timeout=HARD_TIMEOUT, step=None):
    """串行占用 HEYGEM 渲染锁执行一次渲染，期间更新 step。
    锁在调用最开始获取：若锁被其他任务占用，本线程会阻塞在获取锁处，
    此时 step 仍为 'queued'，前端可据此显示"排队中"。拿到锁后才切到 'rendering'。
    timeout: 单次渲染硬超时（秒）；主渲染用 HARD_TIMEOUT，重渲染传 REGEN_TIMEOUT 明显更短。
    step: 可选，显式指定写入的 step（如重渲染保持 'rerender'，避免被覆盖成 'rendering'
          导致前端无法区分「自动重试」与「普通渲染」，用户误以为卡死）。"""
    with render_lock:
        _set_job(job_id, step=(step if step is not None else "rendering"))
        rc, _, err = run_with_timeout(args, GPT_SOVITS, timeout, log_path=log_path)
    return rc, err


def _render_card_segmented(job_id, segs, payload, job_dir, out_path, log_path):
    """图解版长片分段渲染（2026-09-11 方案2）：把长稿逐段渲染、每段各自成一个独立渲染单元
    （每段耗时远小于 HARD_TIMEOUT，段成品落盘可复用=断点续跑），全部完成后拼接整片 + 补一次片头。
    返回 (rc, err)：rc=0 成功；124=某段渲染超时；其它=失败。"""
    seg_dir = os.path.join(job_dir, "_segs_card")
    os.makedirs(seg_dir, exist_ok=True)
    n = len(segs)
    SCRIPT_CARD = os.path.join(GPT_SOVITS, "make_card_pipeline.py")
    brand = str(payload.get("brand") or payload.get("ip_name") or "昆山老张讲财税")
    sub_head = str(payload.get("subtitle") or "").strip()
    title = str(payload.get("title") or "").strip()
    mv = payload.get("male_voice") or payload.get("voice") or DEFAULT_MALE
    seg_outs = []
    for i, seg_text in segs:
        seg_out = os.path.join(seg_dir, "seg%d.mp4" % i)
        if os.path.exists(seg_out) and os.path.getsize(seg_out) > 102400:
            print("[card-seg] 段%d/%d 已有成品，复用（断点续跑）" % (i + 1, n), flush=True)
            seg_outs.append(seg_out)
            continue
        seg_txt = os.path.join(seg_dir, "seg%d.txt" % i)
        with open(seg_txt, "w", encoding="utf-8") as f:
            f.write(seg_text)
        sargs = [PY310, SCRIPT_CARD, "--text", seg_txt, "--out", seg_out,
                 "--brand", brand, "--no-intro",
                 "--seg-index", str(i), "--seg-total", str(n)]
        if sub_head:
            sargs += ["--sub", sub_head[:10]]
        if title and i == 0:      # 标题提示只给首段，避免每段重复出现同名标题
            sargs += ["--title", title]
        if mv:
            sargs += ["--voice", mv]
        seg_log = os.path.join(seg_dir, "seg%d.log" % i)
        # 心跳：每段开始刷新 start_ts，避免卡死看门狗按整片耗时误杀长任务
        _set_job(job_id, start_ts=time.time(), step="rendering",
                 warning="长片分段渲染中：第 %d/%d 段…" % (i + 1, n))
        if _is_cancelled(job_id):
            return (1, "用户已中止")
        rc, err, ok = 1, "", False
        for attempt in (1, 2):
            rc, err = _render_with_lock(job_id, sargs, seg_log,
                                        timeout=HARD_TIMEOUT, step="rendering")
            if rc == 0 and os.path.exists(seg_out) and os.path.getsize(seg_out) > 102400:
                ok = True
                break
            print("[card-seg] 段%d 第%d次失败 rc=%s: %s"
                  % (i + 1, attempt, rc, (err or "")[-200:]), flush=True)
            if rc == 124:
                break   # 超时不重试（重试必然同样超时）
        if not ok:
            return (rc if rc else 1,
                    "分段渲染失败（第 %d/%d 段）：%s" % (i + 1, n, (err or "")[-300:]))
        seg_outs.append(seg_out)
    # ---- 全部段完成 → 拼接整片（纯 ffmpeg，不占 HEYGEM 渲染锁）----
    _set_job(job_id, step="rendering", warning="%d 段全部渲染完成，正在拼接整片…" % n)
    cargs = [PY310, os.path.join(GPT_SOVITS, "concat_segments.py"),
             "--out", out_path] + seg_outs
    rc, _, err = run_with_timeout(cargs, GPT_SOVITS, 900, log_path=log_path)
    if rc != 0 or not os.path.exists(out_path):
        return (rc if rc else 1, "整片拼接失败：%s" % ((err or "")[-300:]))
    return (0, "")


def run_job(job_id, payload):
    tenant_id = payload.get("tenant_id") or "default"
    edit_style = payload.get("edit_style") or None  # 嵌套自动剪辑：scroll/avatar 成片之上的后处理风格
    try:
        mode = (payload.get("mode") or "scroll").lower()
        card_segs = None   # 图解版长片分段：非空 = 走分段渲染+拼接（方案2）
        dialogue = payload.get("dialogue", "").strip()
        # 去 BOM（\ufeff）：文件粘贴/上传常带 BOM，会导致首行"女：/男："前缀识别失败
        dialogue = dialogue.lstrip("\ufeff")
        # ★ 政策文号规范化（显示层）：写稿环节常把「〔2012〕1号」的方括号吃掉 → 粘成「20121号」，
        #   在此统一修一次（→〔2012〕1号），六种形式的字幕/卡片显示一并受益。
        #   朗读层另有 qwen_tts 兜底（normalize_for_tts），两层独立、幂等，重复施加无副作用。
        try:
            from tts_text_rules import fix_wenhao
            dialogue = fix_wenhao(dialogue)
        except Exception as _e:
            print("fix_wenhao skipped:", _e, flush=True)
        if not dialogue:
            _set_job(job_id, status="failed", error="dialogue required")
            return

        job_dir = os.path.join(JOBS_DIR, job_id)
        os.makedirs(job_dir, exist_ok=True)
        dlg_path = os.path.join(job_dir, "dialogue.txt")
        with open(dlg_path, "w", encoding="utf-8") as f:
            f.write(dialogue)
        out_path = os.path.join(job_dir, "out.mp4")
        log_path = os.path.join(job_dir, "render.log")

        # ---- 默认声线（方言由所选克隆音色自然决定，无需独立 dialect 参数）----
        d_mv, d_fv = DEFAULT_MALE, DEFAULT_FEMALE

        if mode == "avatar":
            # 数字人统一单人独白：去除角色前缀，整稿用单一声线（取消男女对话）
            dialogue = re.sub(r'^\s*(?:女|男|旁白)[:：]\s*', '', dialogue, flags=re.M)
            with open(dlg_path, "w", encoding="utf-8") as f:
                f.write(dialogue)
            args = [PY310, SCRIPT_AVATAR, "--dialogue", dlg_path, "--out", out_path]
            # 口语化润色（自然口吻）：avatar 脚本内插语气词，去念稿感
            if payload.get("natural"):
                args += ["--natural"]
            mv = payload.get("male_voice") or payload.get("voice") or d_mv
            args += ["--male-voice", mv]
            # 注：数字人不传 --female-voice，强制单人单声线
            model = payload.get("model")
            # 场景选择：底层 make_avatar_from_dialogue.py 以 --model 决定出镜背景/场景，
            # 其 argparse 不接受 --scene（曾因传 --scene 导致 avatar 任务必崩）；
            # 故将 scene 映射为 model 选择，且仅当用户未显式选 model 时生效（model 优先）。
            SCENE_MODEL = {
                "office_a": DEFAULT_AVATAR_MODEL,          # 办公桌前正面（已验证可用）
                "office_b": "/code/data/YXSZR1.mp4",       # 备选场景（容器内真实存在）
            }
            scene = payload.get("scene")
            if not model and scene:
                model = SCENE_MODEL.get(scene, DEFAULT_AVATAR_MODEL)
            if model:
                # 友好名/裸文件名 -> 容器内完整路径；已是完整路径则原样用；未知则回退默认
                model = MODEL_REGISTRY.get(
                    model,
                    model if model.startswith("/code/data/") else DEFAULT_AVATAR_MODEL,
                )
                args += ["--model", model]
            # 字幕风格（minimal/bubble/dynamic；可选，不传则脚本默认 dynamic）
            if payload.get("subtitle_style"):
                args += ["--subtitle-style", str(payload["subtitle_style"])]
            # 字幕字体（hei/yahei/kaiti/song/fangsong；可选，不传则脚本默认黑体）
            if payload.get("subtitle_font"):
                args += ["--font", resolve_font(payload["subtitle_font"])]
        elif mode == "manga":
            # AI 漫剧(2026-08-28): 内容→类型判断(场景剧/讲解式/法条口播)→LLM分镜→固定角色生图→动效配音成片
            # 2026-08-29: 预判内容类型——法条/政策类不漫剧化(保精确), 直接给友好提示, 不进入渲染
            try:
                from make_manga_storyboard import classify as _manga_classify
                _ctype = _manga_classify(dialogue)
            except Exception:  # noqa: BLE001
                _ctype = None
            if _ctype == "lecture":
                _set_job(job_id, status="failed", failed_reason="lecture",
                         error="该内容属于法条/政策类。为保证法条表述精确，AI 漫剧不呈现法条内容，"
                               "建议改用「幕后音·动态画面」或「数字人」口播形式出片。")
                return
            SCRIPT_MANGA = os.path.join(GPT_SOVITS, "make_manga_pipeline.py")
            args = [PY310, SCRIPT_MANGA, "--text", dialogue,
                    "--voice", (payload.get("male_voice") or payload.get("voice") or d_mv),
                    "--out", out_path]
            # 2026-08-30: i2v=AI图生视频动效模式(惊艳, 每幕约0.24元/秒, 5幕约6元)
            if payload.get("i2v"):
                args += ["--i2v"]
            if payload.get("title"):
                args += ["--title", str(payload["title"])]
        elif mode == "whiteboard":
            # AI 白板式(2026-08-30): 内容→LLM布局(标题/要点/警示,智能配色)→手绘逐笔动画→配音成片
            SCRIPT_WB = os.path.join(GPT_SOVITS, "make_whiteboard_pipeline.py")
            args = [PY310, SCRIPT_WB, "--text", dialogue,
                    "--voice", (payload.get("male_voice") or payload.get("voice") or d_mv),
                    "--out", out_path]
            if payload.get("title"):
                args += ["--title", str(payload["title"])]
        elif mode == "card":
            # 图解版（信息卡片解说，2026-09-11 接入）：稿子 → AI 分屏（哪几句合成一屏/用哪种卡片）
            # → 卡片元素跟口播逐条浮现 + 配音成片。
            # 铁律：AI 只管「哪几句一屏 + 用哪种卡片」，版面由 make_card_video.py 模板写死，
            # 永远不会跑偏（当年「智能图解」被砍就死在让 AI 同时管内容+版面）。
            # 单人单声（同 avatar/manga/whiteboard）：去角色前缀，整稿用所选声线。
            dialogue = re.sub(r'^\s*(?:女|男|旁白)[:：]\s*', '', dialogue, flags=re.M)
            with open(dlg_path, "w", encoding="utf-8") as f:
                f.write(dialogue)
            SCRIPT_CARD = os.path.join(GPT_SOVITS, "make_card_pipeline.py")
            args = [PY310, SCRIPT_CARD, "--text", dlg_path, "--out", out_path,
                    "--brand", str(payload.get("brand") or payload.get("ip_name") or "昆山老张讲财税")]
            # 固定栏目头副标：优先用副标题（≤10字），缺省由分屏器取稿子首行短标题
            sub_head = str(payload.get("subtitle") or "").strip()
            if sub_head:
                args += ["--sub", sub_head[:10]]
            if payload.get("title"):
                args += ["--title", str(payload["title"])]
            mv = payload.get("male_voice") or payload.get("voice") or d_mv
            if mv:
                args += ["--voice", mv]
            # 长片分段（方案2）：预估成片超过单段安全时长 → 切成多段、逐段独立渲染后拼接。
            # 必要性：30 分钟成片任何形式都需渲 1 小时以上，单任务必撞 35 分钟硬超时；分段后
            # 每段各自计时（远小于上限），且段成品落盘可续跑。
            _est = estimate_duration_sec(dialogue)
            if _est > CARD_SEG_SAFE_SEC:
                _cand = _split_script_for_card(dialogue)
                if len(_cand) > 1:
                    card_segs = _cand
                    print("[card] 预估 %ds 超过单段安全时长 %ds → 自动分 %d 段渲染后拼接"
                          % (_est, CARD_SEG_SAFE_SEC, len(card_segs)), flush=True)
        elif mode == "motion":
            # 幕后音·动态画面（对标视频号「建筑财税张老师」风格）：
            # 男声/女声/男女对话 → 双声 TTS + 动态GIF/生图场景 + 中部滚动字幕（motion_v4 内部完成）
            # 声线形式与 scroll 一致：male_mono/female_mono 去角色前缀成单声，dialogue 保留双声
            voice_form = payload.get("voice_form") or "dialogue"
            if voice_form in ("male_mono", "female_mono"):
                dialogue = re.sub(r'^\s*(?:女|男|旁白)[:：]\s*', '', dialogue, flags=re.M)
                with open(dlg_path, "w", encoding="utf-8") as f:
                    f.write(dialogue)
            args = [PY310, SCRIPT_MOTION, "--script", dlg_path, "--out", out_path]
            if payload.get("title"):
                args += ["--title", payload["title"]]
            style = payload.get("motion_style") or "财经严谨"
            args += ["--style", style, "--dialogue"]
            # 无角色前缀文本的默认声线: 男独白→M, 女独白→F
            if voice_form == "male_mono":
                args += ["--default-role", "M"]
            elif voice_form == "female_mono":
                args += ["--default-role", "F"]
            # 租户音色（工作台可传男/女声线；不传则用引擎默认克隆音）
            mv = payload.get("male_voice")
            fv = payload.get("female_voice")
            if voice_form == "male_mono" and mv:
                args += ["--male-voice", mv]
            elif voice_form == "female_mono" and fv:
                args += ["--female-voice", fv]
            elif voice_form == "dialogue":
                if mv:
                    args += ["--male-voice", mv]
                if fv:
                    args += ["--female-voice", fv]
            # 性能可调: 并行渲染/并行TTS 通过环境变量覆盖(不设则脚本自动: 渲染12进程、TTS4并发)
            if os.environ.get("MOTION_WORKERS"):
                args += ["--workers", str(int(os.environ["MOTION_WORKERS"]))]
            if os.environ.get("MOTION_TTS_WORKERS"):
                args += ["--tts-workers", str(int(os.environ["MOTION_TTS_WORKERS"]))]
        else:  # scroll
            args = [PY310, SCRIPT_SCROLL, "--dialogue", dlg_path, "--out", out_path]
            if payload.get("title"):
                args += ["--title", payload["title"]]
            if payload.get("subtitle"):
                args += ["--subtitle", payload["subtitle"]]
            if payload.get("bg"):
                args += ["--bg", payload["bg"]]
            if payload.get("bg_style"):
                args += ["--bg-style", payload["bg_style"]]
            # 真实 TTS 为默认；仅当显式 dry_tts=true 才用静音占位
            if payload.get("dry_tts"):
                args += ["--dry-tts"]

            # 声线形式：three forms（男声独白 / 女声独白 / 男女对话）
            voice_form = payload.get("voice_form") or "dialogue"
            if voice_form == "female_mono":
                # 女声独白：去角色前缀 + 单一女声
                dialogue = re.sub(r'^\s*(?:女|男|旁白)[:：]\s*', '', dialogue, flags=re.M)
                with open(dlg_path, "w", encoding="utf-8") as f:
                    f.write(dialogue)
                fv = payload.get("female_voice") or d_fv
                args += ["--female-voice", fv, "--default-role", "F"]
            elif voice_form == "male_mono":
                # 男声独白：去角色前缀 + 单一男声
                dialogue = re.sub(r'^\s*(?:女|男|旁白)[:：]\s*', '', dialogue, flags=re.M)
                with open(dlg_path, "w", encoding="utf-8") as f:
                    f.write(dialogue)
                mv = payload.get("male_voice") or d_mv
                args += ["--male-voice", mv, "--default-role", "M"]
            elif voice_form == "mono":
                # 单声线（不限性别）：去角色前缀 + 单一声音（从前端的 male_voice 槽位取租户所选，性别无关）
                dialogue = re.sub(r'^\s*(?:女|男|旁白)[:：]\s*', '', dialogue, flags=re.M)
                with open(dlg_path, "w", encoding="utf-8") as f:
                    f.write(dialogue)
                mv = payload.get("male_voice") or d_mv
                args += ["--male-voice", mv, "--default-role", "M"]
            else:  # dialogue：男女双声对话（默认）
                mv = payload.get("male_voice") or d_mv
                if mv:
                    args += ["--male-voice", mv]
                fv = payload.get("female_voice") or d_fv
                if fv:
                    args += ["--female-voice", fv]
            # 分声线感情/快慢（可选；不传则用脚本默认值：男声沉稳慢、女声略活泼）
            for key, flag in (("male_rate", "--male-rate"), ("female_rate", "--female-rate"),
                              ("male_pitch", "--male-pitch"), ("female_pitch", "--female-pitch"),
                              ("male_vol", "--male-vol"), ("female_vol", "--female-vol")):
                v = payload.get(key)
                if v is not None:
                    args += [flag, str(v)]
            # 字幕样式可调（字号/行数/描边/位置；可选，不传则脚本默认）
            for key, flag in (("subtitle_size", "--subtitle-size"), ("subtitle_lines", "--subtitle-lines"),
                              ("subtitle_outline", "--subtitle-outline"), ("subtitle_position", "--subtitle-position")):
                v = payload.get(key)
                if v is not None:
                    args += [flag, str(v)]
            # 口语化自然润色（去AI感）：显式开启才调 DeepSeek 改写稿子
            if payload.get("natural"):
                args += ["--natural"]
            # 字幕风格（minimal/bubble/dynamic；可选，不传则脚本默认 dynamic）
            if payload.get("subtitle_style"):
                args += ["--subtitle-style", str(payload["subtitle_style"])]
            # 字幕字体（hei/yahei/kaiti/song/fangsong；可选，不传则脚本默认黑体）
            if payload.get("subtitle_font"):
                args += ["--font", resolve_font(payload["subtitle_font"])]
            # 嵌套自动剪辑：在 scroll 成片之上做剪辑风格后处理（fast/artistic/vlog）
            # edit_style 已在 run_job 顶部统一捕获，此处无需重复

        # 准入已完成（/generate 已占并发槽）。HEYGEM 为单 GPU 串行渲染：
        # 先以 step="queued" 告知前端"正在等待渲染资源"，抢到渲染锁后由 _render_with_lock 切到 "rendering"。
        _set_job(job_id, status="rendering", step="queued", start_ts=time.time())
        if _is_cancelled(job_id):
            _set_job(job_id, status="cancelled", step="cancelled", error="用户已中止")
            return
        if card_segs:
            # 长片分段：逐段独立渲染（每段各自计时）→ 全部完成后拼接整片
            rc, err = _render_card_segmented(job_id, card_segs, payload, job_dir, out_path, log_path)
        else:
            rc, err = _render_with_lock(job_id, args, log_path)
        if rc == 0 and os.path.exists(out_path):
            if _is_cancelled(job_id):
                _set_job(job_id, status="cancelled", step="cancelled", error="用户已中止")
                return
            # 专业级后处理 + 质量门禁：剪辑（真实标题烧入）→ 智能封面（QC 门禁）
            out_path = _post_process(job_id, payload, out_path, job_dir, edit_style)
            # 终片技术质检：分辨率 / 音轨 / 竖屏 / 中段静音（音频断续/掉字）
            qc = ai_qc_video(out_path)
            _set_job(job_id, qc_video=qc)
            # 可重生成缺陷（音频缺失 / 中段静音）→ 触发一次上游重渲染，而非输出次品
            regen_codes = {"no_audio", "mid_silence"}
            needs_regen = any(i.get("code") in regen_codes for i in qc.get("issues", []))
            # 分段长片不做同步兜底重渲染：regen 会用单段参数重跑整稿（超长必超时），
            # 且分段成品已落盘、重跑代价极高；交由 QC 透明告警 + 用户决定。
            if needs_regen and not payload.get("_regen") and not card_segs:
                payload["_regen"] = True
                payload["regen_attempts"] = payload.get("regen_attempts", 0) + 1
                # 重渲染为「同步兜底重试」：用独立 REGEN_TIMEOUT（默认 15 分钟，远短于主渲染 35 分钟），
                # 避免 QC 兜底重试把任务卡死数十分钟；step 保持 'rerender' 不变，前端可明确提示"自动重试中"，
                # 与普通渲染区分，用户不再误以为卡死。
                _set_job(job_id, step="rerender", regen_attempted=True,
                         warning="终检检出音频缺陷（缺失/中段静音），正在自动重试修复（至多约 15 分钟）…")
                rc2, err2 = _render_with_lock(job_id, args, log_path,
                                              timeout=REGEN_TIMEOUT, step="rerender")
                if rc2 == 0 and os.path.exists(out_path):
                    out_path = _post_process(job_id, payload, out_path, job_dir, edit_style)
                    qc2 = ai_qc_video(out_path)
                    _set_job(job_id, qc_video=qc2, regen_failed=False,
                             warning="已重渲染重试，终检更新")
                    _append_version(job_id, out_path, payload, tag="重渲染修复")
                else:
                    # 重试失败：不掩盖、不静默交付次品。初始成片仍可用（status 保持 done 以便下载），
                    # 但通过 regen_failed + 持久 warning 透明告知缺陷，由用户决定是否重做。
                    _set_job(job_id, regen_failed=True,
                             warning="⚠ 自动修复未成功（成片可能含短暂静音/音频缺口），建议重新生成或联系我们。")
            _set_job(job_id, status="done", step="done", out=out_path)
            if not payload.get("_regen"):
                _append_version(job_id, out_path, payload, tag="初始版本")
            # 自动发布：done 后若请求带了 auto_publish 平台列表，后台线程分发
            # （无凭证时各适配器降级 dry 模拟，不阻塞出片交付，也不掩盖真实发布结果）。
            _auto_targets = payload.get("auto_publish") or []
            if _auto_targets:
                threading.Thread(
                    target=lambda: _publish_job(job_id, list(_auto_targets), payload),
                    daemon=True).start()
        elif rc == 124:
            if card_segs:
                _set_job(job_id, status="failed",
                         error=f"长片分段渲染超时：某一段超过 {HARD_TIMEOUT} 秒硬上限。"
                               f"请调小单段时长（PIPELINE_CARD_SEG_SEC）或缩短内容。")
            else:
                _set_job(job_id, status="failed",
                         error=f"渲染超时（超过 {HARD_TIMEOUT} 秒硬上限），已自动终止以释放资源。请缩短内容或分批生成。")
        else:
            tail = (err or "")[-4000:]
            _set_job(job_id, status="failed", error=tail)
    except Exception as e:  # noqa: BLE001
        _set_job(job_id, status="failed", error=str(e))
    finally:
        # 释放并发计数（无论成功/失败/异常都递减，防泄漏）
        with lock:
            global active_total
            active_total = max(0, active_total - 1)
            if active_by_tenant.get(tenant_id, 0) > 0:
                active_by_tenant[tenant_id] -= 1
                if active_by_tenant[tenant_id] <= 0:
                    del active_by_tenant[tenant_id]


def _wechat_article_cover(title, brand="慧根堂财税", out_path=None):
    """生成微信图文封面（横版 900×383，深底+金线+标题），供文章送草稿箱兜底。

    文章出稿默认不带封面，而微信 draft/add 强制要求 thumb_media_id；未提供封面时
    自动生成一张黑金风格横版封面，保证推送不缺封面而失败。用户可在公众号后台
    草稿箱里替换成自己设计的封面（更贴合账号调性）。字体缺失时回退默认字体，
    绝不抛异常（调用方已 try 包裹，失败仅视为「无封面」）。
    """
    from PIL import Image, ImageDraw, ImageFont
    import tempfile
    W, H = 900, 383
    if not out_path:
        out_path = os.path.join(tempfile.gettempdir(), "hgt_cover_%s.png" % uuid.uuid4().hex[:10])
    img = Image.new("RGB", (W, H), (14, 17, 24))
    d = ImageDraw.Draw(img)
    # 顶部到底部轻微竖向渐变
    for y in range(H):
        t = y / H
        c = tuple(int(a + (b - a) * t) for a, b in zip((20, 24, 34), (10, 12, 18)))
        d.line([(0, y), (W, y)], fill=c)
    gold = (212, 175, 92)
    try:
        f_brand = ImageFont.truetype(r"C:/Windows/Fonts/simhei.ttf", 26)
        f_title = ImageFont.truetype(r"C:/Windows/Fonts/NotoSerifSC-VF.ttf", 46)
        f_tag = ImageFont.truetype(r"C:/Windows/Fonts/simhei.ttf", 22)
    except Exception:
        f_brand = f_title = f_tag = ImageFont.load_default()
    d.text((W // 2, 64), brand, font=f_brand, fill=gold, anchor="mm")
    d.line([(W // 2 - 120, 96), (W // 2 + 120, 96)], fill=gold, width=2)
    # 标题自动折行（≤2 行）
    max_w = W - 140
    lines, cur = [], ""
    for ch in (title or ""):
        if d.textlength(cur + ch, font=f_title) > max_w:
            lines.append(cur)
            cur = ch
        else:
            cur += ch
    if cur:
        lines.append(cur)
    lines = lines[:2]
    y = 150 + (1 - len(lines)) * 30
    for ln in lines:
        d.text((W // 2, y), ln, font=f_title, fill=(245, 240, 230), anchor="mm")
        y += 76
    d.line([(W // 2 - 150, y + 6), (W // 2 + 150, y + 6)], fill=gold, width=2)
    d.text((W // 2, y + 40), "每日财税干货 · 关注不迷路", font=f_tag, fill=(150, 156, 170), anchor="mm")
    img.save(out_path, "PNG")
    return out_path


def _black_gold_cover(title, subtitle, brand="追梦"):
    """无成片视频时的黑金纯文字封面兜底（1080×1920，对标头部财税IP：深底+金线+大字）。
    版式：顶部品牌小字(字距拉开) → 细金线 → 中部衬线大字标题(≤2行) → 金线 → 副标题小字。"""
    from PIL import Image, ImageDraw, ImageFont
    W, H = 1080, 1920
    img = Image.new("RGB", (W, H), (10, 12, 18))
    d = ImageDraw.Draw(img)
    top, bot = (16, 18, 26), (6, 8, 14)
    for y in range(H):
        t = y / H
        c = tuple(int(a + (b - a) * t) for a, b in zip(top, bot))
        d.line([(0, y), (W, y)], fill=c)
    gold = (212, 175, 92)
    try:
        f_brand = ImageFont.truetype(r"C:/Windows/Fonts/simhei.ttf", 44)
        f_title = ImageFont.truetype(r"C:/Windows/Fonts/NotoSerifSC-VF.ttf", 118)
        f_sub = ImageFont.truetype(r"C:/Windows/Fonts/simhei.ttf", 52)
    except Exception:
        f_brand = f_title = f_sub = ImageFont.load_default()
    brand_txt = "   ".join(brand) if len(brand) <= 6 else brand
    d.text((W // 2, 330), brand_txt, font=f_brand, fill=gold, anchor="mm")
    d.line([(W // 2 - 190, 420), (W // 2 + 190, 420)], fill=gold, width=2)
    d2 = ImageDraw.Draw(img)
    max_w = W - 200
    lines, cur = [], ""
    for ch in title:
        if d2.textlength(cur + ch, font=f_title) > max_w:
            lines.append(cur)
            cur = ch
        else:
            cur += ch
    if cur:
        lines.append(cur)
    lines = lines[:2]
    y = 760
    for ln in lines:
        d.text((W // 2, y), ln, font=f_title, fill=(245, 240, 230), anchor="mm")
        y += 170
    d.line([(W // 2 - 260, y + 10), (W // 2 + 260, y + 10)], fill=gold, width=2)
    d.text((W // 2, y + 120), subtitle, font=f_sub, fill=(168, 172, 184), anchor="mm")
    d.text((W // 2, H - 180), "每日财税干货 · 关注不迷路", font=f_sub, fill=(90, 96, 110), anchor="mm")
    return img


# ---- 对话出稿工作台·一期编排器（POST /chat）----
# ai_topic / ai_rewrite 定义于本文件 744/957，deepseek_chat 已 import；
# 此处（Handler 定义前）创建单例，Handler 方法通过模块全局引用。
from chat_orchestrator import ChatOrchestrator  # noqa: E402

_CHAT_ORCH = ChatOrchestrator(ai_topic, ai_rewrite, deepseek_chat, get_text_config,
                              search_fn=tavily_search, get_key_fn=get_key,
                              planning_cfg_fn=get_planning_config)
_CHAT_ORCH._plan_model = (os.environ.get("PLANNING_MODEL") or "").strip()  # 空=默认 flash（推荐留空）；勿设 deepseek-v4-pro（已停用）


class Handler(http.server.BaseHTTPRequestHandler):
    def _send(self, code, obj=None, body=None, ctype="application/json; charset=utf-8"):
        self.send_response(code)
        if obj is not None:
            data = json.dumps(obj, ensure_ascii=False).encode("utf-8")
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)
        elif body is not None:
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        else:
            self.end_headers()

    def do_GET(self):
        p = urlparse(self.path)
        # ---- 对话出稿·会话/空间列表与历史消息（二期）----
        if p.path == "/chat/sessions":
            q = parse_qs(p.query or "")
            return self._handle_chat_sessions((q.get("tenant") or [""])[0])
        if p.path == "/chat/session/messages":
            q = parse_qs(p.query or "")
            return self._handle_chat_messages((q.get("session_id") or [""])[0])
        # 对话出稿·异步长任务进度（B 版）
        if p.path.startswith("/chat/status/"):
            _sid = p.path[len("/chat/status/"):].strip("/")
            return self._handle_chat_status(_sid)
        if p.path == "/health":
            return self._send(200, {"status": "ok"})
        if p.path == "/metrics":
            with lock:
                running = sum(1 for j in jobs.values()
                              if j.get("status") in ("queued", "rendering"))
            return self._send(200, {
                "active_jobs": running,
                "total_jobs_in_memory": len(jobs),
                "global_max": GLOBAL_MAX_JOBS,
                "tenant_max": TENANT_MAX_JOBS,
                "hard_timeout": HARD_TIMEOUT,
                "regen_timeout": REGEN_TIMEOUT,
                "jobs_dir": JOBS_DIR,
            })

        if p.path.startswith("/status/"):
            jid = p.path.rsplit("/", 1)[-1]
            with lock:
                j = jobs.get(jid)
                snap = list(jobs.items())
            if not j:
                return self._send(404, {"error": "not found"})
            queue_pos = 0
            if j.get("step") == "queued":
                me_ts = j.get("start_ts") or 0
                queue_pos = 1 + sum(1 for oid, oj in snap
                                    if oid != jid and oj.get("step") == "queued"
                                    and (oj.get("start_ts") or 0) < me_ts)
            # 读取 render.log 中的最新真实进度，给前端真实进度（避免 ETA 永远停在固定值）。
            # 兼容三种引擎输出格式：旧 progress=N / motion 的「渲染 X%」/ scroll·漫剧·白板的「烧字幕 X%」。
            render_progress = None
            try:
                log_path = os.path.join(JOBS_DIR, jid, "render.log")
                if os.path.isfile(log_path):
                    with open(log_path, "r", encoding="utf-8") as f:
                        lines = f.readlines()
                    for line in reversed(lines):
                        m = re.search(r"(?:progress=(\d{1,3})|渲染\s*(\d{1,3})%|烧字幕\s*(\d{1,3})%)", line)
                        if m:
                            val = int(next(g for g in m.groups() if g is not None))
                            if 0 <= val <= 100:
                                render_progress = val
                                break
            except Exception:  # noqa: BLE001
                pass
            return self._send(200, {
                "job_id": jid,
                "status": j["status"],
                "step": j.get("step"),
                "queue_pos": queue_pos,
                "progress": render_progress,
                "result": f"/download/{jid}" if j["status"] == "done" else None,
                "cover": j.get("cover"),
                "qc_video": j.get("qc_video"),
                "qc_cover": j.get("qc_cover"),
                "versions": j.get("versions"),
                "warning": j.get("warning"),
                "error": j.get("error"),
                "regen_attempted": j.get("regen_attempted", False),
                "regen_failed": j.get("regen_failed", False),
            })

        if p.path.startswith("/download/"):
            jid = p.path.rsplit("/", 1)[-1]
            with lock:
                j = jobs.get(jid)
            if not j or j["status"] != "done":
                return self._send(404, {"error": "not ready"})
            with open(j["out"], "rb") as f:
                data = f.read()
            return self._send(200, body=data, ctype="video/mp4")

        # ---- P3 版本列表 ----
        if p.path.startswith("/versions/"):
            jid = p.path.rsplit("/", 1)[-1]
            with lock:
                j = jobs.get(jid)
            if not j:
                return self._send(404, {"error": "not found"})
            return self._send(200, {"job_id": jid, "status": j.get("status"),
                                    "versions": j.get("versions") or []})

        # ---- P4 实时预览帧（从当前成片抽一帧；渲染中则返回进度占位）----
        # DEPRECATED：无任何调用方（Laravel 与 8385 均未引用），保留仅为兼容旧链路。
        if p.path.startswith("/preview/"):
            jid = p.path.rsplit("/", 1)[-1]
            with lock:
                j = jobs.get(jid)
            if not j:
                return self._send(404, {"error": "not found"})
            if j.get("status") != "done":
                return self._send(200, {"job_id": jid, "status": j.get("status"),
                                        "ready": False, "step": j.get("step")})
            cand = j.get("out") or os.path.join(JOBS_DIR, jid, "out.mp4")
            if not os.path.exists(cand):
                return self._send(404, {"error": "video not ready"})
            frame = os.path.join(JOBS_DIR, jid, "preview.jpg")
            try:
                subprocess.run([FFMPEG, "-y", "-i", cand, "-frames:v", "1",
                                "-q:v", "3", frame], capture_output=True, timeout=20)
                with open(frame, "rb") as f:
                    return self._send(200, body=f.read(), ctype="image/jpeg")
            except Exception:  # noqa: BLE001
                return self._send(503, {"error": "preview gen failed"})

        # ---- P4 留资钩子库（行业中性化模板）----
        # DEPRECATED：无任何调用方（Laravel 与 8385 均未引用），保留仅为兼容旧链路。
        if p.path == "/hooks":
            q = p.query or ""
            typ = ""
            if "type=" in q:
                typ = q.split("type=")[1].split("&")[0]
            libs = HOOK_LIBRARY
            if typ:
                libs = [h for h in libs if h["type"] == typ]
            return self._send(200, {"ok": True, "count": len(libs), "hooks": libs})

        # ---- P4 数据看板（聚合指标）----
        # DEPRECATED：无任何调用方（Laravel 与 8385 均未引用），保留仅为兼容旧链路。
        if p.path == "/stats":
            with lock:
                js = list(jobs.values())
            by_status = {}
            by_platform = {}
            published = 0
            for j in js:
                s = j.get("status", "unknown")
                by_status[s] = by_status.get(s, 0) + 1
                pl = j.get("platform") or "unknown"
                by_platform[pl] = by_platform.get(pl, 0) + 1
                if j.get("publish_results"):
                    published += 1
            return self._send(200, {
                "ok": True,
                "total_jobs": len(js),
                "by_status": by_status,
                "by_platform": by_platform,
                "published_jobs": published,
                "hard_timeout": HARD_TIMEOUT,
                "global_max": GLOBAL_MAX_JOBS,
            })

        # ---- P4 热点追踪（dry 种子 + 可选 LLM  enrichment）----
        # DEPRECATED：Laravel 已改用真实检索版 /hotspot，此 seed 端点无调用方。
        if p.path == "/hotspots":
            q = p.query or ""
            plat = ""
            if "platform=" in q:
                plat = q.split("platform=")[1].split("&")[0]
            seed = HOTSPOT_SEED
            if plat:
                seed = [h for h in seed if h.get("platform") == plat]
            return self._send(200, {"ok": True, "mode": "seed",
                                    "count": len(seed), "hotspots": seed})

        # ---- 每日热点·双题材：读最近一次 daily_hot.json 结果（前端轮询）----
        if p.path == "/hot-daily-result":
            return self._handle_hot_daily_result()

        # ---- OAuth2 授权（抖音 / 小红书 授权码模式；公众号 / 视频号走 client_credential）----
        # 恢复（功能包一）：账号级授权，query 可带 account_id（platform_accounts.id）。
        if p.path.startswith("/oauth/authorize/"):
            platform = p.path.rsplit("/", 1)[-1]
            return self._handle_oauth_authorize(platform, p.query or "")
        if p.path.startswith("/oauth/callback/"):
            platform = p.path.rsplit("/", 1)[-1]
            return self._handle_oauth_callback(platform, p.query or "")
        if p.path.startswith("/oauth/status/"):
            platform = p.path.rsplit("/", 1)[-1]
            return self._handle_oauth_status(platform, p.query or "")

        return self._send(404, {"error": "not found"})

    def do_POST(self):
        p = urlparse(self.path)
        length = int(self.headers.get("Content-Length", 0) or 0)
        raw = self.rfile.read(length) if length else b""
        try:
            data = json.loads(raw.decode("utf-8"))
        except Exception:  # noqa: BLE001
            return self._send(400, {"error": "bad json"})
        # 容忍双重编码：body 本身可能是 JSON 字符串（如 "{\"industry\":...}"）
        if isinstance(data, str):
            try:
                data = json.loads(data)
            except Exception:  # noqa: BLE001
                return self._send(400, {"error": "bad json (double-encoded)"})

        # ---- OAuth2 授权（账号级）：Laravel 解密该账号的 client_key/client_secret 经 body 传入，
        #      明文不出 Laravel 容器、不进 URL/日志。多应用矩阵（每个抖音号一套应用凭证）靠此路由。
        if p.path.startswith("/oauth/authorize/"):
            platform = p.path.rsplit("/", 1)[-1]
            return self._handle_oauth_authorize(platform, p.query or "", data)

        if p.path == "/generate":
            return self._handle_generate(data)
        if p.path == "/topic":
            return self._handle_topic(data)
        if p.path == "/rewrite":
            return self._handle_rewrite(data)
        # ---- 公众号文章四段链路：出稿 / SEO / 草稿箱 / 群发 ----
        # /article 与 /article/write 同义（Laravel 侧两种写法都要兼容）
        if p.path in ("/article", "/article/write"):
            return self._handle_article_write(data)
        if p.path == "/article/seo-check":
            return self._handle_article_seo_check(data)
        if p.path == "/article/keywords":
            return self._handle_article_keywords(data)
        if p.path == "/article/push-draft":
            return self._handle_article_draft(data)
        if p.path == "/article/publish":
            return self._handle_article_publish(data)
        if p.path == "/chat/session/create":
            return self._handle_chat_session_create(data)
        if p.path == "/chat/session/update":
            return self._handle_chat_session_update(data)
        if p.path == "/chat/session/delete":
            return self._handle_chat_session_delete(data)
        if p.path == "/chat":
            return self._handle_chat(data)
        # ---- v2.0 P2/P3 新能力端点（capabilities.py 17 能力的执行层）----
        if p.path == "/advisor":
            return self._send(200, advisor_answer(
                (data.get("topic") or "").strip(),
                (data.get("industry") or "").strip(),
                (data.get("urgency") or "").strip()))
        if p.path == "/crm":
            if (data.get("action") or "").strip() == "list":
                return self._send(200, crm_list())
            return self._send(200, crm_upsert(data))
        if p.path == "/booking":
            return self._send(200, booking_create(data))
        if p.path == "/reception-config":
            return self._send(200, reception_config_save(data))
        if p.path == "/matrix-config":
            return self._send(200, matrix_config_save(data))
        if p.path == "/qc":
            return self._handle_qc(data)
        if p.path == "/qc-video":
            return self._handle_qc_video(data)
        if p.path == "/qc-asset":
            # DEPRECATED：无任何调用方（Laravel 与 8385 均未引用），保留仅为兼容旧链路。
            return self._handle_qc_asset(data)
        if p.path == "/process-asset":
            # DEPRECATED：无任何调用方（Laravel 与 8385 均未引用），保留仅为兼容旧链路。
            return self._handle_process_asset(data)
        if p.path == "/delete-asset":
            # DEPRECATED：无任何调用方（Laravel 与 8385 均未引用），保留仅为兼容旧链路。
            return self._handle_delete_asset(data)
        if p.path == "/clone_voice":
            return self._handle_clone_voice(data)
        if p.path == "/xhs_build_note":
            return self._handle_xhs_build_note(data)
        if p.path == "/xhs_generate":
            return self._handle_xhs_generate(data)
        if p.path == "/xhs_regen_cover":
            return self._handle_xhs_regen_cover(data)
        if p.path == "/strategist":
            return self._handle_strategist(data)
        if p.path == "/moment":
            # DEPRECATED：唯一调用方（Laravel 内容矩阵页）已下线，保留仅为兼容旧链路。
            return self._handle_moment(data)
        if p.path == "/deai":
            # DEPRECATED：Laravel「去AI痕迹」功能已下线，保留仅为兼容旧链路。
            return self._handle_deai(data)
        if p.path == "/suggest-title":
            return self._handle_suggest_title(data)
        if p.path == "/hotspot":
            return self._handle_hotspot(data)
        if p.path == "/hot-daily":
            return self._handle_hot_daily(data)
        if p.path == "/hot-daily-result":
            return self._handle_hot_daily_result()
        if p.path == "/transcribe":
            return self._handle_transcribe(data)
        if p.path == "/dissect":
            return self._handle_dissect(data)
        if p.path == "/footage-edit":
            return self._handle_footage_edit(data)
        if p.path == "/publish-pack":
            return self._handle_publish_pack(data)
        if p.path == "/follow_hot":
            return self._handle_follow_hot(data)
        if p.path == "/cancel":
            return self._handle_cancel(data)
        if p.path == "/policy_asset":
            # DEPRECATED：无任何调用方（Laravel 与 8385 均未引用），保留仅为兼容旧链路。
            return self._handle_policy_asset(data)
        if p.path == "/publish":
            return self._handle_publish(data)
        if p.path == "/polish":
            return self._handle_polish(data)
        if p.path == "/clone":
            # DEPRECATED：无任何调用方（Laravel 与 8385 均未引用），保留仅为兼容旧链路。
            return self._handle_clone(data)
        if p.path == "/metrics/fetch":
            return self._send(200, {"ok": True, "results": fetch_batch((data or {}).get("items") or [])})
        return self._send(404, {"error": "not found"})

    # ---- 出片中止：标记 job 为已取消 ----
    def _handle_cancel(self, data):
        """POST /cancel：标记 job 为已取消，使其渲染循环尽快停止。

        入参 JSON: {"job_id": "<id>"}
        返回: {"ok": true, "status": "cancelled" | "not_found" | "already_done"}
        """
        job_id = (data or {}).get("job_id") or ""
        if not job_id:
            return self._send(400, {"error": "job_id required"})
        with lock:
            j = jobs.get(job_id)
            if j is None:
                return self._send(404, {"ok": False, "status": "not_found"})
            if j.get("status") in ("done", "failed", "cancelled"):
                return self._send(200, {"ok": True, "status": j.get("status")})
            j["cancelled"] = True
            _save_job(job_id, j)
        return self._send(200, {"ok": True, "status": "cancelled"})

    # ---- 真人出镜素材自动精剪：去气口/停顿/重复句 + 字幕 + 封面 ----
    def _handle_footage_edit(self, data):
        """POST /footage-edit
        {"file_path": "<宿主绝对路径>", "language": "zh"}
        → 精剪后 {out_mp4, cover, ass, duration_before/after, silences_removed, dups_removed, transcript}
        """
        try:
            if not isinstance(data, dict):
                return self._send(400, {"error": "invalid request body"})
            fp = (data.get("file_path") or "").strip()
            if not fp or not os.path.exists(fp):
                return self._send(400, {"error": "file_path required / not exist"})
            lang = str(data.get("language") or "zh")
            result = edit_footage(fp, lang)
            return self._send(200, result)
        except Exception as e:  # noqa: BLE001
            traceback.print_exc()
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- 发布包装：标题 + 副标题 + 高级感封面（对标主流财税IP，拒绝简单堆砌）----
    def _handle_publish_pack(self, data):
        """POST /publish-pack
        {"text": "<稿子/字幕>", "video_path": "<宿主绝对路径,可选>", "industry": "财税", "brand": "追梦"}
        → {ok, title, subtitle, cover_path}
        1) DeepSeek 生成 主标题(≤10字,数字/痛点/反常识) + 副标题(≤20字,补充钩子)，
           风格对标头部财税IP（张琦式大字+痛点，克制、高级，无标题党堆砌）；
        2) make_cover.py 对成片智能选帧 + 人脸构图 + 自动对比度出封面（QC 门禁）；
           无视频时用 PIL 黑金纯文字封面兜底。
        """
        try:
            if not isinstance(data, dict):
                return self._send(400, {"error": "invalid request body"})
            text = (data.get("text") or "").strip()
            video = (data.get("video_path") or "").strip()
            cover_photo = (data.get("cover_photo") or "").strip()
            if not text and not (video and os.path.exists(video)) and not (cover_photo and os.path.exists(cover_photo)):
                return self._send(400, {"error": "text 或 video_path 至少一项"})
            industry = (data.get("industry") or "").strip() or "财税"
            brand = (data.get("brand") or "").strip() or "昆山老张讲财税"

            # 1) LLM 标题/副标题（对标头部财税IP · 高级感）
            prompt = (
                f"为{industry}短视频生成1组「封面标题 + 副标题」，对标头部财税IP的高级封面文案（如'私户收款，正在被重点比对''年底了，老板别再借钱给公司'）。\n"
                "铁律：\n"
                "1. 主标题≤10字：用数字/痛点/反常识/警示抓人，前5字让人懂讲什么，绝不堆砌形容词；\n"
                "2. 副标题≤20字：补充一个具体价值/钩子，不重复主标题；\n"
                "3. 高级感：克制、留白、像大号财经号，拒绝'震惊/重磅/速看/马上'式标题党，拒绝多个感叹号；\n"
                "4. 禁违禁词：最/第一/唯一/100%/根治/必看/暴富/躺赚/包过；\n"
                f'5. 严格只输出JSON:{{"title":"主标题","subtitle":"副标题"}}，不要其他内容。\n\n【文稿】\n{text[:400]}'
            )
            cfg = get_text_config()
            raw = deepseek_chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=25)
            if isinstance(raw, dict):
                raw = raw.get("content") or json.dumps(raw, ensure_ascii=False)
            content = (raw or "").strip()
            if content.startswith("```"):
                content = content.strip("`")
                if content[:4].lower() == "json":
                    content = content[4:]
                content = content.strip()
            m = re.search(r"\{.*\}", content, re.S)
            obj = json.loads(m.group(0)) if m else {}
            title = str(obj.get("title") or "").strip()
            subtitle = str(obj.get("subtitle") or "").strip()
            if not title:
                title = text[:10]
            if not subtitle:
                subtitle = industry + " · 老板必看"

            # 2) 封面：优先个人形象照（海马体等专业肖像，人脸居中零变形）；
            #    无形象照时用成片智能选帧；两者都没有则黑金纯文字兜底。
            cover = ""
            if cover_photo and os.path.exists(cover_photo):
                try:
                    vdir = os.path.dirname(cover_photo)
                    cover = os.path.join(vdir, "portrait_pack_cover.jpg")
                    from make_cover import compose_from_photo
                    compose_from_photo(cover_photo, cover, title, subtitle, brand=brand)
                    if not os.path.exists(cover):
                        cover = ""
                except Exception:
                    cover = ""
            if not cover and video and os.path.exists(video):
                vdir = os.path.dirname(video)
                stem = os.path.splitext(os.path.basename(video))[0]
                cover = os.path.join(vdir, stem + "_pack_cover.jpg")
                try:
                    subprocess.run([PY310, SCRIPT_COVER, "--input", video, "--output", cover,
                                    "--title", title, "--subtitle", subtitle,
                                    "--platform", "video", "--brand", brand],
                                   capture_output=True, text=True, encoding="utf-8",
                                   errors="replace", timeout=180, cwd=GPT_SOVITS)
                    if not os.path.exists(cover):
                        cover = ""
                except Exception:
                    cover = ""
            if not cover:
                # 兜底黑金纯文字封面：PIL Image 不能直接进 JSON（曾致
                # "TypeError: Object of type Image is not JSON serializable"），
                # 必须落盘为 *_pack_cover.jpg 再返回路径。存到 jobs/_pack_covers/：
                # recover_jobs 只认含 job.json 的目录（此目录被跳过），
                # PHP cover() 经 jobs/*/name 通配可服务到该文件。
                try:
                    bg_img = _black_gold_cover(title, subtitle, brand)
                    cdir = os.path.join(JOBS_DIR, "_pack_covers")
                    os.makedirs(cdir, exist_ok=True)
                    cpath = os.path.join(cdir, "bg_%d_pack_cover.jpg" % int(time.time() * 1000))
                    bg_img.save(cpath, "JPEG", quality=90)
                    if os.path.exists(cpath):
                        cover = cpath
                except Exception:  # noqa: BLE001
                    traceback.print_exc()

            return self._send(200, {"ok": True, "title": title, "subtitle": subtitle,
                                    "cover_path": cover})
        except Exception as e:  # noqa: BLE001
            traceback.print_exc()
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- 语音输入整理：把口语化/啰嗦/有口误的口述整理成清晰指令 ----
    def _handle_polish(self, data):
        """POST /polish
        {"text": "<语音识别原始文本>"}
        → {"ok": true, "polished": "<整理后文本>"}
        复用 DeepSeek 轻度改写：去口语赘余、修正口误、保留原意与财税关键信息。
        不擅自补充财税结论。超时/无 key 时降级返回原文（ok=false 但带 polished=原文）。
        """
        try:
            if not isinstance(data, dict):
                return self._send(400, {"error": "invalid request body"})
            raw = (data.get("text") or "").strip()
            if not raw:
                return self._send(400, {"error": "text required"})
            if len(raw) <= 6:
                return self._send(200, {"ok": True, "polished": raw})  # 太短无需整理
            cfg = get_text_config() or {}
            prompt = (
                "你是「慧根堂财税短视频出稿助手」的语音整理模块。用户用语音口述了想让 AI 做的事（一句话需求/指令/想法），"
                "语音识别可能有口误、啰嗦、重复、口语 filler。\n"
                "任务：把下面这段口语整理成一句清晰、准确、可直接作为对 AI 助手指令的自然中文。\n"
                "要求：\n"
                "1. 修正明显口误，删除「嗯/那个/然后然后/就是说/这个/那个/啊/对吧」等赘余与重复；\n"
                "2. 保留原意、关键主体（税种/业务/受众/动作：出稿/改写/出片/选题）与所有具体信息；\n"
                "3. 不动专业财税事实，不擅自补充用户没说的结论；\n"
                "4. 输出一句通顺、长度适中（30–80 字内）的话，不加引号、不加解释、不写「整理后：」；\n"
                "5. 若原文已通顺，则基本保持原样只去赘余。\n"
                "只输出最终结果文本。\n\n【原始口述】\n" + raw
            )
            try:
                content = deepseek_chat(prompt, cfg.get("model", ""), cfg.get("key", ""),
                                        cfg.get("base_url"), timeout=30)
            except Exception as e:  # noqa: BLE001
                traceback.print_exc()
                # 模型不可用：降级返回原文，前端照常可发送
                return self._send(200, {"ok": False, "polished": raw, "error": str(e)})
            if isinstance(content, dict):
                content = content.get("content") or json.dumps(content, ensure_ascii=False)
            polished = (content or "").strip()
            if not polished:
                polished = raw
            return self._send(200, {"ok": True, "polished": polished})
        except Exception as e:  # noqa: BLE001
            traceback.print_exc()
            fb = (data.get("text") or "") if isinstance(data, dict) else ""
            return self._send(200, {"ok": False, "error": str(e), "polished": fb})

    # ---- 自动发布：调 publishers 适配器把成片分发到指定平台 ----
    def _handle_publish(self, data):
        """POST /publish：把成片（视频或图文）发布到多平台。

        视频笔记入参:
            {"job_id": "<id>", "platforms": [...], "title": "", "description": "",
             "tags": [], "credential_ref": ""}
        图文笔记入参（mode="image"，不依赖视频 job）:
            {"mode": "image", "image_paths": ["/abs/cover.png", ...],
             "platforms": ["xiaohongshu"], "title": "", "description": "", "tags": []}
        返回: {"job_id", "results": [{"platform","status","post_id","url","error"}]}
        无凭证时各适配器降级 dry 模拟（status=published + 模拟 post_id/url）。
        """
        mode = str(data.get("mode") or "video").lower()
        # 图文/文章发布不依赖视频 job：无 job_id 时生成占位 id（仅用于回写 publish_results）
        if mode == "article":
            job_id = data.get("job_id") or ("article_" + secrets.token_hex(8))
        elif mode == "image":
            job_id = data.get("job_id") or ("xhs_" + secrets.token_hex(8))
        else:
            job_id = data.get("job_id") or ""
            if not job_id:
                return self._send(400, {"error": "job_id required"})
        platforms = data.get("platforms") or []
        if not platforms:
            return self._send(400, {
                "error": "platforms required (e.g. ['douyin','shipinhao','xiaohongshu'])",
                "supported": supported_platforms(),
            })
        results = _publish_job(job_id, platforms, data)
        # 单元素错误列表 → 映射为对应 HTTP 状态码
        if len(results) == 1 and results[0].get("error"):
            err = results[0]["error"]
            if "not found" in err or "missing" in err:
                return self._send(404, results[0])
            if "not done" in err:
                return self._send(409, results[0])
            if "unsupported" in err:
                return self._send(400, results[0])
        return self._send(200, {"job_id": job_id, "results": results})

    # ---- 小红书图文笔记生成：选题+卖点+受众 → 结构化笔记 + 渲染出图 ----
    def _handle_xhs_build_note(self, data):
        """POST /xhs_build_note：仅根据选题/卖点/受众生成结构化笔记（不出图）。

        入参 JSON:
            {"topic": "选题", "selling_points": "卖点（可多句）", "audience": "受众",
             "pages": 可选期望内页数(默认4), "raw_body": "用户已写好的正文草稿（可选）"}
        返回:
            {"ok": true, "note": {"cover":..., "pages":..., "body":..., "titles":...}}
        """
        topic = (data.get("topic") or "").strip()
        if not topic:
            return self._send(400, {"error": "topic required"})
        selling = (data.get("selling_points") or "").strip()
        audience = (data.get("audience") or "").strip()
        want_pages = max(2, min(8, int(data.get("pages") or 4)))
        raw_body = (data.get("raw_body") or "").strip()

        note = self._xhs_build_note(topic, selling, audience, want_pages, raw_body=raw_body)
        if not note:
            return self._send(502, {"error": "内容生成失败（DeepSeek 不可用或超时）"})
        return self._send(200, {"ok": True, "note": note})

    def _handle_xhs_generate(self, data):
        """POST /xhs_generate：基于结构化笔记渲染出「能直接发布」的小红书系列图文。

        支持三种入参模式：
          1) 传完整 note：{"note": {...}, "brand": ..., "seed": ...}
          2) 传用户正文草稿：{"topic":..., "selling_points":..., "audience":..., "pages":..., "raw_body": ...}
          3) 老模式：{"topic":..., "selling_points":..., "audience":..., "pages":..., "brand": ..., "seed": ...}
        返回:
            {"ok": true, "note": {...}, "images": [base64...], "image_paths": [abs...],
             "count": <总张数>}
        总张数封顶 9（封面1 + 内文≤8）。
        """
        brand = (data.get("brand") or "追梦短视频").strip()
        # 注：brand 由 Laravel 侧按租户传入（settings.brand 或租户名），此处仅为无调用方兜底。
        cover_seed = int(data.get("seed") or secrets.randbelow(100000))

        note = data.get("note")
        if not note:
            topic = (data.get("topic") or "").strip()
            raw_body = (data.get("raw_body") or "").strip()
            if not topic and not raw_body:
                return self._send(400, {"error": "缺少选题、已生成的笔记结构(note)或正文草稿(raw_body)"})
            selling = (data.get("selling_points") or "").strip()
            audience = (data.get("audience") or "").strip()
            want_pages = max(2, min(8, int(data.get("pages") or 4)))
            note = self._xhs_build_note(topic or "（用户粘贴文案）", selling, audience, want_pages, raw_body=raw_body)
            if not note:
                return self._send(502, {"error": "内容生成失败（DeepSeek 不可用或超时）"})

        # 渲染出图（封面 + 内文分页，封顶 9 张）
        import base64
        from xhs_render import render_note, render_cover
        outdir = os.path.join(JOBS_DIR, "xhs_" + secrets.token_hex(8))
        os.makedirs(outdir, exist_ok=True)
        paths = render_note(note, outdir, brand, cover_seed=cover_seed)
        images_b64 = []
        for pp in paths:
            with open(pp, "rb") as f:
                images_b64.append("data:image/png;base64," + base64.b64encode(f.read()).decode("ascii"))

        return self._send(200, {
            "ok": True,
            "note": note,
            "images": images_b64,
            "image_paths": paths,
            "count": len(paths),
            "cover_seed": cover_seed,
        })

    def _handle_xhs_regen_cover(self, data):
        """POST /xhs_regen_cover：仅重新生成封面（换背景/配色），文字（标题/副标题）不变。

        入参 JSON:
            {"cover": {"title","subtitle","tag"}, "brand": 可选, "seed": 必填（新随机 seed）,
             "topic"/"selling_points"/"audience": 可选（AI 背景 prompt 用）}
        返回:
            {"ok": true, "cover": "data:image/png;base64,...", "cover_path": abs, "seed": int}
        """
        cover = data.get("cover") or {}
        if not (cover.get("title") or cover.get("subtitle")):
            return self._send(400, {"error": "cover title/subtitle required"})
        brand = (data.get("brand") or "追梦短视频").strip()
        # 注：brand 由 Laravel 侧按租户传入（settings.brand 或租户名），此处仅为无调用方兜底。
        seed = int(data.get("seed") or secrets.randbelow(100000))
        topic = (data.get("topic") or "").strip()
        selling = (data.get("selling_points") or "").strip()
        audience = (data.get("audience") or "").strip()

        import base64
        from xhs_render import render_cover
        outdir = os.path.join(JOBS_DIR, "xhs_" + secrets.token_hex(8))
        os.makedirs(outdir, exist_ok=True)
        cover_path = os.path.join(outdir, "cover.png")
        render_cover({"cover": cover}, cover_path, brand, seed, topic, selling, audience)
        with open(cover_path, "rb") as f:
            cover_b64 = "data:image/png;base64," + base64.b64encode(f.read()).decode("ascii")
        return self._send(200, {
            "ok": True,
            "cover": cover_b64,
            "cover_path": cover_path,
            "seed": seed,
        })

    def _xhs_build_note(self, topic, selling, audience, want_pages, raw_body=None):
        """调 DeepSeek 产出结构化笔记（封面/内文分页/正文/候选标题）。

        若传入 raw_body，则基于用户已写好的正文草稿进行整理和结构化。
        """
        try:
            cfg = get_text_config()
        except Exception:  # noqa: BLE001
            cfg = {"model": "", "key": "", "base_url": None}

        if raw_body:
            prompt = (
                "你是一名资深小红书财税内容运营，擅长把老板写好的财税文案/爆款方案，"
                "整理成能直接出图的小红书系列图文笔记。请严格根据以下信息产出结构化方案。\n\n"
                f"【选题】{topic}\n"
                f"【卖点/核心观点】{selling or '（自行提炼该选题对受众的核心价值）'}\n"
                f"【目标受众】{audience or '中小企业老板/创业者'}\n"
                f"【内文分页数量】请产出 {want_pages} 页内文（不含封面）。\n"
                f"【用户正文草稿】\n{raw_body}\n\n"
                "任务：基于用户正文草稿，保留其核心观点与真实案例，重新整理成小红书爆款风格："
                "口语化、有钩子、有干货、结尾带互动引导。"
                "严格要求：只输出一个 JSON 对象，不要任何解释、不要 markdown 代码块包裹。结构如下：\n"
                "{\n"
                '  "cover": {"title": "封面大字标题（≤18字，抓痛点或给钩子）", "subtitle": "封面副标题（≤24字，补充说明）", "tag": "封面标签（≤8字）"},\n'
                '  "pages": [{"heading": "本页小标题（≤14字）", "points": ["要点1（≤30字）", "要点2（≤30字）", "要点3（≤30字）"]}],\n'
                '  "body": "整理后的完整小红书正文（口语化，带话题标签 #税务风险 #老板必看 等），总长 200~400 字",\n'
                '  "titles": ["候选标题1（≤20字，带情绪/数字/痛点）", "候选标题2", "候选标题3", "候选标题4"]\n'
                "}\n"
                "注意：pages 数组长度必须等于上面要求的内文页数；points 每页 2~4 条；"
                "封面标题必须从用户草稿核心痛点中提炼；所有中文准确、专业、无错别字；"
                "禁止出现违禁词（不承诺避税/不诱导虚开）。"
            )
        else:
            prompt = (
                "你是一名资深小红书财税内容运营，擅长把专业财税知识改写成老板爱看、"
                "能涨粉的爆款图文笔记。请根据以下信息，产出一篇系列图文笔记的结构化方案。\n\n"
                f"【选题】{topic}\n"
                f"【卖点/核心观点】{selling or '（自行提炼该选题对受众的核心价值）'}\n"
                f"【目标受众】{audience or '中小企业老板/创业者'}\n"
                f"【内文分页数量】请产出 {want_pages} 页内文（不含封面）。\n\n"
                "严格要求：只输出一个 JSON 对象，不要任何解释、不要 markdown 代码块包裹。结构如下：\n"
                "{\n"
                '  "cover": {"title": "封面大字标题（≤18字，抓痛点或给钩子）", "subtitle": "封面副标题（≤24字，补充说明）", "tag": "封面标签（≤8字，如 税务风险·老板必看）"},\n'
                '  "pages": [{"heading": "本页小标题（≤14字）", "points": ["要点1（≤30字）", "要点2（≤30字）", "要点3（≤30字）"]}],\n'
                '  "body": "小红书正文：口语化、有干货、结尾带互动引导，末尾附 4~6 个 #话题标签（如 #税务风险 #老板必看），总长 200~400 字",\n'
                '  "titles": ["候选标题1（≤20字，带情绪/数字/痛点）", "候选标题2", "候选标题3", "候选标题4"]\n'
                "}\n"
                "注意：pages 数组长度必须等于上面要求的内文页数；points 每页 2~4 条；"
                "所有中文必须准确、专业、无错别字；禁止出现违禁词（不承诺避税/不诱导虚开）。"
            )
        try:
            content = deepseek_chat(prompt, cfg.get("model", ""), cfg.get("key", ""),
                                    cfg.get("base_url"), timeout=90)
        except Exception as e:  # noqa: BLE001
            _hotspot_debug(["xhs deepseek fail: " + str(e)])
            return None
        if not content:
            return None
        # 抽取 JSON
        try:
            s, e = content.find("{"), content.rfind("}")
            if s < 0 or e < 0:
                return None
            obj = json.loads(content[s:e + 1])
        except Exception:  # noqa: BLE001
            return None
        # 兜底规范化
        pages = obj.get("pages") or []
        if not isinstance(pages, list):
            pages = []
        out = {
            "cover": obj.get("cover") or {"title": topic, "subtitle": "", "tag": "财税干货"},
            "pages": pages[:8],
            "body": obj.get("body") or raw_body or "",
            "titles": obj.get("titles") or [topic],
        }
        if not out["pages"]:
            if raw_body:
                out["pages"] = [{"heading": topic, "points": [selling or "核心卖点", "要点提炼", "行动建议"]}]
            else:
                out["pages"] = [{"heading": topic, "points": [selling or "核心卖点", "适用场景", "行动建议"]}]
        return out

    # ---- P4 OAuth2 授权码模式：抖音/小红书 ----
    def _handle_oauth_authorize(self, platform: str, query: str = "", body: dict | None = None):
        if platform not in ("douyin", "xiaohongshu"):
            return self._send(400, {"error": "unsupported oauth platform (use douyin/xiaohongshu)"})
        body = body or {}
        # 功能包一：账号级授权，query 可带 account_id（platform_accounts.id）
        params = dict(q.split("=") for q in query.split("&") if "=" in q) if query else {}
        account_id = str(body.get("account_id") or params.get("account_id", "") or "")
        # 多应用矩阵：每个账号一套开放平台应用凭证，由 Laravel 解密后经 body 传入；
        # 未传时回退全局 env（单应用部署方式，向后兼容）。
        client_key = str(body.get("client_key") or params.get("client_key") or "")
        client_secret = str(body.get("client_secret") or params.get("client_secret") or "")
        if platform == "douyin":
            scope = str(body.get("scope") or "video.create")
        else:
            scope = str(body.get("scope") or "note.write")
        # 清理过期 state
        now = time.time()
        expired = [k for k, v in _OAUTH_STATES.items() if now >= v["exp"]]
        for k in expired:
            _OAUTH_STATES.pop(k, None)
        state = secrets.token_urlsafe(24)
        _OAUTH_STATES[state] = {"platform": platform, "exp": now + _OAUTH_STATE_TTL,
                                "account_id": account_id,
                                "client_key": client_key, "client_secret": client_secret}
        redirect_uri = f"{OAUTH_REDIRECT_BASE}/oauth/callback/{platform}"
        redirect_q = quote(redirect_uri, safe="")

        if platform == "douyin":
            cid = client_key or os.environ.get("DOUYIN_CLIENT_ID", "")
            if not cid:
                return self._send(500, {"error": "抖音 client_key 未配置（账号凭证为空且 env DOUYIN_CLIENT_ID 未设置）"})
            # 抖音 scope 见开放平台；video.create 为发布权限
            url = (f"https://open.douyin.com/platform/oauth/connect/"
                   f"?client_key={cid}&response_type=code&scope={scope}"
                   f"&redirect_uri={redirect_q}&state={state}")
        else:  # xiaohongshu
            app_id = client_key or os.environ.get("XHS_APP_ID", "")
            if not app_id:
                return self._send(500, {"error": "XHS_APP_ID 未配置"})
            url = (f"https://open.xiaohongshu.com/platform/oauth/authorize"
                   f"?app_id={app_id}&redirect_uri={redirect_q}"
                   f"&scope={scope}&state={state}&response_type=code")
        return self._send(200, {"platform": platform, "authorize_url": url,
                                "redirect_uri": redirect_uri,
                                "account_id": account_id,
                                "app": "account" if client_key else "env"})

    def _handle_oauth_callback(self, platform: str, query: str):
        if platform not in ("douyin", "xiaohongshu"):
            return self._send(400, {"error": "unsupported oauth platform"})
        params = dict(q.split("=") for q in query.split("&") if "=" in q) \
            if query else {}
        code = params.get("code", "")
        state = params.get("state", "")
        now = time.time()
        ss = _OAUTH_STATES.pop(state, None)
        if not ss or now >= ss["exp"] or ss["platform"] != platform:
            return self._send(400, {"error": "invalid or expired state"})
        if not code:
            return self._send(400, {"error": "missing code"})

        try:
            if platform == "douyin":
                # 多应用矩阵：优先用 state 里带过来的账号级应用凭证，缺省回退全局 env
                cid = ss.get("client_key") or os.environ.get("DOUYIN_CLIENT_ID", "")
                sec = ss.get("client_secret") or os.environ.get("DOUYIN_CLIENT_SECRET", "")
                if not cid or not sec:
                    return self._send(400, {"error": "该账号未配置抖音应用凭证（client_key/client_secret）"})
                r = requests.get("https://open.douyin.com/oauth/access_token",
                                 params={"client_key": cid, "client_secret": sec,
                                         "code": code, "grant_type": "authorization_code"},
                                 timeout=30)
                d = r.json().get("data", {})
                if r.json().get("data", {}).get("error_code", 0) != 0:
                    return self._send(400, {"error": "douyin token error: " + str(d)})
                set_oauth_token(platform, d["access_token"], d.get("refresh_token"),
                                int(d.get("expires_in", 7200)), d.get("open_id"))
                account_id = ss.get("account_id", "")
                if account_id:
                    matrix_publish.store_account_token(platform, f"{platform}:{account_id}", {
                        "access_token": d["access_token"],
                        "refresh_token": d.get("refresh_token"),
                        "open_id": d.get("open_id"),
                        "expires_at": time.time() + int(d.get("expires_in", 7200)),
                    })
                    # 记住该账号的应用凭证，供 token 过期后自动 refresh（refresh_token 与应用绑定）
                    matrix_publish.store_account_app(platform, f"{platform}:{account_id}", {
                        "client_key": cid, "client_secret": sec,
                        "open_id": d.get("open_id"),
                    })
            else:  # xiaohongshu
                app_id = ss.get("client_key") or os.environ.get("XHS_APP_ID", "")
                app_secret = ss.get("client_secret") or os.environ.get("XHS_APP_SECRET", "")
                r = requests.post("https://open.xiaohongshu.com/api/open/oauth/access_token",
                                  json={"app_id": app_id, "app_secret": app_secret,
                                        "grant_type": "authorization_code", "code": code},
                                  timeout=30)
                d = r.json().get("data", {})
                if not d.get("access_token"):
                    return self._send(400, {"error": "xhs token error: " + str(r.json())})
                set_oauth_token(platform, d["access_token"], d.get("refresh_token"),
                                int(d.get("expires_in", 86400)))
                account_id = ss.get("account_id", "")
                if account_id:
                    matrix_publish.store_account_token(platform, f"{platform}:{account_id}", {
                        "access_token": d["access_token"],
                        "refresh_token": d.get("refresh_token"),
                        "open_id": d.get("open_id"),
                        "expires_at": time.time() + int(d.get("expires_in", 86400)),
                    })
        except Exception as e:  # noqa: BLE001
            return self._send(502, {"error": "token exchange failed: " + str(e)})

        html = (f"<html><head><meta charset='utf-8'></head><body style='font-family:sans-serif;"
                f"text-align:center;padding:48px'>"
                f"<h3>✅ {platform} 授权成功</h3>"
                f"<p>access_token 已安全保存，即将自动返回发布页…</p>"
                f"<script>try{{if(window.opener){{"
                f"window.opener.postMessage({{type:'oauth_authorized',platform:'{platform}',account_id:'{ss.get('account_id','')}'}},'*');"
                f"setTimeout(function(){{window.close();}},1500);}}}}catch(e){{}}</script>"
                f"</body></html>")
        return self._send(200, body=html.encode("utf-8"),
                          ctype="text/html; charset=utf-8")

    def _handle_oauth_status(self, platform: str, query: str = ""):
        # 功能包一：账号级授权态 ?account_key=douyin:12 优先于平台级
        params = dict(q.split("=") for q in query.split("&") if "=" in q) if query else {}
        account_key = params.get("account_key", "")
        # wechat 走 client_credential，不是 OAuth 授权码模式，账号级 account_key 命中
        # is_account_authorized 恒为 False（那里存的是 access_token），必须先跳过，
        # 交给下面的 wechat 分支按「env / 参数 / 缓存 / configured」综合判断。
        if account_key and platform != "wechat":
            authorized = matrix_publish.is_account_authorized(platform, account_key)
            return self._send(200, {"platform": platform, "account_key": account_key,
                                    "authorized": authorized, "mode": "oauth_account"})
        # 平台级授权态：OAuth 模式读 token 缓存，client_credential 模式读 env
        if platform == "douyin" or platform == "xiaohongshu":
            authorized = get_oauth_token(platform) is not None
            mode = "oauth"
        elif platform == "wechat":  # 公众号：AppID/AppSecret client_credential（与 shipinhao 视频号分离）
            # 原来只看环境变量 WECHAT_MP_APPID，导致租户在「平台账号」页配置了账号级
            # AppID/AppSecret 后，这里仍显示未授权。改为：环境变量 或 账号级凭证 任一命中即可。
            #
            # 账号级凭证的三个来源（按优先级）：
            #   1) query 直接带 appid/app_id + appsecret/app_secret（Laravel 侧有值时可透传）；
            #   2) query 带 account_key/account_id → 查 matrix_publish 的账号级 token 缓存
            #      （/oauth/callback 成功后写入，_publish_job 取号走同一份缓存）；
            #   3) Laravel 侧已确认配置过账号级凭证时，可透传 configured=1 显式声明。
            # 注：凭证本体存在 Laravel 的 platform_accounts 表，Python 侧不连库，
            #     所以这里只能做「存在性」判断，真正取号仍在 _publish_job 走 extra 传入。
            appid = os.environ.get("WECHAT_MP_APPID", "")
            secret = os.environ.get("WECHAT_MP_APPSECRET", "")
            source = "env" if (appid and secret) else ""
            if not source:
                q_appid = (params.get("appid") or params.get("app_id") or "").strip()
                q_secret = (params.get("appsecret") or params.get("app_secret")
                            or params.get("secret") or "").strip()
                if q_appid and q_secret:
                    source = "account_param"
            if not source and (params.get("account_key") or params.get("account_id")):
                ak = (params.get("account_key") or "").strip()
                if not ak:
                    ak = "%s:%s" % (platform, params.get("account_id", "").strip())
                if matrix_publish.get_account_token(platform, ak):
                    source = "account_cache"
            if not source and str(params.get("configured") or "").strip() in ("1", "true", "yes"):
                source = "account_configured"
            authorized = bool(source)
            mode = "client_credential"
            return self._send(200, {"platform": platform, "authorized": authorized,
                                    "mode": mode, "credential_source": source})
        elif platform == "shipinhao":
            appid = os.environ.get("WECHAT_APPID", "")
            secret = os.environ.get("WECHAT_APPSECRET", "")
            authorized = bool(appid and secret)
            mode = "client_credential"
        else:
            return self._send(400, {"error": "unsupported platform"})
        return self._send(200, {"platform": platform, "authorized": authorized,
                                "mode": mode})

    # ---- P4 获客军师（爆款潜力 + 留资钩子 + 行业适配 + 改进建议）----
    # ==================== 公众号文章（出稿 / SEO / 草稿箱 / 群发） ====================
    def _handle_article_write(self, data):
        """POST /article/write —— 生成公众号长文（SEO 规则已写进出稿 prompt）"""
        try:
            res = ai_article(
                topic=data.get("topic") or "",
                kw_main=data.get("kw_main") or "",
                kw_long=data.get("kw_long") or "",
                region=data.get("region") or "全国",
                year=data.get("year") or "",
                words=data.get("words") or 2000,
                style=data.get("style") or "干货科普",
                cta=data.get("cta") or "评论区留言",
                source=data.get("source") or "",
            )
            return self._send(200, res)
        except Exception as e:  # noqa: BLE001
            traceback.print_exc()
            return self._send(200, {"ok": False, "error": "出稿失败：%s" % e})

    def _handle_article_seo_check(self, data):
        """POST /article/seo-check —— 纯规则 SEO 校验（不消耗 LLM）"""
        try:
            import seo_check
            rep = seo_check.check(
                data.get("title") or "", data.get("digest") or "",
                data.get("content") or "", data.get("kw_main") or "",
                data.get("kw_long") or "", data.get("region") or "",
                data.get("year") or "")
            return self._send(200, {"ok": True, "report": rep})
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": "SEO 校验失败：%s" % e})

    def _handle_article_keywords(self, data):
        """POST /article/keywords —— 关键词研究（无第三方 API，LLM 展开 + 自评）"""
        try:
            res = ai_article_keywords(
                data.get("topic") or "", data.get("region") or "",
                int(data.get("count") or 30))
            return self._send(200, res)
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": "关键词生成失败：%s" % e})

    def _handle_article_draft(self, data):
        """POST /article/push-draft —— 送公众号草稿箱（未配凭据时返回 simulated，绝不假成功）。

        入参: {"title":"", "content":"", "cover_path":可选, "extra":{appid,appsecret},
               "tenant_id":可选, "credential_ref":可选}
        返回: {"ok","simulated","warn","media_id","url","status","error","raw"}
        """
        try:
            title = str(data.get("title") or "").strip()
            content = str(data.get("content") or data.get("description") or "").strip()
            if not title or not content:
                return self._send(200, {"ok": False, "error": "标题或正文为空"})
            job_id = "art_%s" % uuid.uuid4().hex[:12]
            results = _publish_job(job_id, ["wechat"], {
                "mode": "article",
                "title": title,
                "description": content,
                "content": content,
                "content_html": data.get("content_html") or "",
                "cover_path": data.get("cover_path") or "",
                "extra": data.get("extra") or {},
                "tenant_id": data.get("tenant_id") or "default",
                "credential_ref": data.get("credential_ref"),
            })
            r = (results or [{}])[0]
            if r.get("error") and not r.get("simulated"):
                # 适配器抛错/参数缺失 → 真失败，不能当成成功
                return self._send(200, {"ok": False, "error": str(r.get("error")),
                                        "simulated": False, "raw": r})
            dry = bool(r.get("simulated"))
            if dry:
                # 未配置公众号凭据时适配器走 dry 模拟：文章并未真正进入草稿箱，
                # 必须显式告知前端，避免「假成功」。
                return self._send(200, {
                    "ok": True,
                    "simulated": True,
                    "warn": "未配置公众号凭据，本次为模拟发送，文章未真正进入草稿箱",
                    "media_id": "", "url": "", "status": "simulated",
                    "error": "", "raw": r,
                })
            return self._send(200, {
                "ok": True,
                "simulated": False,
                "warn": "",
                "media_id": r.get("post_id") or "",
                "url": r.get("url") or "",
                "status": r.get("status") or "",
                "error": r.get("error") or "",
                "raw": r,
            })
        except Exception as e:  # noqa: BLE001
            traceback.print_exc()
            return self._send(200, {"ok": False, "error": "送草稿箱失败：%s" % e})

    def _handle_article_publish(self, data):
        """POST /article/publish —— 群发（直接发表）。

        前置的状态机（必须 reviewed）与每日限流（订阅号 1 篇/天）由 Laravel 侧
        ArticleController::publish 负责；这里只负责调用微信 freepublish 接口。
        """
        try:
            media_id = str(data.get("media_id") or "").strip()
            if not media_id:
                return self._send(200, {"ok": False, "error": "缺少 media_id（需先送草稿箱）"})
            from publishers.registry import get_publisher
            pub = get_publisher("wechat")
            if not pub:
                return self._send(200, {"ok": False, "error": "公众号适配器未注册"})
            res = pub.publish_draft(media_id, extra=data.get("extra") or {})
            raw = getattr(res, "raw", None) or {}
            dry = bool(raw.get("dry") or raw.get("simulated"))
            _sv = getattr(res, "status", None)
            status_s = str(getattr(_sv, "value", _sv) or "")
            return self._send(200, {
                "ok": (not dry) and status_s == "published",
                "simulated": dry,
                "publish_id": "" if dry else str(getattr(res, "platform_post_id", "") or ""),
                "article_url": "" if dry else str(getattr(res, "platform_url", "") or ""),
                "status": "simulated" if dry else status_s,
                "error": str(getattr(res, "error_message", "") or ""),
                "ai_declaration_reminder": (
                    "" if dry else
                    "请到公众号后台勾选『AI 生成合成内容』声明（微信规范与《AI 生成合成内容标识办法》要求）"
                ),
            })
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": "群发失败：%s" % e})

    def _handle_strategist(self, data):
        title = (data.get("title") or "").strip()
        script = (data.get("script") or data.get("text") or "").strip()
        if not title and not script:
            return self._send(400, {"error": "title or script required"})
        try:
            res = ai_strategist(title, script, data.get("industry", ""),
                                data.get("platform"))
            return self._send(200, res)
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": str(e)})

    def _handle_moment(self, data):
        """POST /moment：朋友圈转化文案（3 版：悬念/数据/故事）。供内容矩阵页使用。"""
        topic = (data.get("topic") or "").strip()
        selling = (data.get("selling_points") or "").strip()
        if not topic:
            return self._send(400, {"error": "topic required"})
        prompt = (
            "你是一名专注财税获客的短视频运营，擅长写朋友圈转化文案。"
            "文案中不得出现「深耕财税X年」「从业X年」等资历标榜表述。"
            f"请围绕选题【{topic}】"
            + (f"、核心卖点【{selling}】" if selling else "")
            + "，产出 3 版朋友圈文案（每版不超过 100 字）："
            "A版 悬念提问型（用老板关心的问题开头）；"
            "B版 数据冲击型（用数字/后果制造紧迫感）；"
            "C版 故事共鸣型（用客户案例口吻）。"
            "每版结尾各带一句行动引导（如'评论区扣1，发你对照清单'）。"
            "只输出一个 JSON 对象，不要 markdown 代码块，不要任何解释："
            '{"items":[{"type":"A 悬念","text":"...","reason":"推荐理由（≤20字）"},'
            '{"type":"B 数据","text":"...","reason":"..."},{"type":"C 故事","text":"...","reason":"..."}]}'
        )
        try:
            cfg = get_text_config()
            content = deepseek_chat(prompt, cfg.get("model", ""), cfg.get("key", ""),
                                    cfg.get("base_url"), timeout=60)
        except Exception as e:  # noqa: BLE001
            return self._send(502, {"error": "moment ai fail: " + str(e)})
        if not content:
            return self._send(502, {"error": "moment ai empty"})
        try:
            s, e = content.find("{"), content.rfind("}")
            if s < 0 or e < 0:
                return self._send(200, {"ok": False, "error": "ai returned non-json"})
            obj = json.loads(content[s:e + 1])
        except Exception:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": "ai returned invalid json"})
        return self._send(200, {"ok": True, "items": obj.get("items") or []})

    # ---- P4 去AI痕迹（口语化改写 + 改动标注）----
    def _handle_deai(self, data):
        text = (data.get("text") or "").strip()
        if not text:
            return self._send(400, {"error": "text required"})
        try:
            return self._send(200, ai_deai(text))
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- AI 智能生成标题 / 副标题（根据文稿内容，轻量快速版）----
    def _handle_suggest_title(self, data):
        dialogue = data.get("dialogue")
        if dialogue is not None and not isinstance(dialogue, str):
            return self._send(400, {"error": "dialogue 必须是字符串文本"})
        dialogue = (dialogue or "").strip()
        if not dialogue:
            return self._send(400, {"error": "dialogue required"})
        industry = (data.get("industry") or "").strip()
        style = (data.get("style") or "").strip()
        # 仅取前 300 字（标题只需开头核心信息，不必全文送入）
        trimmed = re.sub(r'^\s*(?:女|男|旁白|解说|主播|画外音|独白|配音)[:：]\s*', '', dialogue, flags=re.M)
        trimmed = "\n".join(l.strip() for l in trimmed.splitlines() if l.strip())[:300]
        ind_hint = f"（行业：{industry}）" if industry else ""
        style_hint = {
            "smart": "极简提取关键词/数字/痛点，≤10字。例：'私户收款被罚30万'。",
            "full": "首句完整语义，≤15字。例：'餐饮老板微信收款被查补税30万'。",
            "suspense": "强悬念/警示，以'为什么/真相是/小心/别再'开头。例：'小心！私户收款正被金税四期紧盯'。",
        }.get(style, "智能提取关键词或首句，可制造悬念。")
        # 轻量 prompt：flash 模型本身懂短视频套路，无需喂大量规则
        prompt = (
            f"为抖音/视频号短视频生成1组封面标题+副标题{ind_hint}。\n"
            f"风格要求：{style_hint}\n"
            "主标题6-12字(上限15)，含核心关键词，前5字让人懂讲什么。\n"
            "副标题15-25字(上限30)，补充说明内容/受众/价值，不重复主标题。\n"
            "风格：真实有警示感、不恐吓、不带违禁词(最/第一/根治/必看/暴富/躺赚)。\n"
            '严格只输出JSON:{"title":"...","subtitle:"..."}，不要其他内容。'
        )
        try:
            cfg = get_text_config()
            raw = deepseek_chat(prompt + "\n\n【文稿】\n" + trimmed, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=20)
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": "AI 标题生成失败：" + str(e)[:200]})
        if isinstance(raw, dict):
            raw = raw.get("content") or json.dumps(raw, ensure_ascii=False)
        content = (raw or "").strip()
        if content.startswith("```"):
            content = content.strip("`")
            if content[:4].lower() == "json":
                content = content[4:]
            content = content.strip()
        # 提取首个 JSON 对象
        obj = None
        try:
            obj = json.loads(content)
        except Exception:  # noqa: BLE001
            m = re.search(r'\{[^{}]*"title"[^{}]*\}', content, re.S)
            if m:
                try:
                    obj = json.loads(m.group(0))
                except Exception:  # noqa: BLE001
                    obj = None
        if not isinstance(obj, dict) or not obj.get("title"):
            return self._send(200, {"ok": False, "error": "AI 返回解析失败", "raw": content[:200]})
        title = str(obj.get("title", "")).strip()[:15]
        subtitle = str(obj.get("subtitle", "")).strip()[:30]
        return self._send(200, {"ok": True, "title": title, "subtitle": subtitle})

    # ---- 政策原文素材采集（文案引用政策 → 官方原文证据卡高清图）----
    def _handle_policy_asset(self, data):
        """POST /policy_asset：根据政策引用抓取官方原文素材。
        入参 JSON: {"policy": "财税〔2024〕15号 或 政策名称/关键词"}
        返回: {"ok": true, "image_path", "source_url", "title", "clause", "doc_no", "level", "note"}
        L1 playwright 截屏 → L2 urllib 正文 + PIL 渲染 → L3 仅返回官方 URL（绝不静默失败）。
        """
        policy = (data or {}).get("policy") or ""
        if not policy.strip():
            return self._send(400, {"error": "policy required（政策文号/名称/关键词）"})
        try:
            asset = fetch_policy_asset(policy)
            return self._send(200, {"ok": True, **asset.to_dict()})
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": str(e)[:200]})

    # ---- 爆款复刻：从历史版本快照一键复用全套参数重新出片（数据驱动迭代）----
    def _handle_clone(self, data):
        """POST /clone：加载某 job 的历史版本快照（对话稿+字幕/剪辑/封面参数），
        复用全套参数重新出片，实现「爆款复刻」——把跑赢的参数一键复用。
        入参 JSON: {"job_id": "...", "version": 1(可选，默认最新)}
        返回: 复用 /generate 的 {"job_id","status":"queued"}
        """
        job_id = (data or {}).get("job_id") or ""
        if not job_id:
            return self._send(400, {"error": "job_id required"})
        with lock:
            vers = list((jobs.get(job_id) or {}).get("versions") or [])
        if not vers:
            return self._send(404, {"error": "该 job 无历史版本可复刻"})
        v = int((data or {}).get("version") or 0)
        if v < 1 or v > len(vers):
            v = len(vers)   # 默认复刻最新版本
        snap = vers[v - 1].get("snapshot") or {}
        if not snap.get("dialogue"):
            return self._send(400, {"error": "该版本快照缺少对话稿，无法复刻"})
        payload = dict(snap)
        payload["clone_of"] = job_id
        payload["clone_version"] = v
        return self._handle_generate(payload)

    # ---- 声音克隆（租户上传参考音频 → CosyVoice 克隆 → voice_id）----
    def _handle_clone_voice(self, data):
        """租户上传参考音频克隆专属音色。
        入参 JSON: {"audio_b64": "<base64>", "name": "xxx", "gender": "male|female"}
        返回: {"voice_id", "model", "name", "gender"}
        """
        import base64
        import tempfile
        import uuid

        audio_b64 = data.get("audio_b64") or ""
        name = (data.get("name") or "未命名声音").strip() or "未命名声音"
        gender = data.get("gender") or "male"
        if gender not in ("male", "female"):
            gender = "male"
        if not audio_b64:
            return self._send(400, {"error": "audio_b64 required"})

        try:
            ensure_env()  # 灌入 model_keys.env 的 DASHSCOPE_API_KEY
            from dashscope import Files
            from dashscope.audio.tts_v2 import VoiceEnrollmentService
        except Exception as e:
            return self._send(500, {"error": "dashscope 不可用: " + str(e)})

        try:
            raw = base64.b64decode(audio_b64)
        except Exception:
            return self._send(400, {"error": "audio_b64 解码失败"})
        if len(raw) < 5000:
            return self._send(400, {"error": "音频过小，请上传至少数秒的有效音频"})

        tmp = None
        try:
            with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tf:
                tf.write(raw)
                tmp = tf.name
            # 1) 上传参考音频
            rsp = Files.upload(tmp, purpose="voice-clone")
            fid = rsp.output["uploaded_files"][0]["file_id"]
            # 2) 取可访问 url
            lst = Files.list()
            url = None
            for f in lst.output["files"]:
                if f["file_id"] == fid:
                    url = f["url"]
                    break
            if not url:
                return self._send(500, {"error": "无法获取上传音频的 url"})
            # 3) 克隆（prefix ≤10 字符且需唯一）
            svc = VoiceEnrollmentService()
            vid = None
            last_err = ""
            for _ in range(3):
                prefix = "vc" + uuid.uuid4().hex[:8]
                try:
                    vid = svc.create_voice(MODEL_CLONE, prefix, url, language_hints=["zh"])
                    if vid:
                        break
                except Exception as e:
                    last_err = str(e)
            if not vid:
                return self._send(500, {"error": "克隆失败: " + last_err})
            return self._send(200, {
                "voice_id": vid,
                "model": MODEL_CLONE,
                "name": name,
                "gender": gender,
            })
        except Exception as e:
            return self._send(500, {"error": "克隆异常: " + str(e)})
        finally:
            if tmp and os.path.exists(tmp):
                try:
                    os.remove(tmp)
                except Exception:
                    pass

    # ---- 出片（异步 job）----
    def _handle_generate(self, data):
        global active_total, active_by_tenant
        dialogue = data.get("dialogue")
        if dialogue is not None and not isinstance(dialogue, str):
            return self._send(400, {"error": "dialogue 必须是字符串文本"})
        dialogue = (dialogue or "").strip()
        if not dialogue:
            return self._send(400, {"error": "dialogue required"})

        # 时长上限（第一道闸）：预估 TTS 时长超 MAX_DURATION_SEC 直接拒
        est = estimate_duration_sec(dialogue)
        if est > MAX_DURATION_SEC:
            return self._send(422, {
                "error": f"时长超限：预估约 {est} 秒，超过单次上限 {MAX_DURATION_SEC} 秒（30分钟）。请拆分内容后分批生成。",
                "code": "duration_exceeded",
                "estimated_sec": est,
                "max_sec": MAX_DURATION_SEC,
            })

        # 并发护栏：全局 + 单租户双闸，超了直接 429 拒绝而非无脑接收
        tenant_id = str(data.get("tenant_id") or "default")
        with lock:
            if active_total >= GLOBAL_MAX_JOBS:
                return self._send(429, {
                    "error": f"系统繁忙：当前渲染任务已达全局上限（{GLOBAL_MAX_JOBS}），请稍后重试。",
                    "code": "global_busy",
                    "active": active_total, "max": GLOBAL_MAX_JOBS,
                })
            if active_by_tenant.get(tenant_id, 0) >= TENANT_MAX_JOBS:
                return self._send(429, {
                    "error": f"并发超限：当前账号已有 {active_by_tenant.get(tenant_id, 0)} 个进行中的渲染任务，请等待完成后再提交。",
                    "code": "tenant_busy",
                    "active": active_by_tenant.get(tenant_id, 0), "max": TENANT_MAX_JOBS,
                })
            active_total += 1
            active_by_tenant[tenant_id] = active_by_tenant.get(tenant_id, 0) + 1
            jid = uuid.uuid4().hex
            jobs[jid] = {"status": "queued", "out": None, "error": None, "cover": None,
                         "tenant_id": tenant_id, "start_ts": None, "step": "queued"}
            _save_job(jid, jobs[jid])
        t = threading.Thread(target=run_job, args=(jid, data), daemon=True)
        t.start()
        return self._send(200, {"job_id": jid, "status": "queued"})

    # ---- 智能选题（同步 AI）----
    def _handle_topic(self, data):
        try:
            if not isinstance(data, dict):
                return self._send(400, {"ok": False, "error": "invalid request body: expected JSON object"})
            result = ai_topic(
                data.get("industry", "") or "",
                data.get("keywords", "") or "",
                int(data.get("count", 6) or 6),
                platform=data.get("platform") or None,
                hotness=data.get("hotness") or None,
                hook=data.get("hook") or None,
                form=data.get("form") or None,
            )
            return self._send(200, {"ok": True, "topics": result})
        except Exception as e:  # noqa: BLE001
            traceback.print_exc()
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- 每日热点·双题材（daily_hot：平台热榜 → 财税/大事 + 爆款方案）----
    _HOT_STATE = {"running": False}
    _HOT_LOCK = threading.Lock()

    def _handle_hot_daily(self, data):
        """POST /hot-daily：触发后台抓取 微博/百度/头条热榜 → LLM 双题材过滤 → 爆款方案。"""
        with self._HOT_LOCK:
            if self._HOT_STATE.get("running"):
                return self._send(200, {"ok": True, "running": True})
            self._HOT_STATE["running"] = True

        def _run():
            try:
                import daily_hot
                daily_hot.run_daily(finance_top=4, event_top=4, per_source=30)
            except Exception as e:  # noqa: BLE001
                print(f"[hot-daily] 失败: {e}", flush=True)
            finally:
                with self._HOT_LOCK:
                    self._HOT_STATE["running"] = False

        threading.Thread(target=_run, daemon=True).start()
        return self._send(200, {"ok": True, "running": True})

    def _handle_hot_daily_result(self):
        """GET /hot-daily-result：读最近一次每日热点结果 daily_hot.json。"""
        p = os.path.join(os.environ.get("HOT_DAILY_OUT", r"D:\heygem_data\runtime-logs\daily_hot.json"))
        result = None
        if os.path.exists(p):
            try:
                result = json.load(open(p, encoding="utf-8"))
            except Exception:  # noqa: BLE001
                result = None
        with self._HOT_LOCK:
            running = self._HOT_STATE.get("running", False)
        return self._send(200, {"running": running, "result": result})

    # ---- 全网财税热点选题（代理到 ai_hotspot：tavily 真实时 + deepseek 角度）----
    def _handle_hotspot(self, data):
        try:
            if not isinstance(data, dict):
                return self._send(400, {"ok": False, "error": "invalid request body: expected JSON object"})
            days = int(data.get("days", 7) or 7)
            if days not in (1, 3, 7, 30):
                days = 7
            subs = data.get("subfields") or []
            if not isinstance(subs, list):
                subs = []
            subs = [str(s).strip() for s in subs if str(s).strip()][:10]
            # 编码损坏防御：某些客户端（如 PowerShell 控制台）发送的中文会被编码为 "????"
            # 此时直接按子领域检索无意义，fallback 到通用财税热点并标记 filtered。
            encoded_broken = bool(subs) and all(set(s) <= {"?"} for s in subs)
            if encoded_broken:
                _hotspot_debug(["WARN subfields encoded broken, fallback to general: %s" % subs])
                subs = []
            result = ai_hotspot(days, subs)
            return self._send(200, {
                "ok": True,
                "realtime": result.get("realtime", False),
                "topics": result.get("topics", []),
                "filtered": result.get("filtered", False),
                "tavily_degraded": result.get("tavily_degraded", False),
                "tavily_message": result.get("tavily_message", ""),
                "from_cache": result.get("from_cache", False),
            })
        except Exception as e:  # noqa: BLE001
            traceback.print_exc()
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- 视频/音频转文字（本地 FunASR）----
    def _handle_transcribe(self, data):
        try:
            if not isinstance(data, dict):
                return self._send(400, {"ok": False, "error": "invalid request body"})
            src = data.get("video_b64") or data.get("video_url") or data.get("file_path")
            if not src:
                return self._send(400, {"ok": False, "error": "video_b64 / video_url / file_path 至少一项"})
            result = ai_transcribe(data, data.get("language", "zh"))
            return self._send(200, result)
        except Exception as e:  # noqa: BLE001
            traceback.print_exc()
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- 爆款结构拆解（纯文案分析）----
    def _handle_dissect(self, data):
        try:
            if not isinstance(data, dict):
                return self._send(400, {"ok": False, "error": "invalid request body"})
            text = (data.get("text") or "").strip()
            if not text:
                return self._send(400, {"ok": False, "error": "text required"})
            result = ai_dissect(text, data.get("platform"), data.get("industry"))
            return self._send(200, result)
        except Exception as e:  # noqa: BLE001
            traceback.print_exc()
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- 对标爆款 → 扒文案(ASR) → 财税仿写（一键闭环）----
    def _handle_follow_hot(self, data):
        try:
            if not isinstance(data, dict):
                return self._send(400, {"ok": False, "error": "invalid request body"})
            text = (data.get("text") or "").strip()
            source_kind = "text"
            transcript = ""
            # 无文案但有对标视频/音频 → ASR 扒逐字稿
            if not text and (data.get("video_b64") or data.get("file_path") or data.get("video_url")):
                asr = ai_transcribe(data, data.get("language", "zh"))
                if not asr.get("ok"):
                    return self._send(200, {"ok": False,
                                            "error": "对标视频转文字失败：" + str(asr.get("error", ""))})
                text = (asr.get("text") or "").strip()
                transcript = text
                source_kind = "asr"
                if not text:
                    return self._send(200, {"ok": False, "error": "视频未识别出文字"})
            if not text:
                return self._send(400, {"ok": False,
                                        "error": "text 或 对标视频(file_path/video_b64/video_url) 至少一项"})
            result = ai_follow_hot(text, data.get("platform"), data.get("industry") or "财税税务咨询")
            result["source_kind"] = source_kind
            if transcript:
                result["transcript"] = transcript
            return self._send(200, result)
        except Exception as e:  # noqa: BLE001
            traceback.print_exc()
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- 智能二创（同步 AI + 违禁词）----
    def _handle_rewrite(self, data):
        text = (data.get("text") or "").strip()
        if not text:
            return self._send(400, {"error": "text required"})
        try:
            return self._send(200, ai_rewrite(
                text, data.get("mode", "dual"), data.get("focus"),
                data.get("target_duration"), data.get("preserve"),
                data.get("role_mode"), data.get("role_note"), data.get("keep_manual_roles"),
                data.get("industry")))
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- 对话出稿工作台·一期（POST /chat）----
    def _handle_chat(self, data):
        sid = (data.get("session_id") or "").strip()
        message = (data.get("message") or "").strip()
        action = data.get("action") if isinstance(data.get("action"), dict) else None
        tenant = data.get("tenant") or ""
        if not message and not action:
            return self._send(400, {"error": "message required"})
        # 重复提问：在 busy 闸门之前拦截，直接复用记忆答案，绝不 blank/busy/异步。
        # 记忆提示：同一句话再说一遍，照样答；若之前答过，提示"刚答过"并引导新问题。
        try:
            _rep = _CHAT_ORCH.check_repeat(sid, message)
        except Exception:
            _rep = None
        if _rep:
            if _rep[0] == "answer":
                return self._send(200, {"stage": "answer", "message": _rep[1],
                                        "session_id": sid})
            # "status" 重复：不拦截，继续走正常同步流程(重算时效)，由 step 在答案后附提示
        # 同会话并发保护：一个会话同时只跑一个 step，避免内存 written/angles 互相覆盖
        with _chat_running_lock:
            if sid and _chat_running.get(sid):
                return self._send(200, {"stage": "busy",
                                        "message": "这条消息还在处理中，请稍候，完成会自动出现。",
                                        "session_id": sid})
        # 长任务(出稿多篇/拆角度/检索/审查)走异步后台线程；短闲聊即时返回
        if sid and self._looks_long(message, action):
            with _chat_running_lock:
                _chat_running[sid] = True
                _chat_done.pop(sid, None)
            def _run():
                try:
                    res = _CHAT_ORCH.step(sid, message, tenant, action=action)
                    with _chat_running_lock:
                        _chat_done[sid] = res
                except Exception as e:  # noqa: BLE001
                    traceback.print_exc()
                    _chat_debug(["chat error sid=%s msg=%s error=%s" % (sid, message, str(e))])
                    with _chat_running_lock:
                        _chat_done[sid] = {"stage": "error", "ok": False, "error": str(e), "session_id": sid}
                finally:
                    with _chat_running_lock:
                        _chat_running.pop(sid, None)
            # 看门狗：批量出稿（本周 7 篇）耗时较长，给 10 分钟；其余 4 分钟。
            _is_batch = any(w in (message or "") for w in
                            ("本周都写", "这周都写", "一周都写", "本周全写", "一周全写", "批量出稿"))
            _wd_secs = 600.0 if _is_batch else 240.0
            _wd_mins = 10 if _is_batch else 4

            def _timeout_guard():
                with _chat_running_lock:
                    if _chat_running.get(sid) and sid not in _chat_done:
                        _chat_done[sid] = {"stage": "error", "ok": False,
                                           "error": "处理超时（超过 %d 分钟）。可能是模型响应慢，请刷新后重试或换个说法。" % _wd_mins,
                                           "session_id": sid}
                        _chat_running.pop(sid, None)
                        _chat_debug(["chat timeout sid=%s msg=%s" % (sid, message)])
            watchdog = threading.Timer(_wd_secs, _timeout_guard)
            watchdog.daemon = True
            watchdog.start()
            t = threading.Thread(target=_run, daemon=True)
            t.start()
            # 线程结束时取消看门狗，避免残留计时器
            def _cleanup_watchdog():
                t.join()
                watchdog.cancel()
            threading.Thread(target=_cleanup_watchdog, daemon=True).start()
            return self._send(200, {"stage": "async", "session_id": sid,
                                    "job_id": sid,
                                    "message": "已开始处理。这一步可能要 1~4 分钟（长出稿），我会持续更新进度，请稍候。"})
        # 短闲聊：同步即时返回（保持快速响应）
        try:
            return self._send(200, _CHAT_ORCH.step(sid, message, tenant, action=action))
        except Exception as e:  # noqa: BLE001
            traceback.print_exc()
            return self._send(200, {"ok": False, "error": str(e)})

    _LONG_WORDS = ("全写", "认可", "可以，全写", "就按这个", "拆角度", "出角度", "出几个角度",
                   "重新拆", "换个角度", "再拆一次", "再拆一遍", "重新出角度", "角度不够", "再来一次", "不要这些",
                   "写第", "重写第", "按这个全写", "角度方案", "全网搜", "搜一下",
                   "参考", "检索", "有没有结合", "是不是结合", "是否结合", "分析一下", "帮我看",
                   "搜索", "查一下", "能不能结合",
                   "规划", "排期", "策划", "一周内容", "选题方案", "排一周", "排期表",
                   "本周都写", "这周都写", "一周都写", "本周全写", "一周全写", "全部写出来", "批量出稿")

    @staticmethod
    def _looks_long(message, action):
        """预判这条消息是否可能触发长任务（出稿多篇/拆角度/检索/审查）。
        是→后台线程异步；否→同步即时返回。宁可多走异步，避免 150s 超时 502。"""
        if isinstance(action, dict) and action.get("cap"):
            return True
        msg = (message or "").strip()
        if not msg:
            return False
        # 状态问句（"X写了吗/出了没/做好没"）走 orchestrator 同步快路径：
        # 这些句子常见 10~20 字，但应答只需查会话状态，不能因长度>=12 就被丢异步。
        try:
            if _CHAT_ORCH._is_status_inquiry(msg):
                return False
        except Exception:
            pass
        # 仅命中已知长任务关键词才走异步；不再用 len>=12 一刀切，避免普通问句被误伤。
        return any(w in msg for w in Handler._LONG_WORDS)

    def _handle_chat_status(self, sid):
        """GET /chat/status/<sid>：查询异步长任务进度。"""
        if not sid:
            return self._send(400, {"error": "session_id required"})
        running = False
        with _chat_running_lock:
            running = bool(_chat_running.get(sid))
            done = _chat_done.get(sid)
        prog = _CHAT_ORCH.progress(sid)
        if running:
            return self._send(200, {"stage": "pending", "session_id": sid,
                                    "progress": prog or {"phase": "working", "msg": "正在处理…"}})
        if done is not None:
            with _chat_running_lock:
                _chat_done.pop(sid, None)   # 取走即清，前端再轮询会走 done 分支自动停下
            _CHAT_ORCH.clear_progress(sid)
            return self._send(200, done)
        # 不在跑也没完成记录：可能是普通同步请求或会话空闲
        return self._send(200, {"stage": "idle", "session_id": sid})

    # ---- 对话出稿·会话/空间管理（二期：持久化 + 左侧列表）----
    def _handle_chat_sessions(self, tenant):
        try:
            return self._send(200, {"ok": True, "sessions": _CHAT_ORCH.list_sessions(tenant)})
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "sessions": [], "error": str(e)})

    def _handle_chat_session_create(self, data):
        try:
            return self._send(200, {"ok": True, **_CHAT_ORCH.create_session(
                data.get("tenant") or "", data.get("title") or "")})
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": str(e)})

    def _handle_chat_session_update(self, data):
        sid = (data.get("session_id") or "").strip()
        if not sid:
            return self._send(400, {"error": "session_id required"})
        try:
            return self._send(200, _CHAT_ORCH.update_session(
                sid, data.get("title"), data.get("kind"), data.get("pinned")))
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": str(e)})

    def _handle_chat_session_delete(self, data):
        sid = (data.get("session_id") or "").strip()
        if not sid:
            return self._send(400, {"error": "session_id required"})
        try:
            return self._send(200, _CHAT_ORCH.delete_session(sid))
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": str(e)})

    def _handle_chat_messages(self, sid):
        if not sid:
            return self._send(400, {"error": "session_id required"})
        try:
            return self._send(200, {"ok": True, **_CHAT_ORCH.get_messages(sid)})
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "messages": [], "error": str(e)})

    # ---- 智能质检（同步：违禁词 + 时长 + 风险）----
    def _handle_qc(self, data):
        text = (data.get("text") or "").strip()
        if not text:
            return self._send(400, {"error": "text required"})
        try:
            return self._send(200, ai_qc(text, data.get("platform")))
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- 出片产物技术质检（按 job_id 从磁盘解析产物路径，重启后仍可用）----
    def _handle_qc_video(self, data):
        jid = (data.get("job_id") or "").strip()
        if not jid:
            return self._send(400, {"error": "job_id required"})
        # 优先用内存中的产物路径，否则回退磁盘（服务重启后内存丢失但文件仍在）
        with lock:
            j = jobs.get(jid)
        candidate = (j or {}).get("out") or os.path.join(JOBS_DIR, jid, "out.mp4")
        if not os.path.exists(candidate):
            return self._send(404, {"error": "video not ready"})
        try:
            return self._send(200, ai_qc_video(candidate, data.get("platform"), data.get("rules")))
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- 用户上传素材质检（按宿主文件路径）----
    def _handle_qc_asset(self, data):
        path = (data.get("file_path") or "").strip()
        if not path or not os.path.exists(path):
            return self._send(400, {"error": "file_path required / not exist"})
        try:
            return self._send(200, ai_qc_asset(path, data.get("rules")))
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- 用户上传模特素材处理（转码+静音化+双写+QC）----
    def _handle_process_asset(self, data):
        path = (data.get("file_path") or "").strip()
        tenant_id = data.get("tenant_id")
        if not path or not os.path.exists(path):
            return self._send(400, {"error": "file_path required / not exist"})
        if not tenant_id:
            return self._send(400, {"error": "tenant_id required"})
        try:
            return self._send(200, process_asset(path, tenant_id))
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": str(e)})

    # ---- 删除宿主上的素材文件（容器无法直接删宿主文件，由 8500 代理）----
    def _handle_delete_asset(self, data):
        paths = data.get("paths") or []
        removed = []
        for p in paths:
            if not isinstance(p, str):
                continue
            # 仅允许删除 face2face / storage 下的文件，防任意删除
            if (p.startswith(FAC2FACE) or p.startswith(PROJECT_STORAGE)) and os.path.exists(p):
                try:
                    os.remove(p)
                    removed.append(p)
                except OSError:
                    pass
        return self._send(200, {"ok": True, "removed": removed})

    def log_message(self, *a):  # 静默访问日志
        pass


if __name__ == "__main__":
    port = int(os.environ.get("PORT", 8500))
    # 全局加载 model_keys.env（发布适配器从 os.environ 读 WECHAT_APPID 等平台凭据，
    # 此前仅在 TTS 路径调 ensure_env，/publish 路径读不到；此处启动即加载，进程级可用）
    try:
        ensure_env()
        print("[pipeline] model_keys.env loaded (platform credentials available)")
    except Exception as e:  # 缺写稿 key 不阻断启动，仅发布/配音相关功能受限
        print(f"[pipeline] ensure_env warning: {e}")
    recovered = recover_jobs()
    print(f"[pipeline] recovered {recovered} interrupted job(s) from disk")
    wd = threading.Thread(target=watchdog_loop, daemon=True)
    wd.start()
    print(f"[pipeline] guard: global_max={GLOBAL_MAX_JOBS} tenant_max={TENANT_MAX_JOBS} "
          f"hard_timeout={HARD_TIMEOUT}s card_seg_sec={CARD_SEG_SAFE_SEC}s max_duration={MAX_DURATION_SEC}s")
    srv = http.server.ThreadingHTTPServer(("0.0.0.0", port), Handler)
    print(f"[pipeline] listening on :{port}")
    srv.serve_forever()
