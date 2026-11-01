<?php

declare(strict_types=1);

namespace App\Models\Projects;

#[\Database\Annotation\Table('studio_tasks')]
final class Task extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('user_id', false)]
    public int $user_id;

    #[\Database\Annotation\Column('title', false)]
    public string $title;

    #[\Database\Annotation\Column('completed', false)]
    public int $completed = 0;

    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('created', false)]
    public int $created;

    #[\Database\Annotation\Column('project_id', true)]
    public ?int $project_id = null;
}
