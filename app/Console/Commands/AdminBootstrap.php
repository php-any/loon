<?php

declare(strict_types=1);

namespace App\Console\Commands;

#[\Loom\Attributes\ConsoleCommand]
final class AdminBootstrap extends \Loom\Console\Command
{
    private \App\Services\Access\Admin $admin;

    public function __construct(\App\Services\Access\Admin $admin)
    {
        $this->admin = $admin;
    }

    protected string $signature = 'admin:bootstrap {email : 已注册的管理员邮箱}';
    protected string $description = '通过服务器控制台授予超级管理员角色（不会自动提权首位注册用户）';

    public function handle(): int
    {
        $id = $this->admin->bootstrap($this->argument('email'));
        $this->info('已配置超级管理员，用户 ID：' . $id);
        return 0;
    }
}
