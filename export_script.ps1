$downloads = [Environment]::GetFolderPath("UserProfile") + "\Downloads"
$timestamp = Get-Date -Format 'yyyyMMdd_HHmm'
$folderName = "upload_SIL_V2.8.3_Stretch_$timestamp"
$dest = "$downloads\$folderName"

Write-Host "Exporting to: $dest"
if (-not (Test-Path $dest)) {
    New-Item -ItemType Directory -Path $dest -Force | Out-Null
}

$items = @("assets", "includes", "readme.txt", "smart-internal-links.php", "uninstall.php")

foreach ($item in $items) {
    $src = "$(Get-Location)\$item"
    if (Test-Path $src) {
        Write-Host "Copying $item..."
        Copy-Item -Path $src -Destination $dest -Recurse -Force
    }
}

Explorer.exe $dest
Write-Host "🚀 PLUGIN EXPORTÉ : $dest"
