# 追梦短视频平台 — 系统稳定性诊断与保障方案

- **诊断日期**：2026-08-05
- **诊断范围**：8500 出片微服务 / Docker 编排 / Laravel↔8500 调用链 / 依赖服务（frpc、HEYGEM、MySQL、Redis）/ 日志实证
- **诊断方法**：源码审查（server.py、Controllers、docker-compose、迁移）+ 运行态快照（容器状态、进程、JOBS_DIR、PHP 限制、Laravel 日志错误签名）
- **目标**：定位间歇错误根因 → 给出监控追踪方案 → 制定容错/自愈/压测/灰度保障策略，达到推向租户前的生产级可靠性

---

## 0. 环境快照（诊断当下）

| 项 | 状态 |
|----|------|
| 容器 | nginx/app/mysql/redis 全 `Up`（app 10 分钟前重启过）；heygem-tts-old / heygem-gen-video 全 `Up 4h` |
| 8500 进程 | 宿主 3 个 `python.exe`（Services）：PID 37352（主服务，28MB）+ 另 2 个需审计是否为孤儿/重复 |
| JOBS_DIR | `python-pipeline/jobs`：**38 个目录 / 39MB**，无清理策略 |
| PHP | `max_execution_time=0`（不限制）、`memory_limit=128M` |
| Docker | 4 容器均 `restart: always`，但**无 healthcheck、无资源上限** |
| 8500 并发护栏 | `GLOBAL_MAX_JOBS=3` / `TENANT_MAX_JOBS=2` / `HARD_TIMEOUT=2100s` |
| 仓库状态 | 大量未提交改动（server.py、3 个 Controller、多个 blade、compose、frpc）；根目录遗留 `c3.txt/c4.txt/.../ck2.txt` 等临时文件 |

---

## 1. 间歇错误根因分析

### 1.1 已确认的代码层缺陷（来自日志实证）

| # | 缺陷 | 日志实证 | 现状 | 严重度 |
|---|------|----------|------|--------|
| C1 | `publish.blade.php` 漏 `</x-app-layout>` 闭合标签 | `08-05 06:10~06:12` 连续 3 次 `syntax error, unexpected end of file`（GET/POST 全 500） | **已修复**（补闭合 + `view:clear`） | 阻塞性（已消） |
| C2 | `VideoController.php:81` `Undefined variable $job` | `08-01 10:07~10:10` 多次 | 文件已改，当前 81 行已非该上下文，**需确认无残留** | 历史（待核） |
| C3 | `cover_assets.user_id` NOT NULL 约束冲突 | `08-02 06:14` `SQLSTATE[23000]: 1048 Column 'user_id' cannot be null`（预设封面种子插入） | **潜伏未修**：预设封面 `is_preset=1` 无归属用户，种子/租户上传若该列缺值即 500 | 中（种子/上传路径） |
| C4 | 未提交改动堆积 + 临时文件 | `git status` 显示 15+ 文件 modified、10+ 个 `c*.txt` 散落根目录 | **未处理**：部署状态与仓库歧义，叠加 bind-mount + opcache 不重校验 → "改了不生效/行为漂移"幻觉 | 高（部署可信度） |

> **C4 是"看起来不稳定"的重要隐性来源**：compose 用 `.:/var/www` 把宿主机代码直接挂进容器，而 PHP `opcache` 不按 mtime 重校验。改了文件 ≠ 容器立刻生效，必须 `view:clear` / 重启 FPM / `npm run build`。任何"我明明改了却没变 / 时好时坏"都源于此。

### 1.2 架构层潜伏风险（负载/云路径下将转化为真实间歇故障）

