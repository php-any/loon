<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

final class LoomQueueConfigMemoryBackend implements Loom\QueueBackend
{
    public function __construct(
        public readonly string $connection,
        public readonly string $name,
    ) {
    }

    public function enqueue(array $envelope, int $available, int $now): string
    {
        return '0-1';
    }

    public function claim(int $now): ?array
    {
        return null;
    }

    public function ack(string $messageId): void
    {
    }

    public function requeue(string $messageId, array $envelope, int $available, int $now): void
    {
    }

    public function dead(string $messageId, array $envelope): void
    {
    }
}

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
file_put_contents($root . '/config/queue.php', <<<'PHP'
<?php
declare(strict_types=1);
return [
    'default' => 'memory',
    'connections' => [
        'memory' => ['driver' => 'memory', 'connection' => 'analytics', 'name' => 'jobs'],
    ],
];
PHP);
try {
    $builder = Loom\Application::createBuilder($root);
    $builder->queue->extend('memory', static function (Loom\ServiceResolver $resolver): Loom\QueueBackend {
        $queueOptions = $resolver->get(Loom\QueueOptions::class);
        $profile = $queueOptions->connectionProfile($queueOptions->default);
        return new LoomQueueConfigMemoryBackend((string)$profile['connection'], (string)$profile['name']);
    });
    $app = $builder->build();
    $backend = $app->services->get(Loom\QueueBackend::class);
    if (!$backend instanceof LoomQueueConfigMemoryBackend) {
        throw new RuntimeException('QueueBuilder extend must register alternate driver as QueueBackend');
    }
    if ($backend->connection !== 'analytics' || $backend->name !== 'jobs') {
        throw new RuntimeException('Queue connection profile must flow into extended backend');
    }
    $app->services->withScope(static function (Loom\ServiceScope $scope) use ($backend): void {
        $scope->get(Loom\Queue::class);
        if ($scope->get(Loom\QueueBackend::class) !== $backend) {
            throw new RuntimeException('Scoped Queue must use the same QueueBackend singleton');
        }
    });
    $app->dispose();
} finally {
    @unlink($root . '/config/queue.php');
    @rmdir($root . '/config');
    @rmdir($root);
}

echo "loom_queue_config_test: OK\n";
