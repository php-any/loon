<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

use Loom\Monitoring\DatabaseMonitoringStore;
use Loom\Monitoring\MonitoringOptions;

final class MonitoringPruneCommand extends \Loom\Console\Command
{
    protected string $signature = 'monitoring:prune';
    protected string $description = '按 storage.retain_hours 清理监控库中的历史会话';

    public function __construct(
        private readonly \Loom\Database $database,
        private readonly MonitoringOptions $options,
        private readonly DatabaseMonitoringStore $store,
    ) {
    }

    public function handle(): int
    {
        if (!$this->options->writesDatabase()) {
            $this->line('monitoring.driver 不是 database，无需 prune。');
            return 0;
        }
        $hours = max(1, $this->options->storage->retain_hours);
        $this->store->prune($this->database, $hours);
        $this->line('已清理早于 ' . $hours . ' 小时的监控会话。');
        return 0;
    }
}
