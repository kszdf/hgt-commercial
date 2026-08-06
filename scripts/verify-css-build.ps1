# ====================================================================
# verify-css-build.ps1
# Post-build verification: confirm critical Tailwind utility classes
# made it into the compiled CSS artifact.
# Prevents "edited Blade / added component but forgot npm run build"
# from silently shipping broken layout.
# Usage: powershell -ExecutionPolicy Bypass -File scripts/verify-css-build.ps1
# Exit: 0 = all present; 1 = missing (artifact likely stale)
# ====================================================================
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)

$cssDir = Join-Path $root 'public/build/assets'
if (-not (Test-Path $cssDir)) {
    Write-Host "ERROR: build dir not found: $cssDir" -ForegroundColor Red
    Write-Host "Run: npm run build" -ForegroundColor Yellow
    exit 1
}
$cssFile = Get-ChildItem $cssDir -Filter 'app-*.css' |
    Sort-Object LastWriteTime -Descending | Select-Object -First 1
if (-not $cssFile) {
    Write-Host "ERROR: app-*.css not found. Run npm run build first." -ForegroundColor Red
    exit 1
}
Write-Host "Verifying artifact: $($cssFile.Name) ($([math]::Round($cssFile.Length/1KB)) KB, $($cssFile.LastWriteTime.ToString('yyyy-MM-dd HH:mm')))" -ForegroundColor Cyan

# Critical utility classes relied on by workspace-layout + dashboard.
# If these are absent, layout breaks (sidebar loses width, flex fails).
# NOTE: component inline <style> .ws-* classes are NOT compiled by Vite,
# so they are intentionally excluded from this check.
$critical = @(
    'min-h-screen',
    'w-56',
    'shrink-0',
    'flex',
    'flex-col',
    'flex-1',
    'overflow-y-auto',
    'backdrop-blur-sm',
    'grid-cols-3',
    'border-r',
    'gap-4',
    'luxury-glass',
    'hero-card'
)

$miss = @()
foreach ($cls in $critical) {
    $pattern = '\.' + [regex]::Escape($cls) + '[{:]'
    if (Select-String -Path $cssFile.FullName -Pattern $pattern -Quiet) {
        Write-Host "  OK  $cls" -ForegroundColor DarkGreen
    } else {
        Write-Host "  MISSING  $cls" -ForegroundColor Red
        $miss += $cls
    }
}

if ($miss.Count -eq 0) {
    Write-Host "`nPASS: all critical classes present in build artifact." -ForegroundColor Green
    exit 0
} else {
    Write-Host "`nFAIL: missing classes -> build artifact may be stale or @source missing:" -ForegroundColor Red
    Write-Host ("   " + ($miss -join ', ')) -ForegroundColor Red
    Write-Host "Fix: cd $root ; npm run build" -ForegroundColor Yellow
    exit 1
}
