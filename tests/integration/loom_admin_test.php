<?php

require dirname(__DIR__, 2) . '/framework/bootstrap.php';

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
$app = (static function () { return require dirname(__DIR__, 2) . '/loom.php'; })();
try {
    $app->services->withScope(static function (\Loom\ServiceScope $scope): void {
        $scope->get(\Loom\Migrations::class)->migrate();
        require dirname(__DIR__) . '/fixtures/remove_default_admin.php';
        foreach (['root', 'member', 'manager'] as $name) {
            $id = $scope->get(\Loom\Database::class)->execute('INSERT INTO loom_users(name,email,password) VALUES(?,?,?)', [$name, $name . '@example.test', password_hash('initial password', PASSWORD_BCRYPT, ['cost' => 4])])['id'];
            $scope->get(\App\Services\Access\Admin::class)->enroll($id);
        }
        adminCheck(!Admin::allows($scope->get(\App\Services\Access\Admin::class)->grants(1), 'users.manage'), 'First signup must not be root');
        $scope->get(\App\Services\Access\Admin::class)->bootstrap('root@example.test');
        $rootMenus = $scope->get(\App\Services\Access\Admin::class)->menus(['*']);
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

        adminDenied(fn () => $scope->get(\App\Services\Access\Admin::class)->listing('users', 2, 1, ''), 403);
        adminDenied(fn () => $scope->get(\App\Services\Access\Admin::class)->mutate('settings', 2, new Input(['action' => 'save'])), 403);
        adminDenied(fn () => $scope->get(\App\Services\Access\Admin::class)->mutate('users', 1, new Input(['action' => 'save', 'id' => 1, 'name' => 'root', 'enabled' => false, 'roles' => '1'])), 409);
        adminCheck(Admin::allows($scope->get(\App\Services\Access\Admin::class)->grants(1), 'users.manage'), 'Last root failure rolled back');
        $role = $scope->get(\App\Services\Access\Admin::class)->mutate('roles', 1, new Input(['action' => 'save', 'id' => 0, 'name' => 'User manager', 'permissions' => 'users.manage,roles.manage']));
        $scope->get(\App\Services\Access\Admin::class)->mutate('users', 1, new Input(['action' => 'save', 'id' => 3, 'name' => 'manager', 'enabled' => true, 'roles' => (string)$role['id']]));
        adminDenied(fn () => $scope->get(\App\Services\Access\Admin::class)->mutate('users', 3, new Input(['action' => 'save', 'id' => 3, 'name' => 'manager', 'enabled' => true, 'roles' => '1'])), 403);
        adminDenied(fn () => $scope->get(\App\Services\Access\Admin::class)->mutate('roles', 3, new Input(['action' => 'save', 'id' => 0, 'name' => 'Escalated', 'permissions' => 'settings.manage'])), 403);
        adminDenied(fn () => $scope->get(\App\Services\Access\Admin::class)->mutate('roles', 1, new Input(['action' => 'delete', 'id' => 1])), 422);
        adminDenied(fn () => $scope->get(\App\Services\Access\Admin::class)->mutate('roles', 1, new Input(['action' => 'delete', 'id' => $role['id']])), 409);
        $menu = ['action' => 'save', 'id' => 0, 'label' => 'Custom', 'path' => '/admin#overview', 'permission' => 'overview.view', 'parent_id' => 0, 'sort_order' => 5, 'enabled' => true];
        $created = $scope->get(\App\Services\Access\Admin::class)->mutate('menus', 1, new Input($menu));
        $directory = $menu;
        $directory['label'] = 'Directory';
        $directory['path'] = '';
        $top = $scope->get(\App\Services\Access\Admin::class)->mutate('menus', 1, new Input($directory));
        $directory['parent_id'] = $top['id'];
        $nested = $scope->get(\App\Services\Access\Admin::class)->mutate('menus', 1, new Input($directory));
        $leaf = $menu;
        $leaf['parent_id'] = $nested['id'];
        $leaf['path'] = '/admin#/overview';
        $child = $scope->get(\App\Services\Access\Admin::class)->mutate('menus', 1, new Input($leaf));
        $visibleMenus = $scope->get(\App\Services\Access\Admin::class)->menus(['overview.view']);
        adminCheck(count(array_filter($visibleMenus, fn ($item) => (int)$item['id'] === $child['id'])) === 1, 'Three-level menu is visible');
        $directory['id'] = $top['id'];
        $directory['parent_id'] = $child['id'];
        adminDenied(fn () => $scope->get(\App\Services\Access\Admin::class)->mutate('menus', 1, new Input($directory)), 422);
        $directory['parent_id'] = 0;
        $directory['enabled'] = false;
        $scope->get(\App\Services\Access\Admin::class)->mutate('menus', 1, new Input($directory));
        $visibleMenus = $scope->get(\App\Services\Access\Admin::class)->menus(['overview.view']);
        adminCheck(count(array_filter($visibleMenus, fn ($item) => (int)$item['id'] === $child['id'])) === 0, 'Hidden directory hides all descendants');
        $menu['id'] = $created['id'];
        $menu['parent_id'] = $created['id'];
        adminDenied(fn () => $scope->get(\App\Services\Access\Admin::class)->mutate('menus', 1, new Input($menu)), 422);
        $menu['parent_id'] = 0;
        $menu['path'] = '//evil.example';
        adminDenied(fn () => $scope->get(\App\Services\Access\Admin::class)->mutate('menus', 1, new Input($menu)), 422);
        foreach ($scope->get(\App\Services\Access\Admin::class)->menus($scope->get(\App\Services\Access\Admin::class)->grants(2)) as $item) {
            adminCheck(in_array($item['permission'], ['overview.view', 'workspace.use'], true), 'Menu permissions');
        }
        $scope->get(\App\Services\Access\Admin::class)->mutate('settings', 1, new Input(['action' => 'save', 'site_name' => '测试后台', 'site_description' => 'test', 'registration_enabled' => false]));
        adminCheck($scope->get(\App\Services\Access\Admin::class)->settings()['registration_enabled'] === 'false', 'Settings persisted');
        $scope->get(\App\Services\Identity\Account::class)->requestToken('member@example.test', 'verify');
        $mail = $scope->get(\Loom\Database::class)->query('SELECT * FROM admin_mail ORDER BY id DESC')[0];
        preg_match('/[a-f0-9]{64}/', $mail['body'], $matches);
        $token = $matches[0];
        adminCheck($scope->get(\Loom\Database::class)->query('SELECT token FROM admin_account_tokens')[0]['token'] !== $token, 'Token is hashed');
        $scope->get(\App\Services\Identity\Account::class)->requestToken('member@example.test', 'verify');
        adminCheck(count($scope->get(\Loom\Database::class)->query('SELECT id FROM admin_mail')) === 1, 'Mail cooldown');
        $scope->get(\App\Services\Identity\Account::class)->consume($token, 'verify');
        adminDenied(fn () => $scope->get(\App\Services\Identity\Account::class)->consume($token, 'verify'), 422);
        adminCheck($scope->get(\Loom\Database::class)->query('SELECT verified_at FROM admin_accounts WHERE user_id=2')[0]['verified_at'] !== null, 'Email verified');
        $oldVersion = (int)$scope->get(\Loom\Database::class)->query('SELECT auth_version FROM loom_users WHERE id=2')[0]['auth_version'];
        $scope->get(\App\Services\Identity\Account::class)->requestToken('member@example.test', 'reset');
        preg_match('/[a-f0-9]{64}/', $scope->get(\Loom\Database::class)->query('SELECT body FROM admin_mail ORDER BY id DESC')[0]['body'], $matches);
        $scope->get(\App\Services\Identity\Account::class)->consume($matches[0], 'reset', 'new secure password');
        adminCheck((int)$scope->get(\Loom\Database::class)->query('SELECT auth_version FROM loom_users WHERE id=2')[0]['auth_version'] > $oldVersion, 'Reset revokes sessions by account version');
        adminCheck(password_verify('new secure password', $scope->get(\Loom\Database::class)->query('SELECT password FROM loom_users WHERE id=2')[0]['password']), 'Password reset');
        $scope->get(\App\Services\Access\Admin::class)->mutate('users', 1, new Input(['action' => 'save', 'id' => 2, 'name' => 'member', 'enabled' => false, 'roles' => '2']));
        adminDenied(fn () => $scope->get(\App\Services\Access\Admin::class)->require(2, 'workspace.use'), 403);
        $audit = json_encode($scope->get(\Loom\Database::class)->query('SELECT * FROM admin_audit'));
        adminCheck(!str_contains($audit, $token) && !str_contains($audit, 'new secure password'), 'Audit excludes secrets');
        adminCheck(str_contains($audit, 'roles.save') && str_contains($audit, 'settings.save'), 'Mutations audited');
        $newRole = $scope->get(\App\Services\Access\Admin::class)->mutate('roles', 1, new Input(['action' => 'save', 'id' => 0, 'name' => 'Temporary', 'permissions' => '']));
        $scope->get(\App\Services\Access\Admin::class)->mutate('roles', 1, new Input(['action' => 'delete', 'id' => $newRole['id']]));
        $scope->get(\App\Services\Access\Admin::class)->mutate('menus', 1, new Input(['action' => 'delete', 'id' => $created['id']]));
    });
    echo "loom_admin_test: OK\n";
} finally {
    $app->dispose();
}

