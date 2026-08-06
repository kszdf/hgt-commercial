"""
平台适配器注册表：新增平台只需在此登记，上层按 platform 键分发，零改动。

登记示例（实装抖音后）：
    from .douyin import DouyinPublisher
    _REGISTRY[DouyinPublisher.platform_key] = DouyinPublisher
"""
from __future__ import annotations

from .base import BasePublisher
from .youtube import YouTubePublisher
from .bilibili import BilibiliPublisher
from .douyin import DouyinPublisher
from .shipinhao import ShipinhaoPublisher
from .xiaohongshu import XiaohongshuPublisher

_REGISTRY: dict[str, type[BasePublisher]] = {
    YouTubePublisher.platform_key: YouTubePublisher,
    BilibiliPublisher.platform_key: BilibiliPublisher,
    # 抖音 / 视频号 / 小红书：已实装适配器骨架（OAuth2 + 发布流程），supports_auto=True。
    # 真正全自动需租户在对应开放平台注册应用 + 企业资质 + 授权后填入凭证（见各适配器文件头）。
    # 未配置凭证时降级为 dry 模拟（返回 PUBLISHED 模拟值），便于流程联调。
    DouyinPublisher.platform_key: DouyinPublisher,       # 优先级 1
    ShipinhaoPublisher.platform_key: ShipinhaoPublisher,  # 优先级 2
    XiaohongshuPublisher.platform_key: XiaohongshuPublisher,  # 优先级 3（3:4 竖屏）
}


def get_publisher(platform: str, status_callback=None) -> BasePublisher:
    """按规范平台键取适配器实例。

    Args:
        platform:       规范键（见 PLATFORMS 注册表）
        status_callback: 可选状态回调，透传给适配器
    Returns:
        BasePublisher 实例
    Raises:
        KeyError: 平台未注册
    """
    cls = _REGISTRY.get(platform)
    if not cls:
        raise KeyError(f"未注册的平台适配器: {platform}")
    return cls(status_callback=status_callback)


def supported_platforms() -> list[str]:
    """已注册平台键列表。"""
    return list(_REGISTRY.keys())
