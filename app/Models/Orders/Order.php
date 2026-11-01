<?php

declare(strict_types=1);

namespace App\Models\Orders;

#[\Database\Annotation\Table('admin_orders')]
final class Order extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('number', false)]
    public string $number;

    #[\Database\Annotation\Column('user_id', false)]
    public int $user_id;

    #[\Database\Annotation\Column('currency', false)]
    public string $currency = 'CNY';

    #[\Database\Annotation\Column('total', false)]
    public int $total;

    #[\Database\Annotation\Column('status', false)]
    public string $status = 'pending';

    #[\Database\Annotation\Column('request_key', false)]
    public string $request_key;

    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('created', false)]
    public int $created;
}
