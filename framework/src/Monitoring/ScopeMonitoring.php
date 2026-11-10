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
            $correlation = $scope->get(CorrelationId::class)->establish($context->incomingCorrelationId());
            $active = $context->withCorrelationId($correlation);
            $run = static function () use ($scope, $active, $operation): mixed {
                MonitoringGate::begin($scope, $active);
                return $operation($scope);
            };
            if (\function_exists('Loom\\Native\\withCorrelation')) {
                return \Loom\Native\withCorrelation($correlation, $run);
            }
            return $run();
        });
    }
}
