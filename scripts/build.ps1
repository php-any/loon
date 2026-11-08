param([string]$OrigamiSource=$env:LOOM_ORIGAMI_SOURCE, [switch]$Test)
$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
$module=Join-Path $root 'runtime'
$goArgs=@('-mod=readonly')
if ($OrigamiSource) {
    $source=(Resolve-Path -LiteralPath $OrigamiSource).Path
    if (!(Test-Path -LiteralPath (Join-Path $source 'go.mod'))) { throw 'OrigamiSource must contain go.mod' }
    $goArgs=@('-mod=readonly', ('-modfile=' + (Join-Path $module 'go.local.mod')))
}
$oldWorkspace=$env:GOWORK
Push-Location $module
try {
    $env:GOWORK='off'
    if ($OrigamiSource) {
        # 显式源码覆盖只写被忽略的本地模块，不修改提供的 Origami 源码。
        Copy-Item -LiteralPath (Join-Path $module 'go.mod') -Destination (Join-Path $module 'go.local.mod') -Force
        Copy-Item -LiteralPath (Join-Path $module 'go.sum') -Destination (Join-Path $module 'go.local.sum') -Force
        & go mod edit '-modfile=go.local.mod' "-replace=github.com/php-any/origami=$source"
        if ($LASTEXITCODE -ne 0) { throw 'Cannot configure Origami source override' }
    }
    if ($Test) {
        & go test @goArgs ./...
        if ($LASTEXITCODE -ne 0) { throw 'Go host tests failed' }
    }
    New-Item -ItemType Directory -Force -Path (Join-Path $root 'build') | Out-Null
    $name=if ([System.Environment]::OSVersion.Platform -eq 'Win32NT') { 'loom.exe' } else { 'loom' }
    & go build @goArgs -trimpath -o (Join-Path $root "build/$name") ./cmd/loom
    if ($LASTEXITCODE -ne 0) { throw 'Host build failed; check the Go errors above and docs/runtime-requirements.md' }
    Write-Host "Built build/$name"
} finally {
    Pop-Location
    $env:GOWORK=$oldWorkspace
}
