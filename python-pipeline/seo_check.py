# -*- coding: utf-8 -*-
"""公众号文章 SEO 校验器（纯规则、零第三方依赖）。

面向微信站内「搜一搜」排名与微信 AI 搜索/元宝引用（GEO）设计，
不涉及百度、谷歌等站外搜索引擎（mp.weixin.qq.com 已被 robots 屏蔽）。

口径以《双号公众号文章规则总汇》为准：第四节（标题与 SEO）、第六节（写作红线）、第八节（lint A-L）。

用法:
    import seo_check
    result = seo_check.check(title, digest, content, kw_main, kw_long, region, year)
"""

import re

# ---------------------------------------------------------------- 词表

# 标题党词。前半段是手册 4.1 明列的标题禁词（lint A 级阻断），后半段是通用标题党套路。
# 「怎么办」必须留着：实测生成标题「个人卡收款，2026年昆山老板被查怎么办」正是靠它拦下的。
CLICKBAIT_WORDS = [
    # 手册 4.1 标题禁词
    "9亿", "数亿", "彻底", "这招", "不好使", "蒙混过关", "红线变了", "重大调整",
    "一文读懂", "深度解析", "大量", "惊呆", "必看", "刷屏", "震惊", "吓死",
    "必须知道", "怎么办", "亏大了", "血亏", "躲坑", "被骗", "雷区",
    # 通用标题党套路
    "紧急通知", "紧急提醒", "不看后悔", "后悔莫及", "国家刚宣布", "国家宣布", "中央宣布",
    "惊天", "重磅", "速看", "赶紧看", "马上看", "最后一天", "即将删除",
    "删前速看", "内部消息", "绝密", "99%的人不知道", "不知道就亏",
    "疯了", "炸锅", "真相曝光", "背后真相", "竟然是这样", "看完秒懂",
    "千万别再", "再不看就没了", "所有人都慌了", "刚刚传来", "深夜突发",
    "突发消息", "网传属实", "官方紧急", "紧急叫停", "最后机会", "仅此一次", "倒计时",
]

# 财税违规词（承诺型 / 诱导型 / 绝对化）
# 注意：列表中的「免征」误伤率较高（政策原文常用语），如策略允许建议移除或改为「承诺免征」。
# 「偷税」「逃税」已移出：两者是法定术语（征管法第 63 条偷税、刑法第 201 条逃税罪），
# 专业文章客观引用法条时必然出现，按单词命中会大量误报；真正要拦的是"教人去偷逃税"的
# 语境，那属于内容层面，交给出稿提示词的合规约束去管，不适合用单词表一刀切。
# 前半段是手册第六节的「严禁自我吹嘘标签」（lint C 级阻断），后半段是承诺/诱导/绝对化。
BANNED_TAX_WORDS = [
    "老法师", "业内公认", "权威", "唯一", "第一人", "顶尖", "最专业", "独家", "老张独家",
    "避税", "合理避税", "包过", "100%通过", "百分百通过", "零风险",
    "绝对安全", "绝对合理", "保证退税", "保证通过", "税务筹划包过", "筹划包过", "免征",
    "全额免", "稳赚", "包退税", "一定退", "一定能退", "无风险", "包下证",
    "万能方案", "秒批秒过", "内部渠道", "包成功", "100%安全", "不用交税", "不交税",
    "完美筹划", "政策漏洞", "钻空子", "阴阳合同", "两套账",
]

# ---------------------------------------------------------------- 阈值

