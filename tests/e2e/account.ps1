param([string]$Origami = '', [string]$Python = 'python', [string]$DatabaseDsn = $env:LOOM_DATABASE)
if (!$DatabaseDsn -or !$DatabaseDsn.StartsWith('mysql:')) { throw 'A disposable MySQL DatabaseDsn is required' }
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
. (Join-Path $root 'scripts/runtime.ps1')
$runtime = Resolve-LoomRuntime $Origami
. (Join-Path $PSScriptRoot 'admin-fixture.ps1')
$tag = [guid]::NewGuid().ToString('N')
$prefix = Join-Path $root "storage/testing/account-$tag"
function Free-Port { $s=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0);$s.Start();$p=$s.LocalEndpoint.Port;$s.Stop();return $p }
function Check($value,[string]$message) { if(!$value){throw $message} }
$port=Free-Port; $smtpPort=Free-Port; $origin="http://127.0.0.1:$port"
$saved=@{}
foreach($key in @('LOOM_DATABASE','LOOM_ADDRESS','LOOM_SMTP_ADDRESS','LOOM_SMTP_FROM','LOOM_SMTP_TLS','LOOM_SMTP_USER')){$saved[$key]=[Environment]::GetEnvironmentVariable($key)}
$server=$null;$smtp=$null
try {
    $env:LOOM_DATABASE=$DatabaseDsn; $env:LOOM_ADDRESS="127.0.0.1:$port"
    $env:LOOM_SMTP_ADDRESS="127.0.0.1:$smtpPort";$env:LOOM_SMTP_FROM='noreply@example.test';$env:LOOM_SMTP_TLS='plain-local';$env:LOOM_SMTP_USER=''
    $smtp=Start-Process -FilePath (Get-Command $Python).Source -ArgumentList @('tests/fixtures/smtp.py',"$smtpPort","$prefix.mail") -WorkingDirectory $root -WindowStyle Hidden -RedirectStandardOutput "$prefix.smtp.out.log" -RedirectStandardError "$prefix.smtp.err.log" -PassThru
    $server=Start-Process -FilePath $runtime -ArgumentList @('loom.php','serve') -WorkingDirectory $root -WindowStyle Hidden -RedirectStandardOutput "$prefix.out.log" -RedirectStandardError "$prefix.err.log" -PassThru
    $ready=$false
    for($i=0;$i -lt 60;$i++){try{Invoke-WebRequest "$origin/admin" -TimeoutSec 2|Out-Null;$ready=$true;break}catch{Start-Sleep -Milliseconds 100}}
    Check $ready 'Server readiness'
    $session=[Microsoft.PowerShell.Commands.WebRequestSession]::new();$headers=@{Origin=$origin}
    New-TestAdministrator 'mail@example.test' 'Mail test' 'initial password 123'
    $registration=Invoke-RestMethod "$origin/api/studio/login" -Method Post -Headers $headers -WebSession $session -ContentType 'application/json' -Body '{"email":"mail@example.test","password":"initial password 123"}'
    $headers['X-CSRF-Token']=$registration.csrf
    Invoke-RestMethod "$origin/api/account/request-verify" -Method Post -Headers $headers -WebSession $session -ContentType 'application/json' -Body '{}'|Out-Null
    & $runtime loom.php admin:mail --once
    Check ($LASTEXITCODE -eq 0) 'Verification mail delivery'
    $mail=Get-Content "$prefix.mail" -Raw
    $token=[regex]::Match($mail,'[a-f0-9]{64}').Value
    Check ($token.Length -eq 64 -and $mail.Contains('mail@example.test')) 'Actual SMTP recipient and code'
    Invoke-RestMethod "$origin/api/account/verify" -Method Post -Headers $headers -WebSession $session -ContentType 'application/json' -Body (@{token=$token}|ConvertTo-Json)|Out-Null
    $state=Invoke-RestMethod "$origin/api/admin/session" -WebSession $session
    Check $state.verified 'Verification persisted'
    $reused=Invoke-WebRequest "$origin/api/account/verify" -Method Post -Headers $headers -WebSession $session -ContentType 'application/json' -Body (@{token=$token}|ConvertTo-Json) -SkipHttpErrorCheck
    Check ($reused.StatusCode -eq 422) 'Single use code'
    Invoke-RestMethod "$origin/api/account/request-reset" -Method Post -Headers $headers -ContentType 'application/json' -Body '{"email":"mail@example.test"}'|Out-Null
    & $runtime loom.php admin:mail --once
    Check ($LASTEXITCODE -eq 0) 'Reset mail delivery'
    $resetToken=[regex]::Match((Get-Content "$prefix.mail" -Raw),'[a-f0-9]{64}').Value
    Check ($resetToken -ne $token) 'Separate code for reset'
    Invoke-RestMethod "$origin/api/account/reset" -Method Post -Headers $headers -ContentType 'application/json' -Body (@{token=$resetToken;password='replacement password 456'}|ConvertTo-Json)|Out-Null
    $revoked=Invoke-WebRequest "$origin/api/admin/session" -WebSession $session -SkipHttpErrorCheck
    Check ($revoked.StatusCode -eq 401) 'Reset revokes session'
    $oldLogin=Invoke-WebRequest "$origin/api/studio/login" -Method Post -Headers $headers -WebSession $session -ContentType 'application/json' -Body '{"email":"mail@example.test","password":"initial password 123"}' -SkipHttpErrorCheck
    Check ($oldLogin.StatusCode -eq 401) 'Old password rejected'
    Invoke-RestMethod "$origin/api/studio/login" -Method Post -Headers $headers -WebSession $session -ContentType 'application/json' -Body '{"email":"mail@example.test","password":"replacement password 456"}'|Out-Null
    Check ((Invoke-RestMethod "$origin/api/admin/session" -WebSession $session).user.email -eq 'mail@example.test') 'New password login'
    & $runtime loom.php admin:bootstrap mail@example.test
    Check ($LASTEXITCODE -eq 0) 'Promote notification test administrator'
    $headers['X-CSRF-Token']=(Invoke-RestMethod "$origin/api/admin/session" -WebSession $session).user.csrf
    Invoke-RestMethod "$origin/api/admin/operations/channels" -Method Post -Headers $headers -WebSession $session -ContentType 'application/json' -Body '{"action":"save","channel":"mail","enabled":true}' | Out-Null
    $notice=@{action='send';user_id=1;title='Notification delivery test';body='General notification SMTP body';request_key=[guid]::NewGuid().ToString()}
    Invoke-RestMethod "$origin/api/admin/operations/notifications" -Method Post -Headers $headers -WebSession $session -ContentType 'application/json' -Body ($notice|ConvertTo-Json) | Out-Null
    & $runtime loom.php admin:notify --once
    Check ($LASTEXITCODE -eq 0) 'Notification worker ran'
    Check ((Get-Content "$prefix.mail" -Raw).Contains('General notification SMTP body')) 'Actual general notification SMTP delivery'
    $deliveries=Invoke-RestMethod "$origin/api/admin/operations/notifications" -WebSession $session
    Check ($deliveries.items[0].state -eq 'sent') 'External delivery state persisted'
    $privateHook=Invoke-WebRequest "$origin/api/admin/operations/channels" -Method Post -Headers $headers -WebSession $session -ContentType 'application/json' -Body '{"action":"save","channel":"webhook","enabled":true,"url":"https://127.0.0.1/hook","secret_env":"LOOM_WEBHOOK_SECRET_TEST"}' -SkipHttpErrorCheck
    Check ($privateHook.StatusCode -eq 422) 'Invalid Webhook configuration rejected through HTTP'
    Check ((Get-Item "$prefix.err.log").Length -eq 0) 'No server errors'
    Check ((Get-Item "$prefix.smtp.err.log").Length -eq 0) 'No SMTP fixture errors'
    Write-Host 'SMTP delivery, email verification, single-use recovery and session revocation: OK'
} finally {
    foreach($process in @($server,$smtp)){if($process -and !$process.HasExited){Stop-Process -Id $process.Id;$process.WaitForExit()}}
    foreach($key in $saved.Keys){[Environment]::SetEnvironmentVariable($key,$saved[$key])}
    foreach($suffix in @('.mail','.out.log','.err.log','.smtp.out.log','.smtp.err.log')){
        $file=[IO.Path]::GetFullPath($prefix+$suffix)
        if($file.StartsWith([IO.Path]::GetFullPath((Join-Path $root 'storage/testing'))+[IO.Path]::DirectorySeparatorChar)){Remove-Item -LiteralPath $file -ErrorAction SilentlyContinue}
    }
}

