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
  - _resolve_token():   client_credential 换 access_token（按 appid 隔离缓存，7200s，加锁防互踢）
  - _do_publish():      图文 = 上传封面图 thumb → 组装 HTML（含正文图片）→ draft/add 入草稿箱；
                        视频 = 上传永久视频素材 → 返回 media_id 待后台群发
  - publish_draft():    群发（直接发表）指定草稿 → freepublish/submit → 取 article_url
  - _get_article():     查询发表状态 / 文章链接（freepublish/get）
  - _get_article_detail(): 取文章明细（freepublish/getarticle）
  - update_draft() / delete_draft(): 草稿箱改稿 / 删稿（draft/update、draft/delete）
supports_auto=True：配置齐备即入草稿箱；未配置凭证降级 dry 模拟（返回 SIMULATED，绝无假成功）。

合规提醒（微信公众平台运营规范 3.27）：禁止非真人自动化创作与脚本托管批量连续发布。
故 publish_draft() 设计为「单次单篇、必须显式调用」，并返回完整结果供上层做限流与留痕，
不得在上层包装成循环批量群发。
"""
from __future__ import annotations

import os
import re
import threading
from typing import Optional

import requests

from ._token_cache import get_cached_token, set_cached_token
from .base import BasePublisher, PublishRequest, PublishResult, PublishStatus

_WX_API = "https://api.weixin.qq.com"
_TIMEOUT = 60
_TOKEN_TTL = 7200  # 公众号 access_token 有效期 7200s
# token 失效/过期的错误码：命中时清缓存重换一次再重试（最多 1 次，避免无限循环）
_TOKEN_INVALID_CODES = (40001, 42001)

# 正文里的图片：Markdown 写法与 HTML 写法
_IMG_MD_RE = re.compile(r"!\[[^\]]*\]\(\s*([^)\s]+)\s*\)")
_IMG_HTML_RE = re.compile(r"<img[^>]+src=[\"']([^\"']+)[\"'][^>]*>", re.IGNORECASE)
_MD_LINK_RE = re.compile(r"\[([^\]]*)\]\([^)]*\)")


def _env_creds() -> tuple[str, str]:
    """公众号应用凭据（仅开发/全局配置用，生产应走 Laravel extra 账号级凭证）。"""
    return (
        os.environ.get("WECHAT_MP_APPID", ""),
        os.environ.get("WECHAT_MP_APPSECRET", ""),
    )


def _exchange_token(appid: str, secret: str) -> tuple[str, str]:
    """client_credential 换 access_token；返回 (access_token, errmsg)。

    网络异常（超时/连接错误）一律转成错误文案返回，不向上抛，避免把 requests 异常
    穿透到发布流程里变成 EXCEPTION 而丢失 TOKEN_ERROR 语义。
    """
    try:
        r = requests.get(f"{_WX_API}/cgi-bin/token", params={
            "grant_type": "client_credential", "appid": appid, "secret": secret,
        }, timeout=_TIMEOUT)
        d = r.json()
    except Exception as exc:  # noqa: BLE001  网络/解析异常统一降级为错误文案
        return "", f"换发 access_token 失败（网络异常）: {exc}"
    if d.get("errcode", 0) != 0:
        return "", f"errcode={d.get('errcode')} errmsg={d.get('errmsg')}"
    return d.get("access_token", ""), ""


def _as_int(value, default: int) -> int:
    """把 extra 里的开关值安全转成 0/1；非法或缺失时回退 default。"""
    try:
        return 1 if int(value) else 0
    except (TypeError, ValueError):
        return default


def _plain_text(text: str) -> str:
    """去掉 Markdown 符号、图片/链接语法与多余空白，产出适合做摘要的纯文本。"""
    t = _IMG_MD_RE.sub("", text or "")
    t = _MD_LINK_RE.sub(r"\1", t)
    t = re.sub(r"[*`#>~_]", "", t)
    t = re.sub(r"\s+", " ", t)
    return t.strip()


def _pick_article_url(data: dict) -> str:
    """从 freepublish/get 或 getarticle 的响应里尽量取出文章外链；取不到返回空串。"""
    if not isinstance(data, dict):
        return ""
    if data.get("article_url"):
        return str(data["article_url"])
    # 微信不同接口/版本的外链位置不统一：可能在顶层、article_detail 里，或在 item/news_item 数组中
    for holder in (data, data.get("article_detail") or {}):
        if not isinstance(holder, dict):
            continue
        if holder.get("article_url"):
            return str(holder["article_url"])
        for key in ("item", "news_item"):
            items = holder.get(key) or []
            if isinstance(items, list) and items and isinstance(items[0], dict):
                if items[0].get("article_url"):
                    return str(items[0]["article_url"])
    return ""


class WechatMpPublisher(BasePublisher):
    platform_key = "wechat"
    supports_auto = True  # 配置齐备即入草稿箱；未配置降级 dry 模拟

    # 跨线程锁：保证 get→exchange→set 三步原子，避免并发换 token 互踢（微信语义：
    # 重复获取会使上一个 token 在 5 分钟内失效，表现为随机 40001 invalid credential）
    _TOKEN_LOCK = threading.RLock()

    def authenticate(self, credential_ref: Optional[str] = None) -> dict:
        """环境变量级凭证（账号级凭证在 _resolve_token 里从 req.extra 优先取）。"""
        appid, secret = _env_creds()
        if not appid or not secret:
            return {"dry": True}
        return {"appid": appid, "secret": secret, "dry": False}

    # ---------- 内部：凭证取值（账号级 > 环境级） ----------
    @staticmethod
    def _creds_from(extra: dict, auth: dict) -> tuple[str, str]:
        """取 AppID/AppSecret；优先级 extra（Laravel 解密的账号级）> auth（环境变量）> env。"""
        extra = extra or {}
        auth = auth or {}
        env_appid, env_secret = _env_creds()
        appid = extra.get("appid") or extra.get("app_id") or auth.get("appid", "") or env_appid
        secret = extra.get("appsecret") or extra.get("app_secret") or auth.get("secret", "") or env_secret
        return appid, secret

    # ---------- 内部：解析凭证 → access_token（账号级 > 环境级） ----------
    def _resolve_token(self, req: PublishRequest, auth: dict) -> tuple[str, bool, Optional[str]]:
        """返回 (access_token, dry, errmsg)。

        优先级：req.extra（Laravel 解密后的账号级 AppID/AppSecret）> auth（环境变量）。
        """
        appid, secret = self._creds_from(req.extra or {}, auth)
        return self._token_for(appid, secret)

    def _token_for(self, appid: str, secret: str) -> tuple[str, bool, Optional[str]]:
        """按 appid 取 access_token（带锁 + 双重检查，防并发互踢）。

        check 缓存 → 换发 → 写缓存 三步必须整体原子：并发下若两个线程同时 miss，
        会重复调用 /cgi-bin/token，微信会让前一个 token 在 5 分钟内失效，
        表现为随机 40001 invalid credential。拿到锁后再查一次缓存（double-check），
        避免使用等待期间别的线程刚写入的新 token 被再次覆盖。

        Args:
            appid:  公众号 AppID。
            secret: 公众号 AppSecret。
        Returns:
            (access_token, dry, errmsg)：dry=True 表示未配置凭据（调用方应降级模拟）。
        """
        if not appid or not secret:
            return "", True, None
        key = f"wechat_mp:{appid}"
        with self._TOKEN_LOCK:
            cached = get_cached_token(key)
            if cached:
                return cached, False, None
            token, err = _exchange_token(appid, secret)
            if err:
                return "", False, err
            set_cached_token(key, token, _TOKEN_TTL)
            return token, False, None

    def _resolve_token_from_extra(self, extra: dict) -> tuple[str, bool, Optional[str]]:
        """从 extra（账号级 AppID/AppSecret）或环境变量取 access_token。

        供 publish_draft() / update_draft() / delete_draft() 这类没有 PublishRequest 的
        场景复用带锁的换发逻辑。

        Args:
            extra: 平台专属扩展，可含 appid/app_id 与 appsecret/app_secret。
        Returns:
            (access_token, dry, errmsg)。
        """
        extra = extra or {}
        appid, secret = self._creds_from(extra, {})
        return self._token_for(appid, secret)

    # ---------- 内部：强制重换 token（40001/42001 重试用） ----------
    def _force_refresh_token(self, appid: str, secret: str) -> str:
        """强制换发并覆盖缓存里的 access_token；失败返回空串（调用方放弃重试）。"""
        if not appid or not secret:
            return ""
        with self._TOKEN_LOCK:
            token, err = _exchange_token(appid, secret)
            if err or not token:
                return ""
            set_cached_token(f"wechat_mp:{appid}", token, _TOKEN_TTL)
            return token

    # ---------- 内部：统一 POST 微信接口（含 token 失效重试 1 次） ----------
    def _post(self, endpoint: str, token: str, payload: dict,
              appid: str = "", secret: str = "") -> tuple[dict, Optional[str], str]:
        """POST 微信 JSON 接口，网络异常与 errcode 统一转成错误文案，不向上抛。

        token 失效（errcode 40001/42001）时清缓存重换一次再重试，最多重试 1 次。

        Args:
            endpoint: 接口路径，如 "/cgi-bin/draft/add"。
            token:    当前 access_token。
            payload:  JSON 请求体。
            appid:    可选，传了才启用 token 失效重试。
            secret:   可选，同上。
        Returns:
            (响应 dict, 错误文案, 实际使用的 token)：失败时 dict 为 {}，
            错误文案原样保留微信 errcode/errmsg。
        """
        last_err = ""
        for attempt in range(2):
            try:
                r = requests.post(f"{_WX_API}{endpoint}", params={"access_token": token},
                                  json=payload, timeout=_TIMEOUT)
                d = r.json()
            except (requests.RequestException, ValueError) as exc:
                return {}, f"调用 {endpoint} 失败（网络异常）: {exc}", token
            code = d.get("errcode", 0)
            if code == 0:
                return d, None, token
            last_err = f"errcode={d.get('errcode')} errmsg={d.get('errmsg')}"
            if attempt == 0 and code in _TOKEN_INVALID_CODES and appid and secret:
                new_token = self._force_refresh_token(appid, secret)
                if new_token and new_token != token:
                    token = new_token
                    continue
            return {}, last_err, token
        return {}, last_err, token

    # ---------- 内部：上传素材拿 media_id（封面 thumb / 视频） ----------
    def _upload_material(self, token: str, path: str, typ: str) -> tuple[Optional[str], Optional[str], Optional[str]]:
        """上传永久素材；返回 (media_id, url, errmsg)。

        成功：(media_id, url, None)；失败：("", None, 错误文案)。
        注意：不能用「第二个返回值是否为空」判断成败——成功时 url 非空，
        失败时第二个返回值是错误文案同样非空，历史上正是这个误判导致真实发布 100% 失败。
        判断成功的唯一标准是 media_id 非空。
        """
        try:
            with open(path, "rb") as f:
                r = requests.post(
                    f"{_WX_API}/cgi-bin/material/add_material",
                    params={"access_token": token, "type": typ},
                    files={"media": (os.path.basename(path), f, "image/jpeg" if typ == "image" else "video/mp4")},
                    timeout=_TIMEOUT)
                d = r.json()
        except OSError as e:
            return "", None, f"素材文件读取失败: {e}"
        except (requests.RequestException, ValueError) as e:
            # requests 网络异常（超时/连接错误）或响应非 JSON
            return "", None, f"素材上传失败（网络异常）: {e}"
        if d.get("errcode", 0) != 0:
            return "", None, f"errcode={d.get('errcode')} errmsg={d.get('errmsg')}"
        return d.get("media_id"), d.get("url", ""), None

    # ---------- 内部：正文图片（Markdown/HTML → 可内嵌的 <img>） ----------
    def _extract_images(self, text: str, token: str) -> tuple[str, list[str]]:
        """抽取一段正文里的图片并解析成可内嵌 URL。

        外部图片直链会被微信过滤导致正文图片全丢，所以本地文件路径必须先上传成
        永久图片素材、换成微信返回的 url 再内嵌；http(s) 远程地址保持原样（微信会自行
        抓取，非白名单域名会失败但不至于整篇丢图）。单张图上传失败只跳过该图，
        绝不让整篇发布失败。

        Args:
            text:  单段正文原文（可含 ![alt](path) 与 <img src="path">）。
            token: access_token（用于上传本地图片）。
        Returns:
            (去掉图片语法后的文本, 解析出的图片 URL 列表)。
        """
        found: list[str] = []

        def _take(m):
            found.append(m.group(1))
            return ""

        text = _IMG_MD_RE.sub(_take, text)
        text = _IMG_HTML_RE.sub(_take, text)

        urls: list[str] = []
        for src in found:
            if src.startswith("http://") or src.startswith("https://"):
                urls.append(src)
                continue
            try:
                _mid, url, _err = self._upload_material(token, src, "image")
            except Exception:  # noqa: BLE001  单图上传失败只跳过该图，绝不影响整篇发布
                _mid, url, _ierr = None, None, None
            # 判断成功的唯一标准是 media_id 非空（第二个返回值成功时是 url、失败时是错误文案）
            if _mid and url:
                urls.append(url)
        return text, urls

    def _build_content(self, paragraphs: list[str], token: str) -> str:
        """把正文段落拼成微信可接收的 HTML，并把图片还原成内嵌 <img>。

        Args:
            paragraphs: 按行切好的正文段落。
            token:      access_token（用于上传正文里的本地图片）。
        Returns:
            拼接好的 HTML 片段。
        """
        parts: list[str] = []
        for para in paragraphs:
            text, urls = self._extract_images(para, token)
            if text.strip():
                parts.append(f"<p>{text.strip()}</p>")
            for u in urls:
                parts.append(f'<p><img src="{u}" style="max-width:100%"></p>')
        return "".join(parts)

    # ---------- 实际发布 ----------
    def _do_publish(self, req: PublishRequest, auth: dict) -> PublishResult:
        token, dry, err = self._resolve_token(req, auth)
        if err:
            return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                 error_code="TOKEN_ERROR", error_message=err)
        appid, secret = self._creds_from(req.extra or {}, auth)
        if dry:
            return PublishResult(
                platform=self.platform_key,
                status=PublishStatus.SIMULATED,
                platform_post_id="",
                platform_url="",
                error_message="未配置公众号 AppID/AppSecret，本次为模拟发送，文章未真正进入草稿箱。如需真发，请在「平台账号」填写公众号 AppID/AppSecret。",
                raw={"dry": True, "simulated": True},
            )

        # ---- 图文笔记：封面图 thumb → 组装 HTML → draft/add 入草稿箱 ----
        if req.image_paths:
            extra = req.extra or {}
            paths = req.image_paths[:9]  # 公众号单篇图文最多 9 图（1 封面 + 8 正文）
            thumb_id, _turl, terr = self._upload_material(token, paths[0], "image")
            if terr:
                return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                     error_code="THUMB_ERROR", error_message="封面图上传失败: " + terr)
            # 正文图片逐张上传拿 url（永久图片素材返回 url，可内嵌 <img>）
            body_imgs = []
            for ip in paths[1:]:
                _mid, url, _uerr = self._upload_material(token, ip, "image")
                if _mid and url:
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
            d, derr, _tok = self._post("/cgi-bin/draft/add", token, payload, appid, secret)
            if derr:
                return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                     error_code="DRAFT_ADD_ERROR",
                                     error_message="公众号草稿创建失败: " + derr,
                                     raw={"thumb_media_id": thumb_id})
            draft_id = d.get("media_id", "")
            return PublishResult(
                platform=self.platform_key,
                status=PublishStatus.PUBLISHED,
                platform_post_id=draft_id,
                platform_url="",  # 草稿无外链，需后台群发后才有 URL
                raw={"stage": "draft", "thumb_media_id": thumb_id, "dry": False},
            )

        # ---- 图文文章：标题 + 正文(description 多段) + 封面图 thumb → draft/add 入草稿箱 ----
        if not req.video_path and (((req.description or "").strip() or (req.content_html or "").strip()) or req.cover_path):
            extra = req.extra or {}
            paragraphs = [p.strip() for p in (req.description or "").split("\n") if p.strip()]
            # 优先用预排版 HTML（保留 h2/段首缩进）；仅在无 HTML 时才要求 description 有段落
            has_html = bool((req.content_html or "").strip())
            if not paragraphs and not has_html:
                return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                     error_code="EMPTY_CONTENT", error_message="正文不能为空")
            thumb_id = ""
            if req.cover_path:
                if not os.path.exists(req.cover_path):
                    return PublishResult(
                        platform=self.platform_key, status=PublishStatus.FAILED,
                        error_code="COVER_MISSING",
                        error_message=f"封面图文件不存在：{req.cover_path}。请重新上传封面图（建议 900×383 像素 JPG/PNG）后重试。")
                thumb_id, _unused, terr = self._upload_material(token, req.cover_path, "image")
                if terr:
                    return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                         error_code="THUMB_ERROR", error_message="封面图上传失败: " + terr)
            if not thumb_id:
                return PublishResult(
                    platform=self.platform_key, status=PublishStatus.FAILED,
                    error_code="NO_COVER",
                    error_message="请提供封面图：公众号草稿必须指定封面。请在文章页上传封面后重试（建议 900×383 像素 JPG/PNG）。")
            # 有预排版 HTML 直接用（不再按 \n 切段丢层级）；否则回退段落拼装
            content = (req.content_html or "").strip() if has_html else self._build_content(paragraphs, token)
            digest = str(extra.get("digest") or "").strip() or _plain_text(req.description or "")[:80]
            payload = {
                "articles": [{
                    "title": req.title or "财税文章",
                    "author": extra.get("author", ""),
                    "digest": digest,
                    "content": content,
                    "content_source_url": extra.get("content_source_url", ""),
                    "thumb_media_id": thumb_id,
                    "need_open_comment": _as_int(extra.get("need_open_comment"), 0),
                    "only_fans_can_comment": _as_int(extra.get("only_fans_can_comment"), 0),
                    "original": _as_int(extra.get("original"), 1),
                }]
            }
            d, derr, _tok = self._post("/cgi-bin/draft/add", token, payload, appid, secret)
            if derr:
                return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                     error_code="DRAFT_ADD_ERROR",
                                     error_message="公众号草稿创建失败: " + derr,
                                     raw={"thumb_media_id": thumb_id})
            draft_id = d.get("media_id", "")
            return PublishResult(
                platform=self.platform_key,
                status=PublishStatus.PUBLISHED,
                platform_post_id=draft_id,
                platform_url="",  # 草稿无外链，需后台群发后才有 URL
                raw={"stage": "draft", "thumb_media_id": thumb_id, "dry": False},
            )

        # ---- 视频素材：上传永久视频 → 待后台群发（公众号视频群发有额度，走半自动） ----
        media_id, _unused, verr = self._upload_material(token, req.video_path, "video")
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

    # ---------- 内部：群发（直接发表）相关接口 ----------
    def _freepublish(self, token: str, media_id: str, appid: str = "",
                     secret: str = "") -> tuple[Optional[dict], Optional[str], str]:
        """群发（直接发表）指定草稿：POST /cgi-bin/freepublish/submit。

        注意入参是「草稿的 media_id」（draft/add 返回值），不是素材 media_id。

        Args:
            token:    access_token。
            media_id: 草稿 media_id。
            appid:    可选，传了才启用 token 失效（40001/42001）重换重试。
            secret:   可选，同上。
        Returns:
            (响应 dict, 错误信息, 实际使用的 token)：成功时 dict 含 publish_id / msg_data_id；
            errcode 非 0 时返回 (None, "errcode=... errmsg=...", token)，原样保留微信错误码便于排查。
        """
        d, err, token = self._post("/cgi-bin/freepublish/submit", token,
                                   {"media_id": media_id}, appid, secret)
        if err:
            return None, err, token
        return d, None, token

    def _get_article(self, token: str, publish_id: str, appid: str = "",
                     secret: str = "") -> tuple[dict, Optional[str], str]:
        """查询发表状态并取文章链接：POST /cgi-bin/freepublish/get。

        Args:
            token:      access_token。
            publish_id: freepublish/submit 返回的 publish_id。
            appid:      可选，传了才启用 token 失效（40001/42001）重换重试。
            secret:     可选，同上。
        Returns:
            (响应 dict, 错误信息, 实际使用的 token)：失败时 dict 为 {}，错误文案原样带微信 errcode/errmsg。
        """
        d, err, token = self._post("/cgi-bin/freepublish/get", token,
                                   {"publish_id": publish_id}, appid, secret)
        if err:
            return {}, err, token
        return d, None, token

    def _get_article_detail(self, token: str, publish_id: str, appid: str = "",
                            secret: str = "") -> tuple[dict, Optional[str], str]:
        """取文章明细（含 news_item / article_url）：POST /cgi-bin/freepublish/getarticle。

        部分场景下 freepublish/get 不直接返回外链，需要用本接口从 article_detail 里取。

        Args:
            token:      access_token。
            publish_id: freepublish/submit 返回的 publish_id。
            appid:      可选，传了才启用 token 失效（40001/42001）重换重试。
            secret:     可选，同上。
        Returns:
            (响应 dict, 错误信息, 实际使用的 token)：失败时 dict 为 {}，错误文案原样带微信 errcode/errmsg。
        """
        d, err, token = self._post("/cgi-bin/freepublish/getarticle", token,
                                   {"publish_id": publish_id}, appid, secret)
        if err:
            return {}, err, token
        return d, None, token

    # ---------- 对外：群发（直接发表）指定草稿 ----------
    def publish_draft(self, media_id: str, extra: Optional[dict] = None) -> PublishResult:
        """把草稿箱里的指定草稿群发（直接发表）出去。

        合规：公众号群发受平台额度与运营规范 3.27 约束，本方法为「单次单篇、必须显式调用」，
        返回完整结果供上层做限流与留痕，禁止上层包装成循环批量群发。

        Args:
            media_id: draft/add 返回的草稿 media_id（不是素材 media_id）。
            extra:    可选，账号级凭证（appid/appsecret）等扩展参数，缺省回退环境变量。
        Returns:
            PublishResult：
              - 未配置凭据 → status=SIMULATED，raw 含 {"dry": True, "simulated": True}；
              - 成功 → status=PUBLISHED，platform_post_id=publish_id，platform_url=article_url
                （查 URL 失败仍判成功，仅 url 为空，raw 里附 article_url_error）；
              - 失败 → status=FAILED，error_message 原样带上微信 errcode/errmsg。
            本方法不向上抛异常，网络异常一律转成 FAILED。
        """
        extra = extra or {}
        try:
            token, dry, err = self._resolve_token_from_extra(extra)
            if err:
                return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                     error_code="TOKEN_ERROR", error_message=err,
                                     raw={"media_id": media_id})
            if dry:
                return PublishResult(
                    platform=self.platform_key,
                    status=PublishStatus.SIMULATED,
                    platform_post_id="",
                    platform_url="",
                    error_message="未配置公众号 AppID/AppSecret，本次为模拟群发，草稿未真正发表。",
                    raw={"dry": True, "simulated": True, "media_id": media_id},
                )

            appid, secret = self._creds_from(extra, {})
            d, ferr, token = self._freepublish(token, media_id, appid, secret)
            if ferr:
                return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                     error_code="FREEPUBLISH_ERROR",
                                     error_message="公众号群发失败: " + ferr,
                                     raw={"media_id": media_id})

            publish_id = str(d.get("publish_id") or "")
            article_url = ""
            article_url_err = ""
            if publish_id:
                adata, aerr, token = self._get_article(token, publish_id, appid, secret)
                if aerr:
                    article_url_err = aerr
                else:
                    article_url = _pick_article_url(adata)
                if not article_url:
                    ddata, derr, token = self._get_article_detail(token, publish_id, appid, secret)
                    if derr:
                        article_url_err = article_url_err or derr
                    else:
                        article_url = _pick_article_url(ddata)

            raw = {"media_id": media_id, "publish_id": publish_id,
                   "msg_data_id": str(d.get("msg_data_id") or ""), "dry": False}
            if article_url_err:
                raw["article_url_error"] = article_url_err
            return PublishResult(
                platform=self.platform_key,
                status=PublishStatus.PUBLISHED,
                platform_post_id=publish_id,
                platform_url=article_url,
                raw=raw,
            )
        except Exception as exc:  # noqa: BLE001  异常一律转成 FAILED，不向上抛
            return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                 error_code="EXCEPTION",
                                 error_message="公众号群发异常: " + str(exc),
                                 raw={"media_id": media_id})

    # ---------- 对外：草稿箱改稿 / 删稿 ----------
    def update_draft(self, media_id: str, index: int, article: dict,
                     extra: Optional[dict] = None) -> PublishResult:
        """修改草稿箱里指定草稿的第 index 篇文章：POST /cgi-bin/draft/update。

        Args:
            media_id: 草稿 media_id。
            index:    要更新的文章序号（多图文从 0 开始）。
            article:  文章字段 dict（title/author/digest/content/content_source_url/
                      thumb_media_id 等，与 draft/add 的 articles[0] 结构一致）。
            extra:    可选，账号级凭证（appid/appsecret）等扩展参数，缺省回退环境变量。
        Returns:
            PublishResult：未配置凭据返回 SIMULATED；成功返回 PUBLISHED（platform_post_id 为
            草稿 media_id）；失败返回 FAILED，error_message 原样带微信 errcode/errmsg。
        """
        extra = extra or {}
        try:
            token, dry, err = self._resolve_token_from_extra(extra)
            if err:
                return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                     error_code="TOKEN_ERROR", error_message=err,
                                     raw={"media_id": media_id})
            if dry:
                return PublishResult(
                    platform=self.platform_key,
                    status=PublishStatus.SIMULATED,
                    platform_post_id="",
                    platform_url="",
                    error_message="未配置公众号 AppID/AppSecret，本次为模拟改稿，草稿未被修改。",
                    raw={"dry": True, "simulated": True, "media_id": media_id},
                )
            appid, secret = self._creds_from(extra, {})
            d, derr, _tok = self._post("/cgi-bin/draft/update", token,
                                       {"media_id": media_id, "index": index, "articles": article},
                                       appid, secret)
            if derr:
                return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                     error_code="DRAFT_UPDATE_ERROR",
                                     error_message="公众号草稿更新失败: " + derr,
                                     raw={"media_id": media_id})
            return PublishResult(
                platform=self.platform_key,
                status=PublishStatus.PUBLISHED,
                platform_post_id=media_id,
                platform_url="",
                raw={"stage": "draft", "media_id": media_id, "dry": False, "response": d},
            )
        except Exception as exc:  # noqa: BLE001
            return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                 error_code="EXCEPTION",
                                 error_message="公众号草稿更新异常: " + str(exc),
                                 raw={"media_id": media_id})

    def delete_draft(self, media_id: str, extra: Optional[dict] = None) -> PublishResult:
        """删除草稿箱里的指定草稿：POST /cgi-bin/draft/delete。

        Args:
            media_id: 草稿 media_id。
            extra:    可选，账号级凭证（appid/appsecret）等扩展参数，缺省回退环境变量。
        Returns:
            PublishResult：未配置凭据返回 SIMULATED；成功返回 PUBLISHED（platform_post_id 为
            被删草稿 media_id）；失败返回 FAILED，error_message 原样带微信 errcode/errmsg。
        """
        extra = extra or {}
        try:
            token, dry, err = self._resolve_token_from_extra(extra)
            if err:
                return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                     error_code="TOKEN_ERROR", error_message=err,
                                     raw={"media_id": media_id})
            if dry:
                return PublishResult(
                    platform=self.platform_key,
                    status=PublishStatus.SIMULATED,
                    platform_post_id="",
                    platform_url="",
                    error_message="未配置公众号 AppID/AppSecret，本次为模拟删稿，草稿未被删除。",
                    raw={"dry": True, "simulated": True, "media_id": media_id},
                )
            appid, secret = self._creds_from(extra, {})
            d, derr, _tok = self._post("/cgi-bin/draft/delete", token,
                                       {"media_id": media_id}, appid, secret)
            if derr:
                return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                     error_code="DRAFT_DELETE_ERROR",
                                     error_message="公众号草稿删除失败: " + derr,
                                     raw={"media_id": media_id})
            return PublishResult(
                platform=self.platform_key,
                status=PublishStatus.PUBLISHED,
                platform_post_id=media_id,
                platform_url="",
                raw={"stage": "draft_deleted", "media_id": media_id, "dry": False, "response": d},
            )
        except Exception as exc:  # noqa: BLE001
            return PublishResult(platform=self.platform_key, status=PublishStatus.FAILED,
                                 error_code="EXCEPTION",
                                 error_message="公众号草稿删除异常: " + str(exc),
                                 raw={"media_id": media_id})