TITLE_OK_MAX = 16          # 标题通过上限（手册 4.1：12-16 字）
TITLE_FAIL_MAX = 20        # 标题硬性上限，超过即判不通过
TITLE_BEST_MIN = 12        # 推荐长度下限
TITLE_BEST_MAX = 16        # 推荐长度上限
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
# 主词全文出现次数（手册 4.2：3-5 次为佳、上限 8 次）。
# 不要用「每千字密度」判：2000 字文章按密度 5-8 会要求 10-16 次，正好踩手册的堆砌降权红线。
KW_TOTAL_OK_MIN = 3
KW_TOTAL_BEST_MAX = 5
KW_TOTAL_FAIL_MAX = 8
DENSITY_FAIL_MIN = 3.0     # 每千字密度仅作 msg 参考，不再参与判定
DENSITY_OK_MIN = 5.0
DENSITY_OK_MAX = 8.0
DENSITY_FAIL_MAX = 12.0
H2_OK_MIN = 3
H2_OK_MAX = 6
H2_FAIL_MAX = 2            # 少于该值不通过
H2_QUESTION_MIN = 2        # 手册 4.3：至少 2 个 H2 是用户会搜的问题/场景
TAG_OK_MIN = 3
TAG_OK_MAX = 5
TAG_HINT_MAX = 6
TAG_LEN_MAX = 10
EXCLAM_BODY_MAX = 1        # 手册 4.1：全文感叹号 ≤1
ORPHAN_MAX = 2             # 手册 K2：段末孤行字数上限
LONG_KW_MAX = 2            # 手册 4.3 第 3 条：单个长尾词正文上限
DRAIN_WORDS_MAX = 1        # 手册 F：强引流词允许上限，超过告警
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

# H2 判定为「搜索型问句」的标志（手册 4.3 第 1 条，搜一搜最大增量）
QUESTION_HINTS = ["？", "?", "吗", "怎么", "如何", "为什么", "多少", "哪些",
                  "啥", "几", "要不要", "多久", "能不能", "会不会"]

# #话题品牌词：搜不到、纯自嗨，手册 4.3 第 2 条明令禁用
BRAND_WORDS = ["慧根堂", "老张", "昆山老张", "财税咨询", "建筑张老师", "老张讲财税", "慧根堂建筑"]

# 手册第八节 F（强引流，>1 次告警）/ J1（诱导分享，**阻断**）/ J4（通用诱导关注）
SHARE_BAIT_WORDS = ["转发朋友圈", "转发到朋友圈", "分享到朋友圈", "集赞", "邀好友", "邀请好友"]
DRAIN_WORDS = ["加微信", "免费获取", "免费领", "扫码", "领取", "私聊", "限时", "速领"]
FOLLOW_WORDS = ["关注我们", "点击关注", "欢迎关注"]
# 手册 J3：留联系方式等于硬广，公众号平台对此敏感，按阻断处理
CONTACT_PATTERNS = (r"微信号\s*[:：]", r"加我微信", r"电话\s*[:：]?\s*1\d{10}")

# 手册第六节数字格式：税率/金额/比例/日期须用阿拉伯数字。
# 只查带单位的显式写法（百分之X、X元、X月X日），宁可漏报也不误伤成语。
# 第一个字符必须是真正的一~十，否则「万元」「千万不要」这类会被误判成汉字数字
# 说明：金额类必须带单位才算，且「块」只在「块钱」两字时才算金额——
# 正文里「这一块业务」「那一部分」的「一块/一块儿」是量词不是钱，单字「块」会严重误报。
# 数量类必须落到百/千/万/亿，否则「十年」「第一」「一律」都会被当成汉字数字。
NUM_FORMAT_PATTERNS = (
    r"百分之[零一二三四五六七八九十百千]+",
    r"[一二三四五六七八九十]{1,3}[百千]?[万亿]?[元钱]",
    r"[一二三四五六七八九十]{1,3}块钱",
    r"[一二三四五六七八九十]{1,3}(?:[百千][万亿]?|[万亿])",
    r"[一二三四五六七八九十]+月[一二三四五六七八九十]+[日号]",
    r"[一二三四五六七八九十]+年[一二三四五六七八九十]+月[一二三四五六七八九十]+[日号]",
)
# 成语与固定搭配里的汉字数字按手册保留，先剔除再匹配
NUM_IDIOM_WHITELIST = ("百分之百", "一分为二", "三令五申", "一元复始", "十万火急",
                       "万无一失", "一日三秋", "千钧一发", "九牛一毛", "十二万分",
                       "十万八千里", "一五一十")

ITEM_NAMES = {
    "R1": "标题长度", "R2": "主词位置", "R3": "主词重复", "R4": "标题句式",
    "R5": "年份词", "R6": "标题党", "R7": "摘要长度", "R8": "摘要含主词",
    "R9": "首段主词", "R10": "主词次数", "R11": "长尾词覆盖", "R12": "H2小标题",
    "R13": "篇幅", "R14": "段落长度", "R15": "话题标签", "R16": "标签格式",
    "R17": "时效标记", "R18": "地域覆盖", "R19": "违规词", "R20": "原创提示",
    "R21": "感叹号", "R22": "Markdown列表", "R23": "诱导与引流", "R24": "孤行",
    "R25": "数字格式", "R26": "长尾单条上限", "R27": "次级词进摘要", "R28": "序号混用",
}

