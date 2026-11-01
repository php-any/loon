param([string]$Origami='', [string]$DatabaseDsn = $env:LOOM_DATABASE)
if (!$DatabaseDsn -or !$DatabaseDsn.StartsWith('mysql:')) { throw 'A disposable MySQL DatabaseDsn is required' }
$ErrorActionPreference='Stop'
$root=Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
. (Join-Path $root 'scripts/runtime.ps1')
$runtime=Resolve-LoomRuntime $Origami
. (Join-Path $PSScriptRoot 'admin-fixture.ps1')
$tag=[guid]::NewGuid().ToString('N')
$prefix=Join-Path $root "storage/testing/background-$tag"
New-Item -ItemType Directory -Force (Split-Path $prefix) | Out-Null
$oldDatabase=$env:LOOM_DATABASE; $oldAddress=$env:LOOM_ADDRESS
$processes=@(); $artifacts=@()
function Wait-Until([scriptblock]$Check,[int]$Seconds,[string]$Message) {
    $deadline=[datetime]::UtcNow.AddSeconds($Seconds)
    do { if (& $Check) { return }; Start-Sleep -Milliseconds 500 } while ([datetime]::UtcNow -lt $deadline)
    throw $Message
}
try {
    $listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
    $listener.Start(); $port=$listener.LocalEndpoint.Port; $listener.Stop()
    $env:LOOM_DATABASE=$DatabaseDsn; $env:LOOM_ADDRESS="127.0.0.1:$port"
    $origin="http://$($env:LOOM_ADDRESS)"
    foreach ($command in @('serve','queue:work','schedule:work')) {
        $name=$command.Replace(':','-'); $out="$prefix-$name.out.log"; $err="$prefix-$name.err.log"
        $artifacts+=@($out,$err)
        $process=Start-Process $runtime -ArgumentList @('loom.php',$command) -WorkingDirectory $root -WindowStyle Hidden -RedirectStandardOutput $out -RedirectStandardError $err -PassThru
        $processes+=$process
        if ($command -eq 'serve') {
            Wait-Until { try { (Invoke-WebRequest "$origin/demo" -TimeoutSec 2).StatusCode -eq 200 } catch { $false } } 15 'HTTP startup failed'
        }
    }
    Wait-Until { $r=Invoke-RestMethod "$origin/api/studio/runtime" -TimeoutSec 3; @($r.services | Where-Object active).Count -eq 3 } 15 'Missing background heartbeats'
    $before=(Invoke-RestMethod "$origin/api/studio/runtime" -TimeoutSec 3).services | Where-Object name -eq timer
    $session=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $headers=@{Origin=$origin}
    New-TestAdministrator "$tag@example.test" 'Background test' 'background test password'
    $user=Invoke-RestMethod "$origin/api/studio/login" -Method Post -WebSession $session -Headers $headers -ContentType 'application/json' -Body (@{email="$tag@example.test";password='background test password'} | ConvertTo-Json) -TimeoutSec 5
    $headers['X-CSRF-Token']=$user.csrf
    $job=Invoke-RestMethod "$origin/api/studio/reports" -Method Post -WebSession $session -Headers $headers -ContentType 'application/json' -Body '{"delay":10}' -TimeoutSec 5
    $state=Invoke-RestMethod "$origin/api/studio/state" -WebSession $session -TimeoutSec 5
    if ($state.jobs[0].state -ne 'pending' -or $state.reports.Count -ne 0) { throw 'Delayed job must remain pending before its deadline' }
    Wait-Until { $s=Invoke-RestMethod "$origin/api/studio/state" -WebSession $session -TimeoutSec 5; $s.jobs[0].state -eq 'completed' -and $s.reports.Count -eq 1 } 25 'Queue did not consume delayed job'
    & $runtime tests/fixtures/register_background_schedule.php "$tag@example.test"
    if ($LASTEXITCODE -ne 0) { throw 'Code schedule registration failed' }
    Wait-Until { $s=Invoke-RestMethod "$origin/api/studio/state" -WebSession $session -TimeoutSec 5; $s.reports.Count -ge 2 } 75 'Cron did not enqueue and complete a real report'
    $after=(Invoke-RestMethod "$origin/api/studio/runtime" -TimeoutSec 3).services | Where-Object name -eq timer
    if ([int]$after.ticks -le [int]$before.ticks) { throw 'Timer execution count did not advance' }
    Stop-Process -Id $processes[2].Id; $processes[2].WaitForExit()
    Wait-Until { $r=Invoke-RestMethod "$origin/api/studio/runtime" -TimeoutSec 3; @($r.services | Where-Object { $_.name -eq 'scheduler' -and !$_.active }).Count -eq 1 } 15 'Stopped scheduler must become stale'
    foreach ($file in $artifacts | Where-Object { $_.EndsWith('.err.log') }) { if ((Get-Item $file).Length) { throw (Get-Content $file -Raw) } }
    Write-Host 'Live timer, delayed queue consumption, real Cron execution and stale worker status: OK'
} finally {
    foreach ($process in $processes) { if (!$process.HasExited) { Stop-Process -Id $process.Id; $process.WaitForExit() } }
    $env:LOOM_DATABASE=$oldDatabase; $env:LOOM_ADDRESS=$oldAddress
    foreach ($file in $artifacts) { Remove-Item -LiteralPath $file -ErrorAction SilentlyContinue }
}

