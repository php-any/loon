param([string]$Origami = '', [string]$DatabaseDsn = $env:LOOM_DATABASE)
if (!$DatabaseDsn -or !$DatabaseDsn.StartsWith('mysql:')) { throw 'A disposable MySQL DatabaseDsn is required' }
$ErrorActionPreference='Stop'
$root=Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
. (Join-Path $root 'scripts/runtime.ps1')
$runtime=Resolve-LoomRuntime $Origami
$listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
$listener.Start(); $port=$listener.LocalEndpoint.Port; $listener.Stop()
$origin="http://127.0.0.1:$port"
$tag=[guid]::NewGuid().ToString('N')
$directory=Join-Path $root 'storage/testing'
New-Item -ItemType Directory -Force -Path $directory | Out-Null
$stdout=Join-Path $directory "$tag.out.log"; $stderr=Join-Path $directory "$tag.err.log"
$oldAddress=$env:LOOM_ADDRESS; $oldDatabase=$env:LOOM_DATABASE; $server=$null
try {
    $env:LOOM_ADDRESS="127.0.0.1:$port"; $env:LOOM_DATABASE=$DatabaseDsn
    $server=Start-Process -FilePath $runtime -ArgumentList @('tests/fixtures/http/serve.php') -WorkingDirectory $root -WindowStyle Hidden -RedirectStandardOutput $stdout -RedirectStandardError $stderr -PassThru
    $ready=$false
    for($i=0;$i -lt 40;$i++) {
        if($server.HasExited) { throw (Get-Content $stderr -Raw) }
        try { $null=Invoke-RestMethod "$origin/api/isolation" -TimeoutSec 2; $ready=$true; break } catch { Start-Sleep -Milliseconds 100 }
    }
    if (!$ready) { throw 'Isolation fixture startup timeout' }
    $tokens=1..12 | ForEach-Object { "probe-$_" }
    $results=$tokens | ForEach-Object -Parallel { Invoke-RestMethod "$using:origin/api/isolation" -Headers @{'X-Loom-Probe'=$_} -TimeoutSec 8 } -ThrottleLimit 6
    foreach($result in $results) {
        if ($result.counter -ne 1 -or $result.token -notin $tokens) { throw 'Concurrent request isolation failed' }
    }
    if (($results.token | Sort-Object -Unique).Count -ne 12) { throw 'Request identity leaked' }
    $ws=[Net.WebSockets.ClientWebSocket]::new(); $ws.Options.SetRequestHeader('Origin',$origin)
    $cancel=[Threading.CancellationTokenSource]::new(8000)
    try {
        [void]$ws.ConnectAsync([uri]"ws://127.0.0.1:$port/ws/echo",$cancel.Token).GetAwaiter().GetResult()
        $payload=[Text.Encoding]::UTF8.GetBytes('isolated websocket fixture')
        [void]$ws.SendAsync([ArraySegment[byte]]::new($payload),[Net.WebSockets.WebSocketMessageType]::Text,$true,$cancel.Token).GetAwaiter().GetResult()
        $buffer=[byte[]]::new(1024)
        $received=$ws.ReceiveAsync([ArraySegment[byte]]::new($buffer),$cancel.Token).GetAwaiter().GetResult()
        if ([Text.Encoding]::UTF8.GetString($buffer,0,$received.Count) -ne 'isolated websocket fixture') { throw 'WebSocket transport failed' }
    } finally { $ws.Dispose(); $cancel.Dispose() }
    $ws=[Net.WebSockets.ClientWebSocket]::new(); $ws.Options.SetRequestHeader('Origin','https://other.example.test')
    $cancel=[Threading.CancellationTokenSource]::new(8000)
    $rejected=$false
    try {
        try { [void]$ws.ConnectAsync([uri]"ws://127.0.0.1:$port/ws/echo",$cancel.Token).GetAwaiter().GetResult() }
        catch { $rejected=$_.Exception.ToString().Contains('403') }
        if (!$rejected) { throw 'Cross-origin WebSocket upgrade must return 403' }
    } finally { $ws.Dispose(); $cancel.Dispose() }
    if ((Get-Item $stderr).Length -ne 0) { throw (Get-Content $stderr -Raw) }
    Write-Host 'Isolated HTTP fixture: request state, identities and WebSocket transport/origin: OK'
} finally {
    if ($server -and !$server.HasExited) { Stop-Process -Id $server.Id }
    $env:LOOM_ADDRESS=$oldAddress; $env:LOOM_DATABASE=$oldDatabase
    foreach ($file in @($stdout,$stderr)) { Remove-Item -LiteralPath $file -ErrorAction SilentlyContinue }
}
