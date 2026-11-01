<?php

declare(strict_types=1);

namespace App\Models\Release;

#[\Database\Annotation\Table('demo_release_tickets')]
final class ReleaseTicket extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('job_id', false)]
    public int $job_id;

    #[\Database\Annotation\Column('token', false)]
    public string $token;

    #[\Database\Annotation\Column('expires', false)]
    public int $expires;
}
