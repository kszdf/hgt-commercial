@echo off
REM ============================================================
REM 慧根堂 — 本机出片微服务穿透启动器（混合云路线 A）
REM 作用：把本机 Windows 的 Python 出片微服务(8500) 经 frp 穿透到
REM       云服务器 hgtcs 的 8500 端口，Laravel 云端即可调用本地渲染。
REM 用法：双击本文件即可（首次会自动下载 frpc 客户端）。
REM ============================================================
setlocal
cd /d "%~dp0"
set FRPC=bin\frpc.exe
set FRPC_URL=https://ghproxy.com/https://github.com/fatedier/frp/releases/download/v0.61.0/frpc_0.61.0_windows_amd64.zip

if not exist "%FRPC%" (
  echo [1/2] 首次运行，下载 frpc 客户端...
  if not exist bin mkdir bin
  powershell -Command "Invoke-WebRequest -Uri '%FRPC_URL%' -OutFile bin\frpc.zip"
  powershell -Command "Expand-Archive -Force bin\frpc.zip bin\frpc_tmp"
  copy "bin\frpc_tmp\frpc_0.61.0_windows_amd64\frpc.exe" "%FRPC%" >nul
  rmdir /s /q bin\frpc_tmp
  del /q bin\frpc.zip
)

echo [2/2] 启动 frpc 穿透（配置文件 frpc-local.toml）...
"%FRPC%" -c frpc-local.toml
pause
