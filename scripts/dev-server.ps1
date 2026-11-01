param([switch]$Stop, [string]$Origami='', [string]$Address='127.0.0.1:18087', [string]$Database='')
$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
$statePath=Join-Path $root 'storage/run/dev-server.json'
if (Test-Path -LiteralPath $statePath) {
    $state=Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    $owned=Get-Process -Id $state.pid -ErrorAction SilentlyContinue
    if ($owned) {
        if ($owned.Path -ne $state.executable -or $owned.StartTime.ToUniversalTime().Ticks -ne ([datetime]$state.started).ToUniversalTime().Ticks) {
            throw 'PID was reused by another process; refusing to stop it.'
        }
        Stop-Process -Id $owned.Id
        $owned.WaitForExit()
    }
    Remove-Item -LiteralPath $statePath
}
if ($Stop) { return }
. (Join-Path $root 'scripts/runtime.ps1')
$runtime=Resolve-LoomRuntime $Origami
foreach ($directory in @('storage/run','storage/logs')) {
    New-Item -ItemType Directory -Force -Path (Join-Path $root $directory) | Out-Null
}
$oldAddress=$env:LOOM_ADDRESS; $oldDatabase=$env:LOOM_DATABASE
try {
    $env:LOOM_ADDRESS=$Address
    if ($Database -ne '') { $env:LOOM_DATABASE=$Database }
    $server=Start-Process -FilePath $runtime -ArgumentList @('loom.php','serve') -WorkingDirectory $root -WindowStyle Hidden -RedirectStandardOutput (Join-Path $root 'storage/logs/dev-server.out.log') -RedirectStandardError (Join-Path $root 'storage/logs/dev-server.err.log') -PassThru
    $state=@{pid=$server.Id; executable=$runtime; started=$server.StartTime.ToUniversalTime().ToString('o')}
    $state | ConvertTo-Json | Set-Content -LiteralPath $statePath
    $ready=$false
    $startupError='No HTTP response'
    for ($attempt=0; $attempt -lt 30; $attempt++) {
        if ($server.HasExited) { break }
        try {
            $response=Invoke-WebRequest "http://$Address/" -TimeoutSec 2 -SkipHttpErrorCheck
            if ($response.StatusCode -eq 200 -and $response.Headers['Content-Type'] -like 'text/html*' -and $response.Content.Length -gt 0) {
                $ready=$true
                break
            }
            $startupError="Homepage returned HTTP $($response.StatusCode)"
        } catch { $startupError=$_.Exception.Message }
        Start-Sleep -Milliseconds 200
    }
    if (!$ready) {
        if (!$server.HasExited) { Stop-Process -Id $server.Id; $server.WaitForExit() }
        Remove-Item -LiteralPath $statePath -ErrorAction SilentlyContinue
        throw ("Server failed readiness check: $startupError`n"+(Get-Content (Join-Path $root 'storage/logs/dev-server.err.log') -Raw))
    }
    Write-Host "Loom: http://$Address (PID $($server.Id))"
} finally {
    $env:LOOM_ADDRESS=$oldAddress; $env:LOOM_DATABASE=$oldDatabase
}
