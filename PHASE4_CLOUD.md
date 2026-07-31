# Phase 4 · 上云部署与 8385 退役方案

> 目标：将本商用平台部署到云服务器（124.222.33.233 / 实例 hgtcs，到期 2027-07-13），
> 达到可供外部访问的商用 SaaS，并让本地遗留原型平台 8385 正式退役。

---

## 一、架构与依赖约束（部署前必读）

| 组件 | 运行位置 | 说明 |
|------|----------|------|
| Laravel + Livewire + FluxUI 前端/后端 | Docker（php-fpm + nginx + mysql + redis） | 全容器化，预览 8080 |
| 视频出片微服务（Python） | **Windows 宿主**（非容器） | 依赖宿主机 ffmpeg 绝对路径 + 本地 `qwen_tts` + HEYGEM + dashscope key |
| 本地数字人 HEYGEM | Docker（heygem-gen-video:8383） | 数字人嘴型对齐，须本机 GPU/CPU 可用 |
| 真实配音 CosyVoice | 阿里云 dashscope（联网） | `model_keys.env` 的 `DASHSCOPE_API_KEY` |

**关键约束**：视频微服务无法进 Linux 容器（依赖 Windows ffmpeg 路径与本地模块）。
因此上云有两种路线：

### 路线 A（推荐 · 混合云）：平台后端上云，出片微服务留在本地 Windows 机器
- 云服务器跑 Laravel 容器栈（对外提供 Web）。
- 本地 Windows 机器继续跑 Python 出片微服务 + HEYGEM；通过**内网穿透/frp**把本地 8500 暴露给云服务器调用。
- 优点：复用已验证的本地出片环境，无需在云上重建 ffmpeg/GPU/HEYGEM。
- 已具备：云服务器 frps 已起（token `hgt2026studio#K8mPq`，端口需与 frpc 配置对齐）。

### 路线 B（纯云）：出片微服务也上云
- 需在云服务器装 Windows 虚拟机（含 ffmpeg、PY310、HEYGEM Docker、dashscope key），或用 Linux 等价管线。
- 成本/复杂度高，且 HEYGEM 人脸模型在 Linux 容器已验证可跑（参见老平台 Docker）。
- 仅当要彻底脱离本地机器时采用。

**本方案默认路线 A**（最快达到可商用外部访问，且复用已验证管线）。

---

## 二、云服务器部署步骤（Laravel 容器栈）

### 1) 代码上云
```bash
# 本地已 git 管理（仓库 git@github.com:kszdf/szrstudio.git 的 hgt-commercial 子目录或独立仓库）
git push  # 推到云端可拉取的仓库
# 云服务器：
git clone <repo> /opt/hgt-commercial && cd /opt/hgt-commercial
cp .env.example .env   # 填云环境配置（见下）
```

### 2) 云 .env 关键差异
```
APP_URL=https://your-domain.com
DB_HOST=mysql
DB_DATABASE=hgt_commercial
DB_USERNAME=hgt
DB_PASSWORD=<强密码>
SESSION_DRIVER=redis        # 生产用 redis 而非 file
CACHE_STORE=redis
QUEUE_CONNECTION=redis
PYTHON_PIPELINE_URL=http://<frp域名或内网地址>:8500   # 指向本地出片微服务（路线A）
```

### 3) 构建并起栈
```bash
docker compose -f docker-compose.yml build --no-cache
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
docker compose exec app php artisan storage:link
```

### 4) HTTPS（生产必需）
- 用 Caddy / Traefik 反向代理 nginx:8080，自动签发 Let's Encrypt。
- 或 nginx 容器直接配证书（已有 `nginx/default.conf`，补 `listen 443 ssl` + 证书路径）。

### 5) 本地出片微服务经 frp 暴露（路线 A）
- 本地 `frpc.ini` 增加：`[hgt-pipeline] type=tcp local_ip=127.0.0.1 local_port=8500 remote_port=<云端口>`。
- 云 `.env` 的 `PYTHON_PIPELINE_URL` 指向 `http://<云公网IP或域名>:<云端口>`。

---

## 三、8385 老平台退役清单

8385（NSSM 服务 `HGTStudio`，托管 `rewrite_studio.py`）为遗留原型，**非商用**。
新平台商用后按以下顺序退役：

1. **确认替代完整**：新平台已覆盖 8385 的核心能力
   - [x] 多租户账号体系（注册/登录/隔离）
   - [x] 滚动字幕卡出片（真实配音）
   - [x] 本地数字人出片（HEYGEM）
   - [ ] 选题/二创（Phase 后续，未上线前可暂保留 8385 只读）
2. **通知并冻结**：停止向 8385 录入新任务；公告用户迁移到新平台。
3. **停服务**：`Stop-Service HGTStudio` → `sc delete HGTStudio`（或 NSSM `nssm remove HGTStudio`）。
4. **保留源码**：8385 代码仍在 GitHub 仓库，不删除（留档/回滚）。
5. **回收资源**：本地 `D:/heygem_data/gpt_sovits/rewrite_studio.*` 可归档。

> 退役只在「新平台经真实商用测试且无功能性缺口」后进行，避免业务中断。

---

## 四、当前已完成 vs 待办（截至本阶段）

**已完成（已端到端验证）**
- 多租户数据层 + 真实注册/登录（PHP 8.4 + MySQL utf8mb4）
- 滚动字幕卡出片（真实 CosyVoice 配音，闭环验证通过）
- 本地数字人出镜出片（avatar 模式，HEYGEM 嘴型对齐，闭环验证中）
- 计费基础：套餐/月度配额/用量统计/配额拦截/计费页

**待办（Phase 后续）**
- 支付网关接入（微信支付/Stripe）—— 当前升级套餐为直接额度切换，用于测试
- 选题中心 / 智能二创 模块（dashboard 已占位）
- 第三方 SaaS 数字人通道（组织认证门槛高，暂缓）
- 云服务器实际部署 + 域名/HTTPS + frp 打通（需执行本手册步骤）
- 8385 退役（待上述无缺口后执行）
