<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function monitoringConfigCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// 未提供 config/monitoring.php 时 bindOptional 回落到类默认值。
$bareRoot = sys_get_temp_dir() . '/loom-monitoring-bare-' . bin2hex(random_bytes(6));
mkdir($bareRoot . '/config', 0700, true);
try {
    file_put_contents($bareRoot . '/config/modules.php', '<?php return [];');
    $bare = (new Loom\Options(new Loom\Config($bareRoot)))->bindOptional(Loom\Monitoring\MonitoringOptions::class, 'monitoring');
    if (!($bare instanceof Loom\Monitoring\MonitoringOptions)) {
        throw new RuntimeException('Missing monitoring config must return MonitoringOptions');
    }
    monitoringConfigCheck($bare->driver === 'log', 'Default driver is log');
    monitoringConfigCheck($bare->channels->http && $bare->channels->console && $bare->record->sql, 'Defaults keep all channels and record flags on');
} finally {
    unlink($bareRoot . '/config/modules.php');
    rmdir($bareRoot . '/config');
    rmdir($bareRoot);
}

/**
 * @param list<string> $lines
 */
function monitoringTestProvider(Loom\Monitoring\MonitoringOptions $options, array &$lines): Loom\ServiceProvider
{
    $services = new Loom\ServiceCollection();
    $services->addSingleton(Loom\Config::class, static fn (): Loom\Config => new Loom\Config(sys_get_temp_dir()));
    $services->addInstance(Loom\Monitoring\MonitoringOptions::class, $options);
    $services->addScoped(Loom\Monitoring\CorrelationId::class);
    $services->addScoped(
        Loom\Monitoring\Monitoring::class,
        static function () use (&$lines, $options): Loom\Monitoring\Monitoring {
            if ($options->driver === 'null') {
                return new Loom\Monitoring\NullMonitoring();
            }
            return new Loom\Monitoring\LoggingMonitoring(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            }, null, $options);
        },
    );
    return $services->build();
}

// driver=null：无 monitoring.session，但作用域仍可执行。
$nullOptions = new Loom\Monitoring\MonitoringOptions();
$nullOptions->driver = 'null';
$nullLines = [];
$nullProvider = monitoringTestProvider($nullOptions, $nullLines);
$ran = false;
$nullProvider->withMonitoredScope(
    Loom\Monitoring\MonitoringContext::console('silent'),
    static function () use (&$ran): void {
        $ran = true;
    },
);
monitoringConfigCheck($ran, 'driver=null still runs scoped work');
monitoringConfigCheck(count($nullLines) === 0, 'driver=null collects nothing');

// 关闭 http 通道：不缓冲会话，CorrelationId 仍 establish。
$httpOff = new Loom\Monitoring\MonitoringOptions();
$httpOff->channels->http = false;
$httpLines = [];
$httpProvider = monitoringTestProvider($httpOff, $httpLines);
$headerId = 'cfg-disabled-http-correlation';
$httpProvider->withMonitoredScope(
    Loom\Monitoring\MonitoringContext::http('GET', '/off')->withIncomingCorrelation($headerId),
    static function (Loom\ServiceScope $scope) use ($headerId): void {
        monitoringConfigCheck($scope->get(Loom\Monitoring\CorrelationId::class)->current() === $headerId, 'Correlation works when http channel off');
    },
);
monitoringConfigCheck(count($httpLines) === 0, 'Disabled http channel collects nothing');

// record.sql=false：会话 active 但不记录 SQL。
$noSql = new Loom\Monitoring\MonitoringOptions();
$noSql->record->sql = false;
$lines = [];
$services = new Loom\ServiceCollection();
$services->addInstance(Loom\Monitoring\MonitoringOptions::class, $noSql);
$services->addScoped(
    Loom\Monitoring\Monitoring::class,
    static function () use (&$lines, $noSql): Loom\Monitoring\Monitoring {
        return new Loom\Monitoring\LoggingMonitoring(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }, null, $noSql);
    },
);
$provider = $services->build();
$provider->withMonitoredScope(
    Loom\Monitoring\MonitoringContext::console('sql-off'),
    static function (Loom\ServiceScope $scope): void {
        $scope->get(Loom\Monitoring\Monitoring::class)->recordSql('SELECT 1', [], 0.5, 1);
        $scope->get(Loom\Monitoring\Monitoring::class)->recordEvent('still.event', []);
    },
);
monitoringConfigCheck(count($lines) === 1, 'Session still flushes when sql recording off');
$session = json_decode($lines[0], true, 64, JSON_THROW_ON_ERROR);
$events = array_column($session['entries'], 'event');
monitoringConfigCheck(!in_array('database.sql', $events, true), 'record.sql=false skips SQL');
monitoringConfigCheck(in_array('still.event', $events, true), 'record.events still records events');

echo "loom_monitoring_config_test: OK\n";
