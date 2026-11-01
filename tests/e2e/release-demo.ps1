param([string]$Origami='', [string]$DatabaseDsn=$env:LOOM_DATABASE)
if (!$DatabaseDsn -or !$DatabaseDsn.StartsWith('mysql:')) { throw 'A disposable MySQL DatabaseDsn is required' }
$ErrorActionPreference='Stop'
$root=Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
. (Join-Path $root 'scripts/runtime.ps1')
$runtime=Resolve-LoomRuntime $Origami
$tag=[guid]::NewGuid().ToString('N'); $prefix=Join-Path $root "storage/testing/release-$tag"
New-Item -ItemType Directory -Force (Split-Path $prefix) | Out-Null
$oldDb=$env:LOOM_DATABASE; $oldAddress=$env:LOOM_ADDRESS; $processes=@(); $artifacts=@()
$oldTcp=$env:LOOM_TCP_ADDRESS; $oldGrpc=$env:LOOM_GRPC_ADDRESS
$success=$false
function Check($condition,[string]$message) { if (!$condition) { throw $message } }
function Call([string]$path,[string]$method='GET',$body=$null,$session=$client,$csrf=$script:csrf) {
    $options=@{Uri="$origin/api/demo/release$path";Method=$method;WebSession=$session;Headers=@{Origin=$origin;'X-CSRF-Token'=$csrf};TimeoutSec=8;SkipHttpErrorCheck=$true}
    if ($null -ne $body) { $options.ContentType='application/json';$options.Body=$body | ConvertTo-Json }
    Invoke-WebRequest @options
}
function Wait-Reports([int]$count,[int]$seconds) {
    $deadline=[datetime]::UtcNow.AddSeconds($seconds)
    do {
        $current=(Call '').Content|ConvertFrom-Json
        if ($current.reports.Count -ge $count) { return $current }
        Start-Sleep -Milliseconds 500
    } while ([datetime]::UtcNow -lt $deadline)
    throw "Expected $count reports: $($current|ConvertTo-Json -Depth 5 -Compress)"
}
try {
    $listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0);$listener.Start();$port=$listener.LocalEndpoint.Port;$listener.Stop()
    $env:LOOM_DATABASE=$DatabaseDsn;$env:LOOM_ADDRESS="127.0.0.1:$port";$origin="http://$($env:LOOM_ADDRESS)"
    $reserved=@()
    foreach($name in @('LOOM_TCP_ADDRESS','LOOM_GRPC_ADDRESS')) {
        $socket=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0);$socket.Start();$reserved+= $socket
        [Environment]::SetEnvironmentVariable($name,"127.0.0.1:$($socket.LocalEndpoint.Port)")
    }
    foreach($socket in $reserved){$socket.Stop()}
    foreach ($command in @('serve','tcp:serve','grpc:serve','queue:work','schedule:work')) {
        $name=$command.Replace(':','-');$out="$prefix-$name.out.log";$err="$prefix-$name.err.log";$artifacts+=@($out,$err)
        $processes+=Start-Process $runtime -ArgumentList @('loom.php',$command) -WorkingDirectory $root -WindowStyle Hidden -RedirectStandardOutput $out -RedirectStandardError $err -PassThru
        if($command -eq 'grpc:serve'){$grpcProcess=$processes[-1]}
        if ($command -eq 'serve') {
            $ready=$false
            for($i=0;$i -lt 30;$i++){try{Invoke-WebRequest "$origin/demo" -TimeoutSec 2|Out-Null;$ready=$true;break}catch{Start-Sleep -Milliseconds 200}}
            Check $ready 'Server startup failed'
        }
    }
    $client=[Microsoft.PowerShell.Commands.WebRequestSession]::new();$script:csrf=''
    Check ((Call '').StatusCode -eq 401) 'Anonymous state must be private'
    $created=Call '/start' 'POST' @{};$state=$created.Content|ConvertFrom-Json
    Check ($created.StatusCode -eq 201) "Start failed: $($created.Content)"
    $script:csrf=$state.csrf;$owner=$state.workspace.id
    $resumed=(Call '/start' 'POST' @{}).Content|ConvertFrom-Json
    Check ($resumed.workspace.id -eq $owner) 'Start must resume same workspace'
    Check ((Call '/project' 'POST' @{name='Release candidate'} $client 'wrong').StatusCode -eq 403) 'CSRF must be enforced'
    $created=Call '/project' 'POST' @{name='Release candidate'};$state=$created.Content|ConvertFrom-Json
    Check ($state.tasks.Count -eq 3) "Project setup failed: $($created.Content)"
    Check ((Call '/project' 'POST' @{name='Duplicate'}).StatusCode -eq 409) 'Duplicate project must be rejected'
    Check ((Call '/report' 'POST' @{}).StatusCode -eq 409) 'Incomplete release must not dispatch report'
    $other=[Microsoft.PowerShell.Commands.WebRequestSession]::new();$otherState=(Call '/start' 'POST' @{} $other '').Content|ConvertFrom-Json
    Check ((Call "/tasks/$($state.tasks[0].id)" 'PATCH' @{completed=$true} $other $otherState.csrf).StatusCode -eq 404) 'Task owner isolation'
    Check ($otherState.tasks.Count -eq 0) 'Fresh workspace must be empty'
    foreach($task in $state.tasks) {Check ((Call "/tasks/$($task.id)" 'PATCH' @{completed=$true}).StatusCode -eq 200) 'Task update failed'}
    $queued=(Call '/report' 'POST' @{}).Content|ConvertFrom-Json
    Check ($queued.jobs[0].state -eq 'pending' -and $queued.reports.Count -eq 0) 'Delayed task must be pending'
    Check ((Call '/report' 'POST' @{}).StatusCode -eq 409) 'Duplicate pending report must be rejected'
    $state=Wait-Reports 1 20
    Check ([int]$state.reports[0].total -eq 3 -and [int]$state.reports[0].completed -eq 3) 'Real report must count the saved tasks'
    Check (@($state.events | Where-Object kind -eq report).Count -eq 1) 'Worker must record delivery evidence'
    Check (@($state.exchanges | Where-Object protocol -eq tcp).Count -eq 1) 'TCP must read the business snapshot'
    $policy=@($state.exchanges | Where-Object protocol -eq grpc)[0]
    Check (($policy.response|ConvertFrom-Json).ready -eq $true) 'gRPC must approve actual snapshot'
    Check (!$state.exchanges[0].request.Contains('token') -and !$state.exchanges[1].request.Contains('token')) 'Job capability must not leak into evidence'
    $private=(Call '' 'GET' $null $other $otherState.csrf).Content|ConvertFrom-Json
    Check ($private.exchanges.Count -eq 0) 'Protocol evidence must be tenant scoped'
    Check ((Call '/schedule' 'POST' @{enabled=$true}).StatusCode -eq 200) 'Schedule enable failed'
    $state=Wait-Reports 2 25
    Check ($state.reports[0].job_id -ne $state.reports[1].job_id) 'Scheduled report needs a distinct job'
    $paused=(Call '/schedule' 'POST' @{enabled=$false}).Content|ConvertFrom-Json
    Check ($null -eq $paused.schedule) 'Schedule must pause'
    $before=$paused.reports.Count
    Check ((Call '/report' 'POST' @{}).StatusCode -eq 200) 'Submit business rejection scenario'
    Check ((Call "/tasks/$($state.tasks[0].id)" 'PATCH' @{completed=$false}).StatusCode -eq 200) 'Reopen acceptance before delayed execution'
    $deadline=[datetime]::UtcNow.AddSeconds(15)
    do {
        Start-Sleep -Milliseconds 500
        $rejected=(Call '').Content|ConvertFrom-Json
        $decision=@($rejected.exchanges|Where-Object { $_.job_id -eq $rejected.jobs[0].id -and $_.protocol -eq 'grpc' })
    } while (!$decision.Count -and [datetime]::UtcNow -lt $deadline)
    Check ($decision.Count -gt 0 -and !(($decision[0].response|ConvertFrom-Json).ready)) 'gRPC must reject reopened acceptance from TCP snapshot'
    Check ($rejected.reports.Count -eq $before) 'Rejected business decision must not produce a report'
    $deadline=[datetime]::UtcNow.AddSeconds(20)
    while($rejected.jobs[0].state -ne 'failed' -and [datetime]::UtcNow -lt $deadline){Start-Sleep -Milliseconds 500;$rejected=(Call '').Content|ConvertFrom-Json}
    Check ($rejected.jobs[0].state -eq 'failed') 'Business rejection must exhaust retries without delivering'
    Check ((Call "/tasks/$($state.tasks[0].id)" 'PATCH' @{completed=$true}).StatusCode -eq 200) 'Restore acceptance'
    Stop-Process -Id $grpcProcess.Id;$grpcProcess.WaitForExit()
    Check ((Call '/report' 'POST' @{}).StatusCode -eq 200) 'Submit while rule service is offline'
    $deadline=[datetime]::UtcNow.AddSeconds(15)
    do {
        Start-Sleep -Milliseconds 300
        $offline=(Call '').Content|ConvertFrom-Json
        $failures=@($offline.exchanges|Where-Object {$_.job_id -eq $offline.jobs[0].id -and $_.protocol -eq 'grpc' -and $_.ok -eq 0})
    } while(!$failures.Count -and [datetime]::UtcNow -lt $deadline)
    Check ($failures.Count -gt 0 -and $offline.reports.Count -eq $before) 'Offline gRPC must record real transport failure without a report'
    $out="$prefix-grpc-recovered.out.log";$err="$prefix-grpc-recovered.err.log";$artifacts+=@($out,$err)
    $processes+=Start-Process $runtime -ArgumentList @('loom.php','grpc:serve') -WorkingDirectory $root -WindowStyle Hidden -RedirectStandardOutput $out -RedirectStandardError $err -PassThru
    $recovered=Wait-Reports ($before+1) 20
    Check ($recovered.reports[0].job_id -eq $offline.jobs[0].id) 'Recovery must complete the same retried job'
    foreach($file in $artifacts|Where-Object {$_.EndsWith('.err.log')}){Check ((Get-Item $file).Length -eq 0) (Get-Content $file -Raw)}
    Write-Host 'Release journey: TCP snapshot, gRPC policy/rejection, offline recovery, isolation, delayed delivery and automatic review: OK'
    $success=$true
} finally {
    foreach($process in $processes){if(!$process.HasExited){Stop-Process -Id $process.Id;$process.WaitForExit()}}
    $env:LOOM_DATABASE=$oldDb;$env:LOOM_ADDRESS=$oldAddress
    $env:LOOM_TCP_ADDRESS=$oldTcp;$env:LOOM_GRPC_ADDRESS=$oldGrpc
    if($success){foreach($file in $artifacts){Remove-Item -LiteralPath $file -ErrorAction SilentlyContinue}}
    else {Write-Host "Failure artifacts retained: $prefix"}
}
