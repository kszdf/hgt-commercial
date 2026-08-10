# ====================================================================
# deploy.ps1
# One command: clear -> cache -> restart app container.
# Must be run after .env / PHP / route / view changes take effect.
# Usage: powershell -ExecutionPolicy Bypass -File scripts/deploy.ps1
# Exit: non-zero if any cache/restart step fails.
# ====================================================================
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$container = 'hgt-commercial-app-1'

function Invoke-ContainerArtisan($arg) {
    docker exec $container php artisan $arg
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: php artisan $arg failed" -ForegroundColor Red
        exit 1
    }
}

Push-Location $root
try {
    docker info | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: Docker Desktop not running. Open Docker Desktop and wait for the whale icon to settle." -ForegroundColor Red
        exit 1
    }

    Write-Host ">> Clearing old caches..." -ForegroundColor Cyan
    Invoke-ContainerArtisan 'view:clear'
    Invoke-ContainerArtisan 'config:clear'
    Invoke-ContainerArtisan 'route:clear'

    Write-Host ">> Building fresh caches..." -ForegroundColor Cyan
    Invoke-ContainerArtisan 'view:cache'
    Invoke-ContainerArtisan 'config:cache'
    Invoke-ContainerArtisan 'route:cache'

    Write-Host ">> Restarting app container..." -ForegroundColor Cyan
    docker restart $container
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: docker restart $container failed" -ForegroundColor Red
        exit 1
    }

    Write-Host "SUCCESS: deploy complete." -ForegroundColor Green
} finally {
    Pop-Location
}
