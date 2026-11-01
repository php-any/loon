<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Services\Operations\Operations;
use Loom\Database;
use Loom\HttpError;
use Loom\Input;
use Loom\Models\User;
use App\Models\Identity\Account;
use App\Models\Identity\AccountToken;
use App\Models\Access\Role;
use App\Models\Access\UserRole;
use App\Models\Access\Menu;
use App\Models\System\Setting;
use App\Models\System\AuditEvent;

final class Admin
{
    private \Loom\Database $database;
    private \Loom\Auth $auth;

    public function __construct(\Loom\Database $database, \Loom\Auth $auth)
    {
        $this->database = $database;
        $this->auth = $auth;
    }

    public static function permissions(): array
    {
        return array_merge(['overview.view' => '查看总览', 'workspace.use' => '管理自己的项目与任务', 'users.manage' => '管理管理员', 'customers.manage' => '管理官网用户', 'roles.manage' => '管理角色权限', 'menus.manage' => '管理菜单', 'settings.manage' => '修改系统配置', 'audit.view' => '查看操作审计'], Operations::permissions());
    }

    public function enroll(int $id): void
    {
        Account::using($this->database)->insertOrIgnore(['user_id' => $id], ['user_id']);
        UserRole::ensureMember($this->database, $id);
    }

    public function assertActive(int $id): void
    {
        $account = Account::using($this->database)->whereUserId($id)->rows(['enabled']);
        if ($account !== [] && (int)$account[0]['enabled'] !== 1) {
            throw new HttpError(403, '账号已停用');
        }
    }

    public function grants(int $id): array
    {
        $this->assertActive($id);
        $roles = Role::forUser($this->database, $id);
        $grants = [];
        foreach ($roles as $role) {
            foreach (json_decode($role->permissions, true, 32, JSON_THROW_ON_ERROR) as $permission) {
                if (!in_array($permission, $grants, true)) {
                    $grants[] = $permission;
                }
            }
        }
        // 旧客户端创建的账户具有与成员相同的最低访问权限。
        return $roles === [] ? ['overview.view', 'workspace.use'] : $grants;
    }

    public static function allows(array $grants, string $permission): bool
    {
        return in_array('*', $grants, true) || in_array($permission, $grants, true);
    }

    public function require(int $id, string $permission): void
    {
        if (!self::allows($this->grants($id), $permission)) {
            throw new HttpError(403, '没有此操作的权限');
        }
    }

    public function audit(?int $actor, string $action, string $target, array $detail = []): void
    {
        AuditEvent::using($this->database)->create(['actor_id' => $actor, 'action' => $action, 'target' => $target, 'detail' => json_encode($detail, JSON_THROW_ON_ERROR)]);
    }

    public function settings(): array
    {
        $settings = [];
        foreach (Setting::using($this->database)->orderBy('key')->rows(['key', 'value']) as $row) {
            $settings[$row['key']] = $row['value'];
        }
        return $settings;
    }

    public function menus(array $grants): array
    {
        $rows = Menu::using($this->database)->orderBy('sort_order')->orderBy('id')->rows();
        $visible = [];
        foreach ($rows as $row) {
            if ((int)$row['enabled'] === 1 && (($row['path'] === '' && $row['permission'] === '') || self::allows($grants, $row['permission']))) {
                $visible[(int)$row['id']] = $row;
            }
        }
        // 隐藏或禁止访问父节点时，其后代节点也一并隐藏。
        do {
            $changed = false;
            foreach ($visible as $id => $row) {
                if ($row['parent_id'] !== null && !isset($visible[(int)$row['parent_id']])) {
                    unset($visible[$id]);
                    $changed = true;
                }
                if ($row['path'] === '') {
                    $hasChild = false;
                    foreach ($visible as $child) {
                        if ((int)$child['parent_id'] === (int)$id) {
                            $hasChild = true;
                            break;
                        }
                    }
                    if (!$hasChild) {
                        unset($visible[$id]);
                        $changed = true;
                    }
                }
            }
        } while ($changed);
        return array_values($visible);
    }

    public function listing(string $resource, int $actor, int $page, string $search): array
    {
        $permission = $resource === 'audit' ? 'audit.view' : $resource . '.manage';
        if (!isset(self::permissions()[$permission])) {
            throw new HttpError(404, '模块不存在');
        }
        $this->require($actor, $permission);
        if ($resource === 'settings') {
            return ['settings' => $this->settings()];
        }
        if ($resource === 'users') {
            $result = Account::listing($this->database, $page, $search);
            $result['assignments'] = UserRole::using($this->database)->rows(['user_id', 'role_id']);
            $result['roles'] = Role::using($this->database)->orderBy('id')->rows(['id', 'name']);
            return $result;
        }
        if ($resource === 'roles') {
            return ['items' => Role::using($this->database)->orderBy('id')->rows(), 'permissions' => self::permissions()];
        }
        if ($resource === 'menus') {
            return ['items' => Menu::using($this->database)->orderBy('sort_order')->orderBy('id')->rows(), 'permissions' => self::permissions()];
        }
        return AuditEvent::listing($this->database, $page, $search);
    }

