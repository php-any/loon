<?php

declare(strict_types=1);

namespace App\Models\Release;

#[\Database\Annotation\Table('demo_release_sessions')]
final class ReleaseSession extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('token', false)]
    public string $token;

    #[\Database\Annotation\Column('owner', false)]
    public int $owner;

    #[\Database\Annotation\Column('csrf', false)]
    public string $csrf;

    #[\Database\Annotation\Column('created', false)]
    public int $created;

    #[\Database\Annotation\Column('expires', false)]
    public int $expires;
}
