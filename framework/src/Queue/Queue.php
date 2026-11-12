<?php

declare(strict_types=1);

namespace Loom;

/**
 * 队列以 Redis Stream 为投递源；loom_jobs 仅作运维归档。事务内入队在提交后才 XADD。
 */
final class Queue
{
    public function __construct(
        private readonly \Loom\Process $process,
        private readonly \Loom\Config $config,
        private readonly QueueBackend $backend,
        private readonly \Loom\Database $database,
        private readonly ?\Loom\Monitoring\Monitoring $monitoring = null,
        private readonly ?\Loom\Monitoring\CorrelationId $correlationId = null,
    ) {
    }

    public function dispatch(string $kind, array $payload, int $owner, int $delay = 0): int
    {
        if ($kind === '' || $delay < 0 || $delay > 2592000) {
            throw new HttpError(422, 'Invalid job');
        }
        $now = time();
        $available = $now + $delay;
        $payload = \Loom\Monitoring\Correlation::stampPayload($payload, $this->correlationId?->tryCurrent());
        $id = $this->archivePending($kind, $payload, $owner, $available, $now);
        $envelope = ['id' => $id, 'kind' => $kind, 'payload' => $payload, 'owner' => $owner, 'attempt' => 1];
        if ($this->database->inTransaction()) {
            $this->database->afterCommit(function () use ($envelope, $available, $now, $kind, $id): void {
                $messageId = $this->publishEnvelope($envelope, $available, $now);
                $this->monitoring?->recordEvent('queue.job.dispatched', [
                    'kind' => $kind,
                    'id' => $id,
                    'message_id' => $messageId,
                    'attempt' => 1,
                ]);
            });
            return $id;
        }
        $messageId = $this->publishEnvelope($envelope, $available, $now);
        $this->monitoring?->recordEvent('queue.job.dispatched', [
            'kind' => $kind,
            'id' => $id,
            'message_id' => $messageId,
            'attempt' => 1,
        ]);
        return $id;
    }

    public function claim(?int $now = null): ?array
    {
        $job = $this->backend->claim($now ?? time());
        if ($job === null) {
            return null;
        }
        $this->monitoring?->recordEvent('queue.job.claimed', [
            'kind' => $job['kind'],
            'id' => $job['id'] ?? 0,
            'message_id' => $job['message_id'],
            'attempt' => $job['attempt'],
        ]);
        return $job;
    }

    public function finish(array $job, ?string $result, ?string $error, ?int $now = null): void
    {
        $now = $now ?? time();
        $messageId = $job['message_id'] ?? '';
        if ($messageId === '') {
            throw new \LogicException('Job missing message_id');
        }
        $attempt = (int)($job['attempt'] ?? 1);
        if ($error === null) {
            $this->archiveCompleted($job, $result, $now);
            $this->backend->ack($messageId);
            $this->monitoring?->recordEvent('queue.job.acked', [
                'kind' => $job['kind'],
                'id' => $job['id'] ?? 0,
                'message_id' => $messageId,
                'attempt' => $attempt,
            ]);
            return;
        }
        if ($attempt >= 3) {
            $this->archiveFailed($job, $error, $now);
            $this->backend->dead($messageId, $job);
            $this->monitoring?->recordEvent('queue.job.dead', [
                'kind' => $job['kind'],
                'id' => $job['id'] ?? 0,
                'message_id' => $messageId,
                'attempt' => $attempt,
            ]);
            return;
        }
        $nextAttempt = $attempt + 1;
        $available = $now + $attempt * $attempt * 2;
        $retry = $job;
        $retry['attempt'] = $nextAttempt;
        $this->archiveRetry($job, $error, $available, $now);
        $this->backend->requeue($messageId, $retry, $available, $now);
        $this->monitoring?->recordEvent('queue.job.retried', [
            'kind' => $job['kind'],
            'id' => $job['id'] ?? 0,
            'message_id' => $messageId,
            'attempt' => $nextAttempt,
        ]);
    }

    public function tick(): bool
    {
        $job = $this->claim();
        if ($job === null) {
            return false;
        }
        try {
            $result = $this->runIsolated($job);
        } catch (\Throwable $error) {
            $this->finish($job, null, $error->getMessage());
            return true;
        }
        $this->finish($job, $result, null);
        return true;
    }

    private function publishEnvelope(array $envelope, int $available, int $now): string
    {
        return $this->backend->enqueue($envelope, $available, $now);
    }

    private function archivePending(string $kind, array $payload, int $owner, int $available, int $now): int
    {
        try {
            return Models\Job::create([
                'kind' => $kind,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'owner' => $owner,
                'available' => $available,
                'created' => $now,
            ])->id;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function archiveCompleted(array $job, ?string $result, int $now): void
    {
        $id = (int)($job['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        try {
            Models\Job::whereId($id)->update([
                'state' => 'completed',
                'result' => $result,
                'error' => null,
                'lease' => null,
                'attempts' => (int)($job['attempt'] ?? 1),
            ]);
        } catch (\Throwable) {
        }
    }

    private function archiveFailed(array $job, string $error, int $now): void
    {
        $id = (int)($job['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        try {
            Models\Job::whereId($id)->update([
                'state' => 'failed',
                'error' => $error,
                'lease' => null,
                'available' => $now,
                'attempts' => (int)($job['attempt'] ?? 3),
            ]);
        } catch (\Throwable) {
        }
    }

    private function archiveRetry(array $job, string $error, int $available, int $now): void
    {
        $id = (int)($job['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        try {
            Models\Job::whereId($id)->update([
                'state' => 'pending',
                'error' => $error,
                'lease' => null,
                'available' => $available,
                'attempts' => (int)($job['attempt'] ?? 1),
            ]);
        } catch (\Throwable) {
        }
    }

    private function runIsolated(array $job): string
    {
        $job = \Loom\Monitoring\Correlation::enrichJobEnvelope($job);
        if (!isset($job['correlation_id']) || $job['correlation_id'] === '') {
            $current = $this->correlationId?->tryCurrent();
            if ($current !== null && $current !== '') {
                $job['correlation_id'] = $current;
            }
        }
        $input = tempnam(sys_get_temp_dir(), 'loom-job-');
        try {
            chmod($input, 0600);
            $payload = json_encode($job, JSON_THROW_ON_ERROR);
            if (file_put_contents($input, $payload) !== strlen($payload)) {
                throw new \RuntimeException('Cannot write job input');
            }
            $result = $this->process->run([PHP_BINARY, $this->config->root() . '/loom.php', '_job', $input], 30);
            if ($result['code'] !== 0) {
                throw new \RuntimeException($result['stderr']);
            }
            return $result['stdout'];
        } finally {
            unlink($input);
        }
    }
}
