<?php

declare(strict_types=1);

namespace Loom\Monitoring;

use Loom\ServiceProvider;
use Loom\ServiceScope;

/**
 * 在 withScope 生命周期内绑定 Monitoring 会话：打开作用域 → begin → 执行业务 → dispose 时收尾。
 */
final class ScopeMonitoring
{
    /**
     * @template T
     * @param callable(ServiceScope): T $operation
     * @return T
     */
    public static function run(ServiceProvider $services, MonitoringContext $context, callable $operation): mixed
    {
        return $services->withScope(static function (ServiceScope $scope) use ($context, $operation): mixed {
            if ($scope->has(Monitoring::class)) {
                $scope->get(Monitoring::class)->begin($context);
            }
            return $operation($scope);
        });
    }
}
