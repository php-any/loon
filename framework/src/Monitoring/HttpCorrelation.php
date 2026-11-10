<?php

declare(strict_types=1);

namespace Loom\Monitoring;

use Loom\Request;

/**
 * 从 HTTP 服务器变量或 Request 信封提取入站关联 id。
 */
final class HttpCorrelation
{
    public static function fromServer(array $server): ?string
    {
        $name = 'HTTP_' . strtoupper(str_replace('-', '_', Correlation::HEADER));
        if (!isset($server[$name]) || !is_string($server[$name])) {
            return null;
        }
        return Correlation::accepts($server[$name]);
    }

    public static function fromRequest(Request $request): ?string
    {
        return Correlation::accepts($request->header(Correlation::HEADER, ''));
    }
}
