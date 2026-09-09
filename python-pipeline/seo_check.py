# -*- coding: utf-8 -*-
"""公众号文章 SEO 校验器（纯规则、零第三方依赖）。

面向微信站内「搜一搜」排名与微信 AI 搜索/元宝引用（GEO）设计，
不涉及百度、谷歌等站外搜索引擎（mp.weixin.qq.com 已被 robots 屏蔽）。

用法:
    import seo_check
    result = seo_check.check(title, digest, content, kw_main, kw_long, region, year)
"""

import re

# ---------------------------------------------------------------- 词表

# 标题党词
CLICKBAIT_WORDS = [
    "紧急通知", "紧急提醒", "不看后悔", "后悔莫及", "国家刚宣布", "国家宣布", "中央宣布",
    "震惊", "惊呆", "惊天", "重磅", "速看", "赶紧看", "马上看", "最后一天", "即将删除",
    "删前速看", "内部消息", "独家内幕", "绝密", "99%的人不知道", "不知道就亏", "亏大了",
    "疯了", "炸锅", "彻底崩溃", "真相曝光", "背后真相", "竟然是这样", "看完秒懂",
    "千万别再", "再不看就没了", "所有人都慌了", "权威发布", "刚刚传来", "深夜突发",
    "突发消息", "网传属实", "官方紧急", "紧急叫停", "最后机会", "仅此一次", "倒计时",
]

# 财税违规词（承诺型 / 诱导型 / 绝对化）
# 注意：列表中的「免征」误伤率较高（政策原文常用语），如策略允许建议移除或改为「承诺免征」。
BANNED_TAX_WORDS = [
    "避税", "合理避税", "逃税", "偷税", "包过", "100%通过", "百分百通过", "零风险",
    "绝对安全", "绝对合理", "保证退税", "保证通过", "税务筹划包过", "筹划包过", "免征",
    "全额免", "稳赚", "包退税", "一定退", "一定能退", "无风险", "包下证",
    "万能方案", "秒批秒过", "内部渠道", "包成功", "100%安全", "不用交税", "不交税",
    "完美筹划", "政策漏洞", "钻空子", "阴阳合同", "两套账",
]

# ---------------------------------------------------------------- 阈值

TITLE_OK_MAX = 24          # 标题通过上限
TITLE_FAIL_MAX = 30        # 标题硬性上限
TITLE_BEST_MIN = 14        # 推荐长度下限
TITLE_BEST_MAX = 20        # 推荐长度上限
KW_TITLE_POS_MAX = 12      # 主词在标题中的最晚位置
FIRST_LEN = 200            # 首段判定长度
DIGEST_OK_MIN = 55
DIGEST_OK_MAX = 80
DIGEST_FAIL_MIN = 30
DIGEST_FAIL_MAX = 120
WORD_OK_MIN = 1500
WORD_OK_MAX = 2500
WORD_HINT_MIN = 800
WORD_HINT_MAX = 3500
WORD_FAIL_MAX = 4000
DENSITY_FAIL_MIN = 3.0     # 每千字最低
DENSITY_OK_MIN = 5.0
DENSITY_OK_MAX = 8.0
DENSITY_FAIL_MAX = 12.0
H2_OK_MIN = 3
H2_OK_MAX = 6
H2_FAIL_MAX = 2            # 少于该值不通过
TAG_OK_MIN = 3
TAG_OK_MAX = 5
TAG_HINT_MAX = 6
TAG_LEN_MAX = 10
REGION_MIN_HITS = 3        # 地域词最少命中位置数
SCORE_BASE = 100
SCORE_FAIL = 5
SCORE_HARD_FAIL = 30
LEVEL_GOOD = 80
LEVEL_FAIR = 60

# 五类推荐句式
QUESTION_WORDS = ["怎么", "如何", "为什么", "多少", "要不要", "哪个", "怎样", "多久"]
COMPARE_WORDS = ["还是", "哪个", "vs", "VS", "对比", "区别", "哪一种"]
CROWD_WORDS = ["老板", "创业者", "创始人", "会计", "财务", "个体户", "自由职业"]
_CN_NUM = "一二三四五六七八九十"

