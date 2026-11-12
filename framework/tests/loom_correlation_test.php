<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function correlationCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

correlationCheck(
    Loom\Monitoring\Correlation::accepts('client-trace-1a2b') === 'client-trace-1a2b',
    'Valid correlation accepted',
);
correlationCheck(Loom\Monitoring\Correlation::accepts("bad\nid") === null, 'Invalid correlation rejected');

$adopted = Loom\Monitoring\Correlation::resolve('incoming-fixed-id');
correlationCheck($adopted === 'incoming-fixed-id', 'resolve adopts incoming');
$generated = Loom\Monitoring\Correlation::resolve(null);
correlationCheck(strlen($generated) === 32 && ctype_xdigit($generated), 'resolve generates hex id');

$lines = [];
$services = new Loom\ServiceCollection();
$services->addSingleton(Loom\Config::class, static fn (): Loom\Config => new Loom\Config(sys_get_temp_dir()));
$services->addScoped(Loom\Monitoring\CorrelationId::class);
$services->addScoped(Loom\Database::class);
$services->addScoped(
    Loom\Monitoring\Monitoring::class,
    static function () use (&$lines): Loom\Monitoring\Monitoring {
        return new Loom\Monitoring\LoggingMonitoring(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
    },
);
$provider = $services->build();

$headerId = 'http-client-correlation-9f0e';
$provider->withMonitoredScope(
    Loom\Monitoring\MonitoringContext::http('GET', '/demo')->withIncomingCorrelation($headerId),
    static function (Loom\ServiceScope $scope) use ($headerId): void {
        correlationCheck($scope->get(Loom\Monitoring\CorrelationId::class)->current() === $headerId, 'Scope exposes adopted id');
    },
);
correlationCheck(count($lines) === 1, 'Monitored scope flushes one line');
$session = json_decode($lines[0], true, 64, JSON_THROW_ON_ERROR);
correlationCheck(($session['correlation_id'] ?? '') === $headerId, 'monitoring.session carries correlation_id');
correlationCheck(($session['tags']['correlation_id'] ?? '') === $headerId, 'tags include correlation_id');

$lines = [];
$generatedId = null;
$provider->withMonitoredScope(
    Loom\Monitoring\MonitoringContext::console('generated'),
    static function (Loom\ServiceScope $scope) use (&$generatedId): void {
        $generatedId = $scope->get(Loom\Monitoring\CorrelationId::class)->current();
        correlationCheck($generatedId !== '', 'New scope gets generated id');
    },
);
$session = json_decode($lines[0], true, 64, JSON_THROW_ON_ERROR);
correlationCheck(($session['correlation_id'] ?? '') === $generatedId, 'Generated id on monitoring line');

if (\function_exists('Loom\\Native\\withCorrelation')) {
    $provider->withMonitoredScope(
        Loom\Monitoring\MonitoringContext::console('model-bind'),
        static function (Loom\ServiceScope $scope): void {
            $database = $scope->get(Loom\Database::class);
            correlationCheck(Loom\ModelContext::database() === $database, 'withMonitoredScope keeps model database inside withCorrelation');
        },
    );
}

$id = new Loom\Monitoring\CorrelationId();
correlationCheck($id->establish('first-id') === 'first-id', 'establish sets id');
correlationCheck($id->establish('second-id') === 'first-id', 'later incoming does not replace established id');

$stamped = Loom\Monitoring\Correlation::stampPayload(['work' => 1], 'parent-queue-id');
correlationCheck(($stamped[Loom\Monitoring\Correlation::PAYLOAD_KEY] ?? '') === 'parent-queue-id', 'dispatch stamp on payload');
$envelope = Loom\Monitoring\Correlation::enrichJobEnvelope([
    'id' => 7,
    'kind' => 'demo',
    'owner' => 1,
    'attempt' => 1,
    'payload' => $stamped,
]);
correlationCheck(($envelope['correlation_id'] ?? '') === 'parent-queue-id', 'Job envelope carries parent correlation');

$jobScope = new Loom\Monitoring\CorrelationId();
$jobScope->establish(null);
$fromJob = Loom\Monitoring\Correlation::fromJobEnvelope($envelope);
correlationCheck($jobScope->adopt($fromJob) === 'parent-queue-id', '_job adopts parent id from envelope');

$request = new Loom\Request([
    'method' => 'GET',
    'path' => '/x',
    'headers' => [Loom\Monitoring\Correlation::HEADER => [$headerId]],
    'query' => [],
    'body' => '',
]);
correlationCheck(Loom\Monitoring\HttpCorrelation::fromRequest($request) === $headerId, 'Request header parsed');

$metadataCall = new class () {
    public function incomingMetadata(string $name): string
    {
        return $name === Loom\Monitoring\Correlation::HEADER ? 'meta-grpc-trace' : '';
    }
};
correlationCheck(
    Loom\Monitoring\GrpcCorrelation::fromCall($metadataCall, [Loom\Monitoring\Correlation::PAYLOAD_KEY => 'body-only']) === 'meta-grpc-trace',
    'gRPC metadata precedes JSON body',
);

$logLines = [];
$logger = new Loom\Http\Middleware\RequestLog(static function (string $line) use (&$logLines): void {
    $logLines[] = $line;
}, null, $id);
$next = static fn (): Loom\Response => Loom\Response::noContent();
$logger->handle($request, $next);
correlationCheck(count($logLines) === 1, 'RequestLog writes one line');
$access = json_decode($logLines[0], true, 64, JSON_THROW_ON_ERROR);
correlationCheck(($access['correlation_id'] ?? '') === 'first-id', 'http.request includes correlation_id');

echo "loom_correlation_test: OK\n";
