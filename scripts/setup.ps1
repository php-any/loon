param(
    [Parameter(Mandatory = $true)]
    [string]$OrigamiPath
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$runtimeRoot = (Resolve-Path -LiteralPath $OrigamiPath).Path
$runtimeModule = Join-Path $runtimeRoot 'go.mod'
if (!(Test-Path -LiteralPath $runtimeModule) -or
    (Get-Content -LiteralPath $runtimeModule -Raw) -notmatch '(?m)^module github\.com/php-any/origami\s*$') {
    throw 'OrigamiPath 必须指向 Origami 源码根目录。'
}

Push-Location (Join-Path $projectRoot 'runtime')
$previousWorkspace = $env:GOWORK
try {
    # 只维护本项目的本地工作区，不影响父目录或其他项目的工作区。
    $env:GOWORK = Join-Path $projectRoot 'runtime/go.work'
    if (!(Test-Path -LiteralPath $env:GOWORK)) {
        go work init .
        if ($LASTEXITCODE -ne 0) { throw '创建 Go 工作区失败。' }
    }
    go work edit "-replace=github.com/php-any/origami=$runtimeRoot"
    if ($LASTEXITCODE -ne 0) { throw '连接 Origami 运行时失败。' }
    Write-Host '运行时已连接。执行 composer start 启动 Loom。'
} finally {
    if ($null -eq $previousWorkspace) { Remove-Item Env:GOWORK -ErrorAction SilentlyContinue }
    else { $env:GOWORK = $previousWorkspace }
    Pop-Location
}