# 进这张表的规则 = 手册里的「阻断级」：命中即 -30 且 level 强制 poor（禁止发布）。
# R21 标题感叹号=lint E，R22 Markdown列表=K1/K3，R23 诱导分享/联系方式=J1/J3，均为 exit 1 项。
HARD_FAIL_RULES = ("R6", "R19", "R21", "R22", "R23")


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
        # 阿拉伯序号「1. / 1、」不再算小标题：手册第六节已把它当 Markdown 有序列表阻断（见 R22）
        if hit:
            clean = re.sub(r"^[#*>\s]+", "", s)
            clean = re.sub(r"<[^>]{1,300}?>", "", clean)
            heads.append(clean.strip())
    return heads


def _count_exclam(text):
    """感叹号数量，全角半角都算。"""
    return _count(text, "!") + _count(text, "！")


def _is_question_h2(text):
    """H2 是否是用户会搜的问题/场景（手册 4.3 第 1 条）。"""
    s = _s(text)
    if not s:
        return False
    if "？" in s or "?" in s:
        return True
    return any(w in s for w in QUESTION_HINTS)


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
    if n >= TITLE_FAIL_MAX:
        return False, "标题 %d 字，已达 %d 字不通过线（手册推荐 %d-%d 字）" % (
            n, TITLE_FAIL_MAX, TITLE_BEST_MIN, TITLE_BEST_MAX), "精简到 %d 字以内，只留一个核心搜索词" % TITLE_OK_MAX
    return None, "标题 %d 字，超过推荐区间" % n, "压缩到 %d-%d 字，删掉修饰成分突出主词" % (TITLE_BEST_MIN, TITLE_BEST_MAX)


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
    # 手册 4.2 按「全文次数」考核核心词，密度只作为参考值展示给运营看
    kw = c["kw_main"]
    if not kw or c["word_count"] <= 0:
        return None, "无主词或正文为空，跳过", ""
    n = c["kw_main_count"]
    d = c["density"]
    tail = "（全文 %d 字，折合 %.2f 次/千字）" % (c["word_count"], d)
    if KW_TOTAL_OK_MIN <= n <= KW_TOTAL_BEST_MAX:
        return True, "核心词全文出现 %d 次，在 %d-%d 次最佳区间%s" % (
            n, KW_TOTAL_OK_MIN, KW_TOTAL_BEST_MAX, tail), ""
    if n > KW_TOTAL_FAIL_MAX:
        return False, "核心词全文出现 %d 次，超过 %d 次上限会触发堆砌降权%s" % (
            n, KW_TOTAL_FAIL_MAX, tail), "删减到 %d-%d 次，多余位置改用同义表述" % (KW_TOTAL_OK_MIN, KW_TOTAL_BEST_MAX)
    if n < KW_TOTAL_OK_MIN:
        return False, "核心词全文仅出现 %d 次，搜一搜抓不到重点%s" % (
            n, tail), "自然补到 %d-%d 次：首段 1 次、H2 里带 1 次、结尾收 1 次" % (KW_TOTAL_OK_MIN, KW_TOTAL_BEST_MAX)
    return None, "核心词全文出现 %d 次，接近 %d 次上限，注意别堆砌%s" % (
        n, KW_TOTAL_FAIL_MAX, tail), "建议回落到 %d-%d 次" % (KW_TOTAL_OK_MIN, KW_TOTAL_BEST_MAX)


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
    q = len([h for h in h2 if _is_question_h2(h)])
    detail = "H2 共 %d 个，其中搜索型问句 %d 个、含长尾词 %d 个" % (n, q, with_kw)
    if n < H2_FAIL_MAX:
        return False, "%s，小标题过少" % detail, "至少写 %d 个二级小标题，并在其中植入长尾词" % H2_OK_MIN
    # 手册 4.3 第 1 条：问句型 H2 是搜一搜最大增量，数量够但没问句同样判不通过
    if q < H2_QUESTION_MIN:
        return False, "%s，搜索型问句不足 %d 个" % (detail, H2_QUESTION_MIN), (
            "把 H2 改写成用户会搜的问题：✅「甲供工程简易计税废止了吗」「清包工还能按3%吗」；"
            "❌「一、政策背景」「二、实务要点」")
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
    # 品牌词优先级最高：这类标签在搜一搜里根本搜不到，属于纯自嗨
    brand = [t for t in tags if any(w in t for w in BRAND_WORDS)]
    if brand:
        return False, "#话题含品牌词：%s（用户不会搜品牌名，进不了话题聚合）" % "、".join(brand[:5]), (
            "换成真实搜索词，如 #%s；参考 ✅#甲供工程 #简易计税 #建筑税务" % (c["kw_main"] or "税务稽查"))
    bad = [t for t in tags if len(t) > TAG_LEN_MAX or " " in t]
    if bad:
        return None, "标签格式不合规：%s" % "、".join(bad[:5]), "单个标签 ≤%d 字且不含空格" % TAG_LEN_MAX
    return True, "标签格式均合规（≤%d 字、无空格、无品牌词）" % TAG_LEN_MAX, ""


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


