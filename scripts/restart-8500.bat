@echo off
chcp 65001 >nul
title Restart HGTCommercial8500 (Async Job Build)

net session >nul 2>&1
if %errorLevel% neq 0 (
  echo Requesting administrator permission...
  powershell -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)

echo.
echo [1/3] Restarting HGTCommercial8500 ...
D:\tools\nssm\nssm.exe restart HGTCommercial8500
echo nssm exit code: %errorlevel%

echo.
echo [2/3] Waiting 8s for service to come up ...
timeout /t 8 /nobreak >nul

echo.
echo ===== Verify 1/2 : /async/submit (expect HTTP 202 + job_id) =====
curl -s -m 10 --noproxy "*" -o "%TEMP%\async_probe.json" -w "HTTP %%{http_code}\n" -X POST http://127.0.0.1:8500/async/submit -H "Content-Type: application/json" -d "{\"path\":\"/topic\",\"payload\":{\"industry\":\"test\"}}"
type "%TEMP%\async_probe.json"
echo.

echo ===== Verify 2/2 : /async/status/job_id =====
powershell -NoProfile -Command "$j = (Get-Content $env:TEMP\async_probe.json -Raw | ConvertFrom-Json).job_id; Write-Host ('job_id = ' + $j); Start-Sleep -Seconds 4; curl.exe -s -m 15 --noproxy '*' ('http://127.0.0.1:8500/async/status/' + $j)"
echo.

echo.
echo [3/3] Result
echo   HTTP 202 + job_id        ===== new code is LIVE, async works
echo   error: not found / 404   ===== restart FAILED
echo.
pause
