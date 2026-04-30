$u = $env:USERPROFILE
$t = Get-Date -Format 'yyyyMMdd_HHmm'
$dest = "$u\Downloads\upload_$t"
Write-Output "Exporting to: $dest"

if (!(Test-Path $dest)) {
    New-Item -ItemType Directory -Path $dest -Force
}

$whitelist = @('assets', 'includes', 'readme.txt', 'smart-internal-links.php', 'uninstall.php')

foreach ($item in $whitelist) {
    if (Test-Path $item) {
        Write-Output "Copying $item..."
        Copy-Item -Path $item -Destination $dest -Recurse -Force
    } else {
        Write-Output "Warning: $item not found."
    }
}

ii $dest
Write-Output "🚀 PLUGIN EXPORTÉ (MODE WHITELIST) : $dest"
