<?php

declare(strict_types=1);

namespace Loom\Monitoring;

use Loom\Database;

interface MonitoringStore
{
    public function persist(MonitoringSession $session, ?Database $database): void;

    public function prune(Database $database, int $retainHours): int;
}
