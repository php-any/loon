<?php
declare(strict_types=1);

namespace App\Grpc\Services\App\Users;

// This business file is never overwritten by make:grpc.
final class UserService extends \App\Grpc\Generated\App\Users\UserServiceBase
{
    /** Request: app.users.GetUserRequest; response: app.users.GetUserReply */
    public function getUser(array $request)
    {
        throw new \Loom\Grpc\RpcException('unimplemented', 'Implement getUser in UserService');
    }

}
