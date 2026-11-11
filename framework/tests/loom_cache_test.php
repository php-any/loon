<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function cacheCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$options = new Loom\CacheOptions();
$options->default = 'array';
$cache = new Loom\Cache($options, new Loom\CacheRegistration());
$store = $cache->store('array');

$store->put('alpha', 'hello', 60);
cacheCheck($store->get('alpha') === 'hello', 'put/get string');
cacheCheck($store->has('alpha'), 'has after put');

$store->put('nullable', null, 60);
cacheCheck($store->get('nullable') === null, 'cached null reads as null');
cacheCheck($store->has('nullable'), 'has distinguishes cached null');

cacheCheck($store->get('missing') === null && !$store->has('missing'), 'missing key');

$runs = 0;
$value = $cache->remember('remembered', 30, static function () use (&$runs): int {
    $runs++;
    return 42;
});
cacheCheck($value === 42 && $runs === 1, 'remember computes once');
$value = $cache->remember('remembered', 30, static function () use (&$runs): int {
    $runs++;
    return 99;
});
cacheCheck($value === 42 && $runs === 1, 'remember hit skips callback');

$throws = false;
try {
    $cache->remember('remember-fail', 30, static function (): void {
        throw new RuntimeException('boom');
    });
} catch (RuntimeException) {
    $throws = true;
}
cacheCheck($throws && !$store->has('remember-fail'), 'remember does not write when callback throws');

$nested = ['a' => ['b' => ['c' => ['d' => 1]]]];
$store->put('deep', $nested, 60);
cacheCheck($store->get('deep') === $nested, 'nested array round trip');

$store->forever('forever', ['k' => 1]);
cacheCheck($store->get('forever') === ['k' => 1], 'forever stores array');

$store->forget('alpha');
cacheCheck(!$store->has('alpha'), 'forget removes key');

cacheCheck($store->increment('counter') === 1, 'increment creates counter');
cacheCheck($store->increment('counter', 4) === 5, 'increment step');
cacheCheck($store->decrement('counter', 2) === 3, 'decrement');

$expiresAt = microtime(true) + 120;
$entryProp = new ReflectionProperty(Loom\ArrayCacheStore::class, 'entries');
$entryProp->setAccessible(true);
$entryProp->setValue($store, ['ttl-counter' => ['payload' => '3', 'expires' => $expiresAt]]);
cacheCheck($store->increment('ttl-counter') === 4, 'increment on ttl counter');
$after = $entryProp->getValue($store);
cacheCheck(
    isset($after['ttl-counter']['expires']) && $after['ttl-counter']['expires'] === $expiresAt,
    'array increment preserves expires',
);

$store->put('envelope-key', 'x', 60);
$mixed = false;
try {
    $store->increment('envelope-key');
} catch (LogicException) {
    $mixed = true;
}
cacheCheck($mixed, 'increment rejects envelope key');

$store->flush();
cacheCheck(!$store->has('nullable') && !$store->has('counter'), 'flush clears array store');

// Monitoring with watcher enabled
$lines = [];
$bareRoot = sys_get_temp_dir() . '/loom-cache-mon-' . bin2hex(random_bytes(4));
@mkdir($bareRoot . '/config', 0777, true);
$services = new Loom\ServiceCollection();
$services->addSingleton(Loom\Config::class, static fn (): Loom\Config => new Loom\Config($bareRoot));
$services->addSingleton(Loom\CacheOptions::class, static fn (): Loom\CacheOptions => new Loom\CacheOptions());
$services->addSingleton(Loom\CacheRegistration::class);
$services->addSingleton(Loom\Cache::class, static function (Loom\ServiceResolver $resolver): Loom\Cache {
    $opts = $resolver->get(Loom\CacheOptions::class);
    $opts->default = 'array';
    return new Loom\Cache($opts, $resolver->get(Loom\CacheRegistration::class));
});
$services->addSingleton(Loom\Monitoring\MonitoringOptions::class, static fn (): Loom\Monitoring\MonitoringOptions => new Loom\Monitoring\MonitoringOptions());
$services->addSingleton(Loom\Monitoring\MonitoringRegistration::class);
$services->addSingleton(Loom\Monitoring\DatabaseMonitoringStore::class);
$services->addScoped(Loom\Monitoring\CorrelationId::class);
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
$provider = $services->build();
$provider->withMonitoredScope(
    Loom\Monitoring\MonitoringContext::console('cache-probe'),
    static function (Loom\ServiceScope $scope): void {
        $store = $scope->get(Loom\Cache::class)->store('array');
        $store->get('direct-miss');
        $store->put('direct-hit', 'x', 60);
        $store->get('direct-hit');
        $store->put('monitored', 'v', 60);
        $scope->get(Loom\Cache::class)->remember('mon-hit', 60, static fn (): string => 'fresh');
        $scope->get(Loom\Cache::class)->remember('mon-hit', 60, static fn (): string => 'ignored');
    },
);
cacheCheck(count($lines) === 1, 'monitoring session written');
$session = json_decode($lines[0], true, 64, JSON_THROW_ON_ERROR);
$events = array_column($session['entries'] ?? [], 'event');
cacheCheck(in_array('cache.write', $events, true), 'cache.write recorded');
cacheCheck(in_array('cache.miss', $events, true), 'cache.miss recorded');
cacheCheck(in_array('cache.hit', $events, true), 'cache.hit recorded');
cacheCheck(count(array_filter($events, static fn (string $e): bool => $e === 'cache.hit')) === 2, 'get hit and remember hit without duplicate');
cacheCheck(count(array_filter($events, static fn (string $e): bool => $e === 'cache.miss')) >= 2, 'direct get miss and remember miss');
foreach ($session['entries'] as $entry) {
    if (!is_array($entry) || !str_starts_with((string)($entry['event'] ?? ''), 'cache.')) {
        continue;
    }
    cacheCheck(!array_key_exists('value', $entry) && !array_key_exists('v', $entry), 'no cached payload in monitoring entry');
}

