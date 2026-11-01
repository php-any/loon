<?php

declare(strict_types=1);

namespace App\Models\Access;

#[\Database\Annotation\Table('admin_menus')]
final class Menu extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('label', false)]
    public string $label;

    #[\Database\Annotation\Column('path', false)]
    public string $path;

    #[\Database\Annotation\Column('permission', false)]
    public string $permission;

    #[\Database\Annotation\Column('parent_id', true)]
    public ?int $parent_id = null;

    #[\Database\Annotation\Column('sort_order', false)]
    public int $sort_order = 0;

    #[\Database\Annotation\Column('enabled', false)]
    public int $enabled = 1;
}
