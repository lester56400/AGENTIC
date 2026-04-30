$timestamp = Get-Date -Format 'yyyyMMdd_HHmm'
$userprofile = $env:USERPROFILE
Write-Host "DEBUG: USERPROFILE is $userprofile"
$dest = Join-Path $userprofile 'Downloads' ('upload_' + $timestamp)
Write-Host "DEBUG: DEST is $dest"
New-Item -ItemType Directory -Path $dest -Force
$whitelist = @('assets', 'includes', 'readme.txt', 'smart-internal-links.php', 'uninstall.php')
Get-ChildItem -Path . | Where-Object { $whitelist -contains $_.Name } | ForEach-Object { 
    Write-Host "Copying $($_.Name) to $dest"
    Copy-Item -Path $_.FullName -Destination $dest -Recurse -Force 
}
ii $dest
Write-Host "🚀 PLUGIN EXPORTÉ (MODE WHITELIST) : $dest"
