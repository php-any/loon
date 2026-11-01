<?php

declare(strict_types=1);

namespace App\Models\Customers;

#[\Database\Annotation\Table('shop_sessions')]
final class CustomerSession extends \Loom\Model
{
    public static function identity(\Loom\Database $database, string $token): ?array
    {
        $rows = $database->query('SELECT c.id,c.name,c.email,s.csrf FROM shop_sessions s JOIN shop_customers c ON c.id=s.customer_id WHERE s.token=? AND s.expires>? AND c.enabled=1', [$token, time()]);
        return $rows[0] ?? null;
    }

    #[\Database\Annotation\Column('csrf', false)]
    public string $csrf;

    #[\Database\Annotation\Column('customer_id', false)]
    public int $customer_id;

    #[\Database\Annotation\Column('expires', false)]
    public int $expires;

    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('token', false)]
    public string $token;

}