| # | 风险 | 触发条件 | 影响 | 严重度 |
|---|------|----------|------|--------|
| A | **Laravel→8500 调用零重试** | 8500 重启 / frpc 抖动 / HEYGEM 短暂卡顿 → `ConnectionException` 未捕获 | 任意瞬时不可达 → 前端直接 500，无兜底 | **高** |
| B | **长阻塞出站调用占用 FPM worker** | `/process-asset`(300s)、`/publish`(180s)、`/clone_voice`(120s)、`/qc-video`(90s) 在请求内同步等待；`max_execution_time=0` | 少量并发重操作耗尽 FPM worker → 其他用户 503/排队超时 | **高** |
| C | **Docker 无 healthcheck / 无资源上限** | 部署/重启后 nginx 代理到未就绪 app → 502；某容器内存泄漏/OOM → 宿主机 OOM killer 级联宕机 | 启动期空白页、级联崩溃 | **高** |
| D | **8500 不在 compose 自愈网内** | 8500 是 Windows NSSM 服务（最重组件）；NSSM 仅进程退出时重启，**挂死（活着但不响应）不重启** | 8500 假死 → 所有 `/status /oauth /generate` 挂到 Laravel 超时 → 全站相关功能失败 | **高** |
| E | **frpc 隧道波动（云路径）** | `hgt-pipeline-8500` 隧道掉线（历史有 Defender 隔离 / 孤儿 PID 问题）；frpc 非 NSSM 自启 | 云端 8080→8500 调用连接失败；本地 127 直连不受影响 → **仅云端间歇报错** | **中** |
| F | **HEYGEM 依赖波动（真人出镜）** | `heygem-gen-video:8383`（GPU）慢/重启/OOM → avatar 任务挂到 `HARD_TIMEOUT`(2100s) 才失败 | 真人出镜模块专属间歇失败、用户看到"渲染中"卡 35 分钟 | **中** |
| G | **JOBS_DIR 无保留清理（磁盘慢泄漏）** | 每任务存成片 + `job.json`；`tempfile.NamedTemporaryFile(delete=False)` 异常路径不清理 | 数月后磁盘满 → 写入失败 → 全局间歇 500 | **中** |
| H | **无结构化监控/追踪** | 错误只落 `laravel.log` + 8500 stdout；无 request_id 串联、无告警 | 用户要求的"精确定位每次故障触发条件"当前**做不到** | **高（可观测性）** |
| I | **并发护栏可被绕过** | Laravel 侧按 `video_jobs.status='queued'` 计数，但 8500 已接收的 `rendering` 不计入 → Laravel 低估在跑数 | 8500 可能接收超过 `GLOBAL_MAX_JOBS` 的并发 → 资源争抢 | **低** |
| J | **DB 弱口令 + 3306 暴露宿主** | `rootpass_change_me`、MySQL 端口发布到宿主 | 租户上线前安全隐患，被入侵即不稳定 | **中（安全）** |

### 1.3 根因归纳

间歇不稳定的本质不是"随机 bug"，而是**单点重依赖缺少韧性**：

1. **无重试 + 无降级**：任何瞬时网络/进程抖动都被放大为前端 500（风险 A）。
2. **同步重操作阻塞 Web 层**：长耗时出站调用占满 FPM（风险 B），系统在高并发下自锁。
3. **无健康探测**：容器与 8500 的"活着≠健康"无法被自动识别与重启（风险 C/D）。
4. **无资源与生命周期治理**：内存无上限、产物无清理、临时文件泄漏（风险 C/G）。
5. **无可观测性**：故障无法定位、无法预警（风险 H）。
6. **部署状态歧义**：未提交改动 + bind-mount + opcache 不重校验，制造"行为漂移"错觉（风险 C4）。

---

## 2. 错误监控与日志追踪方案

目标：让**每一次故障的触发条件都可被精确定位**。

### 2.1 结构化日志 + request_id 串联
- **Laravel**：在 `bootstrap/app.php` 中间件注入 `request_id`（UUID），所有日志带 `request_id`、`tenant_id`、`route`。写入 JSON 而非纯文本：
  ```php
  // 中间件示例
  $rid = (string) Str::uuid();
  Log::withContext(['request_id' => $rid, 'tenant_id' => $tenantId ?? null]);
  header('X-Request-Id: ' . $rid);
  ```
- **8500（server.py）**：每条请求开头打印 `{"ts":,"rid":,"path":,"tenant":}`，job 状态变更（`_set_job`）也带 `rid`。把 stdout 重定向到带日期的日志文件并**按日轮转**（NSSM 配置 `AppStdout`/`AppStderr` 指向 `logs/server_%Y%m%d.log`）。
- **调用链串联**：Laravel 调 8500 时把 `X-Request-Id` 透传（自定义 `Http::withHeaders(['X-Request-Id'=>$rid])`），8500 记入同一条 `rid`，实现跨服务追踪。

### 2.2 健康检查端点矩阵
| 端点 | 实现 | 用途 |
|------|------|------|
| `8500 /health` | 已存在，返回 `{"status":"ok"}` | 浅探测（进程活） |
| `8500 /health/deep` | **新增**：校验 HEYGEM 可达 + 磁盘剩余 > 5GB + job 目录可写 | 深度就绪（决定是否接单） |
| `nginx /healthz` | 新增 Laravel 路由返回 200 | compose/nginx 健康探针 |
| MySQL/Redis | `mysqladmin ping` / `redis-cli ping` | 依赖健康 |

