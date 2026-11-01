<?php

declare(strict_types=1);

namespace App\Models\Access;

#[\Database\Annotation\Table('admin_system_menu_backup')]
final class SystemMenuBackup extends \Loom\Model
{
    #[\Database\Annotation\Column('created', false)]
    public int $created;

    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('label', false)]
    public string $label;

    #[\Database\Annotation\Column('parent_id', true)]
    public ?int $parent_id;

    #[\Database\Annotation\Column('sort_order', false)]
    public int $sort_order;

}
