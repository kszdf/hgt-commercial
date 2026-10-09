# -*- coding: utf-8 -*-
"""
ASR 财税术语纠正表（全平台共用）。

whisper-small 对财税行业词误听严重，凡走本地 ASR 的链路（深度拆解 / 素材精剪 /
对标扒稿）都应在此统一纠正，避免各模块各修一份导致漂移。

用法：
    from asr_terms import apply_term_fix
    text = apply_term_fix(raw_text)

★ 新增误听只改本文件 TERM_FIX，一处生效、处处生效。
"""
import re

# 财税高频误听纠正（bad → good）。键为误听串，值为正确写法。
TERM_FIX = {
    "同分红": "视同分红", "支纳金": "滞纳金", "进向税": "进项税",
    "销向税": "销项税", "虚开法票": "虚开发票", "补购税": "补个税",
    "私户收款": "私户收款", "公转思": "公转私", "视同销受": "视同销售",
    # 2026-10-09 实测补充（whisper small 高频误听）
    "至那金": "滞纳金", "滞那金": "滞纳金", "公司分明": "公私分明",
    "货款达到": "货款打到", "公账私账": "公账私账",
    # 2026-10-09 素材精剪实测补充（footage-edit 转写）
    "代购个税": "代扣个税", "代购缴个税": "代扣缴个税",
    "钱合同": "签合同", "收盈液款": "收营业款", "盈液款": "营业款",
    "盈液": "营业", "核定征受": "核定征收", "一般纳说人": "一般纳税人",
    "小规摸纳税人": "小规模纳税人", "增直税": "增值税", "所得睡": "所得税",
    "汇算清交": "汇算清缴", "留抵退睡": "留抵退税", "查账征受": "查账征收",
}


def apply_term_fix(text):
    """对一段转写文本施加术语纠正（幂等，重复施加无副作用）。"""
    if not text:
        return text
    s = text
    for bad, good in TERM_FIX.items():
        if bad in s:
            s = s.replace(bad, good)
    # 数字口语归一化：「百分之二十」→「20%」（仅限常见整数比例，避免误伤语境）
    _CN_NUM = {"零": 0, "一": 1, "二": 2, "两": 2, "三": 3, "四": 4, "五": 5,
               "六": 6, "七": 7, "八": 8, "九": 9, "十": 10}
    def _pct(m):
        w = m.group(1)
        if len(w) == 1:
            v = _CN_NUM.get(w)
            return ("%d%%" % (v * 10)) if v else m.group(0)
        if w.startswith("十") and len(w) == 2:
            v = _CN_NUM.get(w[1])
            return ("%d%%" % (10 + v)) if v is not None else m.group(0)
        if len(w) == 2 and w[1] == "十":
            v = _CN_NUM.get(w[0])
            return ("%d%%" % (v * 10)) if v else m.group(0)
        if len(w) == 3 and w[1] == "十":
            a, b = _CN_NUM.get(w[0]), _CN_NUM.get(w[2])
            if a is not None and b is not None:
                return "%d%%" % (a * 10 + b)
        return m.group(0)
    s = re.sub(r"百分之([一二三四五六七八九十两零]{1,3})", _pct, s)
    return s
