<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$options = new Loom\RedisOptions();
$options->connections['cache'] = ['url' => 'redis://127.0.0.1:6379/1', 'prefix' => 'cache:'];
$options->connections['default']['url'] = 'redis://127.0.0.1:6379/0';
$options->connections['default']['prefix'] = 'loom:';
Loom\Redis::bindOptions($options);

if (Loom\Redis::key('demo') !== 'loom:demo') {
    throw new RuntimeException('Default Redis connection prefix must resolve from options');
}
if (Loom\Redis::key('demo', 'cache') !== 'cache:demo') {
    throw new RuntimeException('Named Redis connection must use its own prefix');
}

$bare = (new Loom\Options(new Loom\Config(dirname(__DIR__, 2))))->bindOptional(Loom\RedisOptions::class, 'redis');
if (!($bare instanceof Loom\RedisOptions) || $bare->default !== 'default' || !isset($bare->connections['default'])) {
    throw new RuntimeException('config/redis.php must bind RedisOptions');
}

$cacheOptions = new Loom\CacheOptions();
$cacheOptions->stores['isolated'] = ['driver' => 'redis', 'connection' => 'cache'];
$cache = new Loom\Cache($cacheOptions, new Loom\CacheRegistration());
$store = $cache->store('isolated');
if (!$store instanceof Loom\RedisCacheStore) {
    throw new RuntimeException('Cache redis store must accept connection option');
}

echo "loom_redis_connections_test: OK\n";
