# -*- coding: utf-8 -*-
"""
newsfeed.py — 爆款选题雷达：多平台热榜 + 权威源 聚合

为聊天工作台顶部「选题雷达」提供数据：
  - 社会热流（公开接口，失败自动跳过）：
      微博热搜 / 百度热搜 / 头条热榜
  - 权威流（财税政策，15天窗口，解析失败自动跳过）：
      国家税务总局 / 税屋

增强点（相比 daily_hot）：
  1) 跨平台追踪：同一条热搜在几个平台出现都记下来 -> 这才是「搜索度排名」的核心信号；
  2) 每条带热度分（微博 num / 百度 hotScore / 头条 HotValue）与可读热度文字；
  3) 财税关键词过滤，只留财税相关（避免娱乐八卦噪音）；
  4) 权威流补位，长尾政策加权；
  5) 30 分钟磁盘缓存 + 优雅降级（抓取失败用上次缓存，页面不空）。

用法（由 server.py 的 GET /newsfeed 调用）：
  import newsfeed
  data = newsfeed.get_feed(days=15, force=False)
"""
import json
import os
import re
import threading
import time
import urllib.parse
import urllib.request
from pathlib import Path

UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/120.0 Safari/537.36")
CACHE_PATH = Path(os.environ.get("NEWSFEED_CACHE",
                                 r"D:\heygem_data\runtime-logs\newsfeed_cache.json"))
CACHE_TTL = 30 * 60  # 30 分钟

# 选题雷达筛选关键词——分两档（用户要求：紧紧围绕税务，不要什么都放）：
#   TAX_STRONG：命中即视为「可做税务选题」的强信号（税词 + 税务事件 + 政策速递/解读信号）。
#               只有命中强词才让条目进雷达；纯泛经济/民生词一律排除。
#   TAX_WEAK ：仅作语境词（泛企业/泛经济），单独出现不入选。
TAX_STRONG = [
    # 核心税词
    "税", "税务", "发票", "稽查", "个税", "社保费", "金税", "增值税", "所得税",
    "汇算", "留抵", "退税", "减税", "财税", "征管", "申报", "股权", "分红",
    "虚开", "偷税", "逃税", "避税", "税负", "关税", "海关", "出口退税",
    "印花税", "契税", "土地增值税", "纳税", "缴税", "补税", "查账",
    # 税务事件 / 执法（被 税/税务/稽查 单字短词已覆盖，显式列出更稳）
    "税务处罚", "税务稽查", "税收违法", "纳税信用",
    # 政策速递 / 解读信号（仅税务口径：税务总局/税收类政策，去掉"财政部""解读"等易误判的泛词）
    "税务总局", "公告", "政策", "通知", "修订", "草案",
    "征求意见", "减免", "优惠", "税收", "税法", "法案", "条例", "文件",
]
TAX_WEAK = [
    # 泛企业 / 雇主视角——单独出现不算财税选题
    "老板", "企业", "公司", "营商", "个体", "工商户", "创业", "用工", "裁员", "退休",
    # 纯宏观 / 泛经济词——单独出现不算财税选题（俄乌经济战/银行卡盗刷/房价止跌/国资央企 等已凭这些混入）
    "经济", "银行", "房价", "楼市", "消费", "物价", "就业", "央企", "国资", "国企",
]

# 负向兜底：明显非财税赛道（娱乐/体育/吃瓜/民生），无强词时直接丢弃
_NONTAX = ("综艺", "演唱会", "世界杯", "球赛", "明星", "网红", "离婚", "恋情",
           "电影", "电视剧", "股票", "基金", "彩票", "游戏", "电竞",
           "娱乐", "体育", "美食", "旅游", "天气", "疫情", "地震", "事故", "车祸", "招聘")


def _http_json(url, headers=None, timeout=12):
    req = urllib.request.Request(url, headers={"User-Agent": UA, **(headers or {})})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.loads(r.read().decode("utf-8", "replace"))


def _http_text(url, headers=None, timeout=12):
    req = urllib.request.Request(url, headers={"User-Agent": UA, **(headers or {})})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read().decode("utf-8", "replace")


