"""
快手适配器（快手开放平台 open.kuaishou.com —— 当前人工发布）。

API 可行性：🟠 半自动（快手开放平台有视频上传/发布接口，但需企业资质 + 内容发布权限申请）。
当前阶段：未接入，诚实降级为人工发布，绝不用占位 ID 假装发布成功。

后续接入时（真实调用标注）：
  - authenticate(): OAuth2 授权码换 token（或 client_credential）
  - _do_publish():  视频上传 → /openapi/video/create 发布 → 返回 photo_id / url
"""
from __future__ import annotations

from typing import Optional

from .base import BasePublisher, PublishRequest, PublishResult, PublishStatus


class KuaishouPublisher(BasePublisher):
    platform_key = "kuaishou"
    supports_auto = False  # 未接入开放平台，人工发布

    def authenticate(self, credential_ref: Optional[str] = None) -> dict:
        return {}

    def _do_publish(self, req: PublishRequest, auth: dict) -> PublishResult:
        return PublishResult(
            platform=self.platform_key,
            status=PublishStatus.MANUAL_REQUIRED,
            error_code="NO_AUTO_API",
            error_message=(
                "快手当前未接入开放平台，请到「快手创作者平台」手动发表。"
                f"视频文件已就绪：{req.video_path}"
            ),
        )
