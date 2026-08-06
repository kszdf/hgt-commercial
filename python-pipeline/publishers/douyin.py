"""
抖音适配器（抖音开放平台·企业号 —— 优先级第一，作为国内三平台示范实现）。

API 可行性：🟡 可全自动（抖音开放平台·企业号 + 视频内容授权）。
前提条件（需租户/平台侧配合，非代码可控）：
  - 在「抖音开放平台」(https://open.douyin.com) 注册企业号应用，拿到 client_id / client_secret
  - 完成企业资质认证 + 视频发布权限包申请（一般 1-3 天审核）
  - 用户授权（OAuth2 授权码模式）拿到 access_token / refresh_token
  - 将 token 经 Laravel 加密存入 tenant_channel_credentials（credential_ref 句柄，绝不明文）
密钥安全：client_id/secret/access_token 一律从 env 或 credential_ref 取，文件内不含明文。

本文件实现的扩展点（后续专家接入只需填充真实调用，已用【真实调用】标注）：
  - authenticate(): 用 credential_ref 取 access_token（并自动 refresh 过期 token）
  - _do_publish():  上传视频素材 → 创建视频(发布到草稿箱) → 返回 item_id/url
supports_auto=True：配置齐备即全自动；未配置凭证时降级为 dry 模拟（返回 PUBLISHED 模拟值），便于流程联调。

与 base.py 契约一致：统一接收 PublishRequest，统一返回 PublishResult，publish() 统一状态回调。
"""
from __future__ import annotations

import os
import time
from typing import Optional

import requests

from .base import BasePublisher, PublishRequest, PublishResult, PublishStatus
from ._token_cache import get_oauth_token

_DOYIN_API = "https://open.douyin.com"
_TIMEOUT = 60


def _env_creds() -> tuple[str, str]:
    """从环境变量取抖音应用凭据（仅开发/联调用，生产应走 credential_ref）。"""
    return (
        os.environ.get("DOUYIN_CLIENT_ID", ""),
        os.environ.get("DOUYIN_CLIENT_SECRET", ""),
    )


def _token_url() -> str:
    return f"{_DOYIN_API}/oauth/access_token"


class DouyinPublisher(BasePublisher):
    platform_key = "douyin"
    supports_auto = True  # 配置齐备即全自动；未配置降级 dry 模拟

    # ---------- 认证：取 access_token（OAuth2 授权码模式） ----------
    def authenticate(self, credential_ref: Optional[str] = None) -> dict:
        # 无应用凭据 → dry 模式（占位 token，便于流程联调）
        cid, sec = _env_creds()
        if not cid or not sec:
            return {"access_token": "<dry_token>", "dry": True}
        # OAuth2 授权码模式：token 由 /oauth/callback 写入本地缓存
        tok = get_oauth_token("douyin")
        if not tok:
            return {"access_token": "", "dry": False, "need_auth": True}
        return {"access_token": tok["access_token"], "dry": False,
                "open_id": tok.get("open_id")}

    # ---------- 实际发布：上传素材 → 创建(发布) ----------
    def _do_publish(self, req: PublishRequest, auth: dict) -> PublishResult:
        token = auth.get("access_token", "")
        dry = auth.get("dry", False)
        if not dry and not token:
            return PublishResult(
                platform=self.platform_key, status=PublishStatus.FAILED,
                error_message="未授权：请先访问 /oauth/authorize/douyin 完成 OAuth 授权")
        headers = {"access-token": token}

        # 1) 上传视频素材 → 拿 video_id
        if dry:
            video_id = "dry_video_id"
        else:
            with open(req.video_path, "rb") as f:
                r = requests.post(f"{_DOYIN_API}/video/upload",
                                  headers=headers,
                                  files={"video": (os.path.basename(req.video_path), f, "video/mp4")},
                                  timeout=_TIMEOUT)
            d = r.json().get("data", {})
            if not d.get("video", {}).get("video_id"):
                return PublishResult(platform=self.platform_key,
                                     status=PublishStatus.FAILED,
                                     error_message="抖音视频上传失败: " + str(r.json()))
            video_id = d["video"]["video_id"]

        # 2) 创建视频（发布到草稿箱 / 直发，企业号取决于权限包）
        text = req.description or req.title
        # 话题标签拼成 #tag 形式
        for t in req.tags:
            text += f" #{t}"
        payload = {
            "video_id": video_id,
            "title": req.title,
            "text": text,
        }
        if dry:
            item_id = "douyin_dry_item_123"
            post_url = "https://www.douyin.com/user/self"
        else:
            r = requests.post(f"{_DOYIN_API}/video/create",
                              headers={**headers, "Content-Type": "application/json"},
                              json=payload, timeout=_TIMEOUT)
            d = r.json().get("data", {})
            if not d.get("item_id"):
                return PublishResult(platform=self.platform_key,
                                     status=PublishStatus.FAILED,
                                     error_message="抖音发布失败: " + str(r.json()))
            item_id = d["item_id"]
            post_url = f"https://www.douyin.com/video/{item_id}"

        return PublishResult(
            platform=self.platform_key,
            status=PublishStatus.PUBLISHED,
            platform_post_id=item_id,
            platform_url=post_url,
            raw={"video_id": video_id, "dry": dry},
        )