ITEM_NAMES = {
    "R1": "标题长度", "R2": "主词位置", "R3": "主词重复", "R4": "标题句式",
    "R5": "年份词", "R6": "标题党", "R7": "摘要长度", "R8": "摘要含主词",
    "R9": "首段主词", "R10": "主词密度", "R11": "长尾词覆盖", "R12": "H2小标题",
    "R13": "篇幅", "R14": "段落长度", "R15": "话题标签", "R16": "标签格式",
    "R17": "时效标记", "R18": "地域覆盖", "R19": "违规词", "R20": "原创提示",
}

HARD_FAIL_RULES = ("R6", "R19")


# ---------------------------------------------------------------- 通用工具

def _s(v):
    """入参安全转字符串并去空白，None 视为空串。"""
    try:
        if v is None:
            return ""
        return str(v).strip()
    except Exception:
        return ""


def _count(text, sub):
    """子串出现次数，空串一律返回 0。"""
    if not text or not sub:
        return 0
    try:
        return text.count(sub)
    except Exception:
        return 0


def _findall(pattern, text, flags=0):
    """正则兜底：异常返回空列表。"""
    try:
        return re.findall(pattern, text, flags)
    except Exception:
        return []


def _search(pattern, text, flags=0):
    try:
        return re.search(pattern, text, flags)
    except Exception:
        return None


def _plain_text(text):
    """正文纯文本：去 HTML 标签、图片语法与 Markdown 行首符号。"""
    t = _s(text)
    try:
        t = re.sub(r"!\[[^\]]*\]\([^)]*\)", "", t)
        t = re.sub(r"<[^>]{1,300}?>", "", t)
        t = t.replace("**", "").replace("`", "")
        t = re.sub(r"^\s*[#>*+\-]{1,3}\s*", "", t, flags=re.M)
    except Exception:
        pass
    return t


def _word_count(plain):
    """中文按字符计：去掉所有空白后的字符数。"""
    try:
        return len(re.sub(r"\s+", "", plain))
    except Exception:
        return len(plain or "")


def _split_paragraphs(content):
    """按空行切段；无空行时退化为按行切段。"""
    blocks = [b for b in re.split(r"\n\s*\n", content or "") if b.strip()]
    if len(blocks) <= 1:
        blocks = [ln for ln in (content or "").splitlines() if ln.strip()]
    return blocks


def _sentences(block):
    """段落内的句子数（以句号类标点计数）。"""
    parts = re.split(r"[。！？!?；;\n]+", block or "")
    return len([p for p in parts if p.strip()])


def _extract_h2(content):
    """提取二级小标题文本：Markdown ##、HTML <h2>、中文序号独立行。"""
    heads = []
    for line in (content or "").splitlines():
        s = line.strip()
        if not s or len(s) > 60:
            continue
        hit = False
        if _search(r"^#{2,3}\s+\S", s):
            hit = True
        elif _search(r"^<h[23][^>]*>", s, re.I):
            hit = True
        elif _search(r"^[" + _CN_NUM + r"]{1,3}、\s*\S", s):
            hit = True
        elif _search(r"^\d{1,2}[.、]\s*\S", s):
            hit = True
        if hit:
            clean = re.sub(r"^[#*>\s]+", "", s)
            clean = re.sub(r"<[^>]{1,300}?>", "", clean)
            heads.append(clean.strip())
    return heads


def _extract_tags(content):
    """提取 #xxx 话题标签（宽松匹配，用于格式校验）。"""
    raw = _findall(r"#[^\s#]+", content or "")
    tags = []
    for t in raw:
        t = t.strip("#").strip()
        if t:
            tags.append(t)
    return tags


def _split_long(kw_long):
    """长尾词按逗号/顿号/分号/空格切分。"""
    parts = re.split(r"[,，、;；/\s]+", _s(kw_long))
    return [p for p in parts if p]


def _hits(text, words):
    """返回命中的词列表。"""
    res = []
    for w in words:
        if w and w in text:
            res.append(w)
    return res


# ---------------------------------------------------------------- 规则实现

