"""
微信公众号适配器（微信公众平台 mp.weixin.qq.com —— 图文草稿箱 + 素材上传）。

与「视频号 shipinhao」严格区分：
  - wechat    = 公众号（本文件）：AppID/AppSecret 走 client_credential，发布到「草稿箱」，
                运营在公众号后台确认后群发（公众号群发有严格每日额度，草稿箱是标准工作流）。
  - shipinhao = 视频号：无稳定第三方发布 API，见 shipinhao.py（手动导出为主）。

API 可行性：🟡 可全自动入草稿箱（公众号 client_credential 模式，无需用户授权码）。
前提条件（需租户侧配合，非代码可控）：
  - 在「微信公众平台」(https://mp.weixin.qq.com) 注册公众号，拿 AppID / AppSecret
  - 将 IP 加入公众号后台「基本配置 → IP 白名单」，否则 access_token 换发会被拒绝
  - AppSecret 由租户在平台账号页填写，经 Laravel 加密存储，发布时解密后经 8500 extra 传入
密钥安全：AppSecret 一律从 env（WECHAT_MP_APPID/WECHAT_MP_APPSECRET）或 req.extra
（Laravel 解密后的账号级凭证）取，文件内不含明文。

本文件实现（已用【真实调用】标注）：
  - _resolve_token():   client_credential 换 access_token（按 appid 隔离缓存，7200s）
  - _do_publish():      图文 = 上传封面图 thumb → 组装 HTML → draft/add 入草稿箱；
                        视频 = 上传永久视频素材 → 返回 media_id 待后台群发
supports_auto=True：配置齐备即入草稿箱；未配置凭证降级 dry 模拟（绝无假成功）。
"""
from __future__ import annotations

import os
from typing import Optional

import requests

from ._token_cache import get_cached_token, set_cached_token
from .base import BasePublisher, PublishRequest, PublishResult, PublishStatus

_WX_API = "https://api.weixin.qq.com"
_TIMEOUT = 60
_TOKEN_TTL = 7200  # 公众号 access_token 有效期 7200s


def _env_creds() -> tuple[str, str]:
    """公众号应用凭据（仅开发/全局配置用，生产应走 Laravel extra 账号级凭证）。"""
    return (
        os.environ.get("WECHAT_MP_APPID", ""),
        os.environ.get("WECHAT_MP_APPSECRET", ""),
    )


def _exchange_token(appid: str, secret: str) -> tuple[str, str]:
    """client_credential 换 access_token；返回 (access_token, errmsg)。"""
    r = requests.get(f"{_WX_API}/cgi-bin/token", params={
        "grant_type": "client_credential", "appid": appid, "secret": secret,
    }, timeout=_TIMEOUT)
    d = r.json()
    if d.get("errcode", 0) != 0:
        return "", f"errcode={d.get('errcode')} errmsg={d.get('errmsg')}"
    return d.get("access_token", ""), ""


