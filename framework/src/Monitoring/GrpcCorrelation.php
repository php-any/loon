<?php

declare(strict_types=1);

namespace Loom\Monitoring;

/**
 * 从 gRPC 原生 Call（metadata）或请求 JSON 提取入站关联 id；metadata 优先。
 */
final class GrpcCorrelation
{
    public static function fromCall(mixed $call, mixed $request): ?string
    {
        if (is_object($call) && method_exists($call, 'incomingMetadata')) {
            $header = Correlation::accepts((string)$call->incomingMetadata(Correlation::HEADER));
            if ($header !== null) {
                return $header;
            }
        }
        if (is_array($request)) {
            return Correlation::fromGrpcRequest($request);
        }
        return null;
    }
}
