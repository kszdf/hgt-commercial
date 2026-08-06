# ====================================================================
# build-and-verify.ps1
# One command: npm run build -> auto-verify critical classes in artifact.
# Replaces bare `npm run build` so a successful build can never hide
# missing styles.
# Usage: powershell -ExecutionPolicy Bypass -File scripts/build-and-verify.ps1
# Exit: non-zero if build OR verify fails.
# ====================================================================
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)

Write-Host ">> Building (npm run build)..." -ForegroundColor Cyan
Push-Location $root
try {
    npm run build
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: npm run build failed" -ForegroundColor Red
        exit 1
    }
    Write-Host ">> Build done, verifying..." -ForegroundColor Cyan
    $verifyScript = Join-Path $root 'scripts/verify-css-build.ps1'
    $psExe = if (Get-Command pwsh -ErrorAction SilentlyContinue) { 'pwsh' } else { 'powershell' }
    & $psExe -ExecutionPolicy Bypass -File $verifyScript
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: verification failed - build artifact missing critical styles." -ForegroundColor Red
        exit 1
    }
    Write-Host "SUCCESS: build + verify passed." -ForegroundColor Green
} finally {
    Pop-Location
}