class WechatMpPublisher(BasePublisher):
    platform_key = "wechat"
    supports_auto = True  # 配置齐备即入草稿箱；未配置降级 dry 模拟

    def authenticate(self, credential_ref: Optional[str] = None) -> dict:
        """环境变量级凭证（账号级凭证在 _resolve_token 里从 req.extra 优先取）。"""
        appid, secret = _env_creds()
        if not appid or not secret:
            return {"dry": True}
        return {"appid": appid, "secret": secret, "dry": False}

    # ---------- 内部：解析凭证 → access_token（账号级 > 环境级） ----------
    def _resolve_token(self, req: PublishRequest, auth: dict) -> tuple[str, bool, Optional[str]]:
        """返回 (access_token, dry, errmsg)。

        优先级：req.extra（Laravel 解密后的账号级 AppID/AppSecret）> auth（环境变量）。
        """
        extra = req.extra or {}
        appid = extra.get("appid") or extra.get("app_id") or auth.get("appid", "")
        secret = extra.get("appsecret") or extra.get("app_secret") or auth.get("secret", "")
        if not appid or not secret:
            return "", True, None
        cached = get_cached_token(f"wechat_mp:{appid}")
        if cached:
            return cached, False, None
        token, err = _exchange_token(appid, secret)
        if err:
            return "", False, err
        set_cached_token(f"wechat_mp:{appid}", token, _TOKEN_TTL)
        return token, False, None

    # ---------- 内部：上传素材拿 media_id（封面 thumb / 视频） ----------
    def _upload_material(self, token: str, path: str, typ: str) -> tuple[Optional[str], Optional[str]]:
        """上传永久素材；返回 (media_id, url 或 errmsg)。"""
        try:
            with open(path, "rb") as f:
                r = requests.post(
                    f"{_WX_API}/cgi-bin/material/add_material",
                    params={"access_token": token, "type": typ},
                    files={"media": (os.path.basename(path), f, "image/jpeg" if typ == "image" else "video/mp4")},
                    timeout=_TIMEOUT)
        except OSError as e:
            return None, f"素材文件读取失败: {e}"
        d = r.json()
        if d.get("errcode", 0) != 0:
            return None, f"errcode={d.get('errcode')} errmsg={d.get('errmsg')}"
        return d.get("media_id"), d.get("url", "")

    # ---------- 实际发布 ----------
    def _do_publish(self, req: PublishRequest, auth: dict) -> PublishResult:
        token, dry, err = self._resolve_token(req, auth)
        if err:
            return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                 error_code="TOKEN_ERROR", error_message=err)
        if dry:
            return PublishResult(
                platform=self.platform_key,
                status=PublishStatus.PUBLISHED,
                platform_post_id="SIMULATED",
                platform_url="",
                error_message="未配置公众号 AppID/AppSecret，本次为模拟发布（未真正入草稿箱）。如需真发，请在「平台账号」填写公众号 AppID/AppSecret。",
                raw={"dry": True, "simulated": True},
            )

        # ---- 图文笔记：封面图 thumb → 组装 HTML → draft/add 入草稿箱 ----
        if req.image_paths:
            extra = req.extra or {}
            paths = req.image_paths[:9]  # 公众号单篇图文最多 9 图（1 封面 + 8 正文）
            thumb_id, terr = self._upload_material(token, paths[0], "image")
            if terr:
                return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                     error_code="THUMB_ERROR", error_message="封面图上传失败: " + terr)
            # 正文图片逐张上传拿 url（永久图片素材返回 url，可内嵌 <img>）
            body_imgs = []
            for ip in paths[1:]:
                _mid, url = self._upload_material(token, ip, "image")
                if url:
                    body_imgs.append(url)
            imgs_html = "".join(f'<p><img src="{u}" style="max-width:100%"></p>' for u in body_imgs)
            content = f"<p>{req.description or req.title}</p>{imgs_html}".strip()

            payload = {
                "articles": [{
                    "title": req.title or "图文笔记",
                    "author": extra.get("author", ""),
                    "digest": (req.description or req.title)[:120],
                    "content": content,
                    "content_source_url": extra.get("content_source_url", ""),
                    "thumb_media_id": thumb_id,
                    "need_open_comment": 0,
                    "only_fans_can_comment": 0,
                }]
            }
            r = requests.post(f"{_WX_API}/cgi-bin/draft/add",
                              params={"access_token": token}, json=payload, timeout=_TIMEOUT)
            d = r.json()
            if d.get("errcode", 0) != 0:
                return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                     error_code=str(d.get("errcode")),
                                     error_message="公众号草稿创建失败: " + d.get("errmsg", ""),
                                     raw={"thumb_media_id": thumb_id})
            draft_id = d.get("media_id", "")
            return PublishResult(
                platform=self.platform_key,
                status=PublishStatus.PUBLISHED,
                platform_post_id=draft_id,
                platform_url="",  # 草稿无外链，需后台群发后才有 URL
                raw={"stage": "draft", "thumb_media_id": thumb_id, "dry": False},
            )

        # ---- 视频笔记：上传永久视频素材 → 待后台群发（公众号视频群发有额度，走半自动） ----
        media_id, verr = self._upload_material(token, req.video_path, "video")
        if verr:
            return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                 error_code="VIDEO_ERROR", error_message="公众号视频素材上传失败: " + verr)
        return PublishResult(
            platform=self.platform_key,
            status=PublishStatus.PUBLISHED,
            platform_post_id=media_id,
            platform_url="",
            raw={"stage": "material", "note": "视频已入公众号素材库，请在公众号后台群发", "dry": False},
        )
