param(
    [string]$Version = '1.0.0'
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$source = Join-Path $projectRoot 'app'
$dist = Join-Path $projectRoot 'dist'
$stage = Join-Path $dist ('memi-pwa-' + $Version)
$archive = Join-Path $dist ('memi-pwa-' + $Version + '.zip')

if (-not (Test-Path -LiteralPath (Join-Path $source 'vendor\autoload.php'))) {
    throw 'app/vendor/autoload.php est absent. Exécutez composer install --no-dev --optimize-autoloader dans app avant de construire.'
}

$resolvedProject = (Resolve-Path -LiteralPath $projectRoot).Path
$resolvedDistParent = Split-Path -Parent $dist
if ((Resolve-Path -LiteralPath $resolvedDistParent).Path -ne $resolvedProject) {
    throw 'Le dossier de sortie ne se trouve pas dans le projet.'
}

New-Item -ItemType Directory -Path $dist -Force | Out-Null
if (Test-Path -LiteralPath $stage) { Remove-Item -LiteralPath $stage -Recurse -Force }
if (Test-Path -LiteralPath $archive) { Remove-Item -LiteralPath $archive -Force }
New-Item -ItemType Directory -Path $stage | Out-Null

# Copy the children explicitly so dotfiles such as .htaccess are always present.
Get-ChildItem -LiteralPath $source -Force | ForEach-Object {
    Copy-Item -LiteralPath $_.FullName -Destination $stage -Recurse -Force
}

if (-not (Test-Path -LiteralPath (Join-Path $stage '.htaccess'))) {
    throw 'Le fichier .htaccess manque dans la préparation.'
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory(
    $stage,
    $archive,
    [System.IO.Compression.CompressionLevel]::Optimal,
    $false
)
Write-Output $archive