def _http_post_json(url, payload, headers=None, timeout=20):
    body = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=body, method="POST",
                                 headers={"Content-Type": "application/json",
                                          "User-Agent": UA, **(headers or {})})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.loads(r.read().decode("utf-8", "replace"))


def _norm(t):
    return re.sub(r"[\s【】\[\]()（）·•，,。！!？?、:：\"']", "", t or "")


# 社会热流标题里常见的「修饰 filler」，去掉后更易判定「同一条新闻的不同措辞」
_FILLER = ["这几天", "近日", "今天", "刚刚", "曝光", "引发关注", "登上热搜", "冲上热搜",
           "网友", "男子", "女子", "最新", "现场", "视频", "曝光后", "后续", "最新进展",
           "辟谣", "回应", "热议", "刷屏", "悬了", "凉了", "慌了", "炸了", "哭了", "笑了"]


def _norm_soft(t):
    s = _norm(t)
    for w in _FILLER:
        s = s.replace(w, "")
    return s


def _is_tax(title):
    # 只认强词：命中 TAX_STRONG 才算财税选题；TAX_WEAK（含泛经济词）单独出现不再入选
    return any(k in (title or "") for k in TAX_STRONG)


def _is_nontax(title):
    # 负向兜底：明显非财税赛道且无强词 -> 丢弃
    t = title or ""
    if any(k in t for k in TAX_STRONG):
        return False
    return any(k in t for k in _NONTAX)


# ============================== 社会热流 ==============================
def fetch_social(per_source=40):
    """抓微博/百度/头条热榜，按标题归并，记录跨平台出现情况与热度。"""
    plat = {}  # norm -> {title, heat_raw, plats:set, urls:dict}

    def add(title, heat, source, url):
        k = _norm(title)
        if not k:
            return
        e = plat.setdefault(k, {"title": title, "heat_raw": "", "plats": set(), "urls": {}})
        e["plats"].add(source)
        if heat and not e["heat_raw"]:
            e["heat_raw"] = heat
        if url:
            e["urls"][source] = url

    # 微博热搜
    try:
        j = _http_json("https://weibo.com/ajax/side/hotSearch",
                       headers={"Referer": "https://weibo.com/"})
        for it in (j.get("data") or {}).get("realtime", [])[:per_source]:
            w = (it.get("word") or "").strip()
            if w:
                add(w, str(it.get("num") or ""), "微博热搜",
                    "https://s.weibo.com/weibo?q=" + urllib.parse.quote(w))
    except Exception as e:  # noqa: BLE001
        print("[newsfeed] weibo fail: %s" % str(e)[:80], flush=True)

    # 百度热搜（页面里嵌了 word + hotScore）
    try:
        html = _http_text("https://top.baidu.com/board?platform=wise&tab=realtime")
        pairs = re.findall(r'"word":"([^"]+)"\s*,\s*"desc":[^,]*,\s*"img":[^,]*,\s*"hotScore":(\d+)', html)
        if not pairs:
            pairs = [(w, "") for w in re.findall(r'"word":"([^"]+)"', html)]
        for w, hs in pairs[:per_source]:
            w = w.strip()
            if w:
                add(w, hs, "百度热搜", "https://www.baidu.com/s?wd=" + urllib.parse.quote(w))
    except Exception as e:  # noqa: BLE001
        print("[newsfeed] baidu fail: %s" % str(e)[:80], flush=True)

    # 头条热榜
    try:
        j = _http_json("https://www.toutiao.com/hot-event/hot-board/?origin=toutiao_pc")
        for it in (j.get("data") or [])[:per_source]:
            t = (it.get("Title") or "").strip()
            if t:
                add(t, str(it.get("HotValue") or ""), "头条热榜", it.get("Url") or "")
    except Exception as e:  # noqa: BLE001
        print("[newsfeed] toutiao fail: %s" % str(e)[:80], flush=True)

    return list(plat.values())


# ============================== 权威流（Tavily 限定域名，稳定可靠）==============================
def _tavily_key():
    try:
        from model_providers import get_key
        return get_key("TAVILY_API_KEY")
    except Exception:  # noqa: BLE001
        return ""


