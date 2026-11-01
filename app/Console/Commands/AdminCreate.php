<?php

declare(strict_types=1);
namespace App\Console\Commands;

#[\Loom\Attributes\ConsoleCommand]
final class AdminCreate extends \Loom\Console\Command
{
    private \Loom\Database $database;
    private \App\Services\Access\Admin $admin;
    public function __construct(\Loom\Database $database, \App\Services\Access\Admin $admin)
    {
        $this->database = $database;
        $this->admin = $admin;
    }
    protected string $signature = 'admin:create {email : 管理员邮箱} {name : 管理员姓名}';
    protected string $description = '创建后台账号，密码读取 LOOM_ADMIN_INITIAL_PASSWORD；超级权限另用 admin:bootstrap 授予';
    public function handle(): int
    {
        $password = (string)getenv('LOOM_ADMIN_INITIAL_PASSWORD');
        $email = strtolower(trim($this->argument('email')));
        $name = trim($this->argument('name'));
        if (strlen($password) < 10 || strlen($password) > 72 || !str_contains($email, '@') || strlen($email) > 254 || $name === '' || strlen($name) > 80) {
            throw new \RuntimeException('请输入有效姓名、邮箱，并通过 LOOM_ADMIN_INITIAL_PASSWORD 设置 10–72 字节初始密码');
        }
        $id = $this->database->transaction(function () use ($email, $name, $password): int {
            $created = \Loom\Models\User::using($this->database)->insertOrIgnore(['name' => $name, 'email' => $email, 'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10])], ['email']);
            if ($created->rowsAffected !== 1) {
                throw new \RuntimeException('管理员邮箱已存在');
            }
            $this->admin->enroll($created->insertId);
            $this->admin->audit(null, 'admin.create', (string)$created->insertId);
            return $created->insertId;
        });
        $this->info('已创建后台账号，ID：' . $id);
        return 0;
    }
}