def _r21(c):
    # 手册 4.1 / lint E：标题感叹号是阻断项；全文感叹号只做警告（≤1）
    title_ex = _count_exclam(c["title"])
    if title_ex > 0:
        return False, "标题含 %d 个感叹号（lint E 阻断项）" % title_ex, "删掉感叹号，用陈述句或问句收尾"
    body_ex = _count_exclam(c["digest"] + c["content"])
    if body_ex > EXCLAM_BODY_MAX:
        return None, "正文感叹号 %d 个，超过 %d 个上限" % (body_ex, EXCLAM_BODY_MAX), "保留至多 %d 个，其余改为句号" % EXCLAM_BODY_MAX
    return True, "标题无感叹号，正文感叹号 %d 个" % body_ex, ""


def _r22(c):
    # 手册第六节 K1/K3 阻断：微信编辑器会把带长数字串/长括号/顿号的内容渲染成空黑点或孤立序号
    # 只匹配行首，且数字后面必须跟空白或汉字，避免误伤「1.5万元」「2026年」「3%」这类行首数字
    bad = []
    for line in (c["content"] or "").splitlines():
        if _search(r"^\s*[-*•](\s+|[\u4e00-\u9fa5])", line):
            bad.append(line.strip())
        elif _search(r"^\s*\d{1,2}[.、](\s+|[\u4e00-\u9fa5])", line):
            bad.append(line.strip())
    if bad:
        head = "；".join(bad[:3])
        return False, "正文有 %d 行 Markdown 列表（微信会渲染成空黑点/孤立序号）：%s" % (len(bad), head), (
            "改纯段落自然语句，用「第一件/第二件」「一是/二是」「首先/其次」连写")
    return True, "无 Markdown 列表项", ""


def _r23(c):
    # 只有 J1 诱导分享与 J3 联系方式是手册阻断项，走 hard_fail；
    # F 强引流词与 J4 诱导关注在手册里是警告级，只提示，不必拦发布。
    scope = c["title"] + "\n" + c["digest"] + "\n" + c["content"]
    hard = [w for w in SHARE_BAIT_WORDS if w in scope]
    for pat in CONTACT_PATTERNS:
        m = _search(pat, scope)
        if m:
            hard.append(m.group(0).strip())
    if hard:
        return False, "命中阻断级诱导/硬广：%s（手册 J1/J3，禁止发布）" % "、".join(hard[:5]), (
            "删除诱导分享与联系方式，改成纯干货输出，引流交给文末固定 CTA")
    soft = []
    for w in DRAIN_WORDS + FOLLOW_WORDS:
        n = _count(scope, w)
        if n > 0:
            soft.append("%s×%d" % (w, n))
    if soft:
        return None, "强引流/诱导关注词：%s（手册 F/J4 要求 ≤%d 次）" % (
            "、".join(soft[:5]), DRAIN_WORDS_MAX), "删除或改成中性表述，如「可在评论区留言」"
    return True, "无诱导分享/强引流/联系方式", ""


