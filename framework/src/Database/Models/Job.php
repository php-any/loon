<?php

declare(strict_types=1);

namespace Loom\Models;

#[\Database\Annotation\Table('loom_jobs')]
final class Job extends \Loom\Model
{
    public static function countsByState(\Loom\Database $database): array
    {
        $rows = [];
        foreach (self::project($database, \Loom\Results\StateCount::class)->select('state,COUNT(*) AS total')->groupBy('state')->get() as $row) {
            $rows[] = $row->toArray();
        }
        return $rows;
    }

    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('kind', false)]
    public string $kind;

    #[\Database\Annotation\Column('payload', false)]
    public string $payload;

    #[\Database\Annotation\Column('owner', false)]
    public int $owner;

    #[\Database\Annotation\Column('state', false)]
    public string $state = 'pending';

    #[\Database\Annotation\Column('attempts', false)]
    public int $attempts = 0;

    #[\Database\Annotation\Column('available', false)]
    public int $available;

    #[\Database\Annotation\Column('lease', true)]
    public ?string $lease = null;

    #[\Database\Annotation\Column('lease_until', true)]
    public ?int $lease_until = null;

    #[\Database\Annotation\Column('result', true)]
    public ?string $result = null;

    #[\Database\Annotation\Column('error', true)]
    public ?string $error = null;

    #[\Database\Annotation\Column('created', false)]
    public int $created;
}
