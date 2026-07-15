[CmdletBinding()]
param(
    [string] $Version = '1.0.0',
    [string] $OutputDirectory = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$root = $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $root 'dist'
}
$packagesRoot = Join-Path $root 'packages'
$packageSource = Join-Path $root 'package'
$staging = Join-Path ([System.IO.Path]::GetTempPath()) ('memipilates-build-' + [guid]::NewGuid().ToString('N'))

function New-ChildArchive {
    param(
        [Parameter(Mandatory)] [string] $Source,
        [Parameter(Mandatory)] [string] $Destination
    )

    if (-not (Test-Path -LiteralPath $Source)) {
        throw "Missing extension source: $Source"
    }

    $items = Get-ChildItem -LiteralPath $Source -Force
    if ($items.Count -eq 0) {
        throw "Extension source is empty: $Source"
    }

    Compress-Archive -Path $items.FullName -DestinationPath $Destination -CompressionLevel Optimal -Force
}

try {
    New-Item -ItemType Directory -Path $staging, $OutputDirectory -Force | Out-Null
    $packageStage = Join-Path $staging 'package'
    $childrenStage = Join-Path $packageStage 'packages'
    New-Item -ItemType Directory -Path $childrenStage -Force | Out-Null

    Copy-Item -LiteralPath (Join-Path $packageSource 'pkg_memipilates.xml') -Destination $packageStage
    Copy-Item -LiteralPath (Join-Path $packageSource 'language') -Destination $packageStage -Recurse
    Copy-Item -LiteralPath (Join-Path $packageSource 'README.md') -Destination $packageStage

    New-ChildArchive (Join-Path $packagesRoot 'com_memipilates') (Join-Path $childrenStage 'com_memipilates.zip')
    New-ChildArchive (Join-Path $packagesRoot 'plg_task_memipilates') (Join-Path $childrenStage 'plg_task_memipilates.zip')
    New-ChildArchive (Join-Path $packagesRoot 'file_memipilates_cli') (Join-Path $childrenStage 'file_memipilates_cli.zip')

    $artifact = Join-Path $OutputDirectory ("pkg_memipilates-$Version.zip")
    if (Test-Path -LiteralPath $artifact) {
        Remove-Item -LiteralPath $artifact -Force
    }
    Compress-Archive -Path (Get-ChildItem -LiteralPath $packageStage -Force).FullName -DestinationPath $artifact -CompressionLevel Optimal -Force

    $hash = (Get-FileHash -LiteralPath $artifact -Algorithm SHA256).Hash
    Write-Output "Package: $artifact"
    Write-Output "SHA256:  $hash"
} finally {
    if (Test-Path -LiteralPath $staging) {
        Remove-Item -LiteralPath $staging -Recurse -Force
    }
}
