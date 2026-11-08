<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

final class MigrateUpCommand extends \Loom\Console\Command
{
    protected string $signature = 'migrate:up';
    protected string $description = '执行数据库迁移';

    public function __construct(private \Loom\Migrations $migrations)
    {
    }

    public function handle(): int
    {
        $this->migrations->migrate();
        $this->json($this->migrations->applicationStatus());
        return 0;
    }
}
