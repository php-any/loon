<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Loom\Console\Command;
use Loom\Models\MigrationRecord;

#[\Loom\Attributes\ConsoleCommand]
final class ApplicationMigrations extends Command
{
    private \Loom\Config $config;

    public function __construct(\Loom\Config $config)
    {
        $this->config = $config;
    }

    protected string $signature = 'app:migrations {--json : 输出 JSON}';
    protected string $description = '查看当前应用的数据库迁移记录';
    public function handle(): int
    {
        $versions = MigrationRecord::whereNamespace($this->config->get('migrations.namespace'))
            ->orderBy('version')->rows(['version', 'batch', 'applied_at']);
        if ($this->option('json')) {
            $this->json($versions);
            return 0;
        }
        $rows = [];
        foreach ($versions as $version) {
            $rows[] = [$version['version'], $version['batch'], '已应用'];
        }
        $this->table(['迁移版本', '批次', '状态'], $rows);
        return 0;
    }
}