def _r24(c):
    # 孤行 = 段末只剩 1-2 字，微信排版里非常扎眼。
    # 本平台的正文是「一行一段」（见 server.py 的 _article_to_html），所以还要额外查
    # 独立成行且只有 1-2 字的短行——它渲染出来同样是一根孤零零的短行。
    # 标签行（#开头）与小标题（##开头）不算，那是正常结构。
    orphans = []
    for p in c["paragraphs"]:
        lines = [l.strip() for l in p.splitlines() if l.strip()]
        if not lines:
            continue
        last = lines[-1]
        if last.startswith("#"):
            continue
        if len(lines) >= 2 or len(lines[0]) <= ORPHAN_MAX:
            if len(last) <= ORPHAN_MAX:
                orphans.append(last)
    if orphans:
        return False, "段末孤行 %d 处：%s" % (len(orphans), "、".join(orphans[:5])), (
            "把末行并入上一行或改写，别让段末只剩 1-2 字")
    return True, "无段末孤行", ""


def _r25(c):
    # 成语/固定搭配先剔除，避免「一分为二」这类被当成中文数字误报
    t = c["plain"]
    for w in NUM_IDIOM_WHITELIST:
        t = t.replace(w, "")
    hits = []
    for pat in NUM_FORMAT_PATTERNS:
        for m in _findall(pat, t):
            if m not in hits:
                hits.append(m)
    if hits:
        return False, "税率/金额/日期用了汉字数字：%s" % "、".join(hits[:5]), (
            "改用阿拉伯数字，如 13%、500万元、3000元、5月31日（成语与固定搭配保留）")
    return True, "数字均为阿拉伯写法", ""


def _r26(c):
    longs = [w for w in c["long_kws"] if w]
    if not longs:
        return None, "未提供长尾词，跳过", "调用时传入逗号分隔的 kw_long"
    over = []
    for w in longs:
        n = _count(c["plain"], w)
        if n > LONG_KW_MAX:
            over.append("%s×%d" % (w, n))
    if over:
        return False, "长尾词超过单条 %d 次上限：%s（手册 4.3 第 3 条，超量降权）" % (
            LONG_KW_MAX, "、".join(over[:5])), "单条长尾词降到 %d 次以内，多余处用同义表述" % LONG_KW_MAX
    return True, "各长尾词均 ≤%d 次" % LONG_KW_MAX, ""


def _r27(c):
    # 手册 4.2 要求摘要埋 1 个次级词。签名不变，取 kw_long 逗号分隔的第 1 个词当次级词。
    longs = [w for w in c["long_kws"] if w]
    if not longs:
        return None, "未提供长尾词，无次级词可校验", "把次级词放在 kw_long 第 1 位"
    sec = longs[0]
    if sec in c["digest"]:
        return True, "摘要含次级词「%s」" % sec, ""
    return False, "摘要未出现次级词「%s」（取 kw_long 第 1 个词）" % sec, (
        "把「%s」自然写进摘要一句，搜一搜摘要位才有抓手" % sec)


def _r28(c):
    # 中文序号与阿拉伯序号并存 = 手册 K4，微信里会显得体例混乱
    cn = _search(r"(?:^|\s)[一二三四五六七八九十]{1,3}、", c["plain"], re.M)
    ar = _search(r"(?:^|\s)\d{1,2}[.、](?=\s|[\u4e00-\u9fa5])", c["plain"], re.M)
    if cn and ar:
        return False, "序号混用：中文序号「%s」与阿拉伯序号「%s」并存" % (
            cn.group(0).strip(), ar.group(0).strip()), "统一成一种序号，推荐中文序号（一、二、）"
    return True, "序号用法统一", ""


RULES = (
    ("R1", _r1), ("R2", _r2), ("R3", _r3), ("R4", _r4), ("R5", _r5),
    ("R6", _r6), ("R7", _r7), ("R8", _r8), ("R9", _r9), ("R10", _r10),
    ("R11", _r11), ("R12", _r12), ("R13", _r13), ("R14", _r14), ("R15", _r15),
    ("R16", _r16), ("R17", _r17), ("R18", _r18), ("R19", _r19), ("R20", _r20),
    ("R21", _r21), ("R22", _r22), ("R23", _r23), ("R24", _r24), ("R25", _r25),
    ("R26", _r26), ("R27", _r27), ("R28", _r28),
)


# ---------------------------------------------------------------- 结果构造

