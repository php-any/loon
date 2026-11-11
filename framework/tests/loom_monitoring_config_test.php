<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function monitoringConfigCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// 缺省 monitoring 配置与样板一致：enabled、driver=log
$bareRoot = sys_get_temp_dir() . '/loom-monitoring-bare-' . bin2hex(random_bytes(4));
@mkdir($bareRoot . '/config', 0777, true);
$bare = (new Loom\Options(new Loom\Config($bareRoot)))->bindOptional(Loom\Monitoring\MonitoringOptions::class, 'monitoring');
monitoringConfigCheck($bare instanceof Loom\Monitoring\MonitoringOptions, 'bindOptional returns MonitoringOptions');
monitoringConfigCheck($bare->enabled === true && $bare->driver === 'log', 'defaults enabled+log');
monitoringConfigCheck($bare->collects() === true, 'collects by default');

monitoringConfigCheck(
    Loom\Monitoring\MonitoringCollector::shouldCollect($bare, Loom\Monitoring\MonitoringContext::http('GET', '/health')) === true,
    'http path collected',
);
$ignore = clone $bare;
$ignore->ignore_paths = ['/health'];
monitoringConfigCheck(
    Loom\Monitoring\MonitoringCollector::shouldCollect($ignore, Loom\Monitoring\MonitoringContext::http('GET', '/health')) === false,
    'ignore path skips collection',
);

$off = clone $bare;
$off->driver = 'null';
monitoringConfigCheck($off->collects() === false, 'driver null skips');

// slow SQL threshold
$lines = [];
$services = new Loom\ServiceCollection();
$services->addSingleton(Loom\Config::class, static fn (): Loom\Config => new Loom\Config($bareRoot));
$services->addSingleton(Loom\Monitoring\MonitoringOptions::class, static function () use ($bare): Loom\Monitoring\MonitoringOptions {
    $o = new Loom\Monitoring\MonitoringOptions();
    $o->watchers['sql'] = ['enabled' => true, 'slow' => 100];
    return $o;
});
$services->addSingleton(Loom\Monitoring\MonitoringRegistration::class);
$services->addSingleton(Loom\Monitoring\DatabaseMonitoringStore::class);
$services->addScoped(Loom\Monitoring\Monitoring::class, static function (Loom\ServiceResolver $resolver) use (&$lines): Loom\Monitoring\Monitoring {
    $m = new Loom\Monitoring\ConfigurableMonitoring(
        $resolver->get(Loom\Monitoring\MonitoringOptions::class),
        $resolver->get(Loom\Monitoring\MonitoringRegistration::class),
        null,
        null,
        static function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
    );
    $m->bindScope($resolver->get(Loom\ServiceScope::class));
    return $m;
});
$services->addScoped(Loom\Monitoring\CorrelationId::class);
$provider = $services->build();
$provider->withMonitoredScope(Loom\Monitoring\MonitoringContext::console('sql-probe'), static function (Loom\ServiceScope $scope): void {
    $scope->get(Loom\Monitoring\Monitoring::class)->recordSql('SELECT 1', [], 50.0);
    $scope->get(Loom\Monitoring\Monitoring::class)->recordSql('SELECT slow', [], 150.0);
});
monitoringConfigCheck(count($lines) === 1, 'one session flushed');
$session = json_decode($lines[0], true, 64, JSON_THROW_ON_ERROR);
monitoringConfigCheck(count($session['entries'] ?? []) === 1, 'slow threshold keeps one sql entry');
monitoringConfigCheck(($session['entries'][0]['sql'] ?? '') === 'SELECT slow', 'slow query kept');

// filter drops session
$lines = [];
$reg = new Loom\Monitoring\MonitoringRegistration();
$reg->setFilter(static fn (Loom\Monitoring\MonitoringSession $s): bool => false);
$services = new Loom\ServiceCollection();
$services->addSingleton(Loom\Config::class, static fn (): Loom\Config => new Loom\Config($bareRoot));
$services->addInstance(Loom\Monitoring\MonitoringRegistration::class, $reg);
$services->addSingleton(Loom\Monitoring\MonitoringOptions::class, static fn (): Loom\Monitoring\MonitoringOptions => new Loom\Monitoring\MonitoringOptions());
$services->addSingleton(Loom\Monitoring\DatabaseMonitoringStore::class);
$services->addScoped(Loom\Monitoring\Monitoring::class, static function (Loom\ServiceResolver $resolver) use (&$lines): Loom\Monitoring\Monitoring {
    $m = new Loom\Monitoring\ConfigurableMonitoring(
        $resolver->get(Loom\Monitoring\MonitoringOptions::class),
        $resolver->get(Loom\Monitoring\MonitoringRegistration::class),
        null,
        null,
        static function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
    );
    $m->bindScope($resolver->get(Loom\ServiceScope::class));
    return $m;
});
$services->addScoped(Loom\Monitoring\CorrelationId::class);
$provider = $services->build();
$provider->withMonitoredScope(Loom\Monitoring\MonitoringContext::console('drop'), static function (Loom\ServiceScope $scope): void {
    $scope->get(Loom\Monitoring\Monitoring::class)->recordEvent('x', []);
});
monitoringConfigCheck($lines === [], 'filter dropped session');

