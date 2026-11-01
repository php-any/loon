param(
    [string]$MySqlBin = '',
    [string]$RedisBin = '',
    [string]$Origami = '',
    [string]$Python = '',
    [ValidateSet('scripts','mysql_redis','orm','native_model','model_coverage','read_projection','project_commands','timers','operations','admin_http','account_http','release_protocols','http','background','isolation','protocols','network_limits','notifications')]
    [string[]]$Suites = @('scripts','mysql_redis','orm','native_model','model_coverage','read_projection','project_commands','timers','operations','admin_http','account_http','release_protocols','http','background','isolation','protocols','network_limits','notifications')
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot 'runtime.ps1')
$runtime = Resolve-LoomRuntime $Origami
if (!$MySqlBin) { $MySqlBin = Split-Path -Parent (Get-Command mysql -ErrorAction Stop).Source }
$needsRedis = @($Suites | Where-Object { $_ -notin @('orm', 'native_model', 'model_coverage', 'read_projection', 'project_commands', 'timers') }).Count -gt 0
if ($needsRedis -and !$RedisBin) { $RedisBin = Split-Path -Parent (Get-Command redis-server -ErrorAction Stop).Source }
if (!$Python) { $Python = Join-Path $root '.venv/Scripts/python.exe' }
$mysql = (Get-Command (Join-Path $MySqlBin 'mysql.exe') -ErrorAction Stop).Source
$mysqld = (Get-Command (Join-Path $MySqlBin 'mysqld.exe') -ErrorAction Stop).Source
if ($needsRedis) {
    $redis = (Get-Command (Join-Path $RedisBin 'redis-server.exe') -ErrorAction Stop).Source
    $redisCli = (Get-Command (Join-Path $RedisBin 'redis-cli.exe') -ErrorAction Stop).Source
}
$Python = (Get-Command $Python -ErrorAction Stop).Source
$tag = [guid]::NewGuid().ToString('N')
$directory = Join-Path $root "storage/testing/native-storage-$tag"
$data = Join-Path $directory 'mysql-data'
New-Item -ItemType Directory -Path $data -Force | Out-Null
$mysqlConfig = Join-Path $directory 'mysql.ini'
$redisConfig = Join-Path $directory 'redis.conf'
$mysqlProcess = $null; $redisProcess = $null
$saved = @{}
foreach ($name in @('LOOM_ENV_FILE','LOOM_TEST_MYSQL','LOOM_DATABASE','LOOM_MYSQL_USER','LOOM_MYSQL_PASSWORD','LOOM_REDIS_URL','LOOM_REDIS_PREFIX','MYSQL_PWD','REDISCLI_AUTH')) {
    $saved[$name] = [Environment]::GetEnvironmentVariable($name)
}
$report = [ordered]@{ started = [datetime]::UtcNow.ToString('o'); status = 'running'; mysql = ''; redis = ''; suites = @() }
function Free-Port {
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
    $listener.Start(); $port = $listener.LocalEndpoint.Port; $listener.Stop()
    return $port
}
function Mysql-Query([string]$sql) {
    $output = $sql | & $mysql --no-defaults --protocol=TCP --host=127.0.0.1 "--port=$mysqlPort" --user=root --batch --skip-column-names 2>&1
    if ($LASTEXITCODE -ne 0) { throw "MySQL test setup failed: $output" }
    return $output
}
function Run-Suite([string]$name, [scriptblock]$test) {
    if ($name -notin $Suites) { return }
    $database = 'loom_di_' + $tag.Substring(0, 8) + '_' + $name.Replace('-', '_')
    Mysql-Query "CREATE DATABASE $database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" | Out-Null
    $env:LOOM_DATABASE = "mysql:host=127.0.0.1;port=$mysqlPort;dbname=$database;charset=utf8mb4"
    $env:LOOM_REDIS_PREFIX = "loom-test:${tag}:${name}:"
    Write-Host "[$name] MySQL database: $database; isolated Redis prefix"
    & $test
    $report.suites += $name
    $report | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $directory 'result.json')
}
Push-Location $root
try {
    # 子 CLI/HTTP 进程不得加载开发者的数据库配置或监听地址。
    $env:LOOM_ENV_FILE = Join-Path $directory 'test.env'
    Set-Content -LiteralPath $env:LOOM_ENV_FILE -Value ''
    $mysqlPort = Free-Port
    do { $redisPort = Free-Port } while ($redisPort -eq $mysqlPort)
    $mysqlPassword = [guid]::NewGuid().ToString('N')
    $redisPassword = [guid]::NewGuid().ToString('N')
    $base = (Split-Path -Parent $MySqlBin).Replace('\', '/')
    $dataPath = $data.Replace('\', '/')
    @"
[mysqld]
basedir="$base"
datadir="$dataPath"
bind-address=127.0.0.1
port=$mysqlPort
mysqlx=0
"@ | Set-Content -LiteralPath $mysqlConfig -Encoding utf8NoBOM
    @"
bind 127.0.0.1
port $redisPort
protected-mode yes
requirepass $redisPassword
save ""
appendonly no
"@ | Set-Content -LiteralPath $redisConfig -Encoding utf8NoBOM
    & $mysqld "--defaults-file=$mysqlConfig" --initialize-insecure 2>&1 | Set-Content -LiteralPath (Join-Path $directory 'mysql-initialize.log')
    if ($LASTEXITCODE -ne 0) { throw "MySQL initialization failed; see $directory" }
    $mysqlProcess = Start-Process $mysqld -ArgumentList @("--defaults-file=$mysqlConfig", '--console') -WorkingDirectory $directory -WindowStyle Hidden -RedirectStandardOutput (Join-Path $directory 'mysql.out.log') -RedirectStandardError (Join-Path $directory 'mysql.err.log') -PassThru
    if ($needsRedis) { $redisProcess = Start-Process $redis -ArgumentList @('redis.conf') -WorkingDirectory $directory -WindowStyle Hidden -RedirectStandardOutput (Join-Path $directory 'redis.out.log') -RedirectStandardError (Join-Path $directory 'redis.err.log') -PassThru }
    $env:MYSQL_PWD = ''
    $env:REDISCLI_AUTH = $redisPassword
    $ready = $false
    for ($attempt = 0; $attempt -lt 100; $attempt++) {
        if ($mysqlProcess.HasExited -or ($redisProcess -and $redisProcess.HasExited)) { throw "Storage server exited; inspect $directory" }
        try {
            $null = Mysql-Query 'SELECT 1'
            if (!$needsRedis) { $ready = $true; break }
            $pong = & $redisCli -h 127.0.0.1 -p $redisPort --no-auth-warning PING 2>&1
            if ($LASTEXITCODE -eq 0 -and "$pong" -eq 'PONG') { $ready = $true; break }
            $startupError = "Redis PING: $pong"
        } catch { $startupError = $_.Exception.Message }
        Start-Sleep -Milliseconds 200
    }
    if (!$ready) { throw "Storage startup timeout: $startupError; inspect $directory" }
    Mysql-Query "ALTER USER 'root'@'localhost' IDENTIFIED BY '$mysqlPassword'" | Out-Null
    $env:MYSQL_PWD = $mysqlPassword
    $report.mysql = "$(Mysql-Query 'SELECT VERSION()')".Trim()
    if ($needsRedis) {
        $info = & $redisCli -h 127.0.0.1 -p $redisPort --no-auth-warning --raw INFO server
        $report.redis = (($info | Where-Object { $_ -like 'redis_version:*' }) -replace '^redis_version:', '').Trim()
    } else { $report.redis = 'not required by selected suites' }
    $env:LOOM_TEST_MYSQL = '1'
    $env:LOOM_MYSQL_USER = 'root'
    $env:LOOM_MYSQL_PASSWORD = $mysqlPassword
    $env:LOOM_REDIS_URL = "redis://:${redisPassword}@127.0.0.1:$redisPort/0"
    Write-Host "Native MySQL $($report.mysql), Redis $($report.redis)"
    Run-Suite 'scripts' {
        & $runtime tests/run.php
        if ($LASTEXITCODE -ne 0) { throw 'Script suites failed' }
    }
    foreach ($suite in @('mysql_redis', 'orm', 'native_model', 'model_coverage', 'read_projection', 'project_commands', 'timers', 'operations')) {
        Run-Suite $suite {
            & $runtime "tests/integration/loom_${suite}_test.php"
            if ($LASTEXITCODE -ne 0) { throw "Integration suite failed: $suite" }
        }
    }
    Run-Suite 'admin_http' { & "$root/tests/e2e/admin.ps1" -Origami $runtime -DatabaseDsn $env:LOOM_DATABASE }
    Run-Suite 'account_http' { & "$root/tests/e2e/account.ps1" -Origami $runtime -Python $Python -DatabaseDsn $env:LOOM_DATABASE }
    Run-Suite 'release_protocols' { & "$root/tests/e2e/release-demo.ps1" -Origami $runtime -DatabaseDsn $env:LOOM_DATABASE }
    foreach ($suite in @('http', 'background', 'isolation')) {
        Run-Suite $suite { & "$root/tests/e2e/$suite.ps1" -Origami $runtime -DatabaseDsn $env:LOOM_DATABASE }
    }
    foreach ($suite in @('protocols', 'network_limits')) {
        Run-Suite $suite {
            & $Python "$root/tests/e2e/$suite.py" --origami $runtime
            if ($LASTEXITCODE -ne 0) { throw "Protocol suite failed: $suite" }
        }
    }
    Run-Suite 'notifications' {
        & $Python "$root/tests/e2e/notifications.py" --runtime $runtime --redis $redis --database-dsn $env:LOOM_DATABASE
        if ($LASTEXITCODE -ne 0) { throw 'Notification suite failed' }
    }
    $report.status = 'passed'
    Write-Host "All $($report.suites.Count) selected native MySQL/Redis suites passed."
} catch {
    $report.status = 'failed'
    $report.error = $_.Exception.Message
    throw
} finally {
    # 仅停止本次调用创建的进程，不影响现有服务。
    foreach ($process in @($redisProcess, $mysqlProcess)) {
        if ($process -and !$process.HasExited) { Stop-Process -Id $process.Id; $process.WaitForExit() }
    }
    foreach ($name in $saved.Keys) { [Environment]::SetEnvironmentVariable($name, $saved[$name]) }
    Remove-Item -LiteralPath $redisConfig -ErrorAction SilentlyContinue
    $report.finished = [datetime]::UtcNow.ToString('o')
    $report | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $directory 'result.json')
    Write-Host "Results and logs: $directory"
    Pop-Location
}
