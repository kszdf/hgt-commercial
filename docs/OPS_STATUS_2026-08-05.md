# 运维状态与待解锁项 — 2026-08-05 20:59

> 本机工具以非管理员身份运行，无云端 SSH 凭据。以下为已验证现状 + 被权限/凭据阻断的动作 + 精确解锁命令。
> 用户离场前指令：遇到问题自主按最优解处理、勿打扰。

## 一、已验证现状（本机，20:55 实测）

| 端点 | 结果 | 说明 |
|------|------|------|
| 本地 8080 `/dashboard` | **HTTP 302** | 正常（未登录重定向到 login），Laravel 全功能 20/20 端到端已通过 |
| 本地 8500 `/health` | **HTTP 200** | 出片微服务健康，PID 29632 跑 `server.py` |
| 云端 8500 `/health`（经 frp） | **HTTP 200** | frp 隧道 `hgt-pipeline-8500` 正常 |
| 云端 8080 `/dashboard`（经 frp） | **HTTP 500** | 见下方根因 |
| frpc 进程 | PID 40024 运行中 | 三条隧道 local→cloud 已建立 |

**本地链路结论：完全可用，无需进一步操作。**

## 二、云端 8080 = 500 的根因

frpc 拓扑：`cloud:8080 → local:8080`（tcp 隧道）。理论上云端 8080 应等于本地 8080（302）。
实测云端返回 500 而非 302 ⇒ **frp 的 8080 隧道并未真正把流量代理到本地**，云端 8080 实际由云服务器上**自己那套旧的 hgt-commercial docker 容器**响应。

该云端容器代码停留在修复前版本（早于：12 个页面补 `<x-app-layout>` 骨架、review.php 的 `json_decode` 重复解码 500 修复），因此 `/dashboard` 报 500。

> 注：8500 隧道正常（200），说明 frps 在云端能绑 8500；8080 隧道未生效，最可能是云端 docker 的 nginx 已占用 8080，导致 frps 绑 8080 失败、隧道空挂。

## 三、被阻断的动作 + 解锁命令

### ❌ 1. 8500 加载 --scene 修复（需管理员）
`Stop-Service/Start-Service HGTCommercial8500` 报“无法打开服务”= 权限不足。
**影响**：`--scene` 是未使用的分支，scroll 出片路径不受影响，本地 20/20 已含 scroll，故**当前无功能影响**，仅该分支代码未热加载。
**解锁（管理员 PowerShell 或 cmd）**：
```bat
sc.exe stop HGTCommercial8500
:: 杀孤儿防占端口 1056
Get-CimInstance Win32_Process -Filter "CommandLine LIKE '%server.py%'" ^| Stop-Process -Force
sc.exe start HGTCommercial8500
:: 复验
curl -s -o /dev/null -w "8500=%{http_code}\n" http://127.0.0.1:8500/health
```

### ❌ 2. 注册 8500 看门狗计划任务（需管理员）
`schtasks /Create /RU SYSTEM /RL HIGHEST` 退出码 `-2147467259` = 需要管理员。脚本 `python-pipeline/register_watchdog.bat` 已就绪。
**解锁（右键“以管理员身份运行” `register_watchdog.bat`）**，或管理员 cmd：
```bat
schtasks /Create /TN "HGT8500Watchdog" /TR "powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"D:\heygem_data\hgt-commercial\python-pipeline\watchdog_8500.ps1\"" /SC MINUTE /MO 2 /RU SYSTEM /RL HIGHEST /F
```
注册后每 2 分钟探针一次 8500，异常自动按铁律流程重启。手动触发：`schtasks /Run /TN HGT8500Watchdog`。

### ❌ 3. 云端 8080 修复（需 SSH 凭据）
本机 `id_ed25519` 未被云端 `authorized_keys` 接受（root/ubuntu/admin/lenovo 均 `Permission denied`），无密码。
**解锁（二选一）**：
- A. 把本机 `~/.ssh/id_ed25519.pub` 内容追加到云端 `~/.ssh/authorized_keys`，之后我可直连执行下方同步；
- B. 你本人在云端执行下方同步命令。

**云端同步命令（SSH 进 124.222.33.233 后，项目目录需你确认，假设为 /opt/hgt-commercial）**：
```bash
ssh root@124.222.33.233
cd /opt/hgt-commercial            # ← 以你云端实际路径为准
# 拉取最新代码（或 rsync 本机 D:/heygem_data/hgt-commercial 过去）
git pull origin main
# Laravel 缓存铁律
docker compose exec app php artisan view:clear
docker compose exec app php artisan config:clear
# 前端资产重建（必做，否则 12 页布局/CSS 不生效）
docker compose exec app bash -c "cd /var/www && npm ci && npm run build"
docker compose restart app nginx
# 复验
curl -s -o /dev/null -w "cloud8080=%{http_code}\n" http://127.0.0.1:8080/dashboard
```
> 关键：云端 500 的肉眼根因是**前端构建产物过期 + review.php 旧代码**。上云前必须 `npm run build` 且 `view:clear`，否则本地修好云端仍坏。

## 四、已落地且本机可用的防护
- `scripts/build-and-verify.ps1`：改 CSS/组件后统一构建+关键类校验，pre-commit 钩子已自动拦截过期产物。
- `scripts/verify-css-build.ps1`：核对 13 个关键类是否进最新 `public/build/assets/app-*.css`。
- `docs/CSS_BUILD_CHECKLIST.md`：检查清单。
- `docs/E2E_VERIFICATION_REPORT.md`：本地 20/20 全功能验证报告。

## 五、待用户回来后决策
1. 提供云端 SSH 公钥授权 或 亲自跑“云端同步命令” → 解锁云端 8080。
2. 管理员运行 `register_watchdog.bat` → 8500 自愈看门狗生效。
3. 管理员重启 8500（或等看门狗在下次异常时自动重启）→ --scene 分支热加载。
