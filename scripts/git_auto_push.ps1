# HGTCommercial auto-sync (PowerShell) - commit+push only when there are changes (SSH passwordless).
# Retries push up to 3 times against transient "Connection reset by peer"; logs "pushed" only on real success.
# Also pushes when local HEAD is ahead of origin/main (covers a prior push that failed after committing).
$repo = "D:\heygem_data\hgt-commercial"
$log  = Join-Path $repo "auto_push.log"
$git  = "C:\Users\lenovo\.workbuddy\vendor\PortableGit\mingw64\bin\git.exe"
$timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'

function Log($line) {
    Add-Content -Path $log -Value $line -Encoding utf8
}

Set-Location $repo

# 0) Snapshot key runtime logs into tracked runtime-logs/ (overwrite daily) so they get backed up too.
#    (storage/logs/*, python-pipeline server log and auto_push.log are gitignored, so we copy a daily
#     snapshot into a tracked dir instead of un-ignoring the live rotating logs.)
$logDir = Join-Path $repo "runtime-logs"
if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Path $logDir | Out-Null }
$snaps = @(
    @{src = Join-Path $repo "storage\logs\laravel.log";       dst = "laravel.log" },
    @{src = Join-Path $repo "python-pipeline\server_8500.log"; dst = "8500-server.log" },
    @{src = $log;                                              dst = "auto_push.log" }
)
foreach ($s in $snaps) {
    if (Test-Path $s.src) { Copy-Item -Path $s.src -Destination (Join-Path $logDir $s.dst) -Force -ErrorAction SilentlyContinue }
}

# 1) Commit any pending working-tree changes.
$st = & $git status --porcelain
$committedNow = $false
if ($st) {
    & $git add -A
    $msg = "daily backup: " + (Get-Date -Format "yyyy-MM-dd HH:mm")
    & $git commit -q -m $msg
    $committedNow = $true
}

# 2) Decide whether a push is needed: committed just now, or local ahead of origin/main.
$aheadRaw = & $git rev-list --count origin/main..HEAD 2>$null
if (($aheadRaw -eq $null) -or ($aheadRaw -match '^\s*$')) { $aheadRaw = 0 }
$ahead = [int]"$aheadRaw"
if (-not $committedNow -and $ahead -eq 0) {
    Log "$timestamp no change, skip"
    exit 0
}

# 3) Push with retry against transient network resets.
$maxTries = 3
$pushOk = $false
for ($i = 1; $i -le $maxTries; $i++) {
    $pushOut = & $git push -u origin main 2>&1
    $pushExit = $LASTEXITCODE
    $pushOut | ForEach-Object { Log "$timestamp [push try $i] $_" }
    if ($pushExit -eq 0) { $pushOk = $true; break }
    Log "$timestamp [push try $i] FAILED exit=$pushExit, retry after 10s"
    Start-Sleep -Seconds 10
}

if ($pushOk) {
    Log "$timestamp daily backup -> pushed (ahead=$ahead)"
    exit 0
} else {
    Log "$timestamp daily backup -> PUSH FAILED after $maxTries tries; commit kept local, will retry next run"
    exit 1
}
