$timestamp = Get-Date -Format 'yyyyMMdd_HHmm'
$dest = Join-Path ([System.IO.Path]::Combine($env:USERPROFILE, 'Downloads')) ("upload_" + $timestamp)
New-Item -ItemType Directory -Path $dest -Force | Out-Null

$whitelist = @('assets', 'includes', 'readme.txt', 'smart-internal-links.php', 'uninstall.php')
$root = (Get-Item .).FullName

foreach ($itemName in $whitelist) {
    $src = Join-Path $root $itemName
    if (Test-Path $src) {
        if (Test-Path $src -PathType Container) {
            $target = Join-Path $dest $itemName
            robocopy $src $target /E /NP /NFL /NDL /R:0 /W:0 | Out-Null
        } else {
            Copy-Item -Path $src -Destination $dest -Force
        }
    }
}

if (Test-Path $dest) {
    Write-Host "🚀 PLUGIN EXPORTÉ (MODE WHITELIST) : $dest"
    Start-Process explorer.exe $dest
} else {
    Write-Error "Échec de l'exportation vers $dest"
}
