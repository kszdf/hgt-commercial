"""
YouTube 适配器（Google YouTube Data API —— 当前人工发布）。

API 可行性：🟢 官方 API 成熟、可全自动，但需 Google OAuth 授权（client 凭据 + 用户授权刷新 token）。
当前阶段：未接入 Google OAuth，因此诚实降级为人工发布，绝不用占位视频 ID 假装发布成功。

后续接入时（真实调用标注）：
  - authenticate(): 用 credential_ref 从密钥库取 Google OAuth2 access_token（自动刷新）
  - _do_publish():   videos.insert 分块上传 → 返回 video_id / youtube URL
"""
from __future__ import annotations

from typing import Optional

from .base import BasePublisher, PublishRequest, PublishResult, PublishStatus


class YouTubePublisher(BasePublisher):
    platform_key = "youtube"
    supports_auto = False  # 诚实：Google OAuth 未接入，人工发布

    def authenticate(self, credential_ref: Optional[str] = None) -> dict:
        return {}

    def _do_publish(self, req: PublishRequest, auth: dict) -> PublishResult:
        return PublishResult(
            platform=self.platform_key,
            status=PublishStatus.MANUAL_REQUIRED,
            error_code="NO_AUTO_API",
            error_message=(
                "YouTube 需 Google OAuth 授权（当前未接入），请到 YouTube Studio 手动上传。"
                f"视频文件已就绪：{req.video_path}"
            ),
        )
