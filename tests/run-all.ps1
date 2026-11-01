param([string]$Origami='', [string]$Python='', [string]$MySqlBin='', [string]$RedisBin='')
$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
. (Join-Path $root 'scripts/runtime.ps1')
$runtime=Resolve-LoomRuntime $Origami
Push-Location $root
try {
    & $runtime loom.php proto:check
    if ($LASTEXITCODE -ne 0) { throw 'Project protocol check failed' }
    & "$root/scripts/test-storage.ps1" -Origami $runtime -Python $Python -MySqlBin $MySqlBin -RedisBin $RedisBin
} finally { Pop-Location }
