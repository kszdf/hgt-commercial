# 慧根堂商用短视频平台 · Cloudflare Tunnel 本机部署方案

> 仓库：`git@github.com:kszdf/hgt-commercial.git`
> 定位：**仅内部使用**（不对外获客、不收款）→ 用 Cloudflare Tunnel 直接连本机，绑一个域名即可访问。
> 整理日期：2026-10-01
> 说明：本文所有结论均基于对仓库源码的实际通读，关键限制已核对 Cloudflare 官方文档（出处见文末）。

---

## 一、结论

**可行。** 内部使用的前提下，Cloudflare Tunnel 直连本机是最省事的方案：

- **备案、收款通道、大陆访问延迟**这三个障碍整体消失（不对外提供服务）。
- 顺带**干掉整套 frp**，并消除当前"8500 裸暴露公网且无鉴权"的安全漏洞。
- 但仍有 **2 个 CF 免费版硬限制**照样会撞（上传 100MB、源站响应 125s），必须处理。
- 必须挂 **Cloudflare Access** 鉴权，否则登录页和出片端点全球可扫。

---

## 二、目标架构

```
同事浏览器
   │  https://app.<你的域名>
   ▼
Cloudflare 边缘（Access 邮箱 OTP 鉴权）
   │  出站隧道（无需公网 IP、无需开端口）
   ▼
cloudflared（本机 Windows 常驻服务）
   │
   ▼
本机 nginx:8080 ──► app (php:8.4-fpm) Laravel 11 + Livewire 4
                        │  http://host.docker.internal:8500（同机直连，不走公网）
                        ▼
                    python-pipeline:8500（Windows 宿主）
                        │  subprocess
                        ▼
                    ffmpeg / HEYGEM / CosyVoice / faster-whisper
                    mysql:8.0  ·  redis:alpine（本机 Docker 卷）
```

**与旧架构的差别**

| 项 | 旧（腾讯云 + frp） | 新（本机 + Tunnel） |
|---|---|---|
| Web 层 | 腾讯云轻量（已到期） | 本机 Docker |
| 本机 → 云 | frpc → frps:7000 → 云 127.0.0.1:8500 | **不需要，同机直连** |
| 入口 | 云服务器 80/443 | Cloudflare Tunnel |
| 8500 暴露面 | 公网可达（`OAUTH_REDIRECT_BASE=http://124.222.33.233:8500`） | 仅本机，**不再暴露** |
| 备案 | 苏ICP备2026023229号 | 无需（不对外服务） |
| 收款 | 微信支付/支付宝 | **不可用**（要求 ICP 备案域名） |

---

## 三、落地步骤

### 步骤 1｜Cloudflare 侧：Tunnel + 域名

```bash
# 本机（管理员 PowerShell）
cloudflared tunnel login
cloudflared tunnel create hgt
cloudflared tunnel route dns hgt app.<你的域名>
cloudflared service install            # 注册为 Windows 服务，开机自启
```

config.yml（`%USERPROFILE%\.cloudflared\config.yml`）：

```yaml
tunnel: <tunnel-uuid>
credentials-file: C:\Users\<你>\.cloudflared\<tunnel-uuid>.json

ingress:
  - hostname: app.<你的域名>
    service: http://localhost:8080     # 指向本机 nginx，不是 8500
  - service: http_status:404           # 必需兜底规则
```

> 域名前提：NS 托管在 Cloudflare，最省事；若 DNS 不在 CF，可加 CNAME 指向 `<tunnel-uuid>.cfargotunnel.com`。

### 步骤 2｜Cloudflare Access（内部用必做）

1. Zero Trust → Access → Applications → Add self-hosted，域名填 `app.<你的域名>`。
2. 策略：Action = Allow，Include = Emails，填你和同事的邮箱（走邮箱一次性验证码，客户端零安装）。
3. **再加一个 Application 覆盖 `app.<你的域名>/oauth/*`，策略设为 Bypass**。

> ⚠️ **最容易踩的坑**：抖音/小红书授权回调是第三方浏览器跳转，不带 Access cookie；若被 Access 拦住，授权直接失败。所以 `/oauth/*` 必须 bypass。
> 免费版 Zero Trust 有用户数上限，小团队够用，具体名额以控制台实际显示为准。

### 步骤 3｜Laravel / Python 配置改动（仅 2 处 + 1 处保持）

```diff
# .env
- APP_URL=http://124.222.33.233
+ APP_URL=https://app.<你的域名>
+
+ SESSION_SECURE_COOKIE=true
  PYTHON_PIPELINE_URL=http://host.docker.internal:8500    # 保持同机直连，不用改
```

