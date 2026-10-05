@echo off
title Restart HGT 8500 (photo-model)
setlocal

net session >nul 2>&1
if %errorLevel% neq 0 (
  echo Requesting administrator permission...
  powershell -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)

echo [1/2] Restarting HGTCommercial8500 ...
D:\tools\nssm\nssm.exe restart HGTCommercial8500
echo nssm exit code: %errorlevel%

echo.
echo [2/2] Waiting 8s, then verifying /photo-model ...
timeout /t 8 /nobreak >nul

curl -s -m 10 --noproxy "*" -o "%TEMP%\photo_probe.json" -w "HTTP %%{http_code}" ^
  -X POST http://127.0.0.1:8500/photo-model ^
  -H "Content-Type: application/json" ^
  -d "{\"photo_path\":\"D:/heygem_data/tmp/phototest/person.jpg\",\"name\":\"probe\",\"duration\":2}"
echo.
type "%TEMP%\photo_probe.json"
echo.
echo.
echo   ok:true  = SUCCESS - photo-model is LIVE
echo   not found = restart failed, run again
echo.
pause