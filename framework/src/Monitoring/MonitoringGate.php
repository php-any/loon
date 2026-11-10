<?php

declare(strict_types=1);

namespace Loom\Monitoring;

use Loom\ServiceResolver;

/**
 * 在 establish CorrelationId 之后、执行业务之前，按 MonitoringOptions 决定是否 begin 监控会话。
 */
final class MonitoringGate
{
    public static function begin(ServiceResolver $resolver, MonitoringContext $context): void
    {
        if (!$resolver->has(Monitoring::class)) {
            return;
        }
        $options = $resolver->tryGet(MonitoringOptions::class);
        if ($options instanceof MonitoringOptions && !$options->collectsForChannel($context->channel)) {
            return;
        }
        $resolver->tryGet(Monitoring::class)?->begin($context);
    }
}