### 2.3 关键指标采集（Prometheus 风格，或轻量自存）
| 指标 | 来源 | 告警阈值 |
|------|------|----------|
| 在跑渲染任务数 | 8500 `active_total` | > `GLOBAL_MAX_JOBS` 持续 5min → 告警 |
| 出片耗时 P50/P95 | job 起止时间戳 | P95 > 1800s → 告警 |
| 任务失败率 | `status=failed / 总数` | > 5% / 10min → 告警 |
| 8500 `/health` 可达率 | 探针 | 连续 2 次非 200 → 自动重启 |
| frpc 隧道状态 | 探针 | down > 30s → 重启 frpc |
| 宿主磁盘剩余 | node_exporter / 脚本 | < 10GB → 告警；< 5GB → 紧急 |
| 容器内存 | cAdvisor / `docker stats` | 接近 `mem_limit` → 告警 |
| Laravel 5xx 率 | nginx 日志 / 应用埋点 | > 1% / 5min → 告警 |

### 2.4 错误聚合与告警
- **轻量自建**（零外部依赖）：一个 `errors` 表 + 全局异常处理器落库，后台页面按 `code`/`route` 聚合，超阈值发邮件/Webhook。
- **或更稳妥**：接 Sentry（Laravel + Python 两端 SDK），按环境/租户分项目，配置上述阈值告警。
- 分级：P0（全站 5xx 突增 / 8500 down）→ 短信；P1（单模块失败率超阈）→ 邮件；P2（磁盘/内存预警）→ 工单。

### 2.5 日志留存与轮转
- Laravel 日志接入 `logrotate` 或 `MonoLog RotatingFileHandler`（保留 30 天）。
- 8500 日志按日轮转，旧文件 > 14 天删除。
- 敏感信息（token、密钥）**绝不入日志**（已要求 key 不落对话，同样不落日志）。

---

## 3. 稳定性保障策略

### 3.1 容错（重试 / 熔断 / 降级）
- **Laravel→8500 全部加重试 + 优雅降级**（修复风险 A）：
  ```php
  $resp = Http::timeout(15)
      ->retry(3, 200, throw: false)   // 重试3次/间隔200ms/失败不抛异常
      ->withHeaders(['X-Request-Id' => $rid])
      ->post($url, $data);
  if ($resp->failed()) {
      return response()->json(['error' => '出片服务暂时不可用，请稍后重试',
                               'code' => 'pipeline_unavailable'], 503);
  }
  ```
  轮询类（`/status`、`/oauth/status`）包 `try/catch`，失败时返回"上次已知状态"而非报错。
- **8500 内部熔断**：对 HEYGEM、`/clone_voice`（CosyVoice）等外部依赖加失败计数，连续失败超阈值则短期拒绝新任务并返回明确"依赖不可用"提示（修复风险 F 的部分）。
- **前端降级**：8500 不可达时，出片页显示"服务维护中"而非白屏/500。

### 3.2 自动恢复
- **容器自愈**：compose 加 healthcheck + 资源上限（修复风险 C）：
  ```yaml
  app:
    restart: always
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/healthz"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 60s
    deploy:
      resources:
        limits: { memory: 512M, cpus: "1.0" }
  mysql:
    healthcheck: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
  redis:
    healthcheck: ["CMD", "redis-cli", "ping"]
  ```
  nginx `depends_on: { app: { condition: service_healthy } }`，避免代理到未就绪 app。
- **8500 挂死探针 + 自动重启**（修复风险 D，需管理员 PowerShell 计划任务，每 60s）：
  ```powershell
  $r = Invoke-WebRequest http://127.0.0.1:8500/health -TimeoutSec 5 -ErrorAction SilentlyContinue
  if ($r.StatusCode -ne 200) {
      sc.exe stop HGTCommercial8500
      # 先杀 python 孤儿避免端口占用（1056）
      Get-CimInstance Win32_Process -Filter "CommandLine LIKE '%server.py%'" | Stop-Process -Force
      sc.exe start HGTCommercial8500
  }
  ```
- **frpc 看门狗**（修复风险 E）：frpc 改为 NSSM 服务常驻；脚本每 60s 探测 `8500/oauth/status/wechat` 经公网地址可达性，掉线即重启 frpc。
- **HEYGEM 就绪门**（修复风险 F）：`/health/deep` 探测 HEYGEM；avatar 任务提交前先做 HEYGEM 可达预检，不可达则直接返回"数字人服务暂不可用"而非挂着到超时。8500 已有看门狗（60s 回收卡死任务），保留并缩短 `HARD_TIMEOUT+120` 回收窗口或加步骤级超时。

### 3.3 资源与生命周期治理
- **JOBS_DIR 保留清理**（修复风险 G）：每日定时任务删除 > 30 天的 job 目录（DB 已存元数据，`/download` 仅短期需要）；server.py 中 `tempfile` 清理移到 `finally`，确保异常路径也删。
- **宿主监控**：装 `node_exporter` 或简单脚本定时记录 CPU/内存/磁盘并告警；宿主机同时跑 8500 + HEYGEM(GPU/ffmpeg)，是资源瓶颈点。
- **审计 8500 进程**：确认 3 个 `python.exe` 均预期（8500 主服务 + HGTStudio·8385 + ？），清掉孤儿。

