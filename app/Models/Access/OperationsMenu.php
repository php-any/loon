<?php

declare(strict_types=1);

namespace App\Models\Access;

#[\Database\Annotation\Table('admin_operations_menus')]
final class OperationsMenu extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('menu_id', false)]
    public int $menu_id;

    #[\Database\Annotation\Column('path', false)]
    public string $path;
}
