# -*- coding: utf-8 -*-
"""品牌与人设默认值（单一配置源）。

历史背景：平台早期是自用工具，"昆山老张讲财税" / "张老师" / "深耕财税20多年"
散落在 server.py、chat_orchestrator.py、capabilities.py 等多处硬编码。

对外给财税同行使用时，同行生产的内容必须挂同行自己的品牌与人设，
否则"同行获客、客户加到老张微信"——等于商业模式的自我否定。

因此收敛到本模块：**所有引用点一律读这里的常量，不再写字面量**。

对外切换 SOP（无需改代码，改完重启 8500 即生效）：
    .env / model_keys.env 里设置
        HGT_BRAND_FALLBACK=      # 留空则用中性占位"本机构"
        HGT_EXPERT_NAME=         # 留空则用"本机构顾问"
        HGT_EXPERT_YEARS=        # 留空则不强调年限
    同时各租户在租户设置里填 ip_name，生成链路优先用租户值。
"""

import os

# 品牌兜底（租户未配置 ip_name 时使用）
BRAND_FALLBACK = os.environ.get("HGT_BRAND_FALLBACK", "昆山老张讲财税") or "本机构"

# 专家人设称谓
EXPERT_NAME = os.environ.get("HGT_EXPERT_NAME", "张老师") or "本机构顾问"

# 专家年限表述（留空则不向模型注入年限硬约束）
EXPERT_YEARS = os.environ.get("HGT_EXPERT_YEARS", "深耕财税20多年") or ""


def expert_years_clause():
    """生成年限硬约束提示词片段；未配置年限时返回空串（不注入）。"""
    if not EXPERT_YEARS:
        return ""
    return (
        "- **人设年限(硬约束)**：%s的从业年限固定表述为「%s」，"
        "严禁出现其他年限表述（如「30年」「三十年」）。\n" % (EXPERT_NAME, EXPERT_YEARS)
    )
