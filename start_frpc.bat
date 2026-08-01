@echo off
chcp 65001 >nul 2>&1
setlocal enabledelayedexpansion
cd /d "%~dp0"
set FRPC=bin\frpc.exe
set FRPC_VER=0.61.0

if not exist "%FRPC%" (
  echo [1/2] Downloading frpc v%FRPC_VER%...
  if not exist bin mkdir bin
  set "OK=0"
  for %%U in (
    "https://github.moeyy.xyz/https://github.com/fatedier/frp/releases/download/v%FRPC_VER%/frp_%FRPC_VER%_windows_amd64.zip"
    "https://mirror.ghproxy.com/https://github.com/fatedier/frp/releases/download/v%FRPC_VER%/frp_%FRPC_VER%_windows_amd64.zip"
    "https://ghproxy.net/https://github.com/fatedier/frp/releases/download/v%FRPC_VER%/frp_%FRPC_VER%_windows_amd64.zip"
  ) do (
    if "!OK!"=="0" (
      echo   Trying: %%U
      powershell -Command "Invoke-WebRequest -Uri '%%~U' -OutFile bin\frpc.zip" 2>nul
      if exist bin\frpc.zip (
        powershell -Command "Expand-Archive -Force bin\frpc.zip bin\frpc_tmp" 2>nul
        if exist "bin\frpc_tmp\frp_%FRPC_VER%_windows_amd64\frpc.exe" (
          copy "bin\frpc_tmp\frp_%FRPC_VER%_windows_amd64\frpc.exe" "%FRPC%" >nul
          set "OK=1"
        )
        rmdir /s /q bin\frpc_tmp 2>nul
      )
      if exist bin\frpc.zip del /q bin\frpc.zip 2>nul
    )
  )
  if "!OK!"=="0" (
    echo [ERROR] frpc download failed. Manually download frp_%FRPC_VER%_windows_amd64.zip and extract frpc.exe to bin\
    pause
    exit /b 1
  )
)

echo [2/2] Starting frpc tunnel (config: frpc-local.toml)...
echo       Press Ctrl+C to stop. Closing this window will disconnect cloud-to-local rendering.
"%FRPC%" -c frpc-local.toml
pause
