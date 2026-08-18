# -*- coding: utf-8 -*-
"""
追梦平台 · 数据回流适配器（功能包一）
=====================================
当前实现：抖音播放互动数据抓取（真实 API 骨架）。

契约（供 Laravel 侧调用，经 8500 新增端点 /metrics/fetch 代理）：
    POST /metrics/fetch
    {
      "tenant_id": "1",
      "items": [
        {"account_key": "douyin:12", "external_id": "7xxxxx", "video_job_id": 34}
      ]
    }
    → 200 {"ok": true, "results": [
        {"external_id": "7xxxxx", "video_job_id": 34, "platform_account_id": 12,
         "metric_date": "2026-08-20", "views": 1000, "likes": 20,
         "comments": 5, "shares": 3, "favorites": 0, "leads": 0}
      ]}
    → 未授权 / 无凭证时 200 {"ok": false, "error": "not_authorized: ..."}  （绝不写假数据）
    → 8500 不可达 / 超时 → 连接错误（由 Laravel 侧降级提示）

注意：抖音开放平台接口字段以官方最新文档为准（data.external.item 系列），
下方 URL / 字段为当前版本参考，接入真实凭证后需按官方文档核对。
"""

from __future__ import annotations

import json
import time
import urllib.error
import urllib.parse
import urllib.request

DOUYIN_OPEN_BASE = "https://open.douyin.com"

# 兼容 matrix_publish 的账号级 token 缓存；独立运行时也自建一份
try:
    from matrix_publish import get_account_token  # type: ignore
except Exception:  # noqa: BLE001  （未随 server.py 部署时降级）
    get_account_token = None  # type: ignore


class MetricsError(Exception):
    """可读的抓取错误。"""


class NotAuthorizedError(MetricsError):
    """账号未授权 / 无有效 token。"""


def _http_json(url: str, headers: dict, timeout: int = 15):
    req = urllib.request.Request(url, headers=headers)
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.loads(resp.read().decode("utf-8"))


def fetch_douyin_item(account_key: str, aweme_id: str) -> dict:
    """
    拉取单个抖音视频的播放互动数据。
    account_key: "douyin:<platform_accounts.id>"（与发布侧一致）。
    返回 {views, likes, comments, shares}（int）。
    无 token → 抛 NotAuthorizedError；接口错误 → 抛 MetricsError。
    """
    if not get_account_token:
        raise NotAuthorizedError("matrix_publish 未接入：无法取得账号 token")

    token = get_account_token("douyin", account_key)
    if not token:
        raise NotAuthorizedError(f"douyin:{account_key} 无有效 access_token，请先完成平台授权")

    access_token = token.get("access_token") or token.get("token")
    if not access_token:
        raise NotAuthorizedError(f"douyin:{account_key} token 结构异常（缺 access_token）")

    qs = urllib.parse.urlencode({
        "item_id": aweme_id,
        "item_type": "0",        # 视频
        "date_type": "7",        # 近 7 日
    })
    url = f"{DOUYIN_OPEN_BASE}/data/external/item/?{qs}"
    try:
        payload = _http_json(url, {"access-token": access_token, "User-Agent": "hgt-commercial"})
    except urllib.error.HTTPError as e:
        raise MetricsError(f"抖音接口 HTTP {e.code}: {e.read().decode('utf-8', 'ignore')[:200]}")
    except Exception as e:  # noqa: BLE001
        raise MetricsError(f"抖音接口请求失败: {e}")

    if payload.get("error_code") not in (0, None, "0"):
        raise MetricsError(f"抖音接口 error_code={payload.get('error_code')}: {payload.get('description')}")

    item = ((payload.get("data") or {}).get("external_item") or {})
    # 字段名以官方最新文档为准；这里做容错映射
    views = int(item.get("total_play") or 0)
    likes = int(item.get("total_like") or 0)
    comments = int(item.get("total_comment") or 0)
    shares = int(item.get("total_share") or 0)
    return {"views": views, "likes": likes, "comments": comments, "shares": shares}


def fetch_batch(items: list) -> list:
    """逐条抓取（抖音无批量数据接口），带轻量限速（0.4s/条）。"""
    results = []
    for it in items:
        account_key = it.get("account_key") or ""
        external_id = it.get("external_id") or ""
        if not account_key or not external_id:
            continue
        try:
            stat = fetch_douyin_item(account_key, external_id)
            results.append({
                "external_id": external_id,
                "video_job_id": it.get("video_job_id"),
                "platform_account_id": int(account_key.rsplit(":", 1)[-1]) if ":" in account_key else None,
                "metric_date": time.strftime("%Y-%m-%d"),
                **stat,
                "favorites": 0,   # 抖音开放数据无收藏维度
                "leads": 0,       # 留资走企业号线索接口（二期）
            })
        except NotAuthorizedError:
            # 未授权：跳过该条，不写假数据（结果里不包含 = Laravel 侧不落库）
            continue
        except MetricsError:
            continue
        time.sleep(0.4)
    return results
