"""
视频号适配器（微信视频号 API —— 优先级第二）。

API 可行性：🟡 可全自动（微信开放平台 / 公众平台 + 视频号权限）。
前提条件（需租户/平台侧配合，非代码可控）：
  - 注册微信开放平台应用 或 微信公众平台服务号/订阅号（appid / appsecret）
  - 申请视频号相关权限（channels 接口权限），完成企业资质认证
  - 用户授权拿 access_token（视频号接口走公众平台 access_token，非开放平台 openid 体系）
  - token 经 Laravel 加密存入 tenant_channel_credentials
密钥安全：appid/appsecret/access_token 一律从 env 或 credential_ref 取，文件内不含明文。

与抖音的差异点（后续专家接入重点注意）：
  - 视频号 token 用 GET /cgi-bin/token（client_credential 模式，appid+secret），不是 OAuth2 授权码换 token
  - 视频素材先走 /cgi-bin/material/add_material 拿 media_id，再走 /channels/video/create 发布
  - 接口根域为 api.weixin.qq.com 而非 open.douyin.com

本文件扩展点（已用【真实调用】标注）：
  - authenticate(): 取/刷新微信 access_token
  - _do_publish():  上传视频素材 → 视频号创建发布 → 返回 media_id/url
supports_auto=True：配置齐备即全自动；未配置降级 dry 模拟。
"""
from __future__ import annotations

import os
from typing import Optional

import requests

from ._token_cache import get_cached_token, set_cached_token
from .base import BasePublisher, PublishRequest, PublishResult, PublishStatus

_WX_API = "https://api.weixin.qq.com"
_TIMEOUT = 60


def _env_creds() -> tuple[str, str]:
    return (
        os.environ.get("WECHAT_APPID", ""),
        os.environ.get("WECHAT_APPSECRET", ""),
    )


class ShipinhaoPublisher(BasePublisher):
    platform_key = "shipinhao"
    supports_auto = True

    def authenticate(self, credential_ref: Optional[str]) -> dict:
        appid, secret = _env_creds()
        if not appid or not secret:
            return {"access_token": "<dry_token>", "dry": True}
        # 真实模式：client_credential 换 token（视频号无需用户授权码，appid+secret 直换）
        # 带本地缓存，access_token 有效期 7200s，提前 5 分钟过期复用
        cached = get_cached_token("wechat")
        if cached:
            return {"access_token": cached, "dry": False}
        r = requests.get(f"{_WX_API}/cgi-bin/token", params={
            "grant_type": "client_credential", "appid": appid, "secret": secret}, timeout=_TIMEOUT)
        d = r.json()
        if d.get("errcode", 0) != 0:
            raise RuntimeError(f"微信 access_token 获取失败: errcode={d.get('errcode')} errmsg={d.get('errmsg')}")
        set_cached_token("wechat", d["access_token"], d.get("expires_in", 7200))
        return {"access_token": d["access_token"], "dry": False}

    def _do_publish(self, req: PublishRequest, auth: dict) -> PublishResult:
        token = auth.get("access_token", "")
        dry = auth.get("dry", False)

        # 1) 上传视频素材拿 media_id（视频号发布前必须先落素材库）
        if dry:
            media_id = "dry_media_id"
        else:
            try:
                with open(req.video_path, "rb") as f:
                    r = requests.post(f"{_WX_API}/cgi-bin/material/add_material",
                                      params={"access_token": token, "type": "video"},
                                      files={"media": (os.path.basename(req.video_path), f, "video/mp4")},
                                      timeout=_TIMEOUT)
            except OSError as e:
                return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                     error_code="FILE_ERROR", error_message=f"视频文件读取失败: {e}")
            d = r.json()
            if d.get("errcode", 0) != 0:
                return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                     error_code=str(d.get("errcode")), error_message=d.get("errmsg"))
            media_id = d.get("media_id")
            if not media_id:
                return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                     error_code="NO_MEDIA_ID", error_message="素材上传成功但未返回 media_id")

        # 2) 视频号创建并发布（需视频号内容权限；封面暂用视频首帧，指定封面需先上传封面图拿 url）
        payload = {
            "title": req.title,
            "description": req.description or req.title,
            "media_id": media_id,
        }
        if dry:
            item_id = "shipinhao_dry_123"
            post_url = "https://channels.weixin.qq.com"
        else:
            r2 = requests.post(f"{_WX_API}/channels/video/create",
                               params={"access_token": token}, json=payload, timeout=_TIMEOUT)
            d2 = r2.json()
            if d2.get("errcode", 0) != 0:
                return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                     error_code=str(d2.get("errcode")), error_message=d2.get("errmsg"),
                                     raw={"media_id": media_id})
            # 视频号返回结构：{"item":[{"id":"...","create_time":...}]} 或 {"data":{"item_id":"..."}}
            item = (d2.get("item") or [{}])[0] if d2.get("item") else (d2.get("data") or {})
            item_id = item.get("id") or item.get("item_id") or "shipinhao_item"
            post_url = f"https://channels.weixin.qq.com/mobile/{item_id}" if item_id != "shipinhao_item" else "https://channels.weixin.qq.com"

        return PublishResult(
            platform=self.platform_key,
            status=PublishStatus.PUBLISHED,
            platform_post_id=item_id,
            platform_url=post_url,
            raw={"media_id": media_id, "dry": dry},
        )
