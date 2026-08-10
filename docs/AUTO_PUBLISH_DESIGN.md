# 自动发布模块设计（AUTO_PUBLISH_DESIGN）

> 关联：ARCHITECTURE_ANALYSIS.md（模块依赖）、PLATFORM_OUTPUT_SPECS.md（按平台规格出片）
> 目标：视频经人工审核通过后，自动发布至 抖音 / 快手 / B站 / YouTube / 小红书 / 视频号 等主流平台。
> 满足四项要求：① 统一发布接口层 + 适配器模式；② 人工审核检查点；③ 受限平台预留模块接口；④ 状态实时回显 + 失败重试 + 发布日志。

---

## 一、目标与范围

端到端闭环：**选题 → 二创 → 质检 → 出片 → 人工审核 → 多平台自动发布 → 状态回显/重试/日志**。

本文只设计"出片之后"的发布子系统（审核检查点 + 多平台分发），与上游出片（scroll/avatar）通过 `VideoJob` 衔接。

发布目标平台（规范键）：

| 中文 | 规范键 | 备注 |
|---|---|---|
| 抖音 | `douyin` | 出片规格 1080×1920（PLATFORM_OUTPUT_SPECS） |
| 视频号 | `shipinhao` | 1080×1920 |
| 小红书 | `xiaohongshu` | 1080×1440（3:4） |
| 快手 | `kuaishou` | 出片规格 1080×1920（PLATFORM_OUTPUT_SPECS） |
| B站 | `bilibili` | 新增目标，API 受限（见第五节） |
| YouTube | `youtube` | 新增目标，API 成熟（参照实现） |

> 与 P5 选题维度的一致性说明：已统一为单一平台注册表（见第九节 `PLATFORMS` 字典），**选题用子集（视频号/抖音/小红书/快手，4 家短视频）、发布用全集（再加 B站/YouTube，共 6 家）**，避免枚举分裂、出片分辨率与发布目标对不上。

---

## 二、与现有模块的关系

```
[出片 scroll/avatar] → VideoJob(status=done, qc_status=passed/warned)
        │
        ▼
[人工审核] VideoJob.publish_status: draft → reviewing → approved
        │  (approved 后才允许外发，复用既有状态机)
        ▼
[创建发布任务] 为每个选中平台建 publish_jobs 一行
        │
        ▼
[8500 /publish] → 按 platform 取适配器 → 调平台 API → 回调状态
        │
        ▼
[Laravel 状态板] 轮询 publish_jobs → 实时回显 / 失败重试 / 写日志
```

- **复用点**：`VideoJob.publish_status`（draft/reviewing/rejected/approved）即人工审核检查点，无需新建审核实体。
- **新增点**：`publish_jobs`（每平台一行）+ `tenant_channel_credentials`（租户各平台凭证）。

---

## 三、整体架构（分层）

| 层 | 职责 | 技术 |
|---|---|---|
| 审核层 | 视频预览 + 通过/驳回 + 备注 | Laravel Blade + Livewire |
| 编排层 | 建发布任务、调 8500、轮询状态 | Laravel（VideoController 扩展） |
| 接口层 | 统一发布接口 + 适配器注册表 | `python-pipeline/publishers/`（新建） |
| 适配层 | 各平台 API 实现 / 受限平台预留 | 各 `XxxPublisher` |
| 凭证层 | 租户平台凭证加密存储 | `tenant_channel_credentials` 表 |

数据流：**Laravel 不直连平台 API**，统一经 8500 `/publish` 端点，与现有出片架构一致（Web 层 / AI 层 / GPU 层分离）。

---

## 四、统一发布接口层（适配器模式）

核心抽象 `BasePublisher`（见 `python-pipeline/publishers/base.py`）：

- **输入**：`PublishRequest`（tenant_id, platform, video_path, title, description, tags, cover_path, extra, credential_ref）
- **输出**：`PublishResult`（platform, status, platform_post_id, platform_url, error_code, error_message, raw）
- **回调**：`status_callback(platform, job_key, status, detail)` —— 适配器在状态跃迁时调用，上层无需轮询平台即可实时回显
- **统一入口**：`publish(req, job_key)` 包裹认证+上传+异常→状态，子类只实现 `authenticate` / `_do_publish`

新增平台 = 新建一个 `XxxPublisher` 并在 `registry.py` 登记，**上层零改动**。

```python
# 分发示例（8500 /publish 内部）
pub = get_publisher(req.platform, status_callback=on_status_change)
result = pub.publish(req, job_key="pj_123")
```

---

## 五、平台适配器现实评估（2026 现行）

