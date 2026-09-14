# -*- coding: utf-8 -*-
"""
追梦平台 · 多账号发布辅助（功能包一）
====================================
把 8500 的「平台级单 token」升级为「平台 × 账号级多 token」的独立模块。

用途：
  1. 账号级 token 缓存（server.py 的 _handle_oauth_* 改造后写入；未改造前为空）；
  2. server.py /publish 改造时的取号入口：get_account_token(platform, account_key)；
  3. 未取到 token 时由调用方标记 simulated=true（dry 模拟），保证「绝无假成功」。

server.py 接入 diff（最小改动，见《实施说明-功能包一.md》第五节）：
  - _handle_oauth_authorize/_handle_oauth_callback：缓存键改为 (platform, account_key)
    （account_key = platform + ":" + platform_accounts.id，从 query 的 account_id 来）；
  - _handle_oauth_status：支持 ?account_key= 或路径 /oauth/status/{platform}/{account_id}；
  - _publish_job：按 account_key 取号，取不到 → result 增加 simulated=True 并返回；
  - 新增 POST /metrics/fetch：转发给 metrics_adapter.fetch_batch。
本模块不依赖任何三方库（纯标准库）。
"""

from __future__ import annotations

import json
import os
import threading
import time

_lock = threading.Lock()
# 账号级 token 缓存：{(platform, account_key): {"access_token":..., "refresh_token":..., "expires_at": ts}}
_ACCOUNT_TOKENS: dict = {}

_CACHE_FILE = os.path.join(os.path.dirname(__file__), "_account_token_cache.json")


def _load_cache():
    try:
        with open(_CACHE_FILE, "r", encoding="utf-8") as f:
            raw = json.load(f)
        for k, v in raw.items():
            platform, _, account_key = k.partition(":")
            _ACCOUNT_TOKENS[(platform, account_key)] = v
    except Exception:  # noqa: BLE001
        pass


def _save_cache():
    try:
        with open(_CACHE_FILE, "w", encoding="utf-8") as f:
            json.dump({f"{p}:{k}": v for (p, k), v in _ACCOUNT_TOKENS.items()}, f, ensure_ascii=False)
    except Exception:  # noqa: BLE001
        pass


def store_account_token(platform: str, account_key: str, token: dict):
    """授权回调成功后写入账号级 token（expires_at 用 unix 秒）。"""
    with _lock:
        _ACCOUNT_TOKENS[(platform, account_key)] = token
        _save_cache()


def get_account_token(platform: str, account_key: str) -> dict | None:
    """取账号级有效 token；过期/缺失返回 None（调用方应标记 simulated）。"""
    with _lock:
        tok = _ACCOUNT_TOKENS.get((platform, account_key))
    if not tok:
        return None
    expires = tok.get("expires_at")
    if isinstance(expires, (int, float)) and expires < time.time():
        return None
    return tok


def is_account_authorized(platform: str, account_key: str) -> bool:
    return get_account_token(platform, account_key) is not None


def remove_account_token(platform: str, account_key: str):
    with _lock:
        _ACCOUNT_TOKENS.pop((platform, account_key), None)
        _save_cache()


# ---- 账号级「应用凭证」缓存（多应用矩阵） ----
# 每个抖音号对应一套开放平台应用（client_key/client_secret）。授权回调时把该账号用到的
# 应用凭证一并记住，供 access_token 过期后按同应用自动 refresh（refresh_token 与应用绑定）。
_ACCOUNT_APPS: dict = {}
_APP_CACHE_FILE = os.path.join(os.path.dirname(__file__), "_account_app_cache.json")


def _load_app_cache():
    try:
        with open(_APP_CACHE_FILE, "r", encoding="utf-8") as f:
            raw = json.load(f)
        for k, v in raw.items():
            platform, _, account_key = k.partition(":")
            _ACCOUNT_APPS[(platform, account_key)] = v
    except Exception:  # noqa: BLE001
        pass


def _save_app_cache():
    try:
        with open(_APP_CACHE_FILE, "w", encoding="utf-8") as f:
            json.dump({f"{p}:{k}": v for (p, k), v in _ACCOUNT_APPS.items()},
                      f, ensure_ascii=False)
    except Exception:  # noqa: BLE001
        pass


def store_account_app(platform: str, account_key: str, app: dict):
    """记住账号所用开放平台应用凭证（client_key/client_secret/open_id）。"""
    with _lock:
        _ACCOUNT_APPS[(platform, account_key)] = app
        _save_app_cache()


def get_account_app(platform: str, account_key: str) -> dict | None:
    with _lock:
        return _ACCOUNT_APPS.get((platform, account_key))


_load_cache()
_load_app_cache()
