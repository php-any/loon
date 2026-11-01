<?php

declare(strict_types=1);

namespace App\Tcp\Handlers;

final class ReleaseSnapshotHandler
{
    private \App\Services\Release\ReleaseProtocols $releaseProtocols;

    public function __construct(\App\Services\Release\ReleaseProtocols $releaseProtocols)
    {
        $this->releaseProtocols = $releaseProtocols;
    }

    public function handle(array $message): array
    {
        $action = $message['action'] ?? '';
        if ($action === 'release.snapshot') {
            return $this->releaseProtocols->snapshot($message);
        }
        if ($action === 'ping') {
            return ['ok' => true, 'result' => ['pong' => true]];
        }
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
