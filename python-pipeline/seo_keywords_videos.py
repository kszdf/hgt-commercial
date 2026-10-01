# -*- coding: utf-8 -*-
"""
短视频 SEO 词库（HGT 发布包用）。

数据源自项目规则「七支柱搜索词库与SEO埋词规范」：
  - 每条「核心词」更靠前 = 搜索量更大（作为 volume 代理，无实时 API）。
  - 只放用户真实会搜的短语；禁放品牌词（慧根堂/老张说税 等进不了搜索，纯自嗨）。

用法：
  from seo_keywords_videos import resolve_keywords, ALL_TERMS, BRAND_WORDS
  core, secondary = resolve_keywords(subdomain="注册公司")          # 显式子领域
  core, secondary = resolve_keywords(topic="公转私被查")            # 关键词推断
  core, secondary = resolve_keywords(title="私户收款被查怎么办")   # 标题推断
"""

# 子领域 -> {core: [核心搜索词], secondary: [次级搜索词]}
# 顺序即搜索量优先级（越靠前越大）。
KEYWORDS = {
    "注册公司": {
        "core": ["注册公司流程", "注册公司要多久", "个体户还是注册公司", "注册资本认缴",
                  "税务报到", "营业执照下来后要做什么", "新公司零申报"],
        "secondary": ["银行开户基本户", "注册公司需要什么材料", "个体户和公司的区别",
                      "注册资本写多少合适", "公司不经营了要注销吗", "长期零申报后果"],
    },
    "零申报": {
        "core": ["零申报可以多久", "长期零申报有风险吗", "个税汇算清缴", "小规模纳税人免税额度",
                  "发票备注栏怎么填", "企业所得税汇算清缴"],
        "secondary": ["社保基数", "社保入税", "小规模开票超过多少转一般纳税人", "发票抬头开错怎么办",
                      "小微企业所得税优惠", "印花税", "残保金"],
    },
    "稽查": {
        "core": ["税务稽查一般查几年", "税务稽查流程", "收到税务稽查通知书", "进项税转出",
                  "补税滞纳金怎么算", "已证实虚开通知单"],
        "secondary": ["税务行政处罚听证", "陈述申辩", "税务协查函", "稽查查账查什么", "被稽查了怎么补税"],
    },
    "私户收款": {
        "core": ["私户收款被查", "个人账户收货款", "两套账", "其他应收款长期挂账",
                  "股东借款年底不还", "以前年度漏税被查"],
        "secondary": ["往来账挂账怎么处理", "股东借款视同分红", "内外账风险",
                      "历史遗留税务问题", "私户收款怎么整改"],
    },
    "股权": {
        "core": ["股权转让要交哪些税", "股权转让个税", "平价转让股权", "股权代持风险",
                  "公司减资", "股东分红个税"],
        "secondary": ["股权转让印花税", "减资流程", "代持协议有效吗", "一人有限公司",
                      "分红怎么交税", "股权转让20%个税"],
    },
    "电商": {
        "core": ["电商怎么报税", "网店要交税吗", "抖音小店税务", "直播带货怎么交税",
                  "跨境电商税务", "平台数据报送税务"],
        "secondary": ["电商营业执照", "跨境电商核定征收", "刷单被查税", "电商收入申报",
                      "网店不报税的后果"],
    },
    "政策速递": {
        "core": ["最新税收政策", "税收优惠政策", "增值税法", "小规模纳税人优惠", "六税两费减免"],
        "secondary": ["增值税法2026", "小规模免税政策", "残保金优惠", "印花税政策", "政策几月几号施行"],
    },
    "建筑": {
        "core": ["建筑行业税率", "建筑业增值税税率", "甲供材", "异地预缴", "跨县预缴",
                  "分包差额征税", "挂靠风险", "建筑企业进项抵扣"],
        "secondary": ["甲供工程简易计税", "异地施工在哪儿预缴", "分包发票怎么开",
                      "农民工工资专户", "工程发票", "简易计税和一般计税区别", "建筑服务发票备注栏"],
    },
    # 通用兜底（未命中任何子领域时用）
    "__general__": {
        "core": ["公转私怎么合规", "税务风险自查", "老板税务筹划", "财税合规", "税务稽查"],
        "secondary": ["金税四期", "企业税务风险", "老板必懂的税", "财税干货", "税务合规建议"],
    },
}

# 关键词模糊映射（topic/标题里出现这些片段 -> 命中对应子领域）
TOPIC_MAP = {
    "注册": "注册公司", "营业执照": "注册公司", "个体户": "注册公司", "公司": "注册公司",
    "零申报": "零申报", "申报": "零申报", "汇算": "零申报", "发票": "零申报",
    "稽查": "稽查", "被查": "稽查", "补税": "稽查", "滞纳金": "稽查", "虚开": "稽查",
    "私户": "私户收款", "个人卡": "私户收款", "公转私": "私户收款", "两套账": "私户收款",
    "股东借款": "私户收款", "其他应收款": "私户收款", "遗留": "私户收款",
    "股权": "股权", "转让": "股权", "减资": "股权", "分红": "股权", "代持": "股权",
    "电商": "电商", "网店": "电商", "抖音小店": "电商", "直播带货": "电商", "跨境": "电商",
    "政策": "政策速递", "优惠": "政策速递", "增值税法": "政策速递", "六税两费": "政策速递",
    "建筑": "建筑", "甲供": "建筑", "异地预缴": "建筑", "挂靠": "建筑", "分包": "建筑", "工程": "建筑",
}

# 品牌词（一律禁止进 #话题 / 搜索词）
BRAND_WORDS = ["慧根堂", "老张讲财税", "昆山老张讲财税", "老张说税", "财税咨询",
               "建筑张老师", "老张", "昆山老张"]

# 全量真实搜索词集合（用于发布包话题校验）
ALL_TERMS = set()
for _sd in KEYWORDS.values():
    ALL_TERMS.update(_sd["core"])
    ALL_TERMS.update(_sd["secondary"])


def resolve_keywords(subdomain=None, topic=None, title=None):
    """返回 (core_list, secondary_list)。

    优先级：显式 subdomain > topic 关键词命中 > title 关键词命中 > 通用兜底。
    """
    def match(s):
        if not s:
            return None
        for key, sd in KEYWORDS.items():
            if key != "__general__" and key in s:
                return sd
        for kw, sd_key in TOPIC_MAP.items():
            if kw in s:
                return KEYWORDS[sd_key]
        return None

    sd = None
    if subdomain:
        sd = KEYWORDS.get(subdomain)
    if sd is None and topic:
        sd = match(topic)
    if sd is None and title:
        sd = match(title)
    if sd is None:
        sd = KEYWORDS["__general__"]
    return list(sd["core"]), list(sd["secondary"])


def clean_tags(tags, core=None, secondary=None):
    """清洗 #话题：去 # / 去品牌词 / 去重；不足 2 个则用关键词库保底补足真实搜索词。"""
    if core is None:
        core = []
    if secondary is None:
        secondary = []
    seen = set()
    out = []
    for t in tags or []:
        t = str(t).strip().lstrip("#").strip()
        if not t:
            continue
        if any(b in t for b in BRAND_WORDS):
            continue
        if t in seen:
            continue
        seen.add(t)
        out.append(t)
    # 保底：不足 3 个真实搜索词，用关键词库补足
    pool = list(core) + list(secondary)
    for p in pool:
        if len(out) >= 3:
            break
        if p not in seen:
            seen.add(p)
            out.append(p)
    out = out[:6]
    return ["#" + t for t in out]