def _empty_result(reason):
    stats = {
        "title_len": 0, "digest_len": 0, "word_count": 0,
        "kw_main_in_title_pos": -1, "kw_main_count": 0, "kw_density_per_k": 0.0,
        "h2_count": 0, "h2_with_long_kw": 0, "h2_question": 0, "tag_count": 0,
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
    h2_question = len([h for h in h2 if _is_question_h2(h)])
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
        "kw_main_count": c["kw_main_count"],
        "kw_density_per_k": round(c["density"], 2),
        "h2_count": len(h2),
        "h2_with_long_kw": with_kw,
        "h2_question": h2_question,
        "tag_count": len([t for t in c["tags"] if len(t) <= TAG_LEN_MAX]),
        "year_in_title": bool(c["year"]) and c["year"] in c["title"],
        "region_count": region_count,
        "long_kw_total": len(c["long_kws"]),
        "long_kw_hit": len([w for w in c["long_kws"] if _count(c["plain"], w) > 0]),
        "paragraph_count": len(c["paragraphs"]),
        "clickbait_hits": len(_hits(c["title"], CLICKBAIT_WORDS)),
        "banned_hits": len(_hits(c["title"], BANNED_TAX_WORDS)) + len(_hits(c["digest"], BANNED_TAX_WORDS)) + len(_hits(c["content"], BANNED_TAX_WORDS)),
        "tags": c["tags"],
        "exclam_title": _count_exclam(c["title"]),
        "exclam_body": _count_exclam(c["digest"] + c["content"]),
    }


def check(title, digest, content, kw_main, kw_long="", region="", year=""):
    """纯规则 SEO 校验。

    :param title: 文章标题
    :param digest: 摘要
    :param content: 正文（支持 Markdown / 简单 HTML）
    :param kw_main: 主关键词
    :param kw_long: 长尾词，逗号或空格分隔
    :param region: 地域词，如「昆山」；传「全国」表示不校验地域
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
        # 主词全文次数用纯文本统计，避免把 HTML 属性/Markdown 符号里的重复也算进去
        kw_main_count = _count(plain, kw_main)
        density = (kw_main_count * 1000.0 / word_count) if (kw_main and word_count > 0) else 0.0

        ctx = {
            "title": title, "digest": digest, "content": content,
            "kw_main": kw_main, "kw_long": kw_long, "region": region, "year": year,
            "plain": plain, "word_count": word_count, "density": density,
            "kw_main_count": kw_main_count,
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
    # 合规基线样本：14 字标题、5 个问句型 H2、真实搜索词标签、核心词全文 4 次
    good_title = "昆山个体户增值税优惠怎么享受"
    good_digest = (
        "昆山个体户增值税优惠怎么判断自己够不够格？本文讲清免税额度口径、"
        "申报表减免代码怎么选，附政策依据与常见误区，建议收藏。"
    )
    good_content = """昆山个体户增值税优惠是很多老板开完店后最先问到的问题。2026年政策口径延续后，适用对象与判断方式都有细节调整，本文结合现行文件把判断口径、申报动作和常见误区一次说清。

## 个体户增值税优惠到底优惠在哪？
优惠体现在起征点上。小规模纳税人发生应税销售，月销售额未超过规定标准的，当期不用缴纳增值税，这就是老板口中的免税。判断时看的是不含税销售额，包含开具普通发票的部分和未开票收入，不能只算开票那一部分。专用发票部分不适用，这一点在谈合同阶段就要说清楚。

还有一层容易忽略：享受个体户增值税优惠不等于不用申报。当期销售额没超标准，也要按期完成申报动作，长期不申报会被标记异常，直接影响发票领用和额度调整。

## 昆山个体户怎么判断自己够不够格？
第一步看连续12个月的应税销售额，而不是单月。按月申报的看月销售额，按季申报的看一个季度的累计数，两种口径不能混着用。兼营不同税目业务的，要分别核算、分别判断，核算不清的部分通常按最严口径处理。适用差额征税的项目，按差额后的销售额判断，不能直接用开票金额比较。

第二步看开票类型与申报方式是否一致。在电子税务局代开时，系统会自动判断是否享受额度，超出部分按适用征收率计税。发现带出的减免金额和预期不一致，多数是减免性质代码选错，做一次更正申报即可，不必作废发票。

## 免税额度算错了会有什么后果？
最常见的是跨期踩线。季度中途累计销售额一旦超过额度，超出部分是全额计税，不是只对超出部分计税，很多老板在这里吃亏。临近额度时可以与客户协商开票节奏，但不得以虚构拆分、体外循环的方式推迟申报义务，稽查追溯期通常长达数年。

