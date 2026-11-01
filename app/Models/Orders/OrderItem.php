<?php

declare(strict_types=1);

namespace App\Models\Orders;

#[\Database\Annotation\Table('admin_order_items')]
final class OrderItem extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('order_id', false)]
    public int $order_id;

    #[\Database\Annotation\Column('name', false)]
    public string $name;

    #[\Database\Annotation\Column('quantity', false)]
    public int $quantity;

    #[\Database\Annotation\Column('unit_price', false)]
    public int $unit_price;
}
