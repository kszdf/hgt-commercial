# python-pipeline/platform_specs.py
# PLATFORM_REGISTRY_SYNC
# 平台注册表（单一事实源 · 8500 侧权威），与 Laravel config/platforms.php 逐字段同步。
# 任一平台增删 / 规格变更，两处必须同时改（搜索 PLATFORM_REGISTRY_SYNC 定位）。

PLATFORM_SPECS = {
    "douyin":      {"label": "抖音",   "spec": (1080, 1920), "topic": True,  "publish": "api"},
    "shipinhao":   {"label": "视频号", "spec": (1080, 1920), "topic": True,  "publish": "manual"},
    "xiaohongshu": {"label": "小红书", "spec": (1080, 1440), "topic": True,  "publish": "api"},
}


def spec(key):
    """返回 (宽, 高) 或 None。"""
    return PLATFORM_SPECS.get(key, {}).get("spec")


def label(key):
    return PLATFORM_SPECS.get(key, {}).get("label")


def topic_keys():
    """进入选题子集的平台 key 列表。"""
    return [k for k, v in PLATFORM_SPECS.items() if v.get("topic")]


if __name__ == "__main__":
    for k, v in PLATFORM_SPECS.items():
        print(f"{k:12s} {v['label']:8s} spec={v['spec']} topic={v['topic']} publish={v['publish']}")