def _r1(c):
    n = len(c["title"])
    if n == 0:
        return False, "标题为空", "必须填写标题，推荐 %d-%d 字" % (TITLE_BEST_MIN, TITLE_BEST_MAX)
    if n <= TITLE_OK_MAX:
        if n < TITLE_BEST_MIN:
            return True, "标题 %d 字，偏短" % n, "可补充主词或年份，扩到 %d-%d 字" % (TITLE_BEST_MIN, TITLE_BEST_MAX)
        return True, "标题 %d 字，长度合适" % n, ""
    if n > TITLE_FAIL_MAX:
        return False, "标题 %d 字，超过 %d 字会被折叠" % (n, TITLE_FAIL_MAX), "精简到 %d 字以内" % TITLE_OK_MAX
    return None, "标题 %d 字，略长" % n, "建议压缩到 %d-%d 字，突出主词" % (TITLE_BEST_MIN, TITLE_BEST_MAX)


def _r2(c):
    kw = c["kw_main"]
    if not kw:
        return None, "未指定主关键词", "调用时传入 kw_main 以便校验主词位置"
    pos = c["title"].find(kw)
    if 0 <= pos <= KW_TITLE_POS_MAX:
        return True, "主词出现在标题第 %d 字，位置靠前" % (pos + 1), ""
    if pos < 0:
        return False, "标题中未出现主词「%s」" % kw, "把主词放进标题前 %d 字" % KW_TITLE_POS_MAX
    return False, "主词位置在第 %d 字，过于靠后" % (pos + 1), "主词前移到标题前 %d 字内" % KW_TITLE_POS_MAX


def _r3(c):
    kw = c["kw_main"]
    if not kw or not c["title"]:
        return None, "无主词或标题为空，跳过", ""
    n = _count(c["title"], kw)
    if n == 1:
        return True, "主词在标题出现 1 次", ""
    if n == 0:
        return None, "标题未出现主词，已在 R2 判定", ""
    if n >= 3:
        return False, "主词在标题出现 %d 次，属于堆砌" % n, "标题保留主词 1 次，其余用近义词替换"
    return None, "主词在标题出现 %d 次" % n, "建议标题主词只出现 1 次"


def _r4(c):
    t = c["title"]
    if not t:
        return False, "标题为空", "填写标题并使用数字/疑问/对比等推荐句式"
    matched = []
    if _search(r"\d", t):
        matched.append("数字")
    if any(w in t for w in QUESTION_WORDS):
        matched.append("疑问词")
    if (c["region"] and c["region"] in t) or _search(r"[\u4e00-\u9fa5]{2,7}(省|市|区|县)", t):
        matched.append("地域+事项")
    if any(w in t for w in COMPARE_WORDS):
        matched.append("对比")
    if any(w in t for w in CROWD_WORDS):
        matched.append("人群词")
    if matched:
        return True, "命中推荐句式：%s" % "、".join(matched), ""
    return False, "未命中任何推荐句式", "加入数字、疑问词（怎么/多少）、地域、对比或人群词（老板/创业者）"


def _r5(c):
    year = c["year"]
    if not year:
        return None, "未指定年份，跳过", ""
    if year in c["title"]:
        return True, "标题含年份 %s" % year, ""
    return None, "标题缺少年份 %s" % year, "政策类文章建议标题写明年年份，利于搜一搜时效排序"


def _r6(c):
    hits = _hits(c["title"], CLICKBAIT_WORDS)
    if hits:
        return False, "标题命中标题党词：%s" % "、".join(hits), "删除夸张词，改写为陈述式标题，否则微信搜一搜降权"
    return True, "标题无标题党词", ""


def _r7(c):
    n = len(c["digest"])
    if n == 0:
        return False, "摘要为空", "补充 %d-%d 字摘要，含主词和核心结论" % (DIGEST_OK_MIN, DIGEST_OK_MAX)
    if DIGEST_OK_MIN <= n <= DIGEST_OK_MAX:
        return True, "摘要 %d 字，长度合适" % n, ""
    if n < DIGEST_FAIL_MIN or n > DIGEST_FAIL_MAX:
        return False, "摘要 %d 字，超出合理区间" % n, "调整到 %d-%d 字" % (DIGEST_OK_MIN, DIGEST_OK_MAX)
    return None, "摘要 %d 字，略偏" % n, "建议控制在 %d-%d 字" % (DIGEST_OK_MIN, DIGEST_OK_MAX)


