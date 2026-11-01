<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Loom\Database;
use Loom\HttpError;
use Loom\Models\User;

final class OperationsData
{
    private \Loom\Database $database;

    public function __construct(\Loom\Database $database)
    {
        $this->database = $database;
    }

    public function user(int $id): array
    {
        $user = User::using($this->database)->whereId($id)->firstRow(['id', 'name', 'email']);
        if ($user === null) {
            throw new HttpError(422, '用户不存在');
        }
        return $user;
    }
}
