$timestamp = Get-Date -Format 'yyyyMMdd_HHmm'
$dest = Join-Path $env:USERPROFILE 'Downloads' ('upload_' + $timestamp)
New-Item -ItemType Directory -Path $dest -Force
$whitelist = @('assets', 'includes', 'readme.txt', 'smart-internal-links.php', 'uninstall.php')
Get-ChildItem -Path . | Where-Object { $whitelist -contains $_.Name } | ForEach-Object { 
    Write-Host "Copying $($_.Name)..."
    Copy-Item -Path $_.FullName -Destination $dest -Recurse -Force 
}
ii $dest
Write-Host "🚀 PLUGIN EXPORTÉ (MODE WHITELIST) : $dest"