// custom watcher
$lines = [];
$reg = new Loom\Monitoring\MonitoringRegistration();
$reg->addWatcher(new class implements Loom\Monitoring\MonitoringWatcher {
    public function name(): string
    {
        return 'billing';
    }

    public function enabled(Loom\Monitoring\MonitoringOptions $options): bool
    {
        return true;
    }

    public function start(Loom\ServiceScope $scope, Loom\Monitoring\Monitoring $monitoring): void
    {
        $monitoring->recordEvent('billing.tick', ['ok' => true]);
    }

    public function stop(Loom\ServiceScope $scope, Loom\Monitoring\Monitoring $monitoring): void
    {
    }
});
$services = new Loom\ServiceCollection();
$services->addSingleton(Loom\Config::class, static fn (): Loom\Config => new Loom\Config($bareRoot));
$services->addInstance(Loom\Monitoring\MonitoringRegistration::class, $reg);
$services->addSingleton(Loom\Monitoring\MonitoringOptions::class, static fn (): Loom\Monitoring\MonitoringOptions => new Loom\Monitoring\MonitoringOptions());
$services->addSingleton(Loom\Monitoring\DatabaseMonitoringStore::class);
$services->addScoped(Loom\Monitoring\Monitoring::class, static function (Loom\ServiceResolver $resolver) use (&$lines): Loom\Monitoring\Monitoring {
    $m = new Loom\Monitoring\ConfigurableMonitoring(
        $resolver->get(Loom\Monitoring\MonitoringOptions::class),
        $resolver->get(Loom\Monitoring\MonitoringRegistration::class),
        null,
        null,
        static function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
    );
    $m->bindScope($resolver->get(Loom\ServiceScope::class));
    return $m;
});
$services->addScoped(Loom\Monitoring\CorrelationId::class);
$provider = $services->build();
$provider->withMonitoredScope(Loom\Monitoring\MonitoringContext::console('watcher'), static function (): void {
});
$session = json_decode($lines[0], true, 64, JSON_THROW_ON_ERROR);
$events = array_column($session['entries'] ?? [], 'event');
monitoringConfigCheck(in_array('billing.tick', $events, true), 'custom watcher wrote entry');

// dashboard 403 without authorize
$dashRoot = sys_get_temp_dir() . '/loom-monitoring-dash-' . bin2hex(random_bytes(4));
@mkdir($dashRoot . '/config', 0777, true);
file_put_contents($dashRoot . '/config/monitoring.php', "<?php\nreturn ['enabled' => true, 'driver' => 'database', 'dashboard' => ['enabled' => true, 'path' => 'monitoring'], 'storage' => ['retain_hours' => 24], 'watchers' => []];\n");
$dashApp = Loom\Application::createBuilder($dashRoot)->build();
$response = $dashApp->handle(new Loom\Request(['method' => 'GET', 'path' => '/monitoring', 'headers' => [], 'query' => [], 'body' => '']));
monitoringConfigCheck($response->toArray()['status'] === 403, 'dashboard forbidden without authorize');

// env 1/0 → bool（bindOptional 不抛类型错）
$envRoot = dirname(__DIR__, 2);
$previousDash = getenv('LOOM_MONITORING_DASHBOARD');
$previousEnabled = getenv('LOOM_MONITORING_ENABLED');
putenv('LOOM_MONITORING_DASHBOARD=1');
putenv('LOOM_MONITORING_ENABLED=0');
$envConfig = new Loom\Config($envRoot);
$envConfig->load();
$envOpts = (new Loom\Options($envConfig))->bindOptional(Loom\Monitoring\MonitoringOptions::class, 'monitoring');
$envOpts->finalize();
monitoringConfigCheck($envOpts->dashboard->enabled === true, 'LOOM_MONITORING_DASHBOARD=1');
monitoringConfigCheck($envOpts->enabled === false, 'LOOM_MONITORING_ENABLED=0');
if ($previousDash === false) {
    putenv('LOOM_MONITORING_DASHBOARD');
} else {
    putenv('LOOM_MONITORING_DASHBOARD=' . $previousDash);
}
if ($previousEnabled === false) {
    putenv('LOOM_MONITORING_ENABLED');
} else {
    putenv('LOOM_MONITORING_ENABLED=' . $previousEnabled);
}

