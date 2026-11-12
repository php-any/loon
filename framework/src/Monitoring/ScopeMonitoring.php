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
            $collect = true;
            if ($scope->has(MonitoringOptions::class)) {
                $collect = MonitoringCollector::shouldCollect($scope->get(MonitoringOptions::class), $active);
            }
            $run = static function () use ($scope, $active, $operation, $collect): mixed {
                $monitoring = null;
                if ($collect && $scope->has(Monitoring::class)) {
                    $monitoring = $scope->get(Monitoring::class);
                    if ($monitoring instanceof ConfigurableMonitoring) {
                        $monitoring->bindScope($scope);
                    }
                    $monitoring->begin($active);
                }
                ActiveMonitoring::push($monitoring);
                try {
                    return $scope->run($operation);
                } finally {
                    ActiveMonitoring::pop();
                }
            };
            if (\function_exists('Loom\\Native\\withCorrelation')) {
                return \Loom\Native\withCorrelation($correlation, $run);
            }
            return $run();
        });
    }
}
