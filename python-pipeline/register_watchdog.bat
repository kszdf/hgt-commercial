@echo off
chcp 65001 >nul
REM =============================================================================
REM register_watchdog.bat  —  注册 8500 看门狗为系统计划任务（须以管理员运行）
REM -----------------------------------------------------------------------------
REM 用 SYSTEM 账户、最高权限，每 2 分钟跑一次 watchdog_8500.ps1（单次即退，天然自愈）。
REM 取消注册：register_watchdog.bat /unregister
REM =============================================================================

set "SCRIPT=%~dp0watchdog_8500.ps1"
set "TASK=HGT8500Watchdog"

if /I "%1"=="/unregister" (
    schtasks /Delete /TN "%TASK%" /F
    echo [OK] 已移除看门狗任务 %TASK%
    goto :eof
)

schtasks /Create ^
    /TN "%TASK%" ^
    /TR "powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"%SCRIPT%\"" ^
    /SC MINUTE ^
    /MO 2 ^
    /RU SYSTEM ^
    /RL HIGHEST ^
    /F

if %ERRORLEVEL%==0 (
    echo [OK] 看门狗已注册：每 2 分钟检查 8500 存活，异常自动重启（HGTCommercial8500）。
    echo      日志：%SCRIPT%\..\watchdog_8500.log
    echo      手动触发一次：schtasks /Run /TN "%TASK%"
) else (
    echo [FAIL] 注册失败，请以管理员身份运行本脚本。
)