// ApplicationBuilder：Monitoring + Database 同作用域无循环依赖
$cycleRoot = sys_get_temp_dir() . '/loom-monitoring-cycle-' . bin2hex(random_bytes(4));
@mkdir($cycleRoot . '/config', 0777, true);
$cycleApp = Loom\Application::createBuilder($cycleRoot)->build();
$cycled = false;
try {
    $cycleApp->services->withMonitoredScope(
        Loom\Monitoring\MonitoringContext::console('cycle-probe'),
        static function (Loom\ServiceScope $scope) use (&$cycled): void {
            $scope->get(Loom\Monitoring\Monitoring::class);
            $scope->get(Loom\Database::class);
            $cycled = true;
        },
    );
} catch (LogicException $error) {
    if (str_contains($error->getMessage(), 'Dependency cycle')) {
        throw new RuntimeException('Monitoring/Database cycle: ' . $error->getMessage(), 0, $error);
    }
    throw $error;
}
monitoringConfigCheck($cycled, 'monitoring and database resolve in same scope');

// 仪表盘 URL 自动忽略采集
$dashOpts = new Loom\Monitoring\MonitoringOptions();
$dashOpts->dashboard->enabled = true;
$dashOpts->dashboard->path = 'monitoring';
$dashOpts->finalize();
monitoringConfigCheck(
    Loom\Monitoring\MonitoringCollector::shouldCollect($dashOpts, Loom\Monitoring\MonitoringContext::http('GET', '/monitoring')) === false,
    'dashboard path auto-ignored',
);
monitoringConfigCheck(
    Loom\Monitoring\MonitoringCollector::shouldCollect($dashOpts, Loom\Monitoring\MonitoringContext::http('GET', '/monitoring/http')) === false,
    'dashboard subpath auto-ignored',
);
monitoringConfigCheck(
    Loom\Monitoring\MonitoringCollector::shouldCollect($dashOpts, Loom\Monitoring\MonitoringContext::http('GET', '/monitoring/api/overview')) === false,
    'dashboard api path auto-ignored',
);

// dashboard 开启但 driver≠database → 503 说明页（非 authorize 403）
$logDashRoot = sys_get_temp_dir() . '/loom-monitoring-logdash-' . bin2hex(random_bytes(4));
@mkdir($logDashRoot . '/config', 0777, true);
file_put_contents($logDashRoot . '/config/monitoring.php', "<?php\nreturn ['enabled' => true, 'driver' => 'log', 'dashboard' => ['enabled' => true, 'path' => 'monitoring'], 'storage' => ['retain_hours' => 24], 'watchers' => []];\n");
$logDashApp = Loom\Application::createBuilder($logDashRoot)->build();
$logDashBody = $logDashApp->handle(new Loom\Request(['method' => 'GET', 'path' => '/monitoring', 'headers' => [], 'query' => [], 'body' => '']));
$logDashArr = $logDashBody->toArray();
monitoringConfigCheck($logDashArr['status'] === 503, 'dashboard without database driver returns 503');
monitoringConfigCheck(str_contains((string)$logDashArr['body'], 'database'), '503 page mentions database driver');

$api403 = $dashApp->handle(new Loom\Request(['method' => 'GET', 'path' => '/monitoring/api/overview', 'headers' => [], 'query' => [], 'body' => '']));
monitoringConfigCheck($api403->toArray()['status'] === 403, 'api forbidden without authorize');

$api503 = $logDashApp->handle(new Loom\Request(['method' => 'GET', 'path' => '/monitoring/api/entries', 'headers' => [], 'query' => ['type' => ['http']], 'body' => '']));
monitoringConfigCheck($api503->toArray()['status'] === 503, 'api returns 503 when driver is not database');

$openDashApp = Loom\Application::createBuilder($dashRoot)->monitoring->authorize(static fn (): bool => true)->build();
$openApi = $openDashApp->handle(new Loom\Request(['method' => 'GET', 'path' => '/monitoring/api/overview', 'headers' => [], 'query' => ['page' => ['1'], 'per_page' => ['10']], 'body' => '']));
$openStatus = $openApi->toArray()['status'];
monitoringConfigCheck($openStatus !== 403, 'api passes authorize callback');

echo "loom_monitoring_config_test: OK\n";
