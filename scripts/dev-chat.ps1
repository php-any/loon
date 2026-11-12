param([switch]$Stop, [int]$Port=5788, [string]$ApiTarget='http://127.0.0.1:18087')
$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
$statePath=Join-Path $root $(if($Port -eq 5788){'storage/run/chat-dev.json'}else{"storage/run/chat-$Port.json"})
if(Test-Path -LiteralPath $statePath) {
    $entry=Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    $parent=Get-Process -Id $entry.pid -ErrorAction SilentlyContinue
    if($parent) {
        if($parent.Path -ne $entry.executable -or $parent.StartTime.ToUniversalTime().Ticks -ne ([datetime]$entry.started).ToUniversalTime().Ticks) { throw 'PID reused; refusing to stop an unrelated process.' }
        $processes=Get-CimInstance Win32_Process
        function Stop-Children([int]$ParentId) {
            foreach($child in @($processes | Where-Object ParentProcessId -eq $ParentId)) {
                Stop-Children $child.ProcessId
                Stop-Process -Id $child.ProcessId -ErrorAction SilentlyContinue
            }
        }
        Stop-Children $parent.Id
        Stop-Process -Id $parent.Id -ErrorAction SilentlyContinue
    }
    Remove-Item -LiteralPath $statePath
}
if($Stop){return}
$nodeDir=Join-Path $root 'storage/tools/node-v24.12.0-win-x64'
$node=Join-Path $nodeDir 'node.exe'
$npm=Join-Path $nodeDir 'node_modules/npm/bin/npm-cli.js'
if(!(Test-Path -LiteralPath $node)){throw 'Node 24.12.0 is required in storage/tools/node-v24.12.0-win-x64'}
$savedPath=$env:PATH
$savedTarget=$env:LOOM_API_TARGET
try {
    $env:PATH="$nodeDir;$savedPath"
    $env:LOOM_API_TARGET=$ApiTarget
    $process=Start-Process -FilePath $node -ArgumentList @($npm,'run','dev','--','--host','127.0.0.1','--port',"$Port",'--strictPort') -WorkingDirectory (Join-Path $root 'frontend/chat') -WindowStyle Hidden -RedirectStandardOutput (Join-Path $root "storage/logs/chat-$Port.out.log") -RedirectStandardError (Join-Path $root "storage/logs/chat-$Port.err.log") -PassThru
    @{pid=$process.Id;executable=$node;started=$process.StartTime.ToUniversalTime().ToString('o');url="http://127.0.0.1:$Port";api=$ApiTarget}|ConvertTo-Json|Set-Content -LiteralPath $statePath
    Write-Host "Chat starting: http://127.0.0.1:$Port -> $ApiTarget"
} finally {
    $env:PATH=$savedPath
    $env:LOOM_API_TARGET=$savedTarget
}
