<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

/**
 * 断言测试条件成立，失败时立即抛出异常以终止当前测试。
 *
 * @param bool $condition 待断言的条件；为假时抛出测试失败异常。
 * @param string $message 断言失败时抛出的说明。
 * @return void 无返回值；条件失败时抛出 RuntimeException。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example scopeCheck(true, '验证说明');
 */
function scopeCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require __DIR__ . '/fixtures/scopes.php';

/**
 * 构造带来源及 Cookie 的测试请求，供作用域认证校验使用。
 *
 * @param string $origin 请求来源，包含协议、主机和可选端口。
 * @param string $cookie 请求 Cookie 头，空字符串表示不携带会话 Cookie。默认值为 ''。
 * @return Loom\Request GET / 的请求对象。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $request = scopeRequest('http://localhost');
 */
function scopeRequest(string $origin, string $cookie = ''): Loom\Request
{
    return new Loom\Request(['method' => 'GET', 'path' => '/', 'origin' => $origin,
        'headers' => ['origin' => [$origin], 'cookie' => [$cookie]]]);
}

$root = sys_get_temp_dir() . '/loom-scopes-' . bin2hex(random_bytes(6));
$scopes = [];
try {
    $lazy = new Loom\ServiceScope(new Loom\Config());
    $lazy->services()->singleton(Loom\Database::class, function ($services): object {
        throw new RuntimeException('Cleanup must not resolve unused services');
    });
    $lazy->close();
    scopeCheck($lazy->services()->resolved(Loom\Database::class) === null, 'Cleanup preserves lazy service registration');
    foreach (['first', 'second'] as $name) {
        mkdir($root . '/' . $name . '/config', 0700, true);
        file_put_contents($root . '/' . $name . '/config/probe.php', '<?php return ["label" => "' . $name . '"];');
        file_put_contents($root . '/' . $name . '/config/database.php', '<?php return ["dsn" => getenv("LOOM_DATABASE") ?: "mysql:host=127.0.0.1;dbname=loom_test"];');
        $scope = new Loom\ServiceScope(new Loom\Config($root . '/' . $name));
        $scopes[$name] = $scope;
        $services = $scope->services();
        $services->get(Loom\Database::class)->connect((string)getenv('LOOM_DATABASE'));
        $services->get(Loom\Migrations::class)->initialize();
        $services->get(Loom\Database::class)->execute('CREATE TEMPORARY TABLE scope_records(id BIGINT PRIMARY KEY AUTO_INCREMENT, name TEXT NOT NULL)');
        $services->get(Loom\RequestContext::class)->bind(scopeRequest('https://' . $name . '.test'));
    }
    $a = $scopes['first']->services();
    $b = $scopes['second']->services();
    $first = $a->get(ScopedDependencies::class);
    $second = $b->get(ScopedDependencies::class);
    scopeCheck($first->label === 'first' && $second->label === 'second', 'Configuration injection must use the owning scope');
    scopeCheck($first->config !== $second->config && $first->database !== $second->database && $first->auth !== $second->auth, 'Core service identities must be independent');
    scopeCheck($first->database === $a->get(Loom\Database::class), 'Typed constructor injection shares the scope database');
    scopeCheck($first === $a->get(ScopedDependencies::class), 'Business service is shared only inside its scope');

    $modules = $a->get(Loom\ModuleRegistry::class);
    $modules->register(FirstScopedTimer::class);
    $modules->register(SecondScopedTimer::class);
    $timers = $modules->timers($a);
    $timerA = $timers->register(FirstScopedTimer::class);
    $timerB = $timers->register(SecondScopedTimer::class);
    scopeCheck($timerA->database !== $timerB->database && $timerA->database !== $first->database, 'Concurrent timers own independent databases');
    $timers->start();
    try {
        usleep(1250000);
    } finally {
        $timers->stop();
    }
    scopeCheck($timerA->rows === 1 && $timerB->rows === 1, 'Timers execute without sharing temporary tables');
    scopeCheck((int)$timerA->database->query('SELECT CONNECTION_ID() AS id')[0]['id'] !== $timerA->connectionId, 'Timer invocation cleanup reconnects with a new MySQL session');
    $timerA->database->close();

    $loginA = $first->auth->register('First', 'first@example.test', 'a sufficiently long password');
    $loginB = $second->auth->register('Second', 'second@example.test', 'a sufficiently long password');
    $a->get(Loom\RequestContext::class)->bind(scopeRequest('https://first.test', explode(';', $loginA['cookie'])[0]));
    $b->get(Loom\RequestContext::class)->bind(scopeRequest('https://second.test', explode(';', $loginB['cookie'])[0]));
    scopeCheck($first->auth->user()['name'] === 'First' && $second->auth->user()['name'] === 'Second', 'Authentication uses its own connection and request');
    $first->auth->revoke($loginA['id']);
    scopeCheck($second->auth->guard()['name'] === 'Second', 'Revocation in one scope must not affect another');

    $recordsA = ScopedRecord::using($first->database);
    $recordsB = ScopedRecord::using($second->database);
    $recordA = $recordsA->create(['name' => 'A']);
    $recordB = $recordsB->create(['name' => 'B']);
    try {
        $first->database->transaction(function () use ($first, $second): void {
            $first->database->execute("UPDATE scope_records SET name='rolled back'");
            $second->database->transaction(function () use ($second): void {
                $second->database->execute("UPDATE scope_records SET name='committed'");
            });
            throw new RuntimeException('abort first');
        });
    } catch (RuntimeException $error) {
        scopeCheck($error->getMessage() === 'abort first', 'Cross-scope transactions must not appear nested');
    }
    scopeCheck($recordsA->first()->name === 'A', 'First transaction rolled back');
    scopeCheck($recordsB->first()->name === 'committed', 'Second transaction committed independently');
    $recordsB->whereId($recordB->id)->update(['name' => 'bound connection']);
    scopeCheck($recordsB->first()->name === 'bound connection', 'Queries retain their originating database');
    scopeCheck($recordsA->first()->name === 'A', 'Bound writes cannot redirect to another scope');

    $scopes['first']->close();
    $cleared = false;
    try {
        $a->get(Loom\RequestContext::class)->current();
    } catch (LogicException $error) {
        $cleared = true;
    }
    scopeCheck($cleared, 'Closing a scope clears its request');
    scopeCheck($second->auth->user()['name'] === 'Second', 'Closing one scope leaves the other usable');
    $scopes['first']->close();

    // 作用域清理时，即使事务由外部在其拥有的 PDO 上开启，也必须回滚。
    $first->database->connect((string)getenv('LOOM_DATABASE'));
    $first->database->execute('CREATE TABLE cleanup(id INTEGER)');
    $first->database->connection()->beginTransaction();
    $first->database->execute('INSERT INTO cleanup VALUES(1)');
    $scopes['first']->close();
    $first->database->connect((string)getenv('LOOM_DATABASE'));
    scopeCheck($first->database->query('SELECT * FROM cleanup') === [], 'Cleanup rolls back pending writes');
} finally {
    foreach ($scopes as $scope) {
        $scope->close();
    }
    foreach (['first', 'second'] as $name) {
        foreach (glob($root . '/' . $name . '/config/*') as $file) {
            unlink($file);
        }
        if (is_dir($root . '/' . $name . '/config')) {
            rmdir($root . '/' . $name . '/config');
        }
        if (is_dir($root . '/' . $name)) {
            rmdir($root . '/' . $name);
        }
    }
    if (is_dir($root)) {
        rmdir($root);
    }
}
echo "loom_scope_test: OK\n";
