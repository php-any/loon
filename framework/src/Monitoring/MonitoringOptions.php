<?php

declare(strict_types=1);

namespace Loom\Monitoring;

/**
 * 监控会话收集选项，对应 config/monitoring.php。节缺失时保留类默认值（driver=log、全开）。
 * 由 ApplicationBuilder 通过 bindOptional 绑定；与日记（Diary）配置无关。
 */
final class MonitoringOptions
{
    /**
     * log：缓冲会话并经 Diary 写出 monitoring.session；null：不收集（等价于默认 NullMonitoring）。
     */
    public string $driver = 'log';

    public MonitoringChannelOptions $channels;
    public MonitoringRecordOptions $record;

    public function __construct()
    {
        $this->channels = new MonitoringChannelOptions();
        $this->record = new MonitoringRecordOptions();
    }

    /**
     * 当前配置下是否应为该通道 begin 并缓冲监控会话。
     */
    public function collectsForChannel(string $channel): bool
    {
        if ($this->driver === 'null') {
            return false;
        }
        if ($channel === '' || !property_exists($this->channels, $channel)) {
            return true;
        }
        return $this->channels->$channel;
    }
}
