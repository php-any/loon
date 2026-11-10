<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function monitoringCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$lines = [];
$services = new Loom\ServiceCollection();
$services->addSingleton(Loom\Config::class, static fn (): Loom\Config => new Loom\Config(sys_get_temp_dir()));
$services->addScoped(Loom\Monitoring\CorrelationId::class);
$services->addScoped(
    Loom\Monitoring\Monitoring::class,
    static function () use (&$lines): Loom\Monitoring\Monitoring {
        return new Loom\Monitoring\LoggingMonitoring(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
    },
);
$services->addScoped(Loom\Database::class);
$provider = $services->build();

$provider->withMonitoredScope(
    Loom\Monitoring\MonitoringContext::console('probe'),
    static function (Loom\ServiceScope $scope): void {
        $scope->get(Loom\Monitoring\Monitoring::class)->recordEvent('test.event', ['ok' => true]);
    },
);

monitoringCheck(count($lines) === 1, 'Scope dispose writes one monitoring line');
$session = json_decode($lines[0], true, 64, JSON_THROW_ON_ERROR);
monitoringCheck($session['channel'] === 'console', 'Channel is console');
monitoringCheck(($session['tags']['command'] ?? '') === 'probe', 'Command tag preserved');
monitoringCheck($session['entries'][0]['event'] === 'test.event', 'Custom event recorded');

$lines = [];
$provider->withMonitoredScope(
    Loom\Monitoring\MonitoringContext::console('_job'),
    static function (Loom\ServiceScope $scope): void {
        $monitoring = $scope->get(Loom\Monitoring\Monitoring::class);
        $monitoring->begin(Loom\Monitoring\MonitoringContext::console('_job'));
        $monitoring->begin(Loom\Monitoring\MonitoringContext::job('mail.deliver', 42));
        $monitoring->recordEvent('queue.job.dispatch', ['id' => 42, 'kind' => 'mail.deliver', 'attempt' => 1]);
    },
);
monitoringCheck(count($lines) === 1, 'Job nested console scope flushes one line');
$session = json_decode($lines[0], true, 64, JSON_THROW_ON_ERROR);
monitoringCheck($session['channel'] === 'job', 'Job channel after console outer scope and dispatch');
monitoringCheck(($session['tags']['job_kind'] ?? '') === 'mail.deliver' && ($session['tags']['job_id'] ?? 0) === 42, 'Job tags preserved');
$events = array_column($session['entries'], 'event');
monitoringCheck(in_array('queue.job.dispatch', $events, true), 'Job dispatch event in session');

$lines = [];
$provider->withMonitoredScope(
    Loom\Monitoring\MonitoringContext::console('probe'),
    static function (Loom\ServiceScope $scope): void {
        $monitoring = $scope->get(Loom\Monitoring\Monitoring::class);
        $monitoring->begin(Loom\Monitoring\MonitoringContext::console('migrate:up'));
    },
);
monitoringCheck(count($lines) === 1, 'Nested console begin flushes');
$session = json_decode($lines[0], true, 64, JSON_THROW_ON_ERROR);
monitoringCheck($session['channel'] === 'console', 'Console channel unchanged on merge');
monitoringCheck(($session['tags']['command'] ?? '') === 'migrate:up', 'Later console command tag wins');

$lines = [];
$provider->withMonitoredScope(
    Loom\Monitoring\MonitoringContext::http('GET', '/sql'),
    static function (Loom\ServiceScope $scope): void {
        $database = $scope->get(Loom\Database::class);
        try {
            $database->query('SELECT 1');
        } catch (Throwable) {
            // 测试环境可能没有真实连接；只要 hook 不破坏调用即可。
        }
        $scope->get(Loom\Monitoring\Monitoring::class)->recordHttpInput(['method' => 'GET', 'path' => '/sql', 'body_bytes' => 0]);
        $scope->get(Loom\Monitoring\Monitoring::class)->recordHttpOutput(['status' => 200, 'duration_ms' => 1.0]);
    },
);

monitoringCheck(count($lines) === 1, 'HTTP scoped session flushes');
$session = json_decode($lines[0], true, 64, JSON_THROW_ON_ERROR);
monitoringCheck($session['channel'] === 'http', 'HTTP channel');
$events = array_column($session['entries'], 'event');
monitoringCheck(in_array('http.input', $events, true) && in_array('http.output', $events, true), 'HTTP IO events');

$nullProvider = (new Loom\ServiceCollection())
    ->addScoped(Loom\Monitoring\CorrelationId::class)
    ->addScoped(Loom\Monitoring\Monitoring::class, Loom\Monitoring\NullMonitoring::class)
    ->build();
$silent = [];
$nullProvider->withMonitoredScope(
    Loom\Monitoring\MonitoringContext::queue(),
    static function (Loom\ServiceScope $scope) use (&$silent): void {
        $scope->get(Loom\Monitoring\Monitoring::class)->recordEvent('ignored', []);
        $silent[] = 'ran';
    },
);
monitoringCheck($silent === ['ran'] && count($lines) === 1, 'NullMonitoring produces no output');

echo "loom_monitoring_test: OK\n";
