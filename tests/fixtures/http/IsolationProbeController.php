<?php

declare(strict_types=1);

namespace Fixtures\Http;

use Loom\Request;
use Loom\Response;

final class IsolationProbeController
{
    private int $visits = 0;
    private \Loom\RequestContext $context;
    private \Loom\Database $database;
    public function __construct(\Loom\RequestContext $context, \Loom\Database $database)
    {
        $this->context = $context;
        $this->database = $database;
    }
    public function probe(Request $request): Response
    {
        $this->visits++;
        $token = $request->header('x-loom-probe');
        if (strlen($token) > 80) {
            throw new \Loom\HttpError(422, 'Probe token too long');
        }
        $this->database->execute('CREATE TABLE IF NOT EXISTS loom_isolation_records(id BIGINT PRIMARY KEY AUTO_INCREMENT, token VARCHAR(80) NOT NULL)');
        $observed = '';
        try {
            $this->database->transaction(function () use ($token, &$observed): void {
                IsolationRecord::create(['token' => $token]);
                usleep(10000);
                $observed = IsolationRecord::where('token', $token)->first()->token;
                throw new \RuntimeException('rollback probe');
            });
        } catch (\RuntimeException $error) {
            if ($error->getMessage() !== 'rollback probe') {
                throw $error;
            }
        }
        $rolledBack = IsolationRecord::where('token', $token)->count() === 0;
        return Response::json(['token' => $this->context->current()->header('x-loom-probe'), 'counter' => $this->visits,
            'modelToken' => $observed, 'rolledBack' => $rolledBack])
            ->withHeader('Cache-Control', 'no-store');
    }
}
