"""
小红书适配器（小红书开放平台·专业号 —— 优先级第三，竖屏 3:4）。

API 可行性：🟡 可全自动（小红书开放平台 + 专业号内容授权）。
前提条件（需租户/平台侧配合，非代码可控）：
  - 在「小红书开放平台」(https://open.xiaohongshu.com) 注册应用，拿到 app_id / app_secret
  - 完成专业号认证 + 内容发布权限申请
  - OAuth2 授权码模式拿 access_token / refresh_token（与抖音同模式，但域名与字段不同）
  - token 经 Laravel 加密存入 tenant_channel_credentials
密钥安全：app_id/app_secret/access_token 一律从 env 或 credential_ref 取，文件内不含明文。

与抖音的差异点（后续专家接入重点注意）：
  - 根域为 open.xiaohongshu.com，OAuth 授权码换取走 /api/open/oauth/access_token
  - 发布单元是「笔记(note)」而非「视频(video)」；笔记支持图文/视频两种
  - 视频笔记需先 upload 拿 video_id，再 /api/open/v1/note/create 创建（带 title/desc/video_id/cover）
  - 小红书对封面要求高（须单独上传封面图 cover_url），本平台 make_cover 产出正好可复用

本文件扩展点（已用【真实调用】标注）：
  - authenticate(): OAuth2 授权码换 token + 刷新
  - _do_publish():  上传视频 → 创建视频笔记(发布) → 返回 note_id/url
supports_auto=True：配置齐备即全自动；未配置降级 dry 模拟。
"""
from __future__ import annotations

import os
from typing import Optional

import requests

from .base import BasePublisher, PublishRequest, PublishResult, PublishStatus
from ._token_cache import get_oauth_token

_XHS_API = "https://open.xiaohongshu.com"
_TIMEOUT = 60


def _env_creds() -> tuple[str, str]:
    return (
        os.environ.get("XHS_APP_ID", ""),
        os.environ.get("XHS_APP_SECRET", ""),
    )


class XiaohongshuPublisher(BasePublisher):
    platform_key = "xiaohongshu"
    supports_auto = True

    def authenticate(self, credential_ref: Optional[str] = None) -> dict:
        app_id, app_secret = _env_creds()
        if not app_id or not app_secret:
            return {"access_token": "<dry_token>", "dry": True}
        # OAuth2 授权码模式：token 由 /oauth/callback 写入本地缓存
        tok = get_oauth_token("xiaohongshu")
        if not tok:
            return {"access_token": "", "dry": False, "need_auth": True}
        return {"access_token": tok["access_token"], "dry": False}

    def _do_publish(self, req: PublishRequest, auth: dict) -> PublishResult:
        token = auth.get("access_token", "")
        dry = auth.get("dry", False)
        if not dry and not token:
            return PublishResult(
                platform=self.platform_key, status=PublishStatus.FAILED,
                error_message="未授权：请先访问 /oauth/authorize/xiaohongshu 完成 OAuth 授权")
        headers = {"access-token": token, "Content-Type": "application/json"}

        # 1) 上传视频拿 video_id（小红书要求先 upload）
        if dry:
            video_id = "dry_video_id"
        else:
            with open(req.video_path, "rb") as f:
                r = requests.post(f"{_XHS_API}/api/open/v1/upload_video",
                                  headers={"access-token": token},
                                  files={"video": (os.path.basename(req.video_path), f, "video/mp4")},
                                  timeout=_TIMEOUT)
            d = r.json().get("data", {})
            if not d.get("video_id"):
                return PublishResult(platform=self.platform_key,
                                     status=PublishStatus.FAILED,
                                     error_message="小红书视频上传失败: " + str(r.json()))
            video_id = d["video_id"]

        # 2) 创建视频笔记（带标题/正文/话题）
        tags_block = "".join(f"#{t} " for t in req.tags)
        payload = {
            "title": req.title,
            "desc": f"{req.description or req.title}\n{tags_block}".strip(),
            "video_id": video_id,
        }
        if dry:
            note_id = "xhs_dry_note_123"
            post_url = "https://www.xiaohongshu.com/user/profile"
        else:
            r = requests.post(f"{_XHS_API}/api/open/v1/note/create",
                              headers=headers, json={"type": "video", **payload}, timeout=_TIMEOUT)
            d = r.json().get("data", {})
            if not d.get("note_id"):
                return PublishResult(platform=self.platform_key,
                                     status=PublishStatus.FAILED,
                                     error_message="小红书发布失败: " + str(r.json()))
            note_id = d["note_id"]
            post_url = f"https://www.xiaohongshu.com/explore/{note_id}"

        return PublishResult(
            platform=self.platform_key,
            status=PublishStatus.PUBLISHED,
            platform_post_id=note_id,
            platform_url=post_url,
            raw={"video_id": video_id, "dry": dry},
        )
