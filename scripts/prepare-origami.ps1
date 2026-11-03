param([Parameter(Mandatory)][string]$Root)
$ErrorActionPreference = 'Stop'
$module = Join-Path $Root 'runtime'
$patches = @(Get-ChildItem -LiteralPath (Join-Path $module 'patches') -Filter '*.patch' | Sort-Object Name)
if ($patches.Count -eq 0) { throw 'Origami patches are missing' }
$dependencyJson = & go -C $module mod download -json github.com/php-any/origami | Out-String
if ($LASTEXITCODE -ne 0) { throw 'Cannot locate the pinned Origami module' }
$dependency = $dependencyJson | ConvertFrom-Json
if ($dependency.Version -ne 'v1.0.8') { throw 'Review the Origami patches when updating Origami' }
$sha = [System.Security.Cryptography.SHA256]::Create()
foreach ($patch in $patches) {
    $chunk = [System.IO.File]::ReadAllBytes($patch.FullName)
    $null = $sha.TransformBlock($chunk, 0, $chunk.Length, $null, 0)
}
$null = $sha.TransformFinalBlock(@(), 0, 0)
$digest = ([System.BitConverter]::ToString($sha.Hash)).Replace('-', '').ToLowerInvariant()
$relative = "build/dependencies/origami-$($dependency.Version)-$($digest.Substring(0, 16))"
$source = Join-Path $Root ($relative -replace '/', '\')
$marker = Join-Path $source '.loom-context-patch'
if (Test-Path -LiteralPath $marker) {
    if ((Get-Content -LiteralPath $marker -Raw).Trim() -ne $digest) { throw 'Origami patch marker mismatch' }
    return $source
}
if (Test-Path -LiteralPath $source) { throw "Incomplete generated dependency at $source; preserve or remove it before retrying" }
New-Item -ItemType Directory -Force -Path (Split-Path -Parent $source) | Out-Null
Copy-Item -LiteralPath $dependency.Dir -Destination $source -Recurse
Get-ChildItem -LiteralPath $source -Recurse -File | ForEach-Object { $_.IsReadOnly = $false }
foreach ($patch in $patches) {
    & git -C $Root apply "--directory=$relative" --check $patch.FullName
    if ($LASTEXITCODE -ne 0) { throw "Origami patch does not match the pinned source: $($patch.Name)" }
    & git -C $Root apply "--directory=$relative" $patch.FullName
    if ($LASTEXITCODE -ne 0) { throw "Cannot apply Origami patch: $($patch.Name)" }
}
$utf8 = New-Object System.Text.UTF8Encoding $false
[System.IO.File]::WriteAllText($marker, $digest, $utf8)
return $source