def _r8(c):
    kw = c["kw_main"]
    if not kw:
        return None, "未指定主关键词，跳过", ""
    n = _count(c["digest"], kw)
    if n == 1:
        return True, "摘要含主词 1 次" , ""
    if n == 0:
        return False, "摘要未出现主词「%s」" % kw, "摘要自然植入主词 1 次"
    if n >= 3:
        return None, "摘要主词出现 %d 次，偏堆砌" % n, "保留 1 次即可"
    return True, "摘要含主词 %d 次" % n, "建议只保留 1 次"


def _r9(c):
    kw = c["kw_main"]
    if not kw:
        return None, "未指定主关键词，跳过", ""
    n = _count(c["first200"], kw)
    if n == 0:
        return False, "前 %d 字未出现主词" % FIRST_LEN, "开篇第一段自然写出主词 1-2 次"
    if n <= 2:
        return True, "首段主词出现 %d 次" % n, ""
    if n >= 4:
        return None, "首段主词出现 %d 次，堆砌" % n, "首段主词控制在 1-2 次"
    return True, "首段主词出现 %d 次" % n, "接近堆砌阈值，建议降到 2 次以内"


def _r10(c):
    kw = c["kw_main"]
    if not kw or c["word_count"] <= 0:
        return None, "无主词或正文为空，跳过", ""
    d = c["density"]
    if DENSITY_OK_MIN <= d <= DENSITY_OK_MAX:
        return True, "主词密度 %.2f 次/千字" % d, ""
    if d < DENSITY_FAIL_MIN or d > DENSITY_FAIL_MAX:
        level = "过低" if d < DENSITY_FAIL_MIN else "过高"
        return False, "主词密度 %.2f 次/千字，%s" % (d, level), "密度控制在 %.0f-%.0f 次/千字" % (DENSITY_OK_MIN, DENSITY_OK_MAX)
    return None, "主词密度 %.2f 次/千字，略偏离" % d, "推荐区间 %.0f-%.0f 次/千字" % (DENSITY_OK_MIN, DENSITY_OK_MAX)


