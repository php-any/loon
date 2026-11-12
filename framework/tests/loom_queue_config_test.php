<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$options = (new Loom\Options(new Loom\Config(dirname(__DIR__, 2))))->bindOptional(Loom\QueueOptions::class, 'queue');
if (!($options instanceof Loom\QueueOptions)) {
    throw new RuntimeException('config/queue.php must bind QueueOptions');
}
$profile = $options->connectionProfile('default');
if ($profile['driver'] !== 'redis') {
    throw new RuntimeException('Default queue driver must remain redis');
}

$root = sys_get_temp_dir() . '/loom-queue-extend-' . bin2hex(random_bytes(6));
mkdir($root . '/config', 0777, true);
copy(dirname(__DIR__, 2) . '/config/queue.php', $root . '/config/queue.php');
try {
    $builder = Loom\Application::createBuilder($root);
    $builder->queue->extend('redis', static function (Loom\ServiceResolver $resolver): Loom\RedisQueue {
        $queueOptions = $resolver->get(Loom\QueueOptions::class);
        $profile = $queueOptions->connectionProfile($queueOptions->default);
        return new Loom\RedisQueue($resolver->get(Loom\Config::class), (string)$profile['connection'], 'named');
    });
    $app = $builder->build();
    $redisQueue = $app->services->get(Loom\RedisQueue::class);
    if ($redisQueue->queueName() !== 'named') {
        throw new RuntimeException('QueueBuilder extend must supply custom RedisQueue');
    }
    if ($redisQueue->redisConnection() === '' && $profile['connection'] === 'default') {
        // default connection resolves to empty string meaning default redis connection
    }
} finally {
    @unlink($root . '/config/queue.php');
    @rmdir($root . '/config');
    @rmdir($root);
}

echo "loom_queue_config_test: OK\n";
