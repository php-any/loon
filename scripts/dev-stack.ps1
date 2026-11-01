param([switch]$Stop, [string]$Origami='', [string]$Address='127.0.0.1:18087', [string]$TcpAddress='127.0.0.1:18088', [string]$GrpcAddress='127.0.0.1:18089', [string]$Database='')
$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
$statePath=Join-Path $root 'storage/run/dev-stack.json'
. (Join-Path $PSScriptRoot 'runtime.ps1')
# 停止任何进程之前，先验证所有已保存的进程身份。
$owned=@()
if (Test-Path -LiteralPath $statePath) {
    foreach ($entry in @(Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json)) {
        $process=Get-Process -Id $entry.pid -ErrorAction SilentlyContinue
        if ($process) {
            if ($process.Path -ne $entry.executable -or $process.StartTime.ToUniversalTime().Ticks -ne ([datetime]$entry.started).ToUniversalTime().Ticks) {
                throw 'PID was reused; refusing to stop an unrelated process.'
            }
            $owned+=$process
        }
    }
    foreach ($process in $owned) { Stop-Process -Id $process.Id; $process.WaitForExit() }
    Remove-Item -LiteralPath $statePath
}
if ($Stop) { & "$PSScriptRoot/dev-server.ps1" -Stop; & "$PSScriptRoot/dev-redis.ps1" -Stop; return }
& "$PSScriptRoot/dev-redis.ps1"
$runtime=Resolve-LoomRuntime $Origami
$saved=@{}
foreach ($name in @('LOOM_DATABASE','LOOM_TCP_ADDRESS','LOOM_GRPC_ADDRESS')) { $saved[$name]=[Environment]::GetEnvironmentVariable($name) }
$started=@(); $entries=@(); $httpStarted=$false
try {
    if ($Database) { $env:LOOM_DATABASE=$Database }
    $env:LOOM_TCP_ADDRESS=$TcpAddress; $env:LOOM_GRPC_ADDRESS=$GrpcAddress
    & "$PSScriptRoot/dev-server.ps1" -Origami $runtime -Address $Address -Database $Database
    $httpStarted=$true
    # 启动数据库工作进程之前，先确认 HTTP 服务就绪且迁移完成。
    $ready=$false
    for ($i=0; $i -lt 30; $i++) {
        try { Invoke-WebRequest "http://$Address/demo" -TimeoutSec 2 | Out-Null; $ready=$true; break }
        catch { Start-Sleep -Milliseconds 200 }
    }
    if (!$ready) { throw 'HTTP startup timed out' }
    $services = @(@{name='tcp';command='tcp:serve'},@{name='grpc';command='grpc:serve'},@{name='queue';command='queue:work'},@{name='scheduler';command='schedule:work'})
    $services += @{name='notifications';command='admin:notify'}
    if ($env:LOOM_SMTP_ADDRESS) { $services += @{name='mail';command='admin:mail'} }
    foreach ($service in $services) {
        $process=Start-Process -FilePath $runtime -ArgumentList @('loom.php',$service.command) -WorkingDirectory $root -WindowStyle Hidden -RedirectStandardOutput (Join-Path $root "storage/logs/dev-$($service.name).out.log") -RedirectStandardError (Join-Path $root "storage/logs/dev-$($service.name).err.log") -PassThru
        $started+=$process
        $entries+=@{name=$service.name;pid=$process.Id;executable=$runtime;started=$process.StartTime.ToUniversalTime().ToString('o')}
        $entries | ConvertTo-Json -AsArray | Set-Content -LiteralPath $statePath
    }
    Start-Sleep -Seconds 1
    foreach ($process in $started) { if ($process.HasExited) { throw "Background service exited; inspect storage/logs/dev-*.err.log" } }
    Write-Host "HTTP http://$Address/demo | TCP $TcpAddress | gRPC $GrpcAddress"
    Write-Host 'Queue and scheduler running; HTTP owns the 5-second timer. Stop with scripts/dev-stack.ps1 -Stop.'
} catch {
    foreach ($process in $started) { if (!$process.HasExited) { Stop-Process -Id $process.Id; $process.WaitForExit() } }
    if (Test-Path -LiteralPath $statePath) { Remove-Item -LiteralPath $statePath }
    if ($httpStarted) { & "$PSScriptRoot/dev-server.ps1" -Stop }
    throw
} finally {
    foreach ($name in $saved.Keys) { [Environment]::SetEnvironmentVariable($name,$saved[$name]) }
}