def _r11(c):
    longs = c["long_kws"]
    if not longs:
        return None, "未提供长尾词，跳过", "调用时传入逗号分隔的 kw_long 以便校验覆盖度"
    hits = [w for w in longs if _count(c["plain"], w) > 0]
    need = (len(longs) + 1) // 2
    occur = sum(_count(c["plain"], w) for w in longs)
    need_occur = max(1, int(c["word_count"] // 800))
    if len(hits) >= need and occur >= need_occur:
        return True, "长尾词命中 %d/%d 个，正文出现 %d 次" % (len(hits), len(longs), occur), ""
    if len(hits) < need:
        return False, "长尾词仅命中 %d/%d 个（需≥%d）" % (len(hits), len(longs), need), "补充未覆盖长尾词：%s" % "、".join([w for w in longs if w not in hits])
    return False, "长尾词覆盖够，但密度不足：%d 次 / 需≥%d 次（每800字1个）" % (occur, need_occur), "把长尾词分散到各 H2 小节中"


def _r12(c):
    h2 = c["h2"]
    n = len(h2)
    with_kw = 0
    for item in h2:
        if any(_count(item, w) > 0 for w in c["long_kws"] if w):
            with_kw += 1
    detail = "H2 共 %d 个，其中 %d 个含长尾词" % (n, with_kw)
    if n < H2_FAIL_MAX:
        return False, "%s，小标题过少" % detail, "至少写 3 个二级小标题，并在其中植入长尾词"
    if H2_OK_MIN <= n <= H2_OK_MAX and with_kw >= 2:
        return True, detail, ""
    if n > H2_OK_MAX:
        return None, "%s，小标题偏多" % detail, "合并到 %d-%d 个" % (H2_OK_MIN, H2_OK_MAX)
    if with_kw < 2:
        return None, "%s，长尾词覆盖不足" % detail, "至少 2 个小标题写入长尾词，利于 AI 搜索分块引用"
    return None, detail, "确保 H2 数量在 %d-%d 个之间" % (H2_OK_MIN, H2_OK_MAX)


def _r13(c):
    n = c["word_count"]
    if WORD_OK_MIN <= n <= WORD_OK_MAX:
        return True, "正文 %d 字，篇幅合适" % n, ""
    if n < WORD_HINT_MIN or n > WORD_FAIL_MAX:
        level = "过短" if n < WORD_HINT_MIN else "过长"
        return False, "正文 %d 字，篇幅%s" % (n, level), "控制在 %d-%d 字" % (WORD_OK_MIN, WORD_OK_MAX)
    return None, "正文 %d 字，略偏离推荐区间" % n, "推荐 %d-%d 字" % (WORD_OK_MIN, WORD_OK_MAX)


def _r14(c):
    paras = c["paragraphs"]
    if not paras:
        return False, "正文为空", "补充正文内容"
    avg_line = round(sum(len([x for x in p.splitlines() if x.strip()]) for p in paras) / float(len(paras)), 2)
    avg_sent = round(sum(_sentences(p) for p in paras) / float(len(paras)), 2)
    detail = "段落 %d 个，平均 %.1f 行 / %.1f 句" % (len(paras), avg_line, avg_sent)
    if 3 <= avg_sent <= 5:
        return True, detail, ""
    if avg_sent > 5:
        return None, "%s，段落偏长" % detail, "每段拆到 3-5 句，插入小标题或空行便于 AI 抓取"
    if avg_sent < 2:
        return None, "%s，段落偏碎" % detail, "相近短句合并成 3-5 句的段落"
    return True, detail, ""


def _r15(c):
    tags = [t for t in c["tags"] if len(t) <= TAG_LEN_MAX]
    n = len(tags)
    if n == 0:
        return False, "文末无 #话题标签", "文末加 %d-%d 个 #标签，如 #%s" % (TAG_OK_MIN, TAG_OK_MAX, c["kw_main"] or "财税")
    if TAG_OK_MIN <= n <= TAG_OK_MAX:
        return True, "话题标签 %d 个：%s" % (n, "、".join(tags)), ""
    if n > TAG_HINT_MAX:
        return None, "话题标签 %d 个，过多" % n, "保留 %d-%d 个高相关标签" % (TAG_OK_MIN, TAG_OK_MAX)
    return None, "话题标签 %d 个，偏少" % n, "补充到 %d-%d 个" % (TAG_OK_MIN, TAG_OK_MAX)


def _r16(c):
    tags = c["tags"]
    if not tags:
        return None, "无标签，跳过格式校验", ""
    bad = [t for t in tags if len(t) > TAG_LEN_MAX or " " in t]
    if bad:
        return None, "标签格式不合规：%s" % "、".join(bad[:5]), "单个标签 ≤%d 字且不含空格" % TAG_LEN_MAX
    return True, "标签格式均合规（≤%d 字、无空格）" % TAG_LEN_MAX, ""


def _r17(c):
    body = c["content"]
    if _search(r"(政策依据|施行|生效|文号)", body):
        return True, "正文含政策依据/施行/生效类时效表述", ""
    if _search(r"〔\s*\d{4}\s*〕\s*[^\s]{0,20}?号", body) or _search(r"\[\s*\d{4}\s*\]\s*\d+\s*号", body):
        return True, "正文含政策文号", ""
    return None, "正文缺少政策依据/施行日期/文号", "补充「政策依据：xxx〔%s〕xx号，自xx起施行」" % (c["year"] or "2026")


def _r18(c):
    region = c["region"]
    if not region or region in ("全国", "通用"):
        return None, "未指定地域或面向全国，跳过", ""
    spots = []
    if region in c["title"]:
        spots.append("标题")
    if region in c["digest"]:
        spots.append("摘要")
    if region in c["first200"]:
        spots.append("首段")
    if any(region in h for h in c["h2"]):
        spots.append("H2")
    if len(spots) >= REGION_MIN_HITS:
        return True, "地域词命中 %d 处：%s" % (len(spots), "、".join(spots)), ""
    miss = [p for p in ("标题", "摘要", "首段", "H2") if p not in spots]
    return False, "地域词「%s」仅命中 %d 处" % (region, len(spots)), "在%s补充地域词，至少覆盖 %d 处，利于本地搜一搜" % ("、".join(miss), REGION_MIN_HITS)


def _r19(c):
    scope = []
    for name, text in (("标题", c["title"]), ("摘要", c["digest"]), ("正文", c["content"])):
        for w in _hits(text, BANNED_TAX_WORDS):
            scope.append("%s:%s" % (name, w))
    if scope:
        return False, "命中财税违规词 %d 处：%s" % (len(scope), "、".join(scope[:6])), "删除承诺/绝对化表述，改为客观政策解读与风险提示"
    return True, "无财税违规词", ""


def _r20(c):
    return None, "合规解读类建议勾选原创声明；政策原文/大段汇编禁止标原创", "由人工最终确认原创标记"


RULES = (
    ("R1", _r1), ("R2", _r2), ("R3", _r3), ("R4", _r4), ("R5", _r5),
    ("R6", _r6), ("R7", _r7), ("R8", _r8), ("R9", _r9), ("R10", _r10),
    ("R11", _r11), ("R12", _r12), ("R13", _r13), ("R14", _r14), ("R15", _r15),
    ("R16", _r16), ("R17", _r17), ("R18", _r18), ("R19", _r19), ("R20", _r20),
)


# ---------------------------------------------------------------- 结果构造

def _empty_result(reason):
    stats = {
        "title_len": 0, "digest_len": 0, "word_count": 0,
        "kw_main_in_title_pos": -1, "kw_main_count": 0, "kw_density_per_k": 0.0,
        "h2_count": 0, "h2_with_long_kw": 0, "tag_count": 0,
        "year_in_title": False, "region_count": 0,
    }
    return {
        "score": 0, "level": "poor", "items": [], "stats": stats,
        "hard_fail": True, "summary": "校验失败：%s" % reason,
    }


def _build_stats(c):
    kw = c["kw_main"]
    h2 = c["h2"]
    with_kw = len([h for h in h2 if any(_count(h, w) > 0 for w in c["long_kws"] if w)])
    region_count = 0
    if c["region"] and c["region"] not in ("全国", "通用"):
        for text in (c["title"], c["digest"], c["first200"], " ".join(h2)):
            if c["region"] in text:
                region_count += 1
    return {
        "title_len": len(c["title"]),
        "digest_len": len(c["digest"]),
        "word_count": c["word_count"],
        "kw_main_in_title_pos": c["title"].find(kw) if kw else -1,
        "kw_main_count": _count(c["plain"], kw),
        "kw_density_per_k": round(c["density"], 2),
        "h2_count": len(h2),
        "h2_with_long_kw": with_kw,
        "tag_count": len([t for t in c["tags"] if len(t) <= TAG_LEN_MAX]),
        "year_in_title": bool(c["year"]) and c["year"] in c["title"],
        "region_count": region_count,
        "long_kw_total": len(c["long_kws"]),
        "long_kw_hit": len([w for w in c["long_kws"] if _count(c["plain"], w) > 0]),
        "paragraph_count": len(c["paragraphs"]),
        "clickbait_hits": len(_hits(c["title"], CLICKBAIT_WORDS)),
        "banned_hits": len(_hits(c["title"], BANNED_TAX_WORDS)) + len(_hits(c["digest"], BANNED_TAX_WORDS)) + len(_hits(c["content"], BANNED_TAX_WORDS)),
        "tags": c["tags"],
    }


def check(title, digest, content, kw_main, kw_long="", region="", year=""):
    """纯规则 SEO 校验。

    :param title: 文章标题
    :param digest: 摘要
    :param content: 正文（支持 Markdown / 简单 HTML）
    :param kw_main: 主关键词
    :param kw_long: 长尾词，逗号或空格分隔
    :param region: 地域词，如「深圳」；传「全国」表示不校验地域
    :param year: 政策年份，如「2026」
    :return: dict，见模块说明
    """
    try:
        title = _s(title)
        digest = _s(digest)
        content = _s(content)
        kw_main = _s(kw_main)
        kw_long = _s(kw_long)
        region = _s(region)
        year = _s(year)

        plain = _plain_text(content)
        word_count = _word_count(plain)
        density = (plain.count(kw_main) * 1000.0 / word_count) if (kw_main and word_count > 0) else 0.0

        ctx = {
            "title": title, "digest": digest, "content": content,
            "kw_main": kw_main, "kw_long": kw_long, "region": region, "year": year,
            "plain": plain, "word_count": word_count, "density": density,
            "first200": plain[:FIRST_LEN],
            "h2": _extract_h2(content),
            "tags": _extract_tags(content),
            "long_kws": _split_long(kw_long),
            "paragraphs": _split_paragraphs(content),
        }

        items = []
        for rid, fn in RULES:
            try:
                ok, msg, suggest = fn(ctx)
                if ok not in (True, False):
                    ok = None
            except Exception as exc:
                ok, msg, suggest = None, "规则执行异常：%s" % exc, "检查输入内容格式"
            items.append({
                "id": rid, "name": ITEM_NAMES.get(rid, rid),
                "pass": ok, "msg": _s(msg), "suggest": _s(suggest),
            })

        fail_ids = [i["id"] for i in items if i["pass"] is False]
        warn_ids = [i["id"] for i in items if i["pass"] is None]
        hard_ids = [i for i in fail_ids if i in HARD_FAIL_RULES]
        hard_fail = len(hard_ids) > 0

        score = SCORE_BASE
        for i in items:
            if i["pass"] is False:
                score -= SCORE_HARD_FAIL if i["id"] in HARD_FAIL_RULES else SCORE_FAIL
        score = max(0, min(SCORE_BASE, int(score)))

        if score >= LEVEL_GOOD:
            level = "good"
        elif score >= LEVEL_FAIR:
            level = "fair"
        else:
            level = "poor"
        if hard_fail:
            level = "poor"
        # 兜底安全网：无标题或无正文属不可用稿件，不允许进入 fair/good
        if not title or word_count == 0:
            level = "poor"

        tip = ""
        for i in items:
            if i["pass"] is False:
                tip = i["suggest"] or i["msg"]
                break
        if not tip:
            for i in items:
                if i["pass"] is None and i["suggest"]:
                    tip = i["suggest"]
                    break
        if hard_fail:
            head = "存在致命问题（%s），须改写后重发" % "、".join(hard_ids)
        else:
            head = "无致命问题"
        summary = "得分 %d/100（%s）：%s，不通过 %d 项、提示 %d 项。" % (score, level, head, len(fail_ids), len(warn_ids))
        if tip:
            summary += "优先处理：%s" % tip

        result = {
            "score": score,
            "level": level,
            "items": items,
            "stats": _build_stats(ctx),
            "hard_fail": hard_fail,
            "summary": summary,
        }
        result["stats"]["fail_ids"] = fail_ids
        result["stats"]["warn_ids"] = warn_ids
        return result
    except Exception as exc:
        return _empty_result(_s(exc) or "未知异常")


# ---------------------------------------------------------------- 自测

if __name__ == "__main__":
    good_title = "深圳个体户增值税优惠怎么享受？2026年新规解读"
    good_digest = (
        "深圳个体户增值税优惠2026年有哪些变化？本文梳理适用范围、"
        "免税额度计算口径与小规模纳税人申报流程，附上政策依据与常见误区，"
        "帮创业者把优惠合规落到账上。"
    )
    good_content = """深圳个体户增值税优惠是创业者最关心的话题之一。2026年政策口径延续后，适用对象、申报方式与常见误区都有细节调整，本文结合最新文件逐条拆解，方便对照自查。

## 一、2026年深圳个体户增值税优惠适用谁
小规模纳税人是这次优惠的主要适用对象。判断口径看连续12个月的应税销售额，而不是单月开票金额。免税额度按不含税销售额计算，包含开具普通发票和未开票收入两部分。深圳个体户在电子税务局代开时，系统会自动判断是否享受免税额度，超出部分按适用征收率计税。

需要提醒的是，享受个体户增值税优惠不等于不用申报。即使当期销售额未超过免税额度，也必须按期完成申报动作，否则会被标记为申报异常，直接影响后续发票领用和额度调整。

从实操咨询看，问得最多的是按月还是按季判断。按月申报的看月销售额，按季申报的看一个季度的累计销售额，两种口径不能混着用。兼营不同税目业务的，还要分别核算，分别判断个体户增值税优惠是否适用，核算不清的部分通常按最严口径处理。

还有一个容易被忽略的细节：适用差额征税的项目，要按差额后的销售额判断能否达标，不能直接用开票金额比较。旅游、劳务派遣、不动产租赁这几类个体户，在测算自己的免税空间时尤其要注意这一点。

## 二、小规模纳税人申报怎么操作
登录电子税务局，进入申报模块，选择对应的减免性质代码，系统会自动带出减免税额。小规模纳税人申报可以选择按季申报，一个季度只做一次，比按月申报省事不少。填报时务必核对开票税率、申报口径与实际业务是否一致，三者一旦错配，系统会即时比对提示差异。

申报提交后，建议在申报查询中复核减免金额是否与预期一致。带出的减免金额异常时，多数是减免性质代码选错，做一次更正申报即可，不必作废发票。深圳个体户核定征收的，还要留意核定额度与实际经营的匹配；核定额度长期明显低于实际经营规模的，税务机关有权依职权调整定额，届时个体户增值税优惠的计算基数也会随之变化。

申报期限也要盯紧。按季申报的个体户，一、四、七、十月的十五日前要完成上一季度申报，逾期会产生滞纳金并留下信用记录。跨区迁移经营地的，还需要先办结原主管机关的清税手续，再在新辖区申请发票额度。

## 三、免税额度计算的三个误区
谈到免税额度，最常见的第一个误区是把减免当成永久政策。这份文件写明了施行期限，到期后是否延续要以最新公告为准。第二个误区是混淆起征点与免税额度，两者计算基数不同，不能互换使用。第三个误区是不看政策依据，只听口头承诺，等到核查才发现口径不符，补税加滞纳金的代价远高于当初的合规成本。

## 四、个体户增值税优惠的两类常见问题
第一类问题出在开票。开具增值税普通发票的部分可以按规定享受，开具专用发票的部分通常不适用，谈合同时就要把这一点写清楚，避免事后扯皮。已经开具专票又希望适用个体户增值税优惠的，需要按规定追回全部联次后重新开具，流程上要预留时间。

此外，免税与否和是否需要办理税务登记是两件事，不要因为当期不用缴税就放松账务管理，账簿凭证的完整程度，直接决定后续能否顺利解释个体户增值税优惠的适用依据。

第二类问题出在跨期判断。季度中途累计销售额超过免税额度后，超出部分是全额计税，而不是只对超出部分计税，很多创业者在这里吃亏。临近额度时可以与客户协商开票节奏，但不得以虚构拆分、体外循环的方式推迟申报义务，稽查追溯期通常长达数年。

政策依据：财政部、税务总局公告2026年第12号，自2026年1月1日起施行。深圳地区的执行细则以深圳市税务局通知为准。实务中每家个体户的经营形态不同，适用口径也会有差别，拿不准的先向主管税务机关确认个体户增值税优惠的执行口径，再决定开票与申报安排，成本远低于事后更正。

#个体户 #小规模纳税人 #深圳注册公司 #财税2026 #个体户核定征收
"""
    ok_case = check(
        good_title, good_digest, good_content,
        kw_main="个体户增值税优惠",
        kw_long="深圳个体户增值税优惠,小规模纳税人申报,免税额度,核定征收,电子税务局代开",
        region="深圳", year="2026",
    )

    bad_title = "紧急通知！不看后悔！2026年避税新方法，包过税务筹划包过100%通过"
    bad_case = check(
        bad_title,
        "速看，内部消息",
        "个体户增值税优惠的解读如下。\n简单说一下就行。",
        kw_main="个体户增值税优惠",
        kw_long="深圳个体户,免税",
        region="深圳", year="2026",
    )

    for name, res in (("[合格样本]", ok_case), ("[不合格样本]", bad_case)):
        print("=" * 60)
        print(name, "score=%d level=%s hard_fail=%s" % (res["score"], res["level"], res["hard_fail"]))
        print("summary:", res["summary"])
        for it in res["items"][:5]:
            print("  %s %s pass=%s | %s | %s" % (it["id"], it["name"], it["pass"], it["msg"], it["suggest"]))

    # 健壮性：空值与异常入参
    edge = check(None, "", None, "", kw_long=None, region=None, year=None)
    print("=" * 60)
    print("[空值样本] score=%d level=%s item_count=%d" % (edge["score"], edge["level"], len(edge["items"])))
