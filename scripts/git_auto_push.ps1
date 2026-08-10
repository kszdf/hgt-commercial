# HGTCommercial auto-sync (PowerShell) - commit+push only when there are changes (SSH passwordless)
$repo = "D:\heygem_data\hgt-commercial"
$log  = Join-Path $repo "auto_push.log"
$git  = "C:\Users\lenovo\.workbuddy\vendor\PortableGit\mingw64\bin\git.exe"
$timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'

Set-Location $repo
$st = & $git status --porcelain
if (-not $st) {
    Add-Content $log ("$timestamp no change, skip")
    exit 0
}

& $git add -A
$msg = "daily backup: " + (Get-Date -Format "yyyy-MM-dd HH:mm")
& $git commit -q -m $msg

# 推送并捕获真实退出码（原脚本不检查，失败也会假报 pushed）
& $git push -u origin main -q *>> $log
$pushExit = $LASTEXITCODE

if ($pushExit -eq 0) {
    Add-Content $log ("$timestamp $msg -> pushed")
} else {
    Add-Content $log ("$timestamp $msg -> PUSH FAILED (exit=$pushExit), commit kept local, retry next run")
}
exit $pushExit
