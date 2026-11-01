<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
$runtimeScope = new \Loom\ServiceScope(new \Loom\Config(dirname(__DIR__, 2)));
$runtimeServices = $runtimeScope->services();
use Loom\Database;
use Loom\Migrations;
use Loom\Auth;
use Loom\Request;
use Loom\Queue;
use Loom\Schedule;
use Loom\Cron;

/**
 * 断言测试条件成立，失败时立即抛出异常以终止当前测试。
 *
 * @param bool $condition 待断言的条件；为假时抛出测试失败异常。
 * @param string $message 断言失败时抛出的说明。
 * @return void 无返回值；条件失败时抛出 RuntimeException。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example checkService(true, '验证说明');
 */
function checkService(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
/**
 * 构造请求并绑定到全局测试容器的上下文，设置 Cookie、来源和 CSRF 头。
 *
 * @param string $method HTTP 方法字符串，例如 GET 或 POST。默认值为 'POST'。
 * @param string $cookie 请求 Cookie 头，空字符串表示不携带会话 Cookie。默认值为 ''。
 * @param string $csrf 用于请求校验的 CSRF 令牌字符串。默认值为 ''。
 * @param string $origin 请求来源，包含协议、主机和可选端口。默认值为 'http://localhost'。
 * @return void 无返回值，更新请求上下文。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example bindServiceRequest('POST', '', '', 'http://localhost');
 */
function bindServiceRequest(string $method = 'POST', string $cookie = '', string $csrf = '', string $origin = 'http://localhost'): void
{
    global $runtimeServices;
    $runtimeServices->get(\Loom\RequestContext::class)->bind(new Request(['method' => $method, 'path' => '/', 'body' => '', 'query' => [], 'origin' => 'http://localhost', 'headers' => ['origin' => [$origin], 'cookie' => [$cookie], 'x-csrf-token' => [$csrf]]]));
}
$runtimeServices->get(\Loom\Database::class)->connect((string)getenv('LOOM_DATABASE'));
$runtimeServices->get(\Loom\Migrations::class)->initialize();
$runtimeServices->get(\Loom\Database::class)->execute('CREATE TABLE binding(value TEXT, nullable TEXT)');
$runtimeServices->get(\Loom\Database::class)->execute('INSERT INTO binding VALUES(?,?)', ["' OR 1=1 --", null]);
checkService($runtimeServices->get(\Loom\Database::class)->query('SELECT * FROM binding')[0] === ['value' => "' OR 1=1 --", 'nullable' => null], 'parameter/null preservation');
try {
    $runtimeServices->get(\Loom\Database::class)->batch([['sql' => "INSERT INTO binding VALUES('rollback',NULL)"], ['sql' => 'INSERT INTO missing_table VALUES(1)']]);
    throw new LogicException('expected database failure');
} catch (PDOException $error) {
}
checkService(count($runtimeServices->get(\Loom\Database::class)->query('SELECT * FROM binding')) === 1, 'atomic batch rollback');
$plan = [['version' => '001', 'up' => 'CREATE TABLE migration_probe(id INTEGER);', 'down' => 'DROP TABLE migration_probe;']];
$runtimeServices->get(\Loom\Migrations::class)->run('probe', $plan);
$runtimeServices->get(\Loom\Migrations::class)->run('probe', $plan);
checkService($runtimeServices->get(\Loom\Migrations::class)->status('probe', $plan)[0]['batch'] === 1, 'repeat migration');
$bad = $plan;
$bad[0]['up'] .= ' SELECT 1;';
try {
    $runtimeServices->get(\Loom\Migrations::class)->run('probe', $bad);
    throw new RuntimeException('drift not detected');
} catch (LogicException $error) {
}
$runtimeServices->get(\Loom\Migrations::class)->run('probe', $plan, true);
checkService($runtimeServices->get(\Loom\Migrations::class)->status('probe', $plan)[0]['applied'] === false, 'migration down');
$broken = [['version' => '001', 'up' => 'CREATE TABLE leaked(id INTEGER); INSERT INTO missing_table VALUES(1);', 'down' => 'DROP TABLE leaked;']];
try {
    $runtimeServices->get(\Loom\Migrations::class)->run('broken', $broken);
    throw new LogicException('expected failed migration');
} catch (PDOException $error) {
}
checkService(count($runtimeServices->get(\Loom\Database::class)->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='leaked'")) === 1, 'MySQL DDL commits before a later statement fails');
checkService(!$runtimeServices->get(\Loom\Migrations::class)->status('broken', $broken)[0]['applied'], 'Failed migration is not recorded as applied');
$runtimeServices->get(\Loom\Database::class)->execute('DROP TABLE leaked');
$limitDatabase = $runtimeServices->get(\Loom\Database::class);
checkService(\Loom\Models\AuthLimit::recordAttempt($limitDatabase, 'boundary', 100) === 1, 'First attempt opens a window');
checkService(\Loom\Models\AuthLimit::recordAttempt($limitDatabase, 'boundary', 159) === 2, 'Attempt before expiration increments');
checkService(\Loom\Models\AuthLimit::using($limitDatabase)->find('boundary')->until_at === 160, 'Attempts do not extend the current window');
checkService(\Loom\Models\AuthLimit::recordAttempt($limitDatabase, 'boundary', 160) === 1, 'Exact expiration resets the counter');
checkService(\Loom\Models\AuthLimit::using($limitDatabase)->find('boundary')->until_at === 220, 'Reset establishes the next window');
bindServiceRequest();
$registered = $runtimeServices->get(\Loom\Auth::class)->register('Ada', 'ada@example.test', 'a long password');
$cookie = explode(';', $registered['cookie'])[0];
bindServiceRequest('POST', $cookie, $registered['csrf']);
checkService($runtimeServices->get(\Loom\Auth::class)->guard()['id'] === $registered['id'], 'session and csrf');
bindServiceRequest('POST', $cookie, 'wrong');
try {
    $runtimeServices->get(\Loom\Auth::class)->guard();
    throw new LogicException('expected csrf rejection');
} catch (Loom\HttpError $error) {
    checkService($error->status === 403, 'csrf rejection');
}
bindServiceRequest('POST', $cookie, $registered['csrf'], 'http://evil.test');
try {
    $runtimeServices->get(\Loom\Auth::class)->login('ada@example.test', 'a long password');
    throw new LogicException('expected origin rejection');
} catch (Loom\HttpError $error) {
    checkService($error->status === 403, 'origin rejection');
}
bindServiceRequest('POST', $cookie, $registered['csrf']);
$login = $runtimeServices->get(\Loom\Auth::class)->login('ada@example.test', 'a long password');
checkService(\Loom\Redis::command(['GET', \Loom\Redis::key('session:' . hash('sha256', explode('=', $cookie, 2)[1]))]) === null, 'session rotation deletes the old Redis session');
bindServiceRequest('POST', explode(';', $login['cookie'])[0], $login['csrf']);
$runtimeServices->get(\Loom\Auth::class)->logout();
checkService(\Loom\Redis::command(['GET', \Loom\Redis::key('session:' . hash('sha256', explode('=', explode(';', $login['cookie'])[0], 2)[1]))]) === null, 'logout deletes the Redis session');
$runtimeServices->get(\Loom\Schedule::class)->every('probe', 10, 'scheduled', [], 1);
$runtimeServices->get(\Loom\Database::class)->execute('UPDATE loom_schedules SET next_run=100 WHERE name=?', ['probe']);
$runtimeServices->get(\Loom\Schedule::class)->every('probe', 10, 'scheduled', [], 1);
checkService((int)$runtimeServices->get(\Loom\Database::class)->query('SELECT next_run FROM loom_schedules')[0]['next_run'] === 100, 'same registration preserves next run');
$runtimeServices->get(\Loom\Schedule::class)->tick(100);
$runtimeServices->get(\Loom\Schedule::class)->tick(100);
checkService(count($runtimeServices->get(\Loom\Database::class)->query("SELECT id FROM loom_jobs WHERE kind='scheduled'")) === 1, 'schedule tick atomic and idempotent');
$cron = new Cron('*/15 * * * *', 'UTC');
checkService($cron->next(0) === 900, 'cron strictly after');
$local = new Cron('0 8 * * *', 'Asia/Shanghai');
checkService($local->next(0) === 86400, 'cron local timezone');
$fold = new Cron('30 1 * * *', 'America/New_York');
checkService($fold->next(1730611799) === 1730611800 && $fold->next(1730611800) === 1730615400, 'both DST fold instants');
$runtimeServices->get(\Loom\Database::class)->close();
$runtimeServices->get(\Loom\RequestContext::class)->bind(null);
echo "loom_services_test: OK\n";
