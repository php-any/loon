<?php

declare(strict_types=1);

namespace App\Services\Release;

use Loom\Database;
use Loom\Config;
use App\Models\Release\ReleaseSession;
use App\Models\Release\ReleaseTicket;
use App\Models\Release\ProtocolExchange;
use App\Models\Projects\Task;
use Loom\Models\Job;

final class ReleaseProtocols
{
    private \Loom\Config $config;
    private \Loom\Database $database;

    public function __construct(\Loom\Config $config, \Loom\Database $database)
    {
        $this->config = $config;
        $this->database = $database;
    }

    public function snapshot(array $message): array
    {
        $ticket = ReleaseTicket::using($this->database)->whereJobId((int)($message['job'] ?? 0))->whereToken(hash('sha256', (string)($message['token'] ?? '')))->where('expires', '>', time())->first();
        $job = $ticket === null ? null : Job::using($this->database)->whereId($ticket->job_id)->whereKind('demo.release.digest')->whereState('running')->first();
        if ($job === null || !ReleaseSession::using($this->database)->whereOwner($job->owner)->where('expires', '>', time())->exists()) {
            return ['ok' => false, 'error' => 'invalid_or_expired_job_ticket'];
        }
        $counts = Task::using($this->database)->whereUserId($job->owner)->aggregate(['total' => ['COUNT', '*'], 'completed' => ['SUM', 'completed']]);
        return ['ok' => true, 'result' => ['total' => (int)$counts['total'], 'completed' => (int)$counts['completed'], 'captured_at' => time()]];
    }

    public function exchange(int $owner, int $job, string $protocol, string $method, array $input): array
    {
        $wire = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $result = json_decode(\Loom\Native\protocolRequest($protocol, $this->config->get($protocol . '.address'), $method, $protocol === 'grpc' ? json_encode($wire, JSON_THROW_ON_ERROR) : $wire), true, 32, JSON_THROW_ON_ERROR);
        $response = $result['response'] ?? ['error' => $result['error'] ?? 'Protocol request failed', 'status' => $result['status']];
        if ($protocol === 'grpc' && $result['ok']) {
            $response = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        }
        $ok = $result['ok'] && ($protocol === 'tcp' ? ($response['ok'] ?? false) : isset($response['ready']));
        unset($input['token']);
        ProtocolExchange::using($this->database)->create(['owner' => $owner, 'job_id' => $job, 'protocol' => $protocol, 'ok' => $ok ? 1 : 0, 'request' => json_encode($input, JSON_UNESCAPED_UNICODE), 'response' => json_encode($response, JSON_UNESCAPED_UNICODE), 'elapsed_ms' => $result['elapsed_ms']]);
        if (!$ok) {
            throw new \RuntimeException(strtoupper($protocol) . ' 服务调用失败，请查看业务链路记录');
        }
        return $response;
    }
}