第二类是核定征收的个体户。核定额度长期明显低于实际经营规模的，税务机关有权依职权调整定额，届时计算基数也会变化。此外，免税与否和要不要办税务登记是两件事，不要因为当期不用缴税就放松账务管理，账簿凭证的完整程度直接决定后续能不能解释清楚。

第三种情况是把起征点和免税额度当成一回事。两者计算基数不同，前者看销售额是否超过规定标准，后者看扣除后的余额，混着用会出现明明没超标准却申报缴税，或者已经超标准还按免税申报的错位。

## 申报表上的减免性质代码怎么选？
进入申报模块后，先在减免税申报明细表里选择对应的减免性质代码，系统会自动带出减免税额。代码选错是最常见的失误，名字相近的两条很容易点混，选完务必核对代码名称与所属期是否匹配。拿不准时把代码名称抄下来问主管税务机关，比事后更正省事。

申报提交后还要复核一次：在申报查询里看减免金额是否正确、所属期是否有误、是否已扣款成功，三步都过了才算完成。更正申报本身不麻烦，但频繁更正会触发风控提示，反而引来不必要的关注。

还有一点：同一笔业务在不同所属期的适用口径可能不同，跨年更正时要按业务发生当期的口径申报，不能一律套用最新标准，这一点在汇算清缴阶段最容易出错。

## 2026年执行口径以哪份文件为准？
政策依据：财政部、税务总局公告2026年第12号，自2026年1月1日起施行。昆山地区的执行细则以昆山市税务局通知为准。实务中每家个体户的经营形态不同，适用口径也会有差别，拿不准的先向主管税务机关确认个体户增值税优惠的执行口径，再决定开票与申报安排，成本远低于事后更正。

顺带提醒申报期限。按季申报的，一、四、七、十月的十五日前要完成上一季度申报，逾期会产生滞纳金并留下信用记录。跨区迁移经营地的，还要先办结原主管机关的清税手续，再在新辖区申请发票额度。

最后提醒一句：昆山的个体户在电子税务局代开时，如果当期已经开了专用发票，这部分不能按免税处理，开票前先和客户确认票种，避免开票之后再追回全部联次重开，白白多跑一趟。