```diff
# python-pipeline/server.py:100
- OAUTH_REDIRECT_BASE = os.environ.get("OAUTH_REDIRECT_BASE", "http://124.222.33.233:8500")
+ OAUTH_REDIRECT_BASE = os.environ.get("OAUTH_REDIRECT_BASE", "https://app.<你的域名>")
```

> ✅ **`trustProxies` 无需改动**：`bootstrap/app.php:25` 已经是 `$middleware->trustProxies(at: '*')`，Laravel 能正确识别 `X-Forwarded-Proto`。
> （若这一行被改成具体 IP 列表，会导致生成 http 链接、重定向循环。）

### 步骤 4｜清理 frp 遗留

| 文件 | 处理 |
|---|---|
| `deploy/frpc.toml`、`frpc-local.toml` | 删除或标注废弃（两份注释自相矛盾：一份说"永不暴露公网"，一份说"防火墙须放行"） |
| `frps` 云服务器（已随腾讯云到期） | 无需处理 |
| 弱 token `hgt2026studio#K8mPq` | 已进 git 历史，**视为公开**，不再复用 |
| `start_frpc.bat`、`start_pipeline.bat` | 保留 `start_pipeline.bat`（启 8500），删除 frpc 相关 |
| nginx `location /oauth/` 反代 | **保留**（新架构下 OAuth 回调仍走它） |

---

## 四、两个硬限制（内部用也不豁免，改 php.ini 无用）

### 限制 1：请求体上限 100MB

- Cloudflare Free/Pro 计划：单请求最大 100MB（超限返回 **413**），仅 Enterprise 可自行调到最高 5GB。
- 现状冲突：`php/php.ini` 是 `upload_max_filesize = 512M` / `post_max_size = 512M`，nginx `client_max_body_size 256m`，`DEPLOYMENT.md` 写"真人素材精剪 ≤500MB"。
- **绕法（二选一）**：
  - **A｜R2 直传**：浏览器拿 presigned URL 直接 PUT 到 R2（不经 CF 代理，不受 100MB 限制），PHP 侧只存 key，再由本机脚本 sync 到本地盘供 8500 读取。
  - **B｜压到 100MB 内**：内部用就把 `php.ini` 与 nginx 上传限制同步下调到 100MB 以下，并在前端给出明确的大小校验提示，避免"以为能传 500MB"的误判。

### 限制 2：源站响应超时 125 秒

- Cloudflare 的 Proxy Read Timeout 默认 **125 秒**，超时返回 **524**；仅 Enterprise 可提高到 6000 秒。另有 Proxy Write Timeout 30 秒，**不可调整**。
- 现状冲突（全部是同步长请求）：
  - `php/php.ini`：`max_execution_time = 330`、`max_input_time = 330`
  - `nginx/default.conf`：`fastcgi_read_timeout 300`
  - `php/php.ini` 注释实测：对话出稿"全写 N 篇"耗时 **200~280 秒**
  - `app/Http/Controllers/StudioController.php:258-265`：`/rewrite` timeout 180s、`/dissect` 180s、`/topic` 150s、`/xhs_build_note` 180s
- **绕法**：把超过 100 秒的接口改成「提交 → 返回 job_id → 轮询」。
  **项目已有一半基建可复用**：`/studio/chat/status/{jobId}`（对话出稿 B 版异步）、`/studio/scroll/status/{jobId}`（出片）。
- ⚠️ **先实测再改**：不要凭 timeout 配置值推断，只改实测确实 >100s 的端点。

---

## 五、工单清单（四要素）

### 工单 1｜Tunnel + 域名 + Access
- **改什么**：本机安装 cloudflared；创建 tunnel `hgt`；ingress `app.<域名> → http://localhost:8080`；Access 邮箱 OTP 策略 + `/oauth/*` bypass 策略；`cloudflared service install`
- **验收点**：① 外网打开域名先出 Access 登录页，通过后到平台登录页；② `curl -I https://app.<域名>/oauth/callback/douyin` 不被 Access 拦（不返回 302 到 Access）
- **依赖**：一个 NS 在 CF 的域名；本机管理员权限
- **prefactor**：无（纯新增）

### 工单 2｜配置适配
- **改什么**：`.env` 的 `APP_URL` + `SESSION_SECURE_COOKIE`；`python-pipeline/server.py:100` 的 `OAUTH_REDIRECT_BASE`
- **验收点**：① 登录后无重定向循环；② 表单提交不 419；③ 出片页能调到 8500（`/studio/pipeline-health` 返回 ok）；④ 抖音/小红书授权回调能回到平台
- **依赖**：工单 1 的域名
- **prefactor**：无