def fetch_authority(days=15):
    """国家税务总局 + 税屋 最新政策（15 天窗口）。

    用 Tavily 检索并限定域名（比直接爬首页 HTML 稳定，且同样「从这两个站获取」）。
    无 TAVILY_API_KEY 时静默返回空，由社会热流兜底。
    """
    key = _tavily_key()
    if not key:
        return []
    items = []
    domains = [("国家税务总局", "chinatax.gov.cn"), ("税屋", "shui5.com")]
    queries = [
        "税务总局 最新政策 公告 解读 企业",
        "税收 新政 稽查 申报 热点",
        "财税 优惠政策 最新 小规模纳税人",
    ]
    for label, domain in domains:
        for q in queries:
            try:
                data = _http_post_json(
                    "https://api.tavily.com/search",
                    {
                        "query": q, "topic": "news", "search_depth": "advanced",
                        "days": days, "max_results": 5,
                        "include_domains": [domain], "include_answer": False,
                    },
                    headers={"Authorization": "Bearer %s" % key},
                    timeout=20)
                for r in (data.get("results") or []):
                    t = (r.get("title") or "").strip()
                    if not t or len(t) < 6:
                        continue
                    # 过滤掉明显非财税的（如页面导航/栏目名）
                    if not _is_tax(t) and not any(k in (r.get("content") or "") for k in ("税", "发票", "稽查", "申报", "财政")):
                        continue
                    # 过滤栏目/导航类标题（工作动态、政策图解、教学点播、网站…）
                    if re.search(r"(工作动态|政策图解|教学点播|税务辅导|区县工作动态|网站|栏目|专题|访谈|办税指南|通知公告|首页|视频|图表|图解|直播|往前走)", t):
                        continue
                    pub = (r.get("published_date") or "")[:10]
                    items.append({"title": t, "published_at": pub,
                                  "url": r.get("url") or "", "source_label": label})
            except Exception as e:  # noqa: BLE001
                print("[newsfeed] tavily %s fail: %s" % (domain, str(e)[:80]), flush=True)
                continue
    return items


# ============================== 打分 / 归并 ==============================
def _fmt_heat(raw):
    """把原始热度数字转成可读文字，如 3285000 -> 328.5万。"""
    try:
        n = float(raw)
    except Exception:
        return ""
    if n >= 1e8:
        return "%.1f亿" % (n / 1e8)
    if n >= 1e4:
        return "%.1f万" % (n / 1e4)
    return str(int(n))


def _heat_num(raw):
    try:
        return float(raw) if raw else 0.0
    except Exception:
        return 0.0


def build_feed(days=15, per_source=40):
    social = fetch_social(per_source)
    authority = fetch_authority(days)

    items = []
    # 社会热流：财税过滤 + 跨平台打分
    for e in social:
        if not _is_tax(e["title"]):
            continue
        if _is_nontax(e["title"]):
            continue
        plats = list(e["plats"])
        cross = len(plats)
        hnum = _heat_num(e["heat_raw"])
        # 热度分：取对数避免极端值压制；跨平台加成
        import math
        base = math.log10(hnum + 10) if hnum else 5.0
        cross_boost = {1: 1.0, 2: 1.4, 3: 1.8}.get(cross, 2.0)
        score = round(base * cross_boost * 10, 1)
        heat_text = " · ".join(
            ["%s %s" % (p.replace("热搜", "").replace("热榜", ""),
                        (_fmt_heat(e["heat_raw"]) or "热")) for p in plats])
        url = e["urls"].get(plats[0], "") if plats else ""
        items.append({
            "id": _norm(e["title"]),
            "title": e["title"],
            "category": "social",
            "platforms": plats,
            "heat_text": heat_text,
            "heat_score": score,
            "url": url,
            "published_at": "",
            "audience_fit": True,
        })

    # 权威流：长尾加权
    for a in authority:
        items.append({
            "id": _norm(a["title"]) + "_auth",
            "title": a["title"],
            "category": "policy",
            "platforms": [a.get("source_label") or "权威源"],
            "heat_text": "权威政策",
            "heat_score": 60.0,  # 权威源基础权重，保证可见但不喧宾夺主
            "url": a.get("url", ""),
            "published_at": a.get("published_at", ""),
            "audience_fit": True,
        })

    # 社会流同一条新闻去重：去掉常见修饰词后比对（如「贷款中介集体删除朋友圈」vs「…这几天集体删除…」）只留较长者
    seen_soft = [(it["title"], _norm_soft(it["title"])) for it in items if it["category"] == "social"]
    items = [it for it in items if not (
        it["category"] == "social" and any(
            len(it["title"]) < len(o) and _norm_soft(it["title"]) in soft_o
            for o, soft_o in seen_soft))]

    # 去重（同标题只留分高者）
    by_id = {}
    for it in items:
        k = it["id"]
        if k not in by_id or it["heat_score"] > by_id[k]["heat_score"]:
            by_id[k] = it
    items = list(by_id.values())

    # 排序：热度分降序
    items.sort(key=lambda x: x["heat_score"], reverse=True)
    items = items[:40]

    return {
        "updated_at": time.strftime("%Y-%m-%d %H:%M:%S"),
        "days": days,
        "social_count": sum(1 for i in items if i["category"] == "social"),
        "policy_count": sum(1 for i in items if i["category"] == "policy"),
        "items": items,
    }


