<?php

declare(strict_types=1);

namespace App\Models\Customers;

#[\Database\Annotation\Table('shop_orders')]
final class Purchase extends \Loom\Model
{
    public static function listing(\Loom\Database $database, int $page, string $search): array
    {
        $q = '%' . $search . '%';
        $where = ' FROM shop_orders o JOIN shop_customers c ON c.id=o.customer_id WHERE o.number LIKE ? OR c.email LIKE ?';
        $items = $database->query('SELECT o.id,o.number,o.customer_id,c.name,c.email,o.total,o.status,o.created' . $where . ' ORDER BY o.id DESC LIMIT 25 OFFSET ' . (($page - 1) * 25), [$q, $q]);
        $count = $database->query('SELECT COUNT(*) AS total' . $where, [$q, $q]);
        return ['items' => $items, 'total' => (int)$count[0]['total']];
    }

    #[\Database\Annotation\Column('created', false)]
    public int $created;

    #[\Database\Annotation\Column('customer_id', false)]
    public int $customer_id;

    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('number', false)]
    public string $number;

    #[\Database\Annotation\Column('request_key', false)]
    public string $request_key;

    #[\Database\Annotation\Column('status', false)]
    public string $status;

    #[\Database\Annotation\Column('total', false)]
    public int $total;

}
