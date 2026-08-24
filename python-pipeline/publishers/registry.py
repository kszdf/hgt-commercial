"""
平台适配器注册表：新增平台只需在此登记，上层按 platform 键分发，零改动。

登记示例（实装抖音后）：
    from .douyin import DouyinPublisher
    _REGISTRY[DouyinPublisher.platform_key] = DouyinPublisher
"""
from __future__ import annotations

from .base import BasePublisher
from .douyin import DouyinPublisher
from .shipinhao import ShipinhaoPublisher
from .xiaohongshu import XiaohongshuPublisher
from .wechat import WechatMpPublisher

_REGISTRY: dict[str, type[BasePublisher]] = {
    # 自动/半自动（OAuth2 或 client_credential），supports_auto=True：
    DouyinPublisher.platform_key: DouyinPublisher,       # 抖音：OAuth 授权码
    XiaohongshuPublisher.platform_key: XiaohongshuPublisher,  # 小红书：OAuth 授权码（笔记）
    WechatMpPublisher.platform_key: WechatMpPublisher,   # 公众号：AppID/AppSecret → 草稿箱
    # 人工（无稳定公开 API / 未接入），supports_auto=False → MANUAL_REQUIRED：
    ShipinhaoPublisher.platform_key: ShipinhaoPublisher,  # 视频号：无公开 API
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
