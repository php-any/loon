<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Loom\HttpError;
use Loom\Models\User;

final class OperationsData
{
    public function user(int $id): array
    {
        $user = User::whereId($id)->firstRow(['id', 'name', 'email']);
        if ($user === null) {
            throw new HttpError(422, '用户不存在');
        }
        return $user;
    }
}
