<?php

declare(strict_types=1);
namespace App\Http\Controllers\Identity;

#[\Loom\Attributes\Controller]
#[\Loom\Attributes\Route('POST', '/api/studio/register')]
final class RegisterController implements \Loom\Action
{
    public function __invoke(\Loom\Request $request): \Loom\Response
    {
        throw new \Loom\HttpError(403, '管理员不开放注册，请联系超级管理员。官网用户请使用用户注册入口。');
    }
}
