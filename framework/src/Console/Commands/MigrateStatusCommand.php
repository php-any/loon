<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

final class MigrateStatusCommand extends \Loom\Console\Command
{
    protected string $signature = 'migrate:status';
    protected string $description = '查看迁移状态';

    public function __construct(private \Loom\Migrations $migrations)
    {
    }

    public function handle(): int
    {
        $this->json($this->migrations->applicationStatus());
        return 0;
    }
}
