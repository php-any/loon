<?php
declare(strict_types=1);
namespace App\Console\Commands;

use Loom\Console\Command;
use Loom\Database;

final class StudioAbout extends Command
{
    protected string $signature = 'studio:about {--json : 输出 JSON}';
    protected string $description = '命令类示例：查看 Studio 数据库迁移状态';
    public function handle(): int
    {
        $versions = Database::query("SELECT version,batch,applied_at FROM loom_migrations WHERE namespace='studio' ORDER BY version");
        if ($this->option('json')) { $this->json($versions); return 0; }
        $rows = []; foreach ($versions as $version) { $rows[] = [$version['version'], $version['batch'], '已应用']; }
        $this->table(['迁移版本', '批次', '状态'], $rows);
        return 0;
    }
}
