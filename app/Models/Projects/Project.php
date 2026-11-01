<?php

declare(strict_types=1);

namespace App\Models\Projects;

#[\Database\Annotation\Table('studio_projects')]
final class Project extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('user_id', false)]
    public int $user_id;

    #[\Database\Annotation\Column('name', false)]
    public string $name;

    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('created', false)]
    public int $created;
}
