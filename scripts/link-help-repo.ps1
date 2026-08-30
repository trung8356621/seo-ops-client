# Creates a directory junction (Windows) from .local/help-repo → ../seo-ops-help
# Does not require Administrator / Developer Mode (unlike symlink).

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$link = Join-Path $root ".local\help-repo"
$target = Join-Path (Split-Path -Parent $root) "seo-ops-help"

if (-not (Test-Path $target)) {
    Write-Error "Target Help repo not found: $target"
}

$localDir = Join-Path $root ".local"
if (-not (Test-Path $localDir)) {
    New-Item -ItemType Directory -Path $localDir | Out-Null
}

if (Test-Path $link) {
    $item = Get-Item $link -Force
    if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) {
        Write-Host "Already linked: $link"
        exit 0
    }
    Write-Error "Path exists and is not a junction: $link"
}

cmd /c "mklink /J `"$link`" `"$target`""
if ($LASTEXITCODE -ne 0) {
    Write-Error "mklink /J failed"
}

Write-Host "Linked $link -> $target"
