param([switch]$UseRunningServer)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Push-Location $projectRoot
$demoProcess = $null
try {
    $config = Get-Content config/loom.json -Raw | ConvertFrom-Json
    if (!$UseRunningServer) {
        # Build before starting, and keep demo data separate from the Studio DB.
        $outputDir = Join-Path $projectRoot ('storage/protocol-demo-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $outputDir | Out-Null
        $binary = Join-Path $outputDir 'loom-demo.exe'
        go -C runtime build -o $binary ./cmd/loom
        if ($LASTEXITCODE -ne 0) { throw '构建演示宿主失败' }
        $stdout = Join-Path $outputDir 'server.out.log'
        $stderr = Join-Path $outputDir 'server.err.log'
        $database = Join-Path $outputDir 'demo.sqlite'
        # Select three distinct free loopback ports without touching existing services.
        $reservations = @()
        try {
            foreach ($index in 1..3) {
                $listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
                $listener.Start()
                $reservations += $listener
            }
            $config.address = '127.0.0.1:' + $reservations[0].LocalEndpoint.Port
            $config.tcp.address = '127.0.0.1:' + $reservations[1].LocalEndpoint.Port
            $config.grpc.address = '127.0.0.1:' + $reservations[2].LocalEndpoint.Port
        } finally { foreach ($listener in $reservations) { $listener.Stop() } }
        $demoConfig = Join-Path $outputDir 'loom.json'
        $config | ConvertTo-Json -Depth 10 | Set-Content $demoConfig
        $demoProcess = Start-Process -FilePath $binary -ArgumentList @('--root', ('"' + $projectRoot + '"'), '--config', ('"' + $demoConfig + '"'), '--database', ('"' + $database + '"')) -WindowStyle Hidden -PassThru -RedirectStandardOutput $stdout -RedirectStandardError $stderr
        $ready = $false
        for ($attempt = 0; $attempt -lt 40; $attempt++) {
            $demoProcess.Refresh()
            if ($demoProcess.HasExited) { throw (Get-Content $stderr -Raw) }
            try { $null = Invoke-RestMethod ('http://' + $config.address + '/hello/ready') -TimeoutSec 1; $ready = $true; break } catch { Start-Sleep -Milliseconds 250 }
        }
        if (!$ready) { throw '演示服务启动超时，检查 server.err.log' }
        Write-Host "演示数据库和日志：$outputDir"
    }
    go -C runtime run ./cmd/protocol-client --protocol=all --demo "--addr=$($config.grpc.address)" "--http-addr=$($config.address)" "--tcp-addr=$($config.tcp.address)" "--websocket-url=ws://$($config.address)/ws/greet"
    if ($LASTEXITCODE -ne 0) { throw '协议演示验证失败' }
} finally {
    # Stop only the process this invocation created; existing servers are untouched.
    if ($null -ne $demoProcess) {
        $demoProcess.Refresh()
        if (!$demoProcess.HasExited) { Stop-Process -InputObject $demoProcess }
        $demoProcess.Dispose()
    }
    Pop-Location
}
