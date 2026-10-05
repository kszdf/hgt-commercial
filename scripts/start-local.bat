@echo off
chcp 65001 >nul 2>&1
setlocal enabledelayedexpansion
REM ============================================================
REM  慧根堂平台 · 本机一键拉起（开机/重启后用它恢复全套服务）
REM ------------------------------------------------------------
REM  依赖前提（只需配一次，见《慧根堂平台-CF隧道本机部署方案.md》工单 1/6）：
REM   1) Docker Desktop 设为「开机自启」（Settings → General → Start Docker Desktop when you log in）
REM   2) cloudflared 已 cloudflared service install 注册为 Windows 服务（开机自启）
REM   3) 8500 出片微服务已用 NSSM 注册为服务 HGTCommercial8500（开机自启）
REM   4) Windows 电源计划关闭休眠/睡眠（见脚本末尾「每夜备份说明」前的提示）
REM
REM  本脚本做三件事：
REM   a) 拉起 Docker 栈（nginx:8080 + app + mysql + redis + video-sync + scheduler）
REM   b) 校验 8500 与 cloudflared 是否在跑，没跑就提示怎么起（本脚本不擅自注册服务）
REM   c) 逐个探活，把结果打到屏幕上，方便一眼确认
REM ============================================================

cd /d "%~dp0"

echo.
echo ============================================================
echo   慧根堂平台 · 本机拉起
echo   时间：%date% %time%
echo ============================================================
echo.

REM ---- 0) 等 Docker 引擎就绪（开机后 Docker Desktop 起来要一会儿）----
echo [0/4] 等待 Docker 就绪...
set DOCKER_OK=0
for /L %%i in (1,1,30) do (
    docker info >nul 2>&1
    if !errorlevel! EQU 0 (
        set DOCKER_OK=1
        goto :docker_ready
    )
    timeout /t 5 /nobreak >nul
)
:docker_ready
if "!DOCKER_OK!"=="0" (
    echo   [错误] Docker 未就绪。请确认 Docker Desktop 已启动，然后重跑本脚本。
    echo         提示：把 Docker Desktop 加到开机启动项可避免每次手动开。
    goto :fail
)
echo   Docker 已就绪
echo.

REM ---- 1) 拉起 Docker 栈 ----
echo [1/4] 启动 Docker 容器栈...
docker-compose up -d
if !errorlevel! NEQ 0 (
    echo   [错误] docker-compose up 失败，请查看上方报错。
    goto :fail
)
echo.

REM ---- 2) 等 nginx 通过健康检查（最多 90 秒）----
echo [2/4] 等待服务健康检查通过...
set NGINX_OK=0
for /L %%i in (1,1,18) do (
    curl -s -o nul -m 3 http://127.0.0.1:8080/up >nul 2>&1
    if !errorlevel! EQU 0 (
        set NGINX_OK=1
        goto :nginx_ready
    )
    timeout /t 5 /nobreak >nul
)
:nginx_ready
if "!NGINX_OK!"=="1" (
    echo   Web 层已就绪（http://127.0.0.1:8080/up 返回 200）
) else (
    echo   [警告] Web 层 90 秒内未通过健康检查，请查：docker-compose logs nginx
)
echo.

REM ---- 3) 校验 8500 出片微服务 ----
echo [3/4] 校验 8500 出片微服务...
curl -s -m 5 http://127.0.0.1:8500/health | findstr /C:"\"ok\"" /C:"ok" >nul 2>&1
if !errorlevel! EQU 0 (
    echo   8500 已就绪
) else (
    echo   [警告] 8500 无响应。若已用 NSSM 注册服务，用管理员 PowerShell 执行：
    echo           Restart-Service HGTCommercial8500
    echo         否则请手动启动出片微服务。
)
echo.

REM ---- 4) 校验 cloudflared 隧道 ----
echo [4/4] 校验 Cloudflare 隧道服务...
sc query cloudflared 2>nul | findstr /C:"RUNNING" >nul 2>&1
if !errorlevel! EQU 0 (
    echo   cloudflared 服务正在运行
) else (
    echo   [警告] cloudflared 服务未运行。管理员 PowerShell 执行：
    echo           Start-Service cloudflared
    echo         若尚未安装服务： cloudflared service install
)
echo.

REM ---- 汇总 ----
echo ============================================================
echo   拉起完成。容器状态：
echo ============================================================
docker-compose ps --format "table {{.Name}}\t{{.State}}\t{{.Ports}}"
echo.
echo 内网自检： http://127.0.0.1:8080/login
echo 外网访问： https://app.你的域名      （需 cloudflared 正常 + 本机不休眠）
echo.
echo 提示：本机器是单点，请务必在「电源选项」里把休眠/睡眠设为「从不」，
echo       否则机器一睡公网域名就 502（可在 管理员 CMD 执行：powercfg -h off 关闭休眠文件）。
echo.
endlocal
exit /b 0

:fail
echo.
echo 拉起未完成，请按上方提示处理后重跑本脚本。
echo.
endlocal
exit /b 1
