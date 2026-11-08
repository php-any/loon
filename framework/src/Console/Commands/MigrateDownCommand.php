<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

final class MigrateDownCommand extends \Loom\Console\Command
{
    protected string $signature = 'migrate:down';
    protected string $description = '撤销应用最后一个迁移批次';

    public function __construct(private \Loom\Migrations $migrations)
    {
    }

    public function handle(): int
    {
        $this->migrations->rollback();
        $this->json($this->migrations->applicationStatus());
        return 0;
    }
}
