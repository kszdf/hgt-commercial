<#
.SYNOPSIS
    智能热重载 (hotswap) — 检测改动类型并自动重启对应服务，随后冒烟验证
.DESCRIPTION
    读取 git status，按改动文件分类执行最小必要重载：
      - app/ routes/ config/ database/ .env 改动 → 重启 hgt-commercial-app-1 容器 + config:clear
      - python-pipeline/*.py 改动            → nssm restart HGTCommercial8500
      - resources/css|js 改动               → scripts/build-and-verify.ps1 前端构建
      - resources/views/*.blade.php 改动     → php artisan view:clear
    全部完成后用 8500 /health + /metrics 做冒烟，确认新代码已加载。
    需以「管理员 PowerShell」运行（docker / nssm 需提权）。
.PARAMETER Force     忽略 git，强制全量重载（容器+8500+前端）
.PARAMETER SkipBuild 跳过前端 npm 构建（仅刷新视图缓存）
.PARAMETER DryRun    只打印将执行的操作，不实际执行
.EXAMPLE
    .\hotswap.ps1            # 按改动智能重载
    .\hotswap.ps1 -Force     # 全量重载（改了很多文件拿不准时）
    .\hotswap.ps1 -DryRun    # 预览将要做什么
#>
[CmdletBinding()]
param(
    [switch]$Force,
    [switch]$SkipBuild,
    [switch]$DryRun
)

$ErrorActionPreference = 'Continue'
$repo      = 'D:\heygem_data\hgt-commercial'
$nssm      = 'D:\tools\nssm\nssm.exe'
$container = 'hgt-commercial-app-1'
$svc       = 'HGTCommercial8500'

# 探测 git 可执行文件（普通 PowerShell 可能不在 PATH）
$git = $null
$gitCmd = Get-Command git -ErrorAction SilentlyContinue
if ($gitCmd) { $git = $gitCmd.Source }
if (-not $git) {
    $cands = @(
        "$env:USERPROFILE\.workbuddy\vendor\PortableGit\mingw64\bin\git.exe",
        'C:\Program Files\Git\cmd\git.exe',
        'C:\Program Files (x86)\Git\cmd\git.exe',
        "$env:LOCALAPPDATA\Programs\Git\cmd\git.exe"
    )
    foreach ($c in $cands) { if (Test-Path $c) { $git = $c; break } }
}

function Invoke-Step {
    param([string]$Label, [scriptblock]$Action)
    Write-Host "`n> $Label" -ForegroundColor Cyan
    if ($DryRun) {
        Write-Host ('  [DryRun] ' + $Action.ToString().Trim()) -ForegroundColor DarkGray
        return
    }
    try {
        & $Action
        Write-Host "  OK: $Label 完成" -ForegroundColor Green
    } catch {
        Write-Host "  FAIL: $Label 失败: $_" -ForegroundColor Red
        throw
    }
}

function Get-Json {
    param([string]$Uri)
    $r = Invoke-RestMethod -Uri $Uri -TimeoutSec 5
    if ($r -is [string]) { $r = $r | ConvertFrom-Json }
    return $r
}

# ---- 1. 分类改动 ----
$restartPhp  = $false
$buildAssets = $false
$clearViews  = $false
$restart8500 = $false
$changed     = @()

if ($Force) {
    $restartPhp = $buildAssets = $clearViews = $restart8500 = $true
} else {
    if (-not $git) {
        throw '未找到 git 可执行文件，无法自动检测改动。请用 -Force 强制全量重载，或把 git 加入系统 PATH。'
    }
    Push-Location $repo
    try {
        $changed = (& $git status --porcelain | ForEach-Object { $_.Substring(3).Trim() } | Where-Object { $_ })
    } finally {
        Pop-Location
    }
    if (-not $changed) {
        Write-Host 'OK: 没有检测到未提交改动，无需重载。' -ForegroundColor Green
        exit 0
    }
    foreach ($f in $changed) {
        if ($f -match '^(app/|routes/|config/|database/|\.env)') { $restartPhp = $true }
        if ($f -match '^python-pipeline/')                       { $restart8500 = $true }
        if ($f -match '^resources/(css|js)/')                    { $buildAssets = $true }
        if ($f -match '^resources/views/')                       { $clearViews = $true }
    }
}

$phpStr   = if ($restartPhp)  { 'Y' } else { 'N' }
$bizStr   = if ($restart8500) { 'Y' } else { 'N' }
$buildStr = if ($buildAssets) { 'Y' } else { 'N' }
$viewStr  = if ($clearViews)  { 'Y' } else { 'N' }
Write-Host "`n检测到改动类型:" -ForegroundColor Yellow
Write-Host "  PHP/容器   : $phpStr"
Write-Host "  8500 服务  : $bizStr"
Write-Host "  前端构建   : $buildStr"
Write-Host "  视图缓存   : $viewStr"

# ---- 2. 执行 ----
if ($restartPhp) {
    Invoke-Step '重启容器 + config:clear (PHP/Model/.env 改动)' {
        docker restart $container
        Start-Sleep -Seconds 8
        docker exec $container php artisan config:clear
    }
}

if ($buildAssets -and -not $SkipBuild) {
    Invoke-Step '前端构建 build-and-verify.ps1' {
        & powershell -ExecutionPolicy Bypass -File "$repo\scripts\build-and-verify.ps1"
    }
}

if ($clearViews) {
    Invoke-Step '刷新视图缓存 view:clear' {
        docker exec $container php artisan view:clear
    }
}

if ($restart8500) {
    Invoke-Step '重启 8500 服务 (python-pipeline 改动)' {
        & $nssm restart $svc
        Start-Sleep -Seconds 5
    }
}

# ---- 3. 冒烟验证 ----
Invoke-Step '确认 8500 端口监听' {
    $listening = (netstat -ano | Select-String ':8500' | Select-String 'LISTENING').Line
    if (-not $listening) { throw '8500 未处于监听状态，重启可能失败' }
    Write-Host ('  ' + ($listening -join ' | '))
}

Invoke-Step '冒烟 8500 /health' {
    $h = Get-Json 'http://127.0.0.1:8500/health'
    if ($h.status -ne 'ok') { throw "health 返回异常: $($h | ConvertTo-Json -Compress)" }
    Write-Host "  status = $($h.status)"
}

Invoke-Step '冒烟 8500 /metrics' {
    $m = Get-Json 'http://127.0.0.1:8500/metrics'
    Write-Host "  active_jobs=$($m.active_jobs)  total_jobs=$($m.total_jobs_in_memory)"
}

Write-Host "`nDONE: 热重载完成，新代码已确认加载。" -ForegroundColor Green