### 工单 3｜长任务异步化（解 524）
- **改什么**：把实测 >100s 的同步接口改为 job 化（复用 `/studio/chat/status`、`/studio/scroll/status` 模式）
- **验收点**：长任务端点 <5s 返回 job_id；轮询可拿到最终结果；全程无 524
- **依赖**：工单 2
- **prefactor**：实测各端点耗时，产出">100s 端点清单"

### 工单 4｜大文件上传（解 413）
- **改什么**：按第四节方案 A（R2 直传）或 B（下调限制 + 前端校验）落地
- **验收点**：A → 上传 300MB 素材成功且流量不经 CF 代理；B → 配置值与 CF 上限一致，超限时前端有友好提示而非 413 白屏
- **依赖**：工单 2（A 还需要 R2 桶 + S3 凭证）
- **prefactor**：确认这些素材当前落在本地盘 `storage/app` 还是被 8500 直接读，决定是否要回同步

### 工单 5｜内部化瘦身（可选，建议做）
- **改什么**：下线支付（`PaymentController`、`/admin/billing`、`orders` 路由）、关 `MAIL_*` 与短信、关找回密码入口
- **验收点**：相关路由不可达；无外呼 SMTP/短信
- **依赖**：无
- **prefactor**：确认无内部依赖这些入口

### 工单 6｜本机可用性
- **改什么**：`cloudflared service install` + Docker Desktop 开机自启 + 电源计划禁休眠 + 开机脚本拉起 8500（NSSM `HGTCommercial8500`）与 HEYGEM 容器
- **验收点**：重启 Windows 后 5 分钟内域名自动可用
- **依赖**：工单 1
- **prefactor**：无

---

## 六、运维注意（内部用也不可省）

1. **本机 = 单点**：关机/休眠/断电/重启 = 全站 502。必须配齐服务自启 + 禁用休眠。
2. **数据只有一份**：MySQL/Redis 数据在本机 Docker 卷。至少加一个每日 `mysqldump` + 异盘/网盘备份。
3. **上行带宽**：成片 mp4 给同事下载走家宽上行（常见 20~50Mbps），多人同时下会排队。
4. **HEYGEM 资源竞争**：数字人渲染与本机 Docker 栈同机，渲染时注意内存/CPU 抢占。
5. **不再复用的凭据**：`hgt2026studio#K8mPq`（frp token）已进 git 历史，视为泄漏，任何新服务都不要复用。

---

## 七、明确不做 / 不适用

| 事项 | 原因 |
|---|---|
| 把整个平台迁到 Cloudflare Workers/Containers | 无 PHP 运行时；出片管线依赖 Windows ffmpeg 绝对路径、本地 GPU/HEYGEM、本地模型权重、25~90 分钟长任务 → 物理不可行 |
| 用 CF 给已备案主域做橙云代理 | 解析境外与备案接入不符，备案会被注销 |
| 微信支付 / 支付宝在线收款 | 回调域名要求 ICP 备案，本方案无备案 → 通道打不开 |
| 恢复 frp | 已无云端服务器，且 Tunnel 是出站连接、无需公网 IP 与开放端口 |

---

## 八、待确认信息

1. **使用哪个域名**？（需 NS 在 Cloudflare，或可加 CNAME）
2. **Access 邮箱 OTP** 还是 **WARP 私有网络**（完全不对公网暴露，但每台设备要装 WARP）？内部小团队推荐前者。
3. **工单 4 选方案 A（R2 直传）还是 B（压到 100MB 内）**？

---

## 附：核实过的关键数据（出处）

| 结论 | 出处 |
|---|---|
| 请求体上限 Free/Pro = 100MB，超限 413；Enterprise 可自行调整至 5GB | Cloudflare 官方文档「Request body size limits」 |
| Proxy Read Timeout 默认 125 秒，超时 524；Enterprise 可提高至 6000 秒；Proxy Write Timeout 30 秒不可调 | Cloudflare 官方文档「Error 524」 |
| Tunnel 为出站连接，支持 public hostname 与 private network（WARP）两种用法 | Cloudflare Tunnel 官方文档 / Zero Trust |
| `trustProxies(at: '*')` 已配置 | 仓库 `bootstrap/app.php:25` |
| 出片管线依赖 Windows 绝对路径与本地 GPU/模型 | `python-pipeline/server.py:104-110`、`make_avatar_from_dialogue.py:16`、`footage_edit.py:19` |
| 8500 曾公网可达 | `python-pipeline/server.py:100`、`frpc-local.toml`（与 `deploy/frpc.toml` 注释矛盾） |
| 对话出稿同步耗时 200~280 秒 | `php/php.ini` 注释 |
| 上传限制 512M / 256m | `php/php.ini`、`nginx/default.conf` |