#个体户 #小规模纳税人 #昆山注册公司 #财税2026 #个体户核定征收
"""
    good_kw = "个体户增值税优惠"
    good_kw_long = "昆山个体户,小规模纳税人,免税额度,核定征收,电子税务局代开"
    good_region = "昆山"
    good_year = "2026"

    def run(name, t, d, c, expect=None, absent=None):
        """跑一个用例：expect=期望命中的 fail 规则；absent=期望**不**命中的规则（豁免校验）。"""
        r = check(t, d, c, good_kw, good_kw_long, good_region, good_year)
        st = r["stats"]
        print("=" * 70)
        print("[%s] score=%d level=%s hard_fail=%s" % (name, r["score"], r["level"], r["hard_fail"]))
        print("  fail_ids=%s" % (st["fail_ids"],))
        print("  stats: 标题%d字 正文%d字 核心词%d次 H2=%d(问句%d) 标签%d" % (
            st["title_len"], st["word_count"], st["kw_main_count"], st["h2_count"],
            st["h2_question"], st["tag_count"]))
        if expect is None and absent is None:
            hit = len(st["fail_ids"]) == 0
            print("  判定：期望零 fail 项 → %s" % ("符合" if hit else "不符合"))
        elif absent is not None:
            hit = absent not in st["fail_ids"]
            print("  判定：期望 %s 豁免（不进 fail） → %s" % (absent, "符合" if hit else "不符合"))
        else:
            hit = expect in st["fail_ids"]
            print("  判定：期望 %s 未通过 → %s" % (expect, "符合" if hit else "不符合"))
        for rid in (expect, absent):
            if rid:
                for it in r["items"]:
                    if it["id"] == rid:
                        print("    %s 详情: %s" % (rid, it["msg"]))
        return r

    # 用例1：合规基线（14字标题、5个问句型H2、真实搜索词标签、核心词全文4次）
    run("用例1 合规样本", good_title, good_digest, good_content, None)

    # 用例2：标题 20 字，超过手册 12-16 字区间且达 20 字不通过线
    run("用例2 标题20字", "昆山个体户增值税优惠到底应该怎么享受才对", good_digest, good_content, "R1")

    # 用例3：标题含手册 4.1 禁词「怎么办」（线上真实漏网标题）
    run("用例3 标题含怎么办", "个人卡收款，2026年昆山老板被查怎么办", good_digest, good_content, "R6")

    # 用例4：正文出现 Markdown 无序列表（微信会渲染成空黑点）
    run("用例4 正文含Markdown列表", good_title, good_digest,
        good_content + "\n- 第一件事：先核对开票数据\n- 第二件事：再核对申报口径\n", "R22")

    # 用例5：#话题含品牌词（搜不到，纯自嗨）
    run("用例5 标签含品牌词", good_title, good_digest,
        good_content.replace("#个体户 #小规模纳税人", "#慧根堂财税 #老张说税"), "R16")

    # 用例6：标题带感叹号（lint E 阻断项）
    run("用例6 标题感叹号", good_title + "！", good_digest, good_content, "R21")

    # 用例7：H2 全是「一、政策背景」式陈述句，搜一搜拿不到长尾
    run("用例7 H2非问句", good_title, good_digest,
        good_content.replace("## 个体户增值税优惠到底优惠在哪？", "## 一、政策背景")
        .replace("## 昆山个体户怎么判断自己够不够格？", "## 二、实务要点")
        .replace("## 免税额度算错了会有什么后果？", "## 三、常见误区")
        .replace("## 申报表上的减免性质代码怎么选？", "## 四、申报要点")
        .replace("## 2026年执行口径以哪份文件为准？", "## 五、政策依据"), "R12")

    # 用例8：核心词堆到 9 次，超手册 8 次上限
    run("用例8 核心词堆砌", good_title, good_digest,
        good_content + "\n补充一句：个体户增值税优惠、个体户增值税优惠、个体户增值税优惠、个体户增值税优惠、个体户增值税优惠。", "R10")

    # ---- R23 诱导与引流（阻断级）----
    run("用例9  R23命中 转发朋友圈", good_title, good_digest,
        good_content + "\n觉得有用请转发朋友圈，集赞领资料。", "R23")
    run("用例10 R23豁免 仅1处强引流词", good_title, good_digest,
        good_content + "\n有疑问可以加微信交流。", absent="R23")

    # ---- R24 孤行 ----
    run("用例11 R24命中 段末孤行", good_title, good_digest,
        good_content + "\n这是第一段内容说明。\n补充\n", "R24")
    run("用例12 R24豁免 正常短段落", good_title, good_digest,
        good_content + "\n这是正常的一句补充说明。", absent="R24")

    # ---- R25 数字格式 ----
    run("用例13 R25命中 汉字数字", good_title, good_digest,
        good_content + "\n税率为百分之十三，金额为三百万元，自五月三十一日起施行。", "R25")
    run("用例14 R25豁免 成语", good_title, good_digest,
        good_content + "\n这个政策的影响可以说是一分为二，三令五申也不为过。", absent="R25")

    # ---- R26 长尾单条上限 ----
    run("用例15 R26命中 长尾超2次", good_title, good_digest,
        good_content + "\n免税额度免税额度免税额度的判断要小心。", "R26")
    run("用例16 R26豁免 各词均≤2次", good_title, good_digest, good_content, absent="R26")

    # ---- R27 次级词进摘要 ----
    run("用例17 R27命中 摘要缺次级词", good_title,
        "本文讲清申报表减免代码怎么选，附政策依据与常见误区，建议收藏备用。",
        good_content, "R27")
    run("用例18 R27豁免 摘要含次级词", good_title, good_digest, good_content, absent="R27")

    # ---- R28 序号混用 ----
    run("用例19 R28命中 序号混用", good_title, good_digest,
        good_content + "\n一、政策背景说明内容。\n1. 实务要点内容。", "R28")
    run("用例20 R28豁免 序号统一", good_title, good_digest, good_content, absent="R28")

    # 健壮性：空值与异常入参
    edge = check(None, "", None, "", kw_long=None, region=None, year=None)
    print("=" * 70)
    print("[空值样本] score=%d level=%s item_count=%d" % (edge["score"], edge["level"], len(edge["items"])))
