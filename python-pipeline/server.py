#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
慧根堂商用平台 · 视频出片微服务（滚动字幕卡 + 本地数字人出镜）
零三方依赖（仅 Python 标准库），包装 gpt_sovits 下的出片脚本。

运行（Windows 宿主，复用既有 PY310 环境 + ffmpeg + 音色密钥）:
    D:/heygem/py310/Scripts/python.exe server.py
监听 0.0.0.0:8500

接口:
    GET  /health                     -> {"status":"ok"}
    POST /generate                   -> {"job_id","status":"queued"}
         body: {
           "mode": "scroll" | "avatar",
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
    POST /publish                    -> {"job_id","results":[{platform,status,post_id,url,error}]}
         body: {"job_id","platforms":["douyin","shipinhao","xiaohongshu"],
                "tenant_id":"可选","title":"可选覆盖","description":"","tags":[],
                "credential_ref":"可选凭证句柄"}
         说明：无凭证时各适配器降级 dry 模拟（status=published + 模拟 post_id/url）
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
from urllib.parse import urlparse

GPT_SOVITS = r"D:/heygem_data/gpt_sovits"
# 复用 gpt_sovits 侧已验证的 DeepSeek 写稿封装与违禁词库（key 不进 Laravel，仅本机 model_keys.env）
sys.path.insert(0, GPT_SOVITS)
from model_providers import get_text_config, deepseek_chat, ensure_env, tavily_search, get_key  # noqa: E402
import forbidden_words  # noqa: E402
# 本地 ASR（FunASR）：tools/asr 无 __init__.py，用 sys.path 注入目录后直接 import；
# 模型缺失时 only_asr=None，仅 /transcribe 受影响，不拖垮其它端点。
sys.path.insert(0, os.path.join(GPT_SOVITS, "tools", "asr"))
try:
    from funasr_asr import only_asr  # noqa: E402
except Exception:  # noqa: BLE001
    only_asr = None
# 自动发布模块：平台适配器（抖音/视频号/小红书优先，B站/YouTube 顺延）
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from publishers.registry import get_publisher, supported_platforms  # noqa: E402
from publishers.base import PublishRequest, PublishStatus  # noqa: E402
from publishers._token_cache import set_oauth_token, get_oauth_token  # noqa: E402
import matrix_publish  # noqa: E402
from metrics_adapter import fetch_batch  # noqa: E402
import requests  # noqa: E402
import secrets  # noqa: E402

# OAuth2 授权码模式（抖音/小红书）回调基地址；生产可经 env OAUTH_REDIRECT_BASE 覆盖
OAUTH_REDIRECT_BASE = os.environ.get("OAUTH_REDIRECT_BASE",
                                     "http://124.222.33.233:8500")
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
    """从文本中提取第一个 JSON 数组；找不到返回 None。容忍 ``` 包裹与前后多余文本。"""
    if not text:
        return None
    text = text.strip()
    try:
        obj = json.loads(text)
        if isinstance(obj, list):
            return obj
        if isinstance(obj, dict):
            for key in ("topics", "data", "list", "items", "results"):
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
      "form": "呈现形式，取值必须为以下之一：avatar(单人数字人出镜) / scroll_male(男声幕后音) / scroll_female(女声幕后音) / scroll_dual(男女对话幕后音)"
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
        prompt = prompt_base + f"\n\n未启用实时检索（无 TAVILY_API_KEY 或检索失败）。请基于你的财税知识，生成截至近 {days} 天的 3-6 个财税热点选题卡片（JSON 数组，non-realtime，published_at 标'近期'）。"

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
            if f not in ("avatar", "scroll_male", "scroll_female", "scroll_dual"):
                f = "scroll_male"
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
        f"你是资深短视频选题策划。面向「{industry or '你的行业'}」行业的内容创作者与商家，"
        f"结合关键词「{keywords or '行业热点、用户痛点、产品卖点'}」，"
        f"生成 {cnt} 个高转化短视频选题。\n"
        + (f"维度约束（必须满足）：\n{dim_block}\n" if dim_block else "")
        + "每个选题严格按 JSON 数组输出，元素结构：\n"
        '{"title":"标题(吸睛、戳痛点,≤18字)","angle":"切入角度/痛点","potential":"爆款潜力理由","hook":"结尾留资钩子建议","form":"建议形式:单声口播/双声对话"}\n'
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
        return [{"title": "解析失败", "angle": content[:200]}]
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
        return [{"title": "解析失败", "angle": content[:200]}]
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
        "dual_female_lead": "男女双声对话，女声先开口、抛疑问/场景，男声解答。每行以「女：」或「男：」开头，交替自然。",
        "dual_male_lead": "男女双声对话，男声先开口、引出话题，女声提问/补充，男声解答。每行以「男：」或「女：」开头，交替自然。",
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


def ai_rewrite(text, mode, focus=None, target_duration=None, preserve=None,
               role_mode=None, role_note=None, keep_manual_roles=None):
    """智能二创：多模式改写 + 角色/声音分配 + 违禁词标红/清洗。返回含元数据的完整结果。"""
    cfg = get_text_config()

    # 风格基调（由 mode 控制）
    if mode == "single":
        style = ("单人口播（实战派行业顾问）。口语化、去AI播音腔、像真人在跟客户面对面聊；"
                 "说话干脆利落、不拖长音、不堆语气词；该严肃严肃、该轻松轻松，自然有起伏，不演")
    elif mode == "script":
        style = "单人数字人出镜口播稿（保留行业术语与权威感，结构清晰、重点突出，适合直接配音）"
    else:
        style = ("双声对话：女声(抛疑问/场景)，男声(耐心解答，说话干脆、不拖长音、不堆语气词，像真人在跟客户聊)；"
                 "语气词极克制；结尾女声留咨询钩子")

    role_instruction = _build_role_instruction(role_mode, role_note, keep_manual_roles, mode)

    focus_hint = ""
    if focus and isinstance(focus, str) and focus.strip():
        focus_hint = f"\n【用户指定的重点方向】：{focus.strip()} — 请在改写中特别强化这个方向的内容比重与表达力度。\n"

    # 目标时长约束：130–160 字/分 ≈ 2.17–2.67 字/秒；预估按 2.4 字/秒
    dur_hint = ""
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

    prompt = (
        f"你是资深短视频脚本编辑。请把下面的稿子改写为「{style}」的自然口语稿，"
        "彻底去除AI机械感与书面腔，但保持专业准确性、不编造数据、不改原意。\n\n"
        f"【角色与声音分配】\n{role_instruction}\n"
        f"{focus_hint}{dur_hint}{preserve_hint}"
        "要求：保留原意与关键结论；长短句结合、自然停顿；不堆砌语气词、禁用'啊/嘛/呢/哎哟'等夸张口语；"
        "对话感来自内容互动而非语气词；说话干脆直给。\n"
        "只输出改写后的稿子本身，不要解释、不要标题、不要代码块。\n\n"
        "原稿：\n" + text
    )
    rewritten = deepseek_chat(prompt, cfg["model"], cfg["key"], cfg.get("base_url"), timeout=90)
    rewritten = rewritten.strip()
    if rewritten.startswith("```"):
        rewritten = rewritten.split("```")[1]
    hits = forbidden_words.scan(rewritten)
    cleaned = forbidden_words.clean_script(rewritten)

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
    # 仅保留中段静音（与 [2s, dur-2s] 中段区域有重叠且时长达标），避开片头/片尾合理留白
    mid_lo, mid_hi = 2.0, max(0.0, dur - 2.0)
    mid = [s for s in segs
           if s["duration"] >= min_dur
           and (s["start"] + s["duration"]) > mid_lo
           and s["start"] < mid_hi]
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

jobs = {}          # job_id -> {"status","out","error","tenant_id","start_ts","step"}
lock = threading.Lock()
render_lock = threading.Lock()   # HEYGEM 单 GPU 串行渲染锁：同一时刻仅一个视频在渲染，杜绝多任务抢 GPU 互相拖慢
active_total = 0              # 全局在跑任务数（用于并发护栏）
active_by_tenant = {}         # tenant_id -> 在跑任务数


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
             "natural", "platform", "overlay", "bg")}
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
        for p in platforms:
            if account_key:
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
                )
                res = pub.publish(req, job_id)
                results.append({
                    "platform": p, "status": res.status.value,
                    "post_id": res.platform_post_id, "url": res.platform_url,
                    "error": res.error_message, "simulated": False,
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

    results = []
    for p in platforms:
        if account_key:
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
            )
            res = pub.publish(req, job_id)
            results.append({
                "platform": p,
                "status": res.status.value,
                "post_id": res.platform_post_id,
                "url": res.platform_url,
                "error": res.error_message,
                "simulated": False,
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
        nt = payload.get("name_tag")
        if nt:
            edit_args += ["--name-tag", str(nt)]
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


def run_job(job_id, payload):
    tenant_id = payload.get("tenant_id") or "default"
    edit_style = payload.get("edit_style") or None  # 嵌套自动剪辑：scroll/avatar 成片之上的后处理风格
    try:
        mode = (payload.get("mode") or "scroll").lower()
        dialogue = payload.get("dialogue", "").strip()
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
        else:  # scroll
            args = [PY310, SCRIPT_SCROLL, "--dialogue", dlg_path, "--out", out_path]
            if payload.get("title"):
                args += ["--title", payload["title"]]
            if payload.get("subtitle"):
                args += ["--subtitle", payload["subtitle"]]
            if payload.get("bg"):
                args += ["--bg", payload["bg"]]
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
            if needs_regen and not payload.get("_regen"):
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
            # 自动发布（可选）：若请求带 auto_publish 平台列表，done 后后台线程分发
            # 不阻塞 done 返回；分发状态经 _publish_job 回写 job.publish / publish_results
            auto_plats = payload.get("auto_publish") or []
            if auto_plats:
                _set_job(job_id, auto_publish=list(auto_plats))
                threading.Thread(
                    target=_publish_job, args=(job_id, auto_plats, payload),
                    daemon=True,
                ).start()
        elif rc == 124:
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
            # 读取 render.log 最后一行的 progress，给前端真实进度（避免 ETA 永远停在固定值）
            render_progress = None
            try:
                log_path = os.path.join(JOBS_DIR, jid, "render.log")
                if os.path.isfile(log_path):
                    with open(log_path, "r", encoding="utf-8") as f:
                        lines = f.readlines()
                    for line in reversed(lines):
                        m = re.search(r"progress=(\d+)", line)
                        if m:
                            val = int(m.group(1))
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

        # ---- P4 OAuth2 授权码模式：生成授权 URL（抖音/小红书）----
        if p.path.startswith("/oauth/authorize/"):
            plat = p.path.rsplit("/", 1)[-1]
            return self._handle_oauth_authorize(plat, p.query)

        # ---- P4 OAuth2 回调：用 code 换 token 并落盘缓存 ----
        if p.path.startswith("/oauth/callback/"):
            plat = p.path.rsplit("/", 1)[-1]
            return self._handle_oauth_callback(plat, p.query)

        # ---- P4 授权态查询：供前端徽章实时读真实授权状态 ----
        if p.path.startswith("/oauth/status/"):
            plat = p.path.rsplit("/", 1)[-1]
            return self._handle_oauth_status(plat, p.query)

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

        if p.path == "/generate":
            return self._handle_generate(data)
        if p.path == "/topic":
            return self._handle_topic(data)
        if p.path == "/rewrite":
            return self._handle_rewrite(data)
        if p.path == "/qc":
            return self._handle_qc(data)
        if p.path == "/qc-video":
            return self._handle_qc_video(data)
        if p.path == "/qc-asset":
            return self._handle_qc_asset(data)
        if p.path == "/process-asset":
            return self._handle_process_asset(data)
        if p.path == "/delete-asset":
            return self._handle_delete_asset(data)
        if p.path == "/clone_voice":
            return self._handle_clone_voice(data)
        if p.path == "/publish":
            return self._handle_publish(data)
        if p.path == "/xhs_generate":
            return self._handle_xhs_generate(data)
        if p.path == "/strategist":
            return self._handle_strategist(data)
        if p.path == "/moment":
            return self._handle_moment(data)
        if p.path == "/deai":
            return self._handle_deai(data)
        if p.path == "/suggest-title":
            return self._handle_suggest_title(data)
        if p.path == "/hotspot":
            return self._handle_hotspot(data)
        if p.path == "/transcribe":
            return self._handle_transcribe(data)
        if p.path == "/dissect":
            return self._handle_dissect(data)
        if p.path == "/cancel":
            return self._handle_cancel(data)
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
        job_id = data.get("job_id") or ("xhs_" + secrets.token_hex(8) if mode == "image" else "")
        if mode != "image" and not job_id:
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
    def _handle_xhs_generate(self, data):
        """POST /xhs_generate：自动产出一篇「能直接发布」的小红书系列图文。

        入参 JSON:
            {"topic": "选题", "selling_points": "卖点（可多句）",
             "audience": "受众", "brand": "可选品牌署名", "pages": 可选期望内页数(默认4)}
        返回:
            {"ok": true, "note": {...}, "images": [base64...], "image_paths": [abs...],
             "count": <总张数>}
        总张数封顶 9（封面1 + 内文≤8）。
        """
        topic = (data.get("topic") or "").strip()
        if not topic:
            return self._send(400, {"error": "topic required"})
        selling = (data.get("selling_points") or "").strip()
        audience = (data.get("audience") or "").strip()
        brand = (data.get("brand") or "慧根堂 · 老张讲财税").strip()
        want_pages = max(2, min(8, int(data.get("pages") or 4)))

        # 1) DeepSeek 生成结构化笔记
        note = self._xhs_build_note(topic, selling, audience, want_pages)
        if not note:
            return self._send(502, {"error": "内容生成失败（DeepSeek 不可用或超时）"})

        # 2) 渲染出图（封面 + 内文分页，封顶 9 张）
        import base64
        from xhs_render import render_note
        outdir = os.path.join(JOBS_DIR, "xhs_" + secrets.token_hex(8))
        os.makedirs(outdir, exist_ok=True)
        paths = render_note(note, outdir, brand)
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
        })

    def _xhs_build_note(self, topic, selling, audience, want_pages):
        """调 DeepSeek 产出结构化笔记（封面/内文分页/正文/候选标题）。"""
        try:
            cfg = get_text_config()
        except Exception:  # noqa: BLE001
            cfg = {"model": "", "key": "", "base_url": None}
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
            "body": obj.get("body") or "",
            "titles": obj.get("titles") or [topic],
        }
        if not out["pages"]:
            out["pages"] = [{"heading": topic, "points": [selling or "核心卖点", "适用场景", "行动建议"]}]
        return out

    # ---- P4 OAuth2 授权码模式：抖音/小红书 ----
    def _handle_oauth_authorize(self, platform: str, query: str = ""):
        if platform not in ("douyin", "xiaohongshu"):
            return self._send(400, {"error": "unsupported oauth platform (use douyin/xiaohongshu)"})
        # 功能包一：账号级授权，query 可带 account_id（platform_accounts.id）
        params = dict(q.split("=") for q in query.split("&") if "=" in q) if query else {}
        account_id = params.get("account_id", "")
        # 清理过期 state
        now = time.time()
        expired = [k for k, v in _OAUTH_STATES.items() if now >= v["exp"]]
        for k in expired:
            _OAUTH_STATES.pop(k, None)
        state = secrets.token_urlsafe(24)
        _OAUTH_STATES[state] = {"platform": platform, "exp": now + _OAUTH_STATE_TTL,
                                "account_id": account_id}
        redirect_uri = f"{OAUTH_REDIRECT_BASE}/oauth/callback/{platform}"

        if platform == "douyin":
            cid = os.environ.get("DOUYIN_CLIENT_ID", "")
            if not cid:
                return self._send(500, {"error": "DOUYIN_CLIENT_ID 未配置"})
            # 抖音 scope 见开放平台；video.create 为发布权限
            url = (f"https://open.douyin.com/platform/oauth/connect/"
                   f"?client_key={cid}&response_type=code&scope=video.create"
                   f"&redirect_uri={redirect_uri}&state={state}")
        else:  # xiaohongshu
            app_id = os.environ.get("XHS_APP_ID", "")
            if not app_id:
                return self._send(500, {"error": "XHS_APP_ID 未配置"})
            url = (f"https://open.xiaohongshu.com/platform/oauth/authorize"
                   f"?app_id={app_id}&redirect_uri={redirect_uri}"
                   f"&scope=note.write&state={state}&response_type=code")
        return self._send(200, {"platform": platform, "authorize_url": url,
                                "redirect_uri": redirect_uri})

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
                cid = os.environ.get("DOUYIN_CLIENT_ID", "")
                sec = os.environ.get("DOUYIN_CLIENT_SECRET", "")
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
            else:  # xiaohongshu
                app_id = os.environ.get("XHS_APP_ID", "")
                app_secret = os.environ.get("XHS_APP_SECRET", "")
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
        if account_key:
            authorized = matrix_publish.is_account_authorized(platform, account_key)
            return self._send(200, {"platform": platform, "account_key": account_key,
                                    "authorized": authorized, "mode": "oauth_account"})
        """供前端徽章实时查询真实授权状态（OAuth 模式读 token 缓存，client_credential 模式读 env）。"""
        if platform == "wechat":  # Laravel 侧叫 wechat，8500 侧叫 shipinhao，统一
            platform = "shipinhao"
        if platform == "douyin" or platform == "xiaohongshu":
            authorized = get_oauth_token(platform) is not None
            mode = "oauth"
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
            "你是一名深耕财税获客的短视频运营，擅长写朋友圈转化文案。"
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
        dialogue = (data.get("dialogue") or "").strip()
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
        dialogue = (data.get("dialogue") or "").strip()
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

    # ---- 智能二创（同步 AI + 违禁词）----
    def _handle_rewrite(self, data):
        text = (data.get("text") or "").strip()
        if not text:
            return self._send(400, {"error": "text required"})
        try:
            return self._send(200, ai_rewrite(
                text, data.get("mode", "dual"), data.get("focus"),
                data.get("target_duration"), data.get("preserve"),
                data.get("role_mode"), data.get("role_note"), data.get("keep_manual_roles")))
        except Exception as e:  # noqa: BLE001
            return self._send(200, {"ok": False, "error": str(e)})

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
    srv = http.server.ThreadingHTTPServer(("0.0.0.0", port), Handler)
    print(f"[pipeline] listening on :{port}")
    srv.serve_forever()
