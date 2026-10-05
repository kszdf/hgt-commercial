@echo off
chcp 65001 >nul 2>&1
setlocal enabledelayedexpansion
REM ============================================================
REM  慧根堂平台 · MySQL 每日备份（本机部署专用）
REM ------------------------------------------------------------
REM  为什么必须有：本机 = 单点。MySQL 数据只在 Docker 卷里，本机磁盘一旦挂，
REM  所有租户/任务/口播稿全丢。必须每天备份，并把备份同步到本机之外的介质
REM  （移动硬盘 / 网盘目录 / NAS），只放本机同一块盘等于没备份。
REM
REM  用法：
REM    1) 先改下面的 BACKUP_ROOT，指向「本机之外」的目录（强烈建议）
REM    2) 手动跑一次确认成功
REM    3) 任务计划程序 → 创建基本任务 → 每天 23:30 → 程序填本 .bat
REM       （触发器：每天；操作：启动程序 scripts\daily-backup.bat；起始位置：项目根目录）
REM ============================================================

cd /d "%~dp0"

REM ---- 配置区：把 BACKUP_ROOT 改到本机之外的盘符/网盘目录 ----
REM 例如网盘同步目录：set "BACKUP_ROOT=D:\BaiduNetdiskDownload\hgt-backups"
REM 例如移动硬盘：    set "BACKUP_ROOT=E:\hgt-backups"
set "BACKUP_ROOT=%USERPROFILE%\Documents\hgt-backups"
set "KEEP_DAYS=14"

REM ---- 落到项目内的临时目录（每天自动清理，只是中转）----
set "STAGE_DIR=%CD%\storage\backups"
if not exist "%STAGE_DIR%" mkdir "%STAGE_DIR%"
if not exist "%BACKUP_ROOT%" mkdir "%BACKUP_ROOT%"

set TS=%date:~0,4%%date:~5,2%%date:~8,2%_%time:~0,2%%time:~3,2%%time:~6,2%
set TS=%TS: =0%
set OUTFILE=db_%TS%.sql.gz
set OUT=%STAGE_DIR%\%OUTFILE%

echo ============================================================
echo   慧根堂平台 · MySQL 每日备份
echo   时间：%date% %time%
echo   备份目标：%BACKUP_ROOT%
echo ============================================================
echo.

REM ---- 0) 确认容器在跑 ----
docker-compose ps --format "{{.Name}}" 2>nul | findstr /C:"mysql" >nul 2>&1
if !errorlevel! NEQ 0 (
    echo [错误] mysql 容器未运行，请先跑 scripts\start-local.bat
    goto :fail
)

REM ---- 1) 从 app 容器读数据库凭据（不硬编码密码）----
echo [1/4] 读取数据库配置...
for /f "usebackq delims=" %%i in (`docker-compose exec -T app php artisan tinker --execute^="echo config('database.connections.mysql.database');" 2^>nul`) do set DB_DATABASE=%%i
for /f "usebackq delims=" %%i in (`docker-compose exec -T app php artisan tinker --execute^="echo config('database.connections.mysql.username');" 2^>nul`) do set DB_USERNAME=%%i
for /f "usebackq delims=" %%i in (`docker-compose exec -T app php artisan tinker --execute^="echo config('database.connections.mysql.password');" 2^>nul`) do set DB_PASSWORD=%%i

if not defined DB_DATABASE (
    echo [错误] 读取数据库配置失败，请确认 app 容器健康。
    goto :fail
)
echo   数据库：%DB_DATABASE%
echo.

REM ---- 2) mysqldump 导出并 gzip ----
echo [2/4] 导出数据库...
docker-compose exec -T -e MYSQL_PWD=%DB_PASSWORD% mysql mysqldump --single-transaction --no-tablespaces --routines --triggers -u "%DB_USERNAME%" "%DB_DATABASE%" > "%STAGE_DIR%\_dump_tmp.sql" 2>nul
if !errorlevel! NEQ 0 (
    echo [错误] mysqldump 执行失败
    del /q "%STAGE_DIR%\_dump_tmp.sql" 2>nul
    goto :fail
)

REM gzip：优先用 git 自带（Git for Windows 通常已在 PATH），否则保留未压缩 sql
where gzip >nul 2>&1
if !errorlevel! EQU 0 (
    gzip -c "%STAGE_DIR%\_dump_tmp.sql" > "%OUT%"
    del /q "%STAGE_DIR%\_dump_tmp.sql"
) else (
    move /y "%STAGE_DIR%\_dump_tmp.sql" "%STAGE_DIR%\db_%TS%.sql" >nul
    set OUT=%STAGE_DIR%\db_%TS%.sql
    echo    （未找到 gzip，导出为未压缩 .sql）
)

if not exist "%OUT%" (
    echo [错误] 备份文件生成失败
    goto :fail
)
echo   已生成：%OUT%
echo.

REM ---- 3) 复制到本机之外的备份目标（真正起作用的一步）----
echo [3/4] 同步到备份目录...
copy /y "%OUT%" "%BACKUP_ROOT%\" >nul
if !errorlevel! NEQ 0 (
    echo [错误] 复制到 %BACKUP_ROOT% 失败，请检查目录是否可写
    goto :fail
)
echo   已同步到：%BACKUP_ROOT%\%OUTFILE%
echo.

REM ---- 4) 清理过期备份（保留 KEEP_DAYS 天）----
echo [4/4] 清理 %KEEP_DAYS% 天前的旧备份...
forfiles /p "%BACKUP_ROOT%" /m db_*.sql* /d -%KEEP_DAYS% /c "cmd /c del /q @path" 2>nul
forfiles /p "%STAGE_DIR%" /m db_*.sql* /d -%KEEP_DAYS% /c "cmd /c del /q @path" 2>nul
echo.

echo ============================================================
echo   备份完成
echo ============================================================
echo 备份目录当前内容：
dir /b /o-d "%BACKUP_ROOT%\db_*" 2>nul | more +0
echo.
echo 提醒：只放在本机同一块盘的备份不算备份。请把 %BACKUP_ROOT%
echo       指向网盘同步目录或外接硬盘，并确认那边真的同步了。
echo.
endlocal
exit /b 0

:fail
echo.
echo 备份未完成，请按上方提示处理。
echo.
endlocal
exit /b 1
