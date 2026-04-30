---
description: Exporte une version propre du plugin vers le dossier Téléchargements (sans fichiers système/dev)
---

# Exportation d'une version propre

// turbo-all

1. Générer le dossier d'exportation avec un horodatage précis dans `Downloads`.
2. Identifier et copier uniquement les fichiers nécessaires (`assets`, `includes`, fichiers `.php`, `readme.txt`) en excluant les dossiers source (`.git`, `.agent`, `docs`, `tests`, `tmp`) et les fichiers de configuration/audit (`.md`, `.py`, `.json`, `.gitattributes`, `error_log.txt`, `llms.txt`).
3. Ouvrir automatiquement le dossier de destination dans l'explorateur Windows pour vérification immédiate.

## Commande PowerShell utilisée

```powershell
$timestamp = Get-Date -Format 'yyyyMMdd_HHmm'; 
$dest = Join-Path $env:USERPROFILE 'Downloads' ('upload_' + $timestamp); 
New-Item -ItemType Directory -Path $dest -Force; 
$whitelist = @('assets', 'includes', 'readme.txt', 'smart-internal-links.php', 'uninstall.php');
Get-ChildItem -Path . | Where-Object { $whitelist -contains $_.Name } | ForEach-Object { Copy-Item -Path $_.FullName -Destination $dest -Recurse -Force }; 
ii $dest; 
Write-Host "🚀 PLUGIN EXPORTÉ (MODE WHITELIST) : $dest"
```
