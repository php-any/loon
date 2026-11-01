<?php

declare(strict_types=1);

namespace App\Models\Projects;

#[\Database\Annotation\Table('studio_reports')]
final class Report extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('user_id', false)]
    public int $user_id;

    #[\Database\Annotation\Column('job_id', false)]
    public int $job_id;

    #[\Database\Annotation\Column('total', false)]
    public int $total;

    #[\Database\Annotation\Column('completed', false)]
    public int $completed;

    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('created', false)]
    public int $created;
}
