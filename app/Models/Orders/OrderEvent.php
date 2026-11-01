<?php

declare(strict_types=1);

namespace App\Models\Orders;

#[\Database\Annotation\Table('admin_order_events')]
final class OrderEvent extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('order_id', false)]
    public int $order_id;

    #[\Database\Annotation\Column('actor_id', false)]
    public int $actor_id;

    #[\Database\Annotation\Column('status', false)]
    public string $status;

    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('created', false)]
    public int $created;
}
