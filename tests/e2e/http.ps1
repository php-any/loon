param([string]$Origami = '', [string]$DatabaseDsn = $env:LOOM_DATABASE)
if (!$DatabaseDsn -or !$DatabaseDsn.StartsWith('mysql:')) { throw 'A disposable MySQL DatabaseDsn is required' }
$ErrorActionPreference='Stop'
$root=Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
. (Join-Path $root 'scripts/runtime.ps1')
$runtime=Resolve-LoomRuntime $Origami
. (Join-Path $PSScriptRoot 'admin-fixture.ps1')
New-Item -ItemType Directory -Force -Path (Join-Path $root 'storage/testing') | Out-Null
$tag=[guid]::NewGuid().ToString('N')
$stdout=Join-Path $root "storage/testing/test-$tag.out.log"
$stderr=Join-Path $root "storage/testing/test-$tag.err.log"
$listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
$listener.Start(); $port=$listener.LocalEndpoint.Port; $listener.Stop()
$origin="http://127.0.0.1:$port"
$oldDatabase=$env:LOOM_DATABASE; $oldAddress=$env:LOOM_ADDRESS
$server=$null
function Assert-True($value,[string]$message) { if (!$value) { throw $message } }
try {
    $env:LOOM_DATABASE=$DatabaseDsn; $env:LOOM_ADDRESS="127.0.0.1:$port"
    $server=Start-Process -FilePath $runtime -ArgumentList @('loom.php','serve') -WorkingDirectory $root -WindowStyle Hidden -RedirectStandardOutput $stdout -RedirectStandardError $stderr -PassThru
    $ready=$false
    for($i=0;$i -lt 40;$i++) {
        if($server.HasExited) { throw "Server exited: $(Get-Content $stderr -Raw)" }
        try { $page=Invoke-WebRequest "$origin/demo" -TimeoutSec 2; $ready=$true; break } catch { Start-Sleep -Milliseconds 100 }
    }
    Assert-True $ready 'Server startup timeout'
    Assert-True ($page.Content.Contains('release-app')) 'Release scenario'
    $head=Invoke-WebRequest "$origin/demo" -Method Head -TimeoutSec 8
    Assert-True ($head.Content.Length -eq 0) 'HEAD body'
    $missing=Invoke-WebRequest "$origin/missing" -SkipHttpErrorCheck -TimeoutSec 8
    Assert-True ($missing.StatusCode -eq 404) '404 contract'
    Assert-True ((Invoke-WebRequest "$origin/hello/Ada" -SkipHttpErrorCheck -TimeoutSec 8).StatusCode -eq 404) 'Removed generic greeting route'
    Assert-True ((Invoke-WebRequest "$origin/greetings" -Method Post -ContentType 'application/json' -Body '{"name":"Ada","times":2}' -SkipHttpErrorCheck -TimeoutSec 8).StatusCode -eq 404) 'Removed generic greeting action'
    foreach($path in @('/','/demo','/demo/workspace','/guide','/demo/playground','/demo/protocols','/assets/demo/release.js','/assets/official/site.css')) {
        Assert-True ((Invoke-WebRequest "$origin$path" -TimeoutSec 8).StatusCode -eq 200) "Page $path"
    }
    $official=(Invoke-WebRequest "$origin/" -TimeoutSec 8).Content
    $demoPage=(Invoke-WebRequest "$origin/demo" -TimeoutSec 8).Content
    Assert-True ($official.Contains('/assets/official/site.css') -and !$official.Contains('/assets/demo/')) 'Official assets must be independent'
    Assert-True ($demoPage.Contains('/assets/demo/site.css') -and !$demoPage.Contains('/assets/official/')) 'Demo assets must be independent'
    Assert-True ($demoPage.Contains('release-app') -and $demoPage.Contains('release-exchanges') -and !$demoPage.Contains('studio-tcp-form')) 'Protocols are demonstrated inside release acceptance'
    Assert-True (!$demoPage.Contains('自由工作室') -and !$demoPage.Contains('HTTP 实验')) 'One concrete scenario instead of standalone feature demos'
    $redirectHandler=[Net.Http.HttpClientHandler]::new(); $redirectHandler.AllowAutoRedirect=$false
    $redirectClient=[Net.Http.HttpClient]::new($redirectHandler); $redirectClient.Timeout=[timespan]::FromSeconds(8)
    try {
        foreach ($redirect in @(@{old='/studio';target='/demo'},@{old='/playground';target='/demo'},@{old='/demo/workspace';target='/demo'},@{old='/demo/protocols';target='/demo'},@{old='/demo/playground';target='/demo'},@{old='/demo/welcome';target='/demo'},@{old='/welcome';target='/demo'},@{old='/demo/';target='/demo'})) {
            $response=$redirectClient.GetAsync("$origin$($redirect.old)").GetAwaiter().GetResult()
            try { Assert-True ([int]$response.StatusCode -eq 302 -and $response.Headers.Location.OriginalString -eq $redirect.target) 'Legacy demo redirect' }
            finally { $response.Dispose() }
        }
    } finally { $redirectClient.Dispose() }
    $session=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $headers=@{Origin=$origin}
    New-TestAdministrator "$tag@example.test" 'Ada' 'test password 123'
    $registered=Invoke-RestMethod "$origin/api/studio/login" -Method Post -WebSession $session -Headers $headers -ContentType 'application/json' -Body (@{email="$tag@example.test";password='test password 123'} | ConvertTo-Json) -TimeoutSec 8
    $headers['X-CSRF-Token']=$registered.csrf
    $project=Invoke-RestMethod "$origin/api/studio/projects" -Method Post -WebSession $session -Headers $headers -ContentType 'application/json' -Body '{"name":"Script project"}' -TimeoutSec 8
    $task=Invoke-RestMethod "$origin/api/studio/tasks" -Method Post -WebSession $session -Headers $headers -ContentType 'application/json' -Body (@{title='Script task';project_id=[int]$project.id} | ConvertTo-Json) -TimeoutSec 8
    Assert-True ($task.title -eq 'Script task') 'Create task'
    $state=Invoke-RestMethod "$origin/api/studio/state" -WebSession $session -TimeoutSec 8
    Assert-True ($state.tasks.Count -eq 1 -and $state.projects.Count -eq 1) 'ORM state'
    foreach ($done in @($true,$false)) {
        Invoke-RestMethod "$origin/api/studio/tasks/$($task.id)" -Method Patch -WebSession $session -Headers $headers -ContentType 'application/json' -Body (@{completed=$done}|ConvertTo-Json) -TimeoutSec 8 | Out-Null
        $updated=Invoke-RestMethod "$origin/api/studio/state?project=$($project.id)" -WebSession $session -TimeoutSec 8
        Assert-True ([int]$updated.tasks[0].completed -eq [int]$done) 'Model update preserves zero and project filtering'
    }
    $otherSession=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $otherHeaders=@{Origin=$origin}
    New-TestAdministrator "other-$tag@example.test" 'Other' 'test password 123'
    $otherUser=Invoke-RestMethod "$origin/api/studio/login" -Method Post -WebSession $otherSession -Headers $otherHeaders -ContentType 'application/json' -Body (@{email="other-$tag@example.test";password='test password 123'}|ConvertTo-Json) -TimeoutSec 8
    $otherHeaders['X-CSRF-Token']=$otherUser.csrf
    foreach ($method in @('Patch','Delete')) {
        $denied=Invoke-WebRequest "$origin/api/studio/tasks/$($task.id)" -Method $method -WebSession $otherSession -Headers $otherHeaders -ContentType 'application/json' -Body '{"completed":true}' -SkipHttpErrorCheck -TimeoutSec 8
        Assert-True ($denied.StatusCode -eq 404) 'Model writes preserve ownership conditions'
    }
    $temporary=Invoke-RestMethod "$origin/api/studio/tasks" -Method Post -WebSession $session -Headers $headers -ContentType 'application/json' -Body '{"title":"Delete probe"}' -TimeoutSec 8
    Invoke-RestMethod "$origin/api/studio/tasks/$($temporary.id)" -Method Delete -WebSession $session -Headers $headers -TimeoutSec 8 | Out-Null
    $releaseSession=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $releaseHeaders=@{Origin=$origin}
    $release=Invoke-RestMethod "$origin/api/demo/release/start" -Method Post -WebSession $releaseSession -Headers $releaseHeaders -TimeoutSec 8
    $releaseHeaders['X-CSRF-Token']=$release.csrf
    $release=Invoke-RestMethod "$origin/api/demo/release/project" -Method Post -WebSession $releaseSession -Headers $releaseHeaders -ContentType 'application/json' -Body '{"name":"Release model probe"}' -TimeoutSec 8
    Assert-True ($release.tasks.Count -eq 3 -and $release.project.name -eq 'Release model probe') 'Release transaction creates project and tasks through models'
    foreach ($done in @($true,$false)) {
        $release=Invoke-RestMethod "$origin/api/demo/release/tasks/$($release.tasks[0].id)" -Method Patch -WebSession $releaseSession -Headers $releaseHeaders -ContentType 'application/json' -Body (@{completed=$done}|ConvertTo-Json) -TimeoutSec 8
        Assert-True ([int]$release.tasks[0].completed -eq [int]$done) 'Release model update shares transaction with event logging'
    }
    $badSession=[Microsoft.PowerShell.Commands.WebRequestSession]::new(); $badSession.Cookies=$session.Cookies
    $forbidden=Invoke-WebRequest "$origin/api/studio/tasks" -Method Post -WebSession $badSession -Headers @{Origin=$origin} -ContentType 'application/json' -Body '{"title":"Denied"}' -SkipHttpErrorCheck -TimeoutSec 8
    Assert-True ($forbidden.StatusCode -eq 403) ('CSRF denial: '+$forbidden.StatusCode+' '+$forbidden.Content)
    $anonymous=Invoke-WebRequest "$origin/api/studio/state" -SkipHttpErrorCheck -TimeoutSec 8
    Assert-True ($anonymous.StatusCode -eq 401) 'Session isolation'
    foreach ($removed in @('/api/studio/migrations/demo','/api/studio/protocol')) {
        Assert-True ((Invoke-WebRequest "$origin$removed" -Method Post -WebSession $session -Headers $headers -SkipHttpErrorCheck -TimeoutSec 8).StatusCode -eq 404) 'Standalone technical demos are removed'
    }
    $job=Invoke-RestMethod "$origin/api/studio/reports" -Method Post -WebSession $session -Headers $headers -ContentType 'application/json' -Body '{"delay":0}' -TimeoutSec 8
    & $runtime (Join-Path $root 'loom.php') queue:work --once
    Assert-True ($LASTEXITCODE -eq 0) 'Worker exit'
    $state=Invoke-RestMethod "$origin/api/studio/state" -WebSession $session -TimeoutSec 8
    Assert-True ($state.jobs[0].state -eq 'completed' -and $state.reports.Count -eq 1) ('Worker result: '+($state.jobs|ConvertTo-Json -Compress))
    $cron=Invoke-RestMethod "$origin/api/studio/cron/preview" -Method Post -WebSession $session -Headers $headers -ContentType 'application/json' -Body '{"expression":"*/15 * * * *","timezone":"Asia/Shanghai"}' -TimeoutSec 8
    Assert-True ((Invoke-WebRequest "$origin/api/isolation" -SkipHttpErrorCheck -TimeoutSec 8).StatusCode -eq 404) 'Test probe absent from application'
    foreach ($removed in @('/ws/echo','/ws/greet')) {
        Assert-True ((Invoke-WebRequest "$origin$removed" -SkipHttpErrorCheck -TimeoutSec 8).StatusCode -eq 404) 'Generic WebSocket demos absent from application'
    }
    Invoke-RestMethod "$origin/api/studio/logout" -Method Post -WebSession $session -Headers $headers -TimeoutSec 8 | Out-Null
    $after=Invoke-WebRequest "$origin/api/studio/state" -WebSession $session -SkipHttpErrorCheck -TimeoutSec 8
    Assert-True ($after.StatusCode -eq 401) 'Logout'
    Assert-True ((Get-Item $stderr).Length -eq 0) ('Unexpected server errors: '+(Get-Content $stderr -Raw))
    Write-Host 'HTTP, auth, ORM, migrations, worker and Cron: OK'
} finally {
    if($server -and !$server.HasExited) { Stop-Process -Id $server.Id }
    $env:LOOM_DATABASE=$oldDatabase; $env:LOOM_ADDRESS=$oldAddress
    # 仅移除项目存储目录中本测试专属的产物。
    foreach($file in @($stdout,$stderr)) {
        if([IO.Path]::GetFullPath($file).StartsWith([IO.Path]::GetFullPath((Join-Path $root 'storage'))+[IO.Path]::DirectorySeparatorChar)) {
            Remove-Item -LiteralPath $file -ErrorAction SilentlyContinue
        }
    }
}