### 3.4 压力测试方案
- **目标基线**：在 `GLOBAL_MAX_JOBS=3` 下，验证系统不雪崩；API 常规 QPS 下 P95 延迟 < 1s。
- **工具**：`k6`（HTTP 压测）+ 自带 `_validate_*.py` 式脚本（已验证可用）。
- **场景**：
  1. **提交风暴**：并发 20 个 `/generate`（含 scroll+avatar 混合），验证 429 护栏生效、无 5xx、失败率 0。
  2. **轮询压力**：100 并发 `/status` 轮询，验证 P95 < 1s、8500 不阻塞（验证 ThreadingHTTPServer 够用）。
  3. **长链路**：跑满 3 并发真实头像渲染，观测内存/磁盘增长曲线。
  4. ** soak（ soak test）**：12h 持续低负载，检测内存泄漏（风险 C/G）。
- **通过标准**：无 5xx（除预期 429/422/503）、P95 达标、压测前后内存回落后差值 < 50MB、磁盘无异常增长。

### 3.5 渐进式灰度发布
- **环境分离**：新增 staging 环境（复用 compose，独立 `APP_ENV=staging` + 独立 DB），上线前先在 staging 跑压测与回归。
- **特性开关 + 租户白名单**：`features` 配置（或 `tenants` 表 `beta` 标志），新功能（如某平台发布、新模特）先对白名单租户开放。
- **流量切分**：Nginx 按 `tenant_id`/Cookie 分流到新旧版本（或用不同镜像标签）。
- **灰度节奏**：内部租户(老张) 100% → 白名单友好租户 5% → 20% → 50% → 全量；每档观察 24h 关键指标（失败率/延迟/5xx）。
- **回滚**：开关翻转或回退镜像标签，< 5 分钟生效；保留上一稳定镜像。
- **发布检查单**：① 改动已 commit ② `view:clear`+重启 FPM ③ `npm run build` ④ 跑 E2E 冒烟 ⑤ 开监控看板 ⑥ 灰度放行。

---

## 4. 优先级行动清单

### P0（上线前必做，止血类）
1. **Laravel→8500 全加 `retry` + 优雅 503 降级**（风险 A）—— 直接消灭"瞬时抖动即 500"。
2. **compose 加 healthcheck + `mem_limit/cpus` + `depends_on: condition healthy`**（风险 C）。
3. **8500 挂死探针 + 自动重启脚本**（NSSM + 计划任务）（风险 D）。
4. **修复 C3**：`cover_assets.user_id` 改为 `nullable`（预设封面归属空）或种子写入系统用户，消除上传/种子 500。
5. **清理 C4**：commit 全部待提交改动（含已验证修复）、删除根目录 `c*.txt` 临时文件；确立"改动必须 commit + 部署走镜像而非裸 bind-mount"的铁律。

### P1（上线前，韧性类）
6. `/health/deep` + HEYGEM 预检门（风险 F）。
7. frpc 改 NSSM 常驻 + 看门狗（风险 E）。
8. JOBS_DIR 30 天清理 + temp `finally` 清理（风险 G）。
9. 结构化日志 + `request_id` 串联 + 日志轮转（风险 H / 2.1）。

### P2（持续，可观测与演进）
10. 指标采集 + 错误聚合告警（2.3/2.4）。
11. 长阻塞调用迁 Laravel 队列（`artisan queue:work`，compose 已留注释位）（风险 B）。
12. staging 环境 + 特性开关灰度（3.5）。
13. 压测基线建立 + 定期回归（3.4）。
14. DB 强口令 + 取消 3306 宿主暴露（风险 J）。

---

## 附录：快速排障命令

```bash
# 服务存活
curl -s -o /dev/null -w "8080=%{http_code}\n" http://127.0.0.1:8080/healthz
curl -s http://127.0.0.1:8500/health
# 容器
docker ps --format "table {{.Names}}\t{{.Status}}"
docker stats --no-stream
# 8500 进程审计
tasklist | grep -i python
# 磁盘
df -h /d
# 近期错误
docker exec hgt-commercial-app-1 sh -c "grep -iE 'ERROR|Exception|Connection|timeout' /var/www/storage/logs/laravel.log | tail -n 40"
# 重启（按铁律）
docker restart hgt-commercial-app-1        # PHP 改动
php artisan view:clear                     # Blade 改动（容器内）
# 8500 重启（管理员）
sc.exe stop HGTCommercial8500 && sc.exe start HGTCommercial8500
```

---

*本报告基于 2026-08-05 源码审查与运行态快照。C1 已修复；C3/C4 及全部架构风险项待实施。建议从 P0 起逐项落地，每完成一项复测一次，再推进灰度。*
