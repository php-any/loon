<?php

declare(strict_types=1);

namespace App\Models\Release;

#[\Database\Annotation\Table('demo_release_events')]
final class ReleaseEvent extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('owner', false)]
    public int $owner;

    #[\Database\Annotation\Column('kind', false)]
    public string $kind;

    #[\Database\Annotation\Column('detail', false)]
    public string $detail;

    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('created', false)]
    public int $created;
}
