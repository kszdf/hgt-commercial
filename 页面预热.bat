@echo off
chcp 65001 > nul
echo.
echo ============================================
echo   追梦平台 · 页面预热
echo ============================================
echo.
echo 正在预热（约 5-15 秒，请勿关闭窗口）...
echo.

curl -s -o NUL -m 90 http://127.0.0.1:8080/up
echo   [1/5] 框架核心就绪
curl -s -o NUL -m 90 http://127.0.0.1:8080/login
echo   [2/5] 登录页就绪
curl -s -o NUL -m 90 http://127.0.0.1:8080/register
echo   [3/5] 注册页就绪
curl -s -o NUL -m 90 http://127.0.0.1:8080/forgot-password
echo   [4/5] 找回密码就绪
curl -s -o NUL -m 90 http://127.0.0.1:8080/studio/chat
echo   [5/5] 工作台路由就绪

echo.
echo ============================================
echo   预热完成！现在打开网页会很快。
echo ============================================
echo.
echo 什么时候需要跑这个脚本？
echo   - 重启过 Docker 容器之后
echo   - 修改过 PHP / Blade 代码之后
echo   - 感觉打开网页变慢时
echo.
pause
