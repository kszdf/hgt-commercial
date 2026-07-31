@echo off
REM 慧根堂商用平台 · 滚动字幕卡出片微服务启动器
REM 双击本文件即可在 Windows 宿主启动 Python 微服务（端口 8500）。
REM 出片功能依赖此进程常驻；关闭即停止出片。
D:/heygem/py310/Scripts/python.exe D:/heygem_data/hgt-commercial/python-pipeline/server.py
pause
