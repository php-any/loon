<?php

declare(strict_types=1);

namespace App\Grpc\Services;

final class ReleasePolicyService
{
    public function evaluate(string $payload): string
    {
        $input = json_decode($payload, true);
        if (!is_array($input) || !is_int($input['total'] ?? null) || !is_int($input['completed'] ?? null) || $input['total'] < 0 || $input['completed'] < 0 || $input['completed'] > $input['total']) {
            throw new \Loom\Grpc\RpcException('invalid_argument', 'Expected valid total and completed counts');
        }
        $ready = $input['total'] > 0 && $input['completed'] === $input['total'];
        return json_encode(['ready' => $ready, 'rule' => 'all-acceptance-tasks-completed', 'reason' => $ready ? '全部验收完成，允许生成发布摘要' : '验收清单为空或仍有未完成项，阻止生成摘要', 'total' => $input['total'], 'completed' => $input['completed']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
