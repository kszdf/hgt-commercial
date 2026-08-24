"""
视频号适配器（微信视频号 —— 人工发布）。

API 可行性：🔴 无稳定第三方发布 API。
微信视频号未开放「第三方上传/发布视频」的公开接口（「视频号助手」仅官方 App / 网页端可用），
任何声称可全自动发布视频号的接口均非官方，存在封号风险。

本平台处理方式（诚实降级）：
  - supports_auto=False：一键发布时返回 MANUAL_REQUIRED（待人工发布）；
  - 成片在「发布助手」下载后，到「视频号助手」App / 网页手动发表。
"""
from __future__ import annotations

from typing import Optional

from .base import BasePublisher, PublishRequest, PublishResult, PublishStatus


class ShipinhaoPublisher(BasePublisher):
    platform_key = "shipinhao"
    supports_auto = False  # 无公开 API，人工发布

    def authenticate(self, credential_ref: Optional[str] = None) -> dict:
        return {}

    def _do_publish(self, req: PublishRequest, auth: dict) -> PublishResult:
        return PublishResult(
            platform=self.platform_key,
            status=PublishStatus.MANUAL_REQUIRED,
            error_code="NO_AUTO_API",
            error_message=(
                "视频号暂无开放发布 API，请到「视频号助手」App 手动发表。"
                f"视频文件已就绪：{req.video_path}"
            ),
        )
