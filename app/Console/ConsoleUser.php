<?php

declare(strict_types=1);

namespace App\Console;

use Loom\Console\InputError;
use Loom\Models\User;

final class ConsoleUser
{
    public static function requireId(string $value): int
    {
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new InputError('user 必须是正整数');
        }
        $id = (int) $value;
        if (User::find($id) === null) {
            throw new InputError('用户不存在：' . $value);
        }
        return $id;
    }
}
