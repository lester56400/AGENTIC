$timestamp = Get-Date -Format 'yyyyMMdd_HHmm'
$userProfile = $env:USERPROFILE
$dest = Join-Path $userProfile 'Downloads' ("upload_" + $timestamp)

Write-Host "Creating directory: $dest"
New-Item -ItemType Directory -Path $dest -Force | Out-Null

$whitelist = @('assets', 'includes', 'readme.txt', 'smart-internal-links.php', 'uninstall.php')

Write-Host "Copying files..."
Get-ChildItem -Path . | Where-Object { $whitelist -contains $_.Name } | ForEach-Object {
    Write-Host " - Copying $($_.Name)"
    Copy-Item -Path $_.FullName -Destination $dest -Recurse -Force
}

if (Test-Path $dest) {
    Write-Host "`n🚀 PLUGIN EXPORTÉ (MODE WHITELIST) : $dest"
    # Try to open the folder if possible
    Start-Process explorer.exe $dest
} else {
    Write-Error "Failed to export plugin."
}
