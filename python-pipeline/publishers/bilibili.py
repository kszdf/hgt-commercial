"""
B站适配器（预留模块接口示例：官方无稳定自动发布 API，走人工兜底）。

本文件即要求③要求的「独立模块接口」范本，明确约定：
  - 输入参数：沿用 PublishRequest（见 base.py），不自定义入参结构
  - 返回值：   PublishResult，受限时 status=MANUAL_REQUIRED + error_code + error_message
  - 回调机制： publish() 统一在起始回调 PENDING，结束回调 MANUAL_REQUIRED（上层实时感知）
  - 文档注释： 头部已注明 API 可行性、前提条件、本文件扩展点、后续专家接入步骤

后续专家接入真实自动发布时，唯一需改的扩展点：
  1) 填充 authenticate() 取得 B 站调用凭证（如开放平台 client_id/secret 或 cookie 会话）
  2) 填充 _do_publish() 真实上传逻辑（注意风控与频控）
  3) 将类属性 supports_auto 置为 True
上层（registry / 8500 / Laravel 状态板）零改动。
"""
from __future__ import annotations

from typing import Optional

from .base import BasePublisher, PublishRequest, PublishResult, PublishStatus


class BilibiliPublisher(BasePublisher):
    platform_key = "bilibili"
    supports_auto = False  # 声明：当前不支持全自动，需人工在 B 站后台发布

    def authenticate(self, credential_ref: Optional[str]) -> dict:
        # 预留：B 站开放平台 client_id/secret 或 cookie 会话获取
        # 专家接入时在此返回真实凭证字典
        return {}

    def _do_publish(self, req: PublishRequest, auth: dict) -> PublishResult:
        # 预留接口：当前不执行真实上传，返回人工发布指引。
        # 视频文件已就绪（req.video_path），由运营在 B 站创作者中心手动投稿。
        return PublishResult(
            platform=self.platform_key,
            status=PublishStatus.MANUAL_REQUIRED,
            error_code="NO_AUTO_API",
            error_message=(
                "B站暂无稳定自动发布 API；请在 B 站创作者中心手动发布。"
                f"视频文件已就绪：{req.video_path}"
            ),
        )
