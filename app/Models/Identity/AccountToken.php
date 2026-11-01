<?php

declare(strict_types=1);

namespace App\Models\Identity;

#[\Database\Annotation\Table('admin_account_tokens')]
final class AccountToken extends \Loom\Model
{
    /** 调用方持有此锁，直到令牌删除和账户变更提交。 */
    public static function lockOwner(\Loom\Database $database, string $digest, string $purpose, int $now): ?int
    {
        $row = $database->query('SELECT user_id FROM admin_account_tokens WHERE token=? AND purpose=? AND expires>? FOR UPDATE', [$digest, $purpose, $now])[0] ?? null;
        return $row === null ? null : (int)$row['user_id'];
    }


    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('token', false)]
    public string $token;

    #[\Database\Annotation\Column('user_id', false)]
    public int $user_id;

    #[\Database\Annotation\Column('purpose', false)]
    public string $purpose;

    #[\Database\Annotation\Column('expires', false)]
    public int $expires;
}