| 平台 | 自动发布 API 可行性 | 前提条件 | 适配器策略 |
|---|---|---|---|
| **YouTube** | ✅ 成熟 | YouTube Data API v3（OAuth） | **完整实现**（参照基准） |
| **抖音** | 🟡 可全自动 | 抖音开放平台·企业号 + 视频内容授权 | **已实装骨架**（supports_auto=True，未配凭证降级 dry 模拟） |
| **视频号** | 🟡 可全自动 | 微信视频号 API（认证服务号/视频号） | **已实装骨架**（同上） |
| **小红书** | 🟡 可全自动 | 小红书开放平台·专业号 | **已实装骨架**（同上，3:4 竖屏） |
| **快手** | 🟡 可全自动 | 快手开放平台·企业号 + 内容授权 | 一期可实装 / 或先 stub |
| **B站** | ⚠️ 无稳定官方 API | 开放平台偏工具/游戏；常见 cookie 模拟（易风控） | **走预留人工模块** |

结论：YouTube 作为"能全自动"的参照实现；抖音/视频号/小红书 **已实装适配器骨架**（`python-pipeline/publishers/douyin.py|shipinhao.py|xiaohongshu.py`，OAuth2 授权码换 token + 上传 + 发布流程完整，supports_auto=True），未配置凭证时降级 dry 模拟便于流程联调；真实全自动需租户在对应开放平台完成**企业认证 + 内容授权**并填入凭证（见各适配器文件头）。B站 明确走受限平台预留接口。

> **优先级（2026-08-04 用户拍板）**：分发目标端到端闭环以 **抖音 + 视频号 + 小红书 优先**（三个国内主战场，企业资质已齐备），B站 / YouTube 顺延。三个国内平台适配器已就位，待凭证联调即可直发。

---

## 六、人工审核检查点（复用 VideoJob.publish_status）

状态机（扩展既有值，向前兼容）：

```
draft ──(提交审核)──▶ reviewing ──(通过)──▶ approved ──(分发)──▶ publishing
   ▲                     │                   │                   │
   └────(驳回)───────────┘            (驳回)──┘            ├─▶ published
                                                          └─▶ partial(部分平台失败)
rejected（终态之一，可重提交回 draft）
```

- `reviewing`：审核人打开预览页，可填 `review_note`。
- `approved`：唯一允许创建 `publish_jobs` 并分发的外发闸门（满足要求②）。
- 新增状态：`publishing` / `published` / `partial`，由发布流程回写。

前端硬性约束：未 `approved` 的 VideoJob，发布按钮 disabled。

---

## 七、发布状态机 + 实时回显 + 重试 + 日志

### 7.1 publish_jobs 状态
`pending → uploading → processing → published / failed / manual_required`
- `failed`：达到重试上限或不可重试错误。
- `manual_required`：受限平台（如 B站）需人工在平台后台发布，系统给出文件就绪提示。

### 7.2 重试策略
- `max_retries = 3`，退避：30s → 2min → 5min（指数退避）。
- 可重试错误（网络超时/5xx/频控）自动重试；不可重试（认证失效/内容违规）直接 `failed` 并告警。
- 前端"立即重试"按钮：重置 `retry_count`，状态回 `pending` 重新分发。

### 7.3 实时回显
- **v1（一期）**：Laravel 每 3s 轮询 `publish_jobs`（按 video_job_id），状态板实时刷新。
- **v2（二期）**：8500 经 SSE/WebSocket 推送 `status_callback` 至 Laravel，免轮询。

### 7.4 日志
- `publish_logs(job_id, level, message, created_at)` 记录每次尝试、回调、错误原文。
- 状态板可展开查看单平台完整日志。

---

## 八、受限平台预留模块接口契约（满足要求③）

以 `python-pipeline/publishers/bilibili.py` 为范本，定义"预留模块"必须满足的契约：

1. **输入参数**：统一接收 `PublishRequest`（禁止各平台自定义入参结构）。
2. **返回值**：统一返回 `PublishResult`，受限时 `status = MANUAL_REQUIRED` + `error_code` + `error_message`（含视频路径等人工所需信息）。
3. **回调机制**：`publish()` 统一在起始回调 `PENDING`、结束回调 `MANUAL_REQUIRED`，上层实时感知。
4. **文档注释规范**：每个适配器文件头部须注明——API 可行性、前提条件、本文件实现的扩展点（`_do_publish`）、后续专家接入步骤。
5. **零破坏升级**：专家后续接入真实 API 时，仅填充 `_do_publish` 并将 `supports_auto=True`，**上层（registry / 8500 / Laravel）零改动**。

