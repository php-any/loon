param([switch]$Stop, [string]$Executable='')
$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
$statePath=Join-Path $root 'storage/run/notification-redis.json'
$owned=$null
if(Test-Path -LiteralPath $statePath) {
    $entry=Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    if(!$Executable){$Executable=$entry.executable}
    $owned=Get-Process -Id $entry.pid -ErrorAction SilentlyContinue
    if($owned -and ($owned.Path -ne $entry.executable -or $owned.StartTime.ToUniversalTime().Ticks -ne ([datetime]$entry.started).ToUniversalTime().Ticks)) { throw 'Redis PID reused; refusing to manage unrelated process.' }
}
if($Stop) {
    if($owned){Stop-Process -Id $owned.Id; $owned.WaitForExit()}
    if(Test-Path -LiteralPath $statePath){Remove-Item -LiteralPath $statePath}
    return
}
if($env:LOOM_REDIS_URL){ Write-Host 'Using configured Redis service'; return }
$client=[Net.Sockets.TcpClient]::new()
try { $client.Connect('127.0.0.1',6379); Write-Host 'Redis port 6379 is available'; return } catch {} finally { $client.Dispose() }
if(!$Executable){$command=Get-Command redis-server -ErrorAction SilentlyContinue; if($command){$Executable=$command.Source}}
if(!$Executable){throw 'Redis is required for WebSocket notifications. Start Redis, set LOOM_REDIS_URL, or run scripts/dev-redis.ps1 -Executable <redis-server.exe>.'}
$Executable=(Resolve-Path -LiteralPath $Executable).Path
New-Item -ItemType Directory -Force -Path (Join-Path $root 'storage/run') | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $root 'storage/logs') | Out-Null
$configPath=Join-Path $root 'storage/run/notification-redis.conf'
@'
bind 127.0.0.1
protected-mode yes
port 6379
save ""
appendonly no
'@ | Set-Content -LiteralPath $configPath
$process=Start-Process -FilePath $Executable -ArgumentList @('storage/run/notification-redis.conf') -WorkingDirectory $root -WindowStyle Hidden -RedirectStandardOutput (Join-Path $root 'storage/logs/notification-redis.out.log') -RedirectStandardError (Join-Path $root 'storage/logs/notification-redis.err.log') -PassThru
@{pid=$process.Id;executable=$Executable;started=$process.StartTime.ToUniversalTime().ToString('o')} | ConvertTo-Json | Set-Content -LiteralPath $statePath
$ready=$false
for($attempt=0; $attempt -lt 30; $attempt++) {
    if($process.HasExited){break}
    $probe=[Net.Sockets.TcpClient]::new()
    try {$probe.Connect('127.0.0.1',6379);$ready=$true;break} catch {} finally {$probe.Dispose()}
    Start-Sleep -Milliseconds 100
}
if(!$ready){
    if(!$process.HasExited){Stop-Process -Id $process.Id;$process.WaitForExit()}
    Remove-Item -LiteralPath $statePath
    throw 'Redis startup failed; inspect storage/logs/notification-redis.*.log'
}
Write-Host "Redis notifications: 127.0.0.1:6379 (PID $($process.Id))"
