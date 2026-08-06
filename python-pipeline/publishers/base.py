"""
统一发布接口层 — 抽象基类与契约定义（适配器模式）

本模块是「自动发布模块」的接口核心，定义：
  - 输入参数约定：PublishRequest
  - 返回值约定：  PublishResult
  - 回调机制：    StatusCallback（状态跃迁时由适配器主动调用，上层免轮询平台）
  - 统一入口：    BasePublisher.publish()（包裹认证+上传+异常→状态）

所有平台适配器（无论能否全自动）必须继承 BasePublisher 并实现 authenticate / _do_publish，
以保证上层（8500 /publish、Laravel 状态板）以统一方式驱动任意平台。

后续专家接入受限平台时，仅需：
  1) 新建 XxxPublisher(BasePublisher)
  2) 填充 authenticate / _do_publish 真实逻辑
  3) 将类属性 supports_auto 置为 True
  4) 在 registry.py 登记
无需改动任何上层代码。
"""
from __future__ import annotations

import abc
from dataclasses import dataclass, field
from enum import Enum
from typing import Callable, Optional


class PublishStatus(str, Enum):
    """发布任务状态（与 Laravel publish_jobs.status 对齐）。"""
    PENDING = "pending"            # 已建任务，待开始
    UPLOADING = "uploading"        # 上传中
    PROCESSING = "processing"      # 平台处理中（转码/审核）
    PUBLISHED = "published"        # 发布成功
    FAILED = "failed"              # 失败（重试耗尽或不可重试）
    MANUAL_REQUIRED = "manual_required"  # 平台不支持全自动，需人工在平台后台发布


@dataclass
class PublishRequest:
    """发布请求输入参数（所有适配器统一接收此结构，禁止各平台自定义入参）。"""
    tenant_id: int                 # 租户 ID（隔离 + 凭证归属）
    platform: str                 # 规范平台键：douyin/shipinhao/xiaohongshu/bilibili/youtube
    video_path: str               # 本地视频文件绝对路径
    title: str                    # 作品标题
    description: str = ""         # 作品描述/正文
    tags: list[str] = field(default_factory=list)      # 话题标签
    cover_path: Optional[str] = None                  # 封面图路径（可选）
    extra: dict = field(default_factory=dict)          # 平台专属扩展（如 douyin 的 poi_id、youtube 的 privacy_status）
    credential_ref: Optional[str] = None               # 租户在该平台的凭证引用键（真实 secret 不落库）


@dataclass
class PublishResult:
    """发布返回值（所有适配器统一返回此结构）。"""
    platform: str
    status: PublishStatus
    platform_post_id: Optional[str] = None   # 平台侧作品 ID（成功时）
    platform_url: Optional[str] = None        # 作品外链（成功时）
    error_code: Optional[str] = None          # 错误码（失败时）
    error_message: Optional[str] = None       # 人类可读错误（失败时）
    raw: dict = field(default_factory=dict)   # 平台原始响应（调试用）


# 状态变更回调签名： (platform, job_key, new_status, detail:dict) -> None
StatusCallback = Callable[[str, str, PublishStatus, dict], None]


class BasePublisher(abc.ABC):
    """平台发布适配器抽象基类。

    子类必须定义：
      - platform_key   : 类属性，规范平台键（与 PLATFORMS 注册表一致）
      - supports_auto  : 类属性，是否支持全自动发布（False 走预留人工模块）
      - authenticate   : 用 credential_ref 取得调用凭证
      - _do_publish    : 执行实际上传/发布，返回 PublishResult

    子类【不应】重写 publish()：统一入口已处理状态回调与异常包裹。
    """

    platform_key: str = ""
    supports_auto: bool = True

    def __init__(self, status_callback: Optional[StatusCallback] = None):
        self._cb = status_callback

    # ---- 内部：状态回调（上层免轮询平台即可实时回显） ----
    def _emit(self, job_key: str, status: PublishStatus, detail: Optional[dict] = None) -> None:
        if self._cb:
            self._cb(self.platform_key, job_key, status, detail or {})

    # ---- 子类实现：取得调用凭证 ----
    def authenticate(self, credential_ref: Optional[str]) -> dict:
        """用租户凭证引用获取平台调用令牌。

        Args:
            credential_ref: 来自 PublishRequest.credential_ref（密钥库句柄，非明文）。
        Returns:
            dict: 供 _do_publish 使用的认证材料（如 {"access_token": "..."}）。
        """
        raise NotImplementedError

    # ---- 子类实现：实际发布 ----
    @abc.abstractmethod
    def _do_publish(self, req: PublishRequest, auth: dict) -> PublishResult:
        """执行实际发布。

        Args:
            req:  统一发布请求（见 PublishRequest）。
            auth: authenticate() 返回的凭证字典。
        Returns:
            PublishResult: 必须返回，不得抛未捕获异常（异常由 publish() 统一包裹为 FAILED）。
        """
        ...

    # ---- 统一入口（子类一般无需重写） ----
    def publish(self, req: PublishRequest, job_key: str) -> PublishResult:
        """统一发布入口：状态回调 + 异常包裹。

        Args:
            req:     统一发布请求。
            job_key: 上层任务键（publish_jobs.id 或业务键），用于回调定位。
        Returns:
            PublishResult: 含最终状态（成功/失败/需人工）。
        """
        self._emit(job_key, PublishStatus.PENDING)
        try:
            auth = self.authenticate(req.credential_ref)
            self._emit(job_key, PublishStatus.UPLOADING)
            result = self._do_publish(req, auth)
            self._emit(job_key, result.status, {"post_id": result.platform_post_id})
            return result
        except Exception as exc:  # 统一异常 → FAILED（不让异常穿透到 8500 线程）
            res = PublishResult(
                platform=self.platform_key,
                status=PublishStatus.FAILED,
                error_code="EXCEPTION",
                error_message=str(exc),
            )
            self._emit(job_key, PublishStatus.FAILED, {"error": str(exc)})
            return res