```python
# bilibili.py 头部契约示例（详见代码文件）
"""
B站适配器（预留模块接口）：
  - 输入：PublishRequest（见 base.py）
  - 返回：PublishResult(status=MANUAL_REQUIRED)
  - 回调：publish() 统一回调 PENDING → MANUAL_REQUIRED
  - 后续接入：填充 _do_publish 真实逻辑 + supports_auto=True，上层无需改动
"""
```

---

## 九、数据模型

### 9.1 publish_jobs（每平台一行）
| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint | 租户隔离 |
| video_job_id | bigint FK | 关联 VideoJob |
| platform | varchar | 规范键（douyin/shipinhao/...） |
| status | varchar | pending/uploading/processing/published/failed/manual_required |
| post_id | varchar null | 平台作品 ID |
| post_url | varchar null | 作品外链 |
| error_code | varchar null | |
| error_message | text null | |
| retry_count | int default 0 | |
| last_attempt_at | timestamp null | |
| raw_response | json null | 平台原始响应 |
| created_at / updated_at | timestamps | |

### 9.2 tenant_channel_credentials（租户各平台凭证）
| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint | |
| platform | varchar | 规范键 |
| credential_ref | text | 加密后的 OAuth token / client secret 引用（**不落明文**） |
| status | varchar | active / expired / unbound |
| created_at / updated_at | timestamps | |

> 安全铁律：平台 Access Token / Cookie **绝不明文入库**，经 Laravel 加密或密钥库句柄存储；8500 凭 `credential_ref` 取用。

### 9.3 规范平台注册表（统一枚举，避免分裂）
```python
PLATFORMS = {
    "douyin":      {"label": "抖音",   "spec": (1080, 1920), "topic": True},
    "shipinhao":   {"label": "视频号", "spec": (1080, 1920), "topic": True},
    "xiaohongshu": {"label": "小红书", "spec": (1080, 1440), "topic": True},
    "kuaishou":    {"label": "快手",   "spec": (1080, 1920), "topic": True},
    "bilibili":    {"label": "B站",    "spec": (1920, 1080), "topic": False},  # 横屏长视频，不纳入选题调性
    "youtube":     {"label": "YouTube","spec": (1920, 1080), "topic": False},
}
# 选题维度用子集：TOPIC_PLATFORMS = [k for k, v in PLATFORMS.items() if v["topic"]]
# 发布目标用全集：list(PLATFORMS.keys())
```
出片分辨率（PLATFORM_OUTPUT_SPECS）与发布目标共用此表：多目标发布时，竖屏视频通用；小红书 3:4 在发布时 center-crop（见 PLATFORM_OUTPUT_SPECS 二期）。

---

## 十、实施分级

### 一期（建议立即做）
1. `python-pipeline/publishers/` 接口层 + registry + **YouTube 完整适配器** + **B站/受限平台预留 stub**。
2. 扩展 `VideoJob.publish_status` 状态（publishing/published/partial）+ 新建 `publish_jobs` / `tenant_channel_credentials` 迁移。
3. 8500 新增 `/publish` 端点：接收平台列表 → 建 publish_jobs → 后台线程调适配器 → 回调写状态（**异步，不阻塞 8500**）。
4. Laravel 审核页（复用 publish_status）+ 发布状态板（轮询回显 + 重试按钮 + 日志展开）。
5. 抖音/视频号/小红书 适配器：**已实装**（`douyin.py`/`shipinhao.py`/`xiaohongshu.py`，OAuth2 + 发布流程骨架，supports_auto=True，未配凭证降级 dry 模拟）。真实联调需租户在开放平台注册应用 + 企业认证 + 授权后填入凭证（env 或 credential_ref，绝不明文）。

### 二期
1. 抖音/视频号/小红书 **真实适配器**（需租户提供企业认证 + 内容授权）。
2. SSE/WebSocket 实时推送替代轮询。
3. 发布成功后自动生成朋友圈/公众号文案（接 ARCHITECTURE_ANALYSIS 差异化方案 B/A），打通全闭环。

---

## 十一、设计科学性自评
- **开闭原则**：新增平台只加适配器 + 登记，上层稳定（满足要求①）。
- **前置闸门**：审核 `approved` 是唯一外发条件，违规视频在源头拦截（满足要求②）。
- **优雅降级**：受限平台不阻塞整体，以 `MANUAL_REQUIRED` 显式告知，预留契约清晰（满足要求③）。
- **可观测**：状态机 + 重试 + 日志三位一体，发布过程全透明（满足要求④）。
- **安全**：凭证加密、租户隔离，与既有多租户架构一致。
