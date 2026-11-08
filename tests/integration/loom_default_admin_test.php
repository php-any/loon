<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/framework/bootstrap.php';

function defaultAdminCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$app = (static function () { return require dirname(__DIR__, 2) . '/loom.php'; })();
try {
    $app->services->withScope(static function (\Loom\ServiceScope $scope): void {
        $database = $scope->get(\Loom\Database::class);
        $migrations = $scope->get(\Loom\Migrations::class);
        $migrations->migrate();
        $users = $database->query('SELECT * FROM loom_users');
        defaultAdminCheck(count($users) === 1 && $users[0]['email'] === 'admin@example.com', 'Fresh installation creates one default account');
        $id = (int)$users[0]['id'];
        defaultAdminCheck(password_verify('1234567890', $users[0]['password']), 'Default password is stored as a valid hash');
        defaultAdminCheck($scope->get(\App\Services\Access\Admin::class)->grants($id) === ['*'], 'Default administrator is enabled and has full access');
        $database->execute('UPDATE loom_users SET password=? WHERE id=?', [password_hash('changed-password', PASSWORD_BCRYPT), $id]);
        $database->execute('UPDATE admin_accounts SET enabled=0 WHERE user_id=?', [$id]);
        $migrations->migrate();
        defaultAdminCheck(count($database->query('SELECT id FROM loom_users')) === 1, 'Repeated initialization creates no duplicate');
        defaultAdminCheck(password_verify('changed-password', $database->query('SELECT password FROM loom_users WHERE id=?', [$id])[0]['password']), 'Repeated initialization preserves password');
        defaultAdminCheck((int)$database->query('SELECT enabled FROM admin_accounts WHERE user_id=?', [$id])[0]['enabled'] === 0, 'Repeated initialization preserves disabled state');

        // 仅针对现有普通账户重新应用种子数据，同时覆盖邮箱冲突的情况。
        $database->execute('DELETE FROM admin_user_roles WHERE user_id=?', [$id]);
        $namespace = $scope->get(\Loom\Config::class)->get('migrations')['namespace'];
        $database->execute('DELETE FROM loom_migrations WHERE namespace=? AND version=?', [$namespace, '011_default_admin']);
        $migrations->migrate();
        defaultAdminCheck($database->query('SELECT * FROM admin_user_roles WHERE user_id=?', [$id]) === [], 'Existing account is never promoted by initialization');
        defaultAdminCheck(password_verify('changed-password', $database->query('SELECT password FROM loom_users WHERE id=?', [$id])[0]['password']), 'Upgrade preserves existing credentials');
        defaultAdminCheck(count($database->query("SELECT * FROM admin_audit WHERE action='admin.initialize'")) === 1, 'Creation is audited once');
    });
    echo "loom_default_admin_test: OK\n";
} finally {
    $app->dispose();
}
