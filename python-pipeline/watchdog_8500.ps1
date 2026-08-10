# =============================================================================
# watchdog_8500.ps1  —  追梦平台 8500 出片微服务存活探针 + 自动重启看门狗
# -----------------------------------------------------------------------------
# 逻辑（单次检查，幂等）：
#   1. GET http://127.0.0.1:8500/health  （5s 超时）
#   2. 健康 => 记录 OK，不做任何事，退出 0
#   3. 不健康 =>
#        a. sc.exe stop HGTCommercial8500          （优雅停止 NSSM 服务）
#        b. 杀掉所有命令行含 server.py 的 python 孤儿（防占端口 1056）
#        c. 确认 8500 端口已释放
#        d. sc.exe start HGTCommercial8500         （拉起）
#        e. 轮询 /health 最多 30s 复验，成功记 RECOVERED，失败记 FAILED
#
# 设计要点：
#   - 仅检查「有响应」与否，不关心业务错误；业务 4xx/5xx 视为健康。
#   - 重启走与人工一致的铁律流程（stop -> 杀孤儿 -> start），避免端口冲突。
#   - 全部动作写日志到 watchdog_8500.log，便于事后追溯。
#
# 部署（需管理员，见 register_watchdog.bat）：
#   用任务计划程序每 2 分钟跑一次本脚本（单次即退，天然自愈）。
# =============================================================================

$ErrorActionPreference = 'Stop'

$HealthUrl   = 'http://127.0.0.1:8500/health'
$Port        = 8500
$ServiceName = 'HGTCommercial8500'
$LogPath     = Join-Path $PSScriptRoot 'watchdog_8500.log'

function Write-Log {
    param([string]$Level, [string]$Msg)
    $ts = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    $line = "$ts [$Level] $Msg"
    try { Add-Content -Path $LogPath -Value $line -Encoding UTF8 } catch {}
    Write-Output $line
}

function Test-Health {
    try {
        $r = Invoke-WebRequest -Uri $HealthUrl -TimeoutSec 5 -UseBasicParsing -ErrorAction Stop
        return ($r.StatusCode -ge 200 -and $r.StatusCode -lt 600)
    } catch {
        return $false
    }
}

function Test-PortFree {
    try {
        $conn = (Get-NetTCPConnection -LocalPort $Port -ErrorAction SilentlyContinue)
        return ($null -eq $conn)
    } catch {
        return $true
    }
}

function Kill-ServerOrphans {
    # 杀掉所有命令行包含 server.py 的 python 进程（8500 孤儿）
    $orphans = Get-CimInstance Win32_Process -Filter "CommandLine LIKE '%server.py%'" -ErrorAction SilentlyContinue
    foreach ($p in $orphans) {
        try {
            Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
            Write-Log 'INFO' "killed orphan python pid=$($p.ProcessId)"
        } catch {}
    }
    # 确认端口释放（最多等 8s）
    $t = 0
    while (-not (Test-PortFree) -and $t -lt 8) { Start-Sleep -Seconds 1; $t++ }
}

# ---------------------------------------------------------------- 主流程
Write-Log 'INFO' 'watchdog tick start'

if (Test-Health) {
    Write-Log 'OK' '8500 healthy, no action'
    exit 0
}

Write-Log 'WARN' '8500 NOT healthy, attempting recovery'

# a. 停止 NSSM 服务
try {
    & sc.exe stop $ServiceName 2>&1 | Out-Null
} catch {}
Start-Sleep -Seconds 3

# b. 杀孤儿 + 确认端口释放
Kill-ServerOrphans

# d. 拉起服务
try {
    & sc.exe start $ServiceName 2>&1 | Out-Null
} catch {
    Write-Log 'ERROR' "sc start failed: $_"
}

# e. 复验（最多 30s）
$ok = $false
for ($i = 0; $i -lt 30; $i++) {
    Start-Sleep -Seconds 1
    if (Test-Health) { $ok = $true; break }
}

if ($ok) {
    Write-Log 'RECOVERED' '8500 back online after restart'
    exit 0
} else {
    Write-Log 'FAILED' '8500 still unreachable after restart attempt — manual intervention needed'
    exit 1
}