// Watcher disabled
$lines = [];
$services = new Loom\ServiceCollection();
$services->addSingleton(Loom\Config::class, static fn (): Loom\Config => new Loom\Config($bareRoot));
$services->addSingleton(Loom\CacheOptions::class, static function (): Loom\CacheOptions {
    $o = new Loom\CacheOptions();
    $o->default = 'array';
    return $o;
});
$services->addSingleton(Loom\CacheRegistration::class);
$services->addSingleton(Loom\Cache::class, static fn (Loom\ServiceResolver $r): Loom\Cache => new Loom\Cache($r->get(Loom\CacheOptions::class), $r->get(Loom\CacheRegistration::class)));
$services->addSingleton(Loom\Monitoring\MonitoringOptions::class, static function (): Loom\Monitoring\MonitoringOptions {
    $o = new Loom\Monitoring\MonitoringOptions();
    $o->watchers['cache'] = ['enabled' => false];
    return $o;
});
$services->addSingleton(Loom\Monitoring\MonitoringRegistration::class);
$services->addSingleton(Loom\Monitoring\DatabaseMonitoringStore::class);
$services->addScoped(Loom\Monitoring\CorrelationId::class);
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
$provider = $services->build();
$provider->withMonitoredScope(
    Loom\Monitoring\MonitoringContext::console('cache-off'),
    static function (Loom\ServiceScope $scope): void {
        $scope->get(Loom\Cache::class)->store('array')->put('silent', 'v', 60);
    },
);
cacheCheck(count($lines) === 1, 'session still flushed');
$session = json_decode($lines[0], true, 64, JSON_THROW_ON_ERROR);
$events = array_column($session['entries'] ?? [], 'event');
cacheCheck(!in_array('cache.write', $events, true), 'cache watcher off skips cache events');

// Null monitoring
$nullProvider = (new Loom\ServiceCollection())
    ->addSingleton(Loom\CacheOptions::class, static function (): Loom\CacheOptions {
        $o = new Loom\CacheOptions();
        $o->default = 'array';
        return $o;
    })
    ->addSingleton(Loom\CacheRegistration::class)
    ->addSingleton(Loom\Cache::class, static fn (Loom\ServiceResolver $r): Loom\Cache => new Loom\Cache($r->get(Loom\CacheOptions::class), $r->get(Loom\CacheRegistration::class)))
    ->addScoped(Loom\Monitoring\CorrelationId::class)
    ->addScoped(Loom\Monitoring\Monitoring::class, Loom\Monitoring\NullMonitoring::class)
    ->build();
$nullProvider->withScope(static function (Loom\ServiceScope $scope): void {
    $scope->get(Loom\Cache::class)->put('noop', 1, 60);
});
cacheCheck(true, 'null monitoring does not break cache');

// Redis prefix isolation when Redis class is available and PING succeeds
$previousPrefix = getenv('LOOM_REDIS_PREFIX');
$restorePrefix = $previousPrefix === false ? null : $previousPrefix;
$redisReady = class_exists('Redis');
if ($redisReady) {
    try {
        $redisReady = Loom\Redis::command(['PING']) === 'PONG';
    } catch (Throwable) {
        $redisReady = false;
    }
}
if ($redisReady) {
    try {
        $prefix = 'loom-cache-test-' . bin2hex(random_bytes(3)) . ':';
        putenv('LOOM_REDIS_PREFIX=' . $prefix);
        $redisOpts = new Loom\CacheOptions();
        $redisOpts->default = 'redis';
        $redisCache = new Loom\Cache($redisOpts, new Loom\CacheRegistration());
        $sessionKey = Loom\Redis::key('session:keep-me');
        Loom\Redis::command(['SET', $sessionKey, 'session-body']);
        $redisCache->put('isolated', 'cache-value', 120);
        cacheCheck(Loom\Redis::command(['GET', $sessionKey]) === 'session-body', 'session key untouched by cache put');
        $redisCache->flush();
        cacheCheck(Loom\Redis::command(['GET', $sessionKey]) === 'session-body', 'flush does not delete session keys');
        cacheCheck($redisCache->get('isolated') === null, 'flush removed cache key');
        $expected = Loom\Redis::key('cache:redis:isolated');
        cacheCheck(str_starts_with($expected, $prefix . 'cache:redis:'), 'cache redis key uses prefix');
        $ttlKey = Loom\Redis::key('cache:redis:ttl-counter');
        Loom\Redis::command(['SETEX', $ttlKey, '120', '9']);
        $redisCache->increment('ttl-counter');
        $remaining = Loom\Redis::command(['TTL', $ttlKey]);
        cacheCheck(is_int($remaining) && $remaining > 0 && $remaining <= 120, 'redis increment preserves ttl');
        Loom\Redis::command(['DEL', $sessionKey]);
        Loom\Redis::command(['DEL', $ttlKey]);
    } finally {
        if ($restorePrefix === null) {
            putenv('LOOM_REDIS_PREFIX');
        } else {
            putenv('LOOM_REDIS_PREFIX=' . $restorePrefix);
        }
    }
} else {
    fwrite(STDERR, "loom_cache_test: skipped Redis integration (PING unavailable)\n");
}

echo "loom_cache_test: OK\n";