def _build_and_save(days):
    """重建一次并落盘缓存。"""
    data = build_feed(days=days)
    data["from_cache"] = False
    CACHE_PATH.parent.mkdir(parents=True, exist_ok=True)
    CACHE_PATH.write_text(json.dumps(data, ensure_ascii=False), encoding="utf-8")
    return data


_rebuild_lock = threading.Lock()
_rebuilding = False


def _rebuild_async(days):
    """后台线程重建，绝不阻塞请求线程（8500 单线程，重建期间会卡住所有请求）。"""
    global _rebuilding
    with _rebuild_lock:
        if _rebuilding:
            return
        _rebuilding = True

    def _worker():
        global _rebuilding
        try:
            _build_and_save(days)
            print("[newsfeed] async rebuild done", flush=True)
        except Exception as e:  # noqa: BLE001
            print("[newsfeed] async rebuild fail: %s" % str(e)[:120], flush=True)
        finally:
            with _rebuild_lock:
                _rebuilding = False

    threading.Thread(target=_worker, daemon=True).start()


def get_feed(days=15, force=False):
    """Stale-while-revalidate：缓存过期也先秒回旧数据，后台慢慢重建。

    8500 是单线程 HTTP 服务，若在请求线程里实时抓取（微博/百度/头条/Tavily），
    重建的一分多钟内所有请求都会排队——前端「选题雷达」就会一直挂在加载中。
    所以：只要有旧缓存（哪怕过期）就立即返回，重建放后台线程。
    仅冷启动（从无缓存）时同步建一次。
    """
    cached = None
    cache_age = None
    if CACHE_PATH.exists():
        try:
            cached = json.loads(CACHE_PATH.read_text(encoding="utf-8"))
            cache_age = time.time() - os.path.getmtime(CACHE_PATH)
        except Exception:  # noqa: BLE001
            cached = None

    has_data = bool(cached and cached.get("items"))

    if has_data:
        fresh = cache_age is not None and cache_age < CACHE_TTL
        if fresh and not force:
            cached["from_cache"] = True
            return cached
        # 过期（或 force）：秒回旧数据 + 后台重建
        _rebuild_async(days)
        cached["from_cache"] = True
        cached["rebuilding"] = True
        return cached

    # 冷启动无缓存：同步建一次（仅首次）
    try:
        return _build_and_save(days)
    except Exception as e:  # noqa: BLE001
        print("[newsfeed] build fail: %s" % str(e)[:120], flush=True)
        return {"updated_at": time.strftime("%Y-%m-%d %H:%M:%S"), "days": days,
                "social_count": 0, "policy_count": 0, "items": [],
                "degraded": True, "error": str(e)[:120]}


if __name__ == "__main__":
    print(json.dumps(get_feed(force=True), ensure_ascii=False, indent=2))
