<?php

$runtimeScope = (require dirname(__DIR__, 2) . '/framework/bootstrap/autoload.php')(dirname(__DIR__, 2));
$runtimeServices = $runtimeScope->services();

use App\Services\Access\Admin;
use App\Services\Identity\Account;
use Loom\Database;
use Loom\HttpError;
use Loom\Input;

function adminCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function adminDenied(callable $operation, int $status): void
{
    try {
        $operation();
    } catch (HttpError $error) {
        adminCheck($error->status === $status, 'Wrong denial: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected denial ' . $status);
}
$runtimeServices->get(\Loom\Database::class)->connect((string)getenv('LOOM_DATABASE'));
try {
    $runtimeScope->load('framework/bootstrap/database.php');
    require dirname(__DIR__) . '/fixtures/remove_default_admin.php';
    foreach (['root', 'member', 'manager'] as $name) {
        $id = $runtimeServices->get(\Loom\Database::class)->execute('INSERT INTO loom_users(name,email,password) VALUES(?,?,?)', [$name, $name . '@example.test', password_hash('initial password', PASSWORD_BCRYPT, ['cost' => 4])])['id'];
        $runtimeServices->get(\App\Services\Access\Admin::class)->enroll($id);
    }
    adminCheck(!Admin::allows($runtimeServices->get(\App\Services\Access\Admin::class)->grants(1), 'users.manage'), 'First signup must not be root');
    $runtimeServices->get(\App\Services\Access\Admin::class)->bootstrap('root@example.test');
    $rootMenus = $runtimeServices->get(\App\Services\Access\Admin::class)->menus(['*']);
    adminCheck(count(array_filter($rootMenus, fn ($item) => $item['path'] === '')) === 3, 'Default menu directories seeded');
    $usersMenu = array_values(array_filter($rootMenus, fn ($item) => $item['path'] === '/admin#users'))[0];
    adminCheck($usersMenu['parent_id'] !== null, 'Default user menu belongs to a directory');
    adminCheck($usersMenu['label'] === '管理员管理', 'Administrator navigation label');
    $systemMenu = array_values(array_filter($rootMenus, fn ($item) => $item['label'] === '系统管理'))[0];
    foreach (['users', 'roles', 'permissions', 'settings'] as $module) {
        $entry = array_values(array_filter($rootMenus, fn ($item) => $item['path'] === '/admin#' . $module))[0];
        adminCheck((int)$entry['parent_id'] === (int)$systemMenu['id'], 'System child: ' . $module);
    }
    $permissionMenu = array_values(array_filter($rootMenus, fn ($item) => $item['path'] === '/admin#permissions'))[0];
    adminCheck($permissionMenu['permission'] === 'roles.manage', 'Permission editor uses role management authorization');

    adminDenied(fn () => $runtimeServices->get(\App\Services\Access\Admin::class)->listing('users', 2, 1, ''), 403);
    adminDenied(fn () => $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('settings', 2, new Input(['action' => 'save'])), 403);
    adminDenied(fn () => $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('users', 1, new Input(['action' => 'save', 'id' => 1, 'name' => 'root', 'enabled' => false, 'roles' => '1'])), 409);
    adminCheck(Admin::allows($runtimeServices->get(\App\Services\Access\Admin::class)->grants(1), 'users.manage'), 'Last root failure rolled back');
    $role = $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('roles', 1, new Input(['action' => 'save', 'id' => 0, 'name' => 'User manager', 'permissions' => 'users.manage,roles.manage']));
    $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('users', 1, new Input(['action' => 'save', 'id' => 3, 'name' => 'manager', 'enabled' => true, 'roles' => (string)$role['id']]));
    adminDenied(fn () => $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('users', 3, new Input(['action' => 'save', 'id' => 3, 'name' => 'manager', 'enabled' => true, 'roles' => '1'])), 403);
    adminDenied(fn () => $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('roles', 3, new Input(['action' => 'save', 'id' => 0, 'name' => 'Escalated', 'permissions' => 'settings.manage'])), 403);
    adminDenied(fn () => $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('roles', 1, new Input(['action' => 'delete', 'id' => 1])), 422);
    adminDenied(fn () => $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('roles', 1, new Input(['action' => 'delete', 'id' => $role['id']])), 409);
    $menu = ['action' => 'save', 'id' => 0, 'label' => 'Custom', 'path' => '/admin#overview', 'permission' => 'overview.view', 'parent_id' => 0, 'sort_order' => 5, 'enabled' => true];
    $created = $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('menus', 1, new Input($menu));
    $directory = $menu;
    $directory['label'] = 'Directory';
    $directory['path'] = '';
    $top = $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('menus', 1, new Input($directory));
    $directory['parent_id'] = $top['id'];
    $nested = $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('menus', 1, new Input($directory));
    $leaf = $menu;
    $leaf['parent_id'] = $nested['id'];
    $leaf['path'] = '/admin#/overview';
    $child = $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('menus', 1, new Input($leaf));
    $visibleMenus = $runtimeServices->get(\App\Services\Access\Admin::class)->menus(['overview.view']);
    adminCheck(count(array_filter($visibleMenus, fn ($item) => (int)$item['id'] === $child['id'])) === 1, 'Three-level menu is visible');
    $directory['id'] = $top['id'];
    $directory['parent_id'] = $child['id'];
    adminDenied(fn () => $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('menus', 1, new Input($directory)), 422);
    $directory['parent_id'] = 0;
    $directory['enabled'] = false;
    $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('menus', 1, new Input($directory));
    $visibleMenus = $runtimeServices->get(\App\Services\Access\Admin::class)->menus(['overview.view']);
    adminCheck(count(array_filter($visibleMenus, fn ($item) => (int)$item['id'] === $child['id'])) === 0, 'Hidden directory hides all descendants');
    $menu['id'] = $created['id'];
    $menu['parent_id'] = $created['id'];
    adminDenied(fn () => $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('menus', 1, new Input($menu)), 422);
    $menu['parent_id'] = 0;
    $menu['path'] = '//evil.example';
    adminDenied(fn () => $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('menus', 1, new Input($menu)), 422);
    foreach ($runtimeServices->get(\App\Services\Access\Admin::class)->menus($runtimeServices->get(\App\Services\Access\Admin::class)->grants(2)) as $item) {
        adminCheck(in_array($item['permission'], ['overview.view', 'workspace.use'], true), 'Menu permissions');
    }
    $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('settings', 1, new Input(['action' => 'save', 'site_name' => '测试后台', 'site_description' => 'test', 'registration_enabled' => false]));
    adminCheck($runtimeServices->get(\App\Services\Access\Admin::class)->settings()['registration_enabled'] === 'false', 'Settings persisted');
    $runtimeServices->get(\App\Services\Identity\Account::class)->requestToken('member@example.test', 'verify');
    $mail = $runtimeServices->get(\Loom\Database::class)->query('SELECT * FROM admin_mail ORDER BY id DESC')[0];
    preg_match('/[a-f0-9]{64}/', $mail['body'], $matches);
    $token = $matches[0];
    adminCheck($runtimeServices->get(\Loom\Database::class)->query('SELECT token FROM admin_account_tokens')[0]['token'] !== $token, 'Token is hashed');
    $runtimeServices->get(\App\Services\Identity\Account::class)->requestToken('member@example.test', 'verify');
    adminCheck(count($runtimeServices->get(\Loom\Database::class)->query('SELECT id FROM admin_mail')) === 1, 'Mail cooldown');
    $runtimeServices->get(\App\Services\Identity\Account::class)->consume($token, 'verify');
    adminDenied(fn () => $runtimeServices->get(\App\Services\Identity\Account::class)->consume($token, 'verify'), 422);
    adminCheck($runtimeServices->get(\Loom\Database::class)->query('SELECT verified_at FROM admin_accounts WHERE user_id=2')[0]['verified_at'] !== null, 'Email verified');
    $oldVersion = (int)$runtimeServices->get(\Loom\Database::class)->query('SELECT auth_version FROM loom_users WHERE id=2')[0]['auth_version'];
    $runtimeServices->get(\App\Services\Identity\Account::class)->requestToken('member@example.test', 'reset');
    preg_match('/[a-f0-9]{64}/', $runtimeServices->get(\Loom\Database::class)->query('SELECT body FROM admin_mail ORDER BY id DESC')[0]['body'], $matches);
    $runtimeServices->get(\App\Services\Identity\Account::class)->consume($matches[0], 'reset', 'new secure password');
    adminCheck((int)$runtimeServices->get(\Loom\Database::class)->query('SELECT auth_version FROM loom_users WHERE id=2')[0]['auth_version'] > $oldVersion, 'Reset revokes sessions by account version');
    adminCheck(password_verify('new secure password', $runtimeServices->get(\Loom\Database::class)->query('SELECT password FROM loom_users WHERE id=2')[0]['password']), 'Password reset');
    $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('users', 1, new Input(['action' => 'save', 'id' => 2, 'name' => 'member', 'enabled' => false, 'roles' => '2']));
    adminDenied(fn () => $runtimeServices->get(\App\Services\Access\Admin::class)->require(2, 'workspace.use'), 403);
    $audit = json_encode($runtimeServices->get(\Loom\Database::class)->query('SELECT * FROM admin_audit'));
    adminCheck(!str_contains($audit, $token) && !str_contains($audit, 'new secure password'), 'Audit excludes secrets');
    adminCheck(str_contains($audit, 'roles.save') && str_contains($audit, 'settings.save'), 'Mutations audited');
    $newRole = $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('roles', 1, new Input(['action' => 'save', 'id' => 0, 'name' => 'Temporary', 'permissions' => '']));
    $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('roles', 1, new Input(['action' => 'delete', 'id' => $newRole['id']]));
    $runtimeServices->get(\App\Services\Access\Admin::class)->mutate('menus', 1, new Input(['action' => 'delete', 'id' => $created['id']]));
    echo "loom_admin_test: OK\n";
} finally {
    $runtimeServices->get(\Loom\Database::class)->close();
}

