<?php

declare(strict_types=1);

namespace App\Models\Release;

#[\Database\Annotation\Table('demo_release_exchanges')]
final class ProtocolExchange extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('owner', false)]
    public int $owner;

    #[\Database\Annotation\Column('job_id', false)]
    public int $job_id;

    #[\Database\Annotation\Column('protocol', false)]
    public string $protocol;

    #[\Database\Annotation\Column('ok', false)]
    public int $ok;

    #[\Database\Annotation\Column('request', false)]
    public string $request;

    #[\Database\Annotation\Column('response', false)]
    public string $response;

    #[\Database\Annotation\Column('elapsed_ms', false)]
    public float $elapsed_ms;

    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('created', false)]
    public int $created;
}
