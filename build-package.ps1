[CmdletBinding()]
param(
    [string] $Version = '1.0.4',
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

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

function New-JoomlaArchive {
    param(
        [Parameter(Mandatory)] [string] $Source,
        [Parameter(Mandatory)] [string] $Destination
    )

    if (-not (Test-Path -LiteralPath $Source)) {
        throw "Missing extension source: $Source"
    }

    $items = @(Get-ChildItem -LiteralPath $Source -File -Recurse -Force)
    if ($items.Count -eq 0) {
        throw "Extension source is empty: $Source"
    }

    if (Test-Path -LiteralPath $Destination) {
        Remove-Item -LiteralPath $Destination -Force
    }

    # Compress-Archive writes backslashes on Windows. Use POSIX separators so
    # Joomla can extract the archive consistently on Linux hosting.
    $sourceRoot = (Resolve-Path -LiteralPath $Source).Path.TrimEnd([char]'\', [char]'/')
    $archive = [System.IO.Compression.ZipFile]::Open(
        $Destination,
        [System.IO.Compression.ZipArchiveMode]::Create
    )

    try {
        foreach ($item in $items | Sort-Object FullName) {
            $relativePath = $item.FullName.Substring($sourceRoot.Length).TrimStart([char]'\', [char]'/')
            $entryName = $relativePath -replace '\\', '/'
            $entry = $archive.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
            $input = [System.IO.File]::OpenRead($item.FullName)
            $output = $entry.Open()

            try {
                $input.CopyTo($output)
            } finally {
                $output.Dispose()
                $input.Dispose()
            }
        }
    } finally {
        $archive.Dispose()
    }
}

try {
    New-Item -ItemType Directory -Path $staging, $OutputDirectory -Force | Out-Null
    $packageStage = Join-Path $staging 'package'
    $childrenStage = Join-Path $packageStage 'packages'
    New-Item -ItemType Directory -Path $childrenStage -Force | Out-Null

    Copy-Item -LiteralPath (Join-Path $packageSource 'pkg_memipilates.xml') -Destination $packageStage
    Copy-Item -LiteralPath (Join-Path $packageSource 'language') -Destination $packageStage -Recurse
    Copy-Item -LiteralPath (Join-Path $packageSource 'README.md') -Destination $packageStage

    New-JoomlaArchive (Join-Path $packagesRoot 'com_memipilates') (Join-Path $childrenStage 'com_memipilates.zip')
    New-JoomlaArchive (Join-Path $packagesRoot 'plg_task_memipilates') (Join-Path $childrenStage 'plg_task_memipilates.zip')
    New-JoomlaArchive (Join-Path $packagesRoot 'file_memipilates_cli') (Join-Path $childrenStage 'file_memipilates_cli.zip')

    $artifact = Join-Path $OutputDirectory ("pkg_memipilates-$Version.zip")
    if (Test-Path -LiteralPath $artifact) {
        Remove-Item -LiteralPath $artifact -Force
    }
    New-JoomlaArchive $packageStage $artifact

    $hash = (Get-FileHash -LiteralPath $artifact -Algorithm SHA256).Hash
    Write-Output "Package: $artifact"
    Write-Output "SHA256:  $hash"
} finally {
    if (Test-Path -LiteralPath $staging) {
        Remove-Item -LiteralPath $staging -Recurse -Force
    }
}
