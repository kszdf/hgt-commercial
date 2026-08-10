# fix_502_and_8500.ps1
# Run this file AS ADMINISTRATOR (right-click -> Run with PowerShell / Run as admin).
# Purpose:
#   1) Start Docker Desktop Service so the local Laravel app container comes up on :8080.
#      The cloud nginx proxies to local :8080 via frp; when local :8080 is down the cloud
#      returns 502. Bringing Docker up resolves the 502.
#   2) Restart the HGTCommercial8500 NSSM service so server.py picks up the committed
#      de-gender "mono" voice branch (server.py:1046). The running process still has old code.
$ErrorActionPreference = 'Continue'

Write-Host "[1/2] Starting Docker Desktop Service (resolves cloud 502)..."
try {
    Start-Service com.docker.service
    Write-Host "Docker service start requested."
} catch {
    Write-Host ("WARN: could not start com.docker.service via service API: " + $_.Exception.Message)
    Write-Host "ACTION: please open Docker Desktop from the Start menu instead, then wait for 'Engine running'."
}

# Wait for local :8080 to come up (docker daemon + restart:always containers boot).
$maxWait = 60
$i = 0
$up = $false
do {
    Start-Sleep -Seconds 3
    $i++
    $conn = Get-NetTCPConnection -LocalPort 8080 -State Listen -ErrorAction SilentlyContinue
    if ($conn) { $up = $true; break }
} while ($i -lt $maxWait)

if ($up) {
    Write-Host ("OK: local :8080 is listening (containers up) after about " + ($i * 3) + "s")
} else {
    Write-Host "WARN: :8080 not listening after ~3min. Open Docker Desktop GUI and confirm 'Engine running', then re-run this step."
}

Write-Host "[2/2] Restarting HGTCommercial8500 (loads de-gender mono branch)..."
try {
    Restart-Service HGTCommercial8500 -Force
    Write-Host "HGTCommercial8500 restart requested."
} catch {
    Write-Host ("WARN: could not restart HGTCommercial8500: " + $_.Exception.Message)
    Write-Host "ACTION: in an admin PowerShell run: Restart-Service HGTCommercial8500 -Force"
}
Start-Sleep -Seconds 3
$h = Get-NetTCPConnection -LocalPort 8500 -State Listen -ErrorAction SilentlyContinue
if ($h) {
    Write-Host ("OK: :8500 listening (PID " + $h.OwningProcess + ") -> mono branch loaded")
} else {
    Write-Host "WARN: :8500 not listening after restart."
}

Write-Host ""
Write-Host "Verification:"
Write-Host "  - Open https://zmgen.cn -> should load (no 502)."
Write-Host "  - Submit a script with NO role prefix (e.g. plain narration) -> it should use the single 'mono' voice (gender-neutral), not default male."
Write-Host "Done."
