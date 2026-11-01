<?php

declare(strict_types=1);

namespace App\Models\Access;

#[\Database\Annotation\Table('admin_menu_groups')]
final class MenuGroup extends \Loom\Model
{
    #[\Database\Annotation\Column('group_id', false)]
    public int $group_id;

    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('name', false)]
    public string $name;

}
