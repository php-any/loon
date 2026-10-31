<?php
declare(strict_types=1);

namespace App\Grpc\Services\App\Users;

// This business file is never overwritten by make:grpc.
final class UserService extends \App\Grpc\Generated\App\Users\UserServiceBase
{
    /** Request: app.users.GetUserRequest; response: app.users.GetUserReply */
    public function getUser(array $request)
    {
        $id = $request['id'] ?? 0;
        if ($id < 1) {
            throw new \Loom\Grpc\RpcException('invalid_argument', 'id 必须大于 0');
        }
        return ['id' => $id, 'name' => '示例用户'];
    }

}
