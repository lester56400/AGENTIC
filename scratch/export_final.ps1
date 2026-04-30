$timestamp = Get-Date -Format "yyyyMMdd_HHmm"
$dest = "C:\Users\leste\Downloads\upload_$timestamp"

Write-Host "Creating: $dest"
md $dest -ErrorAction SilentlyContinue

$items = @("assets", "includes", "readme.txt", "smart-internal-links.php", "uninstall.php")

foreach ($item in $items) {
    $src = Join-Path (Get-Location) $item
    if (Test-Path $src) {
        Write-Host "Exporting $item..."
        if (Test-Path $src -PathType Container) {
            robocopy $src (Join-Path $dest $item) /E /NJH /NJS /NDL /NFL
        } else {
            copy $src $dest
        }
    }
}

ii $dest
Write-Host "🚀 EXPORT RÉUSSI : $dest"