    private static function text(Input $input, string $key, int $max): string
    {
        $text = trim($input->string($key, 1, $max));
        if ($text === '') {
            throw new HttpError(422, '内容不能为空');
        }
        return $text;
    }

    private static function list(Input $input, string $key, array $allowed): array
    {
        $raw = $input->string($key, 0, 2000);
        $items = $raw === '' ? [] : explode(',', $raw);
        $result = [];
        foreach ($items as $item) {
            $item = trim($item);
            if (!in_array($item, $allowed, true) || in_array($item, $result, true)) {
                throw new HttpError(422, '选项无效或重复');
            }
            $result[] = $item;
        }
        return $result;
    }

    public function mutate(string $resource, int $actor, Input $input): array
    {
        if (!in_array($resource, ['users', 'roles', 'menus', 'settings'], true)) {
            throw new HttpError(404, '模块不存在或只读');
        }
        return $this->database->transaction(function () use ($resource, $actor, $input): array {
            Role::lockAdministrator($this->database);
            $this->require($actor, $resource . '.manage');
            $action = $input->string('action', 1, 10);
            if (!in_array($action, ['save', 'delete'], true)) {
                throw new HttpError(422, '操作无效');
            }
            if ($resource === 'settings') {
                $input->only(['action', 'site_name', 'site_description', 'registration_enabled']);
                if ($action !== 'save') {
                    throw new HttpError(422, '系统配置不可删除');
                }
                $values = ['site_name' => self::text($input, 'site_name', 100), 'site_description' => $input->string('site_description', 0, 500), 'registration_enabled' => $input->boolean('registration_enabled') ? 'true' : 'false'];
                foreach ($values as $key => $value) {
                    Setting::using($this->database)->where('key', $key)->update(['value' => $value, 'updated_at' => time()]);
                }
                $this->audit($actor, 'settings.save', 'system', $values);
                return ['ok' => true];
            }
            $id = $input->integer('id', 0);
            if ($resource === 'users') {
                $input->only($id === 0 ? ['action', 'id', 'name', 'enabled', 'roles', 'email', 'password'] : ['action', 'id', 'name', 'enabled', 'roles']);
                if ($action === 'save' && $id === 0) {
                    $email = strtolower(trim($input->string('email', 3, 254)));
                    if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/D', $email)) {
                        throw new HttpError(422, '邮箱格式无效');
                    }
                    $password = $input->string('password', 10, 72);
                    $created = User::using($this->database)->insertOrIgnore(['name' => self::text($input, 'name', 80), 'email' => $email, 'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10])], ['email']);
                    if ($created->rowsAffected !== 1) {
                        throw new HttpError(409, '邮箱已存在');
                    }
                    $id = $created->insertId;
                }
                if ($action !== 'save' || $id === 0 || !User::using($this->database)->whereId($id)->exists()) {
                    throw new HttpError(422, '用户不存在；请停用账号而非删除业务数据');
                }
                $name = self::text($input, 'name', 80);
                $enabled = $input->boolean('enabled');
                $roleIds = [];
                foreach (Role::using($this->database)->rows(['id']) as $role) {
                    $roleIds[] = (string)$role['id'];
                }
                $roles = self::list($input, 'roles', $roleIds);
                if ($roles === []) {
                    throw new HttpError(422, '至少分配一个角色');
                }
                // 仅 root 可以授予或移除 root；用户管理员不能自行提权。
                $wasRoot = UserRole::using($this->database)->whereUserId($id)->whereRoleId(1)->exists();
                $isRoot = in_array('1', $roles, true);
                if (($wasRoot || $isRoot) && !in_array('*', $this->grants($actor), true)) {
                    throw new HttpError(403, '仅超级管理员可以管理超级管理员');
                }
                if (!in_array('*', $this->grants($actor), true)) {
                    foreach ($roles as $roleId) {
                        $role = Role::using($this->database)->findOrFail($roleId)->toArray();
                        foreach (json_decode($role['permissions'], true) as $permission) {
                            $this->require($actor, $permission);
                        }
                    }
                }
                $this->enroll($id);
                User::using($this->database)->whereId($id)->update(['name' => $name]);
                Account::using($this->database)->whereUserId($id)->update(['enabled' => $enabled ? 1 : 0]);
                UserRole::using($this->database)->whereUserId($id)->delete();
                foreach ($roles as $roleId) {
                    UserRole::using($this->database)->create(['user_id' => $id, 'role_id' => (int)$roleId]);
                }
                $this->keepRoot();
                if (!$enabled) {
                    $this->auth->revoke($id);
                    AccountToken::using($this->database)->whereUserId($id)->delete();
                }
                $this->audit($actor, 'users.save', (string)$id, ['name' => $name, 'enabled' => $enabled, 'roles' => $roles]);
                return ['ok' => true, 'id' => $id];
            }
            $records = $resource === 'roles' ? Role::using($this->database) : Menu::using($this->database);
            $before = $id === 0 ? null : $records->whereId($id)->firstRow();
            if ($id !== 0 && $before === null) {
                throw new HttpError(404, '记录不存在');
            }
            if ($resource === 'roles' && in_array($id, [1, 2], true)) {
                throw new HttpError(422, '内置角色不可修改或删除，请创建自定义角色');
            }
            if ($action === 'delete') {
                $input->only(['action', 'id']);
                if ($id === 0) {
                    throw new HttpError(422, '请选择记录');
                }
                $references = $resource === 'roles' ? UserRole::using($this->database)->whereRoleId($id)->exists() : Menu::using($this->database)->whereParentId($id)->exists();
                if ($references) {
                    throw new HttpError(409, '记录仍被使用，请先移除关联');
                }
                $records->whereId($id)->delete();
            } elseif ($resource === 'roles') {
                $input->only(['action', 'id', 'name', 'permissions']);
                $name = self::text($input, 'name', 80);
                $permissions = self::list($input, 'permissions', array_keys(self::permissions()));
                foreach ($permissions as $permission) {
                    $this->require($actor, $permission);
                }
                // 修改现有角色时，不得允许权限受限的角色管理员接管高权限角色。
                if ($id !== 0) {
                    foreach (json_decode(Role::using($this->database)->findOrFail($id)->permissions, true) as $permission) {
                        $this->require($actor, $permission);
                    }
                }
                if (Role::using($this->database)->whereName($name)->where('id', '<>', $id)->exists()) {
                    throw new HttpError(409, '角色名称已存在');
                }
                $json = json_encode($permissions, JSON_THROW_ON_ERROR);
                if ($id === 0) {
                    $id = Role::using($this->database)->create(['name' => $name, 'permissions' => $json])->id;
                } else {
                    Role::using($this->database)->whereId($id)->update(['name' => $name, 'permissions' => $json]);
                }
            } else {
                $input->only(['action', 'id', 'label', 'path', 'permission', 'parent_id', 'sort_order', 'enabled']);
                $label = self::text($input, 'label', 80);
                $path = $input->string('path', 0, 200);
                // 仅允许内部目标。禁止省略协议的 URL、转义序列或任意脚本。
                if ($path !== '' && !preg_match('~^/(?:admin(?:#/?[a-z]+)?|demo(?:/[a-z]+)?|guide(?:#[a-z]+)?)$~D', $path)) {
                    throw new HttpError(422, '菜单地址必须是本站后台、演示或指南地址');
                }
                $permission = $input->string('permission', 0, 80);
                if (!($path === '' && $permission === '') && !isset(self::permissions()[$permission])) {
                    throw new HttpError(422, '权限无效');
                }
                $parent = $input->integer('parent_id', 0);
                $ancestor = $parent;
                $seen = [];
                while ($ancestor !== 0) {
                    if ($ancestor === $id || in_array($ancestor, $seen, true)) {
                        throw new HttpError(422, '菜单不能形成循环');
                    }
                    $seen[] = $ancestor;
                    $rows = Menu::using($this->database)->whereId($ancestor)->rows(['parent_id']);
                    if ($rows === []) {
                        throw new HttpError(422, '父菜单不存在');
                    }
                    $ancestor = (int)$rows[0]['parent_id'];
                }
                $values = ['label' => $label, 'path' => $path, 'permission' => $permission, 'parent_id' => $parent === 0 ? null : $parent, 'sort_order' => $input->integer('sort_order', -10000, 10000), 'enabled' => $input->boolean('enabled') ? 1 : 0];
                if ($id === 0) {
                    $id = Menu::using($this->database)->create($values)->id;
                } else {
                    Menu::using($this->database)->whereId($id)->update($values);
                }
            }
            $after = $action === 'delete' ? null : $records->whereId($id)->firstRow();
            $this->audit($actor, $resource . '.' . $action, (string)$id, ['before' => $before, 'after' => $after]);
            return ['ok' => true, 'id' => $id];
        });
    }

    private function keepRoot(): void
    {
        if (!UserRole::hasActiveAdministrator($this->database)) {
            throw new HttpError(409, '必须保留至少一名启用的超级管理员');
        }
    }

    public function bootstrap(string $email): int
    {
        return $this->database->transaction(function () use ($email): int {
            $rows = User::using($this->database)->whereEmail(strtolower(trim($email)))->rows(['id']);
            if ($rows === []) {
                throw new \RuntimeException('请先使用 admin:create 创建管理员账号，再指定该邮箱');
            }
            $id = (int)$rows[0]['id'];
            $this->enroll($id);
            Account::using($this->database)->whereUserId($id)->update(['enabled' => 1]);
            UserRole::using($this->database)->insertOrIgnore(['user_id' => $id, 'role_id' => 1], ['user_id', 'role_id']);
            $this->audit(null, 'admin.bootstrap', (string)$id, ['source' => 'console']);
            return $id;
        });
    }
}


