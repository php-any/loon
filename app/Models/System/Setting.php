<?php

declare(strict_types=1);

namespace App\Models\System;

#[\Database\Annotation\Table('admin_settings')]
final class Setting extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('key', false)]
    public string $key;

    #[\Database\Annotation\Column('value', false)]
    public string $value;

    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('updated_at', false)]
    public int $updated_at;
}
