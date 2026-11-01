<?php

declare(strict_types=1);

namespace App\Models\Customers;

#[\Database\Annotation\Table('shop_customers')]
final class Customer extends \Loom\Model
{
    public static function listing(\Loom\Database $database, int $page, string $search): array
    {
        $q = '%' . $search . '%';
        $items = $database->query('SELECT id,name,email,enabled,created FROM shop_customers WHERE name LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT 25 OFFSET ' . (($page - 1) * 25), [$q, $q]);
        $count = $database->query('SELECT COUNT(*) AS total FROM shop_customers WHERE name LIKE ? OR email LIKE ?', [$q, $q]);
        return ['items' => $items, 'total' => (int)$count[0]['total']];
    }

    #[\Database\Annotation\Column('created', false)]
    public int $created;

    #[\Database\Annotation\Column('email', false)]
    public string $email;

    #[\Database\Annotation\Column('enabled', false)]
    public int $enabled;

    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('name', false)]
    public string $name;

    #[\Database\Annotation\Column('password', false)]
    public string $password;

}
