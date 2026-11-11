<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

/**
 * 鏂█娴嬭瘯鏉′欢鎴愮珛锛屽け璐ユ椂绔嬪嵆鎶涘嚭寮傚父浠ョ粓姝㈠綋鍓嶆祴璇曘€?
 *
 * @param bool $condition 寰呮柇瑷€鐨勬潯浠讹紱涓哄亣鏃舵姏鍑烘祴璇曞け璐ュ紓甯搞€?
 * @param string $message 鏂█澶辫触鏃舵姏鍑虹殑璇存槑銆?
 * @return void 鏃犺繑鍥炲€硷紱鏉′欢澶辫触鏃舵姏鍑?RuntimeException銆?
 *
 * 浣跨敤绀轰緥锛堜緷璧栧璞＄敱鎵€鍦ㄥ簲鐢ㄦ垨娴嬭瘯鐜鎻愪緵锛夛細
 * @example scopeCheck(true, '楠岃瘉璇存槑');
 */
function scopeCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 鏂█鎿嶄綔鎶涘嚭鍚寚瀹氭枃瀛楃殑寮傚父銆?
 *
 * @param callable $operation 棰勬湡澶辫触鐨勬搷浣溿€?
 * @param string $message 寮傚父淇℃伅閲屽簲鍖呭惈鐨勬枃瀛椼€?
 * @return void 鏃犺繑鍥炲€硷紱娌℃湁鎸夐鏈熷け璐ユ椂鎶涘嚭 RuntimeException銆?
 *
 * 浣跨敤绀轰緥锛堜緷璧栧璞＄敱鎵€鍦ㄥ簲鐢ㄦ垨娴嬭瘯鐜鎻愪緵锛夛細
 * @example scopeReject(fn () => $scope->get('x'), 'disposed');
 */
function scopeReject(callable $operation, string $message): void
{
    $rejected = false;
    try {
        $operation();
    } catch (Throwable $error) {
        $rejected = str_contains($error->getMessage(), $message);
    }
    scopeCheck($rejected, 'Expected rejection: ' . $message);
}

require __DIR__ . '/fixtures/scopes.php';

/**
 * 鏋勯€犲甫鏉ユ簮鍙?Cookie 鐨勬祴璇曡姹傦紝渚涗綔鐢ㄥ煙璁よ瘉鏍￠獙浣跨敤銆?
 *
 * @param string $origin 璇锋眰鏉ユ簮锛屽寘鍚崗璁€佷富鏈哄拰鍙€夌鍙ｃ€?
 * @param string $cookie 璇锋眰 Cookie 澶达紝绌哄瓧绗︿覆琛ㄧず涓嶆惡甯︿細璇?Cookie銆傞粯璁ゅ€间负 ''銆?
 * @return Loom\Request GET / 鐨勮姹傚璞°€?
 *
 * 浣跨敤绀轰緥锛堜緷璧栧璞＄敱鎵€鍦ㄥ簲鐢ㄦ垨娴嬭瘯鐜鎻愪緵锛夛細
 * @example $request = scopeRequest('http://localhost');
 */
function scopeRequest(string $origin, string $cookie = ''): Loom\Request
{
    return new Loom\Request(['method' => 'GET', 'path' => '/', 'origin' => $origin,
        'headers' => ['origin' => [$origin], 'cookie' => [$cookie]]]);
}

/**
 * 鍗曚緥鏃ュ織锛岃褰曚綔鐢ㄥ煙閲屽彂鐢熺殑浜嬩欢銆?
 */
final class ScopeLog
{
    public array $entries = [];
    public array $timers = [];
}

/**
 * 浣滅敤鍩熸湇鍔★紝閲婃斁鏃跺啓鏃ュ織銆?
 */
final class ScopeTracked implements Loom\Disposable
{
    public function __construct(private ScopeLog $log, private Loom\Database $database)
    {
    }

    public function dispose(): void
    {
        $this->log->entries[] = 'disposed:' . spl_object_id($this->database);
    }
}

/**
 * 璁板綍姣忔鑺傛媿鎷垮埌鐨?Database 鍙婃ā鍨嬬粦瀹氾紝楠岃瘉骞跺彂瀹氭椂鍣ㄥ悇鏈変綔鐢ㄥ煙銆?
 */
abstract class ScopeTimerProbe
{
    public function __construct(private ScopeLog $log, private Loom\Database $database)
    {
    }

    public function __invoke(): void
    {
        usleep(20000);
        $this->log->timers[] = [static::class, spl_object_id($this->database), Loom\ModelContext::database() === $this->database];
    }
}

$root = sys_get_temp_dir() . '/loom-scopes-' . bin2hex(random_bytes(6));
mkdir($root . '/config', 0700, true);
// Origami 瑙ｆ瀽鏂囦欢鏃跺氨瑕佹壘鍒版敞瑙ｇ被锛屾墍浠ュ甫 Timer 娉ㄨВ鐨勭被鏀捐繘 bootstrap 涔嬪悗鎵嶅姞杞界殑鏂囦欢銆?
file_put_contents($root . '/timers.php', '<?php #[Loom\Timer(1)] final class FirstScopeTimer extends ScopeTimerProbe {} #[Loom\Timer(1)] final class SecondScopeTimer extends ScopeTimerProbe {}');
require $root . '/timers.php';
unlink($root . '/timers.php');
$dsn = (string)getenv('LOOM_DATABASE');
$otherName = null;
$app = null;
try {
    file_put_contents($root . '/config/probe.php', '<?php return ["label" => "scoped"];');
    file_put_contents($root . '/config/database.php', '<?php return ["dsn" => getenv("LOOM_DATABASE") ?: "mysql:host=127.0.0.1;dbname=loom_test"];');
    $builder = Loom\Application::createBuilder($root);
    $builder->services->addSingleton(ScopeLog::class);
    $builder->services->addScoped(ScopeTracked::class);
    $app = $builder->build();
    $provider = $app->services;
    $log = $provider->get(ScopeLog::class);

    $a = $provider->createScope();
    $b = $provider->createScope();
    $first = $a->get(ScopedDependencies::class);
    $second = $b->get(ScopedDependencies::class);
    scopeCheck($first->label === 'scoped' && $second->label === 'scoped', 'Configuration injection works inside scopes');
    scopeCheck($first->config === $second->config && $first->config === $provider->get(Loom\Config::class), 'Configuration is an application singleton');
    scopeCheck($first->database !== $second->database && $first->auth !== $second->auth, 'Scoped core services are independent per scope');
    scopeCheck($first->database === $a->get(Loom\Database::class) && $first->auth === $a->get(Loom\Auth::class), 'Constructor injection shares the scope instances');
    scopeCheck($first === $a->get(ScopedDependencies::class), 'Business service is shared only inside its scope');
    scopeReject(static fn () => $provider->get(Loom\Database::class), 'from the root provider');

    $databaseA = $first->database;
    $databaseB = $second->database;
    $a->run(static fn (Loom\ServiceScope $scope) => $scope->get(Loom\Migrations::class)->initialize());
    $databaseA->execute('CREATE TABLE scope_records(id BIGINT PRIMARY KEY AUTO_INCREMENT, name TEXT NOT NULL)');

    // 璁よ瘉鐘舵€佽窡鐫€鍚勮嚜浣滅敤鍩熺殑 RequestContext 璧般€?
    $a->get(Loom\RequestContext::class)->bind(scopeRequest('https://first.test'));
    $b->get(Loom\RequestContext::class)->bind(scopeRequest('https://second.test'));
    $loginA = $a->run(static fn (): array => $first->auth->register('First', 'first@example.test', 'a sufficiently long password'));
    $loginB = $b->run(static fn (): array => $second->auth->register('Second', 'second@example.test', 'a sufficiently long password'));
    $a->get(Loom\RequestContext::class)->bind(scopeRequest('https://first.test', explode(';', $loginA['cookie'])[0]));
    $b->get(Loom\RequestContext::class)->bind(scopeRequest('https://second.test', explode(';', $loginB['cookie'])[0]));
    scopeCheck($a->run(static fn (): string => $first->auth->user()['name']) === 'First' && $b->run(static fn (): string => $second->auth->user()['name']) === 'Second', 'Authentication uses its own request');
    $a->run(static fn () => $first->auth->revoke($loginA['id']));
    scopeCheck($b->run(static fn (): string => $second->auth->guard()['name']) === 'Second', 'Revocation in one scope must not affect another');

    // 浜嬪姟鍙睘浜庡紑鍚畠鐨?Database 鍜岄偅娆″洖璋冿紱鍙︿竴涓綔鐢ㄥ煙璧拌繛鎺ユ睜锛岀湅涓嶅埌鏈彁浜ょ殑鍐欏叆銆?
    $recordA = ScopedRecord::using($databaseA)->create(['name' => 'A']);
    try {
        $databaseA->transaction(static function () use ($a, $databaseA, $databaseB, $recordA): void {
            $databaseA->execute("UPDATE scope_records SET name='rolled back' WHERE id=?", [$recordA->id]);
            $a->run(static function () use ($databaseB, $recordA): void {
                ScopedRecord::create(['name' => 'model in transaction']);
                scopeCheck(ScopedRecord::query()->count() === 2, 'Model writes inside the callback use its transaction');
                scopeCheck($databaseB->query('SELECT name FROM scope_records WHERE id=?', [$recordA->id])[0]['name'] === 'A', 'Another scope cannot see uncommitted writes');
                scopeCheck(!$databaseB->inTransaction(), 'A transaction does not leak into another scope');
            });
            throw new RuntimeException('abort first');
        });
    } catch (RuntimeException $error) {
        scopeCheck($error->getMessage() === 'abort first', 'Transaction error is preserved');
    }
    scopeCheck(!$databaseA->inTransaction(), 'The transaction ends with its callback');
    scopeCheck(ScopedRecord::using($databaseA)->count() === 1 && ScopedRecord::using($databaseA)->find($recordA->id)->name === 'A', 'First transaction rolled back raw and model writes');
    $databaseB->transaction(static function () use ($databaseB, $recordA): void {
        $databaseB->execute("UPDATE scope_records SET name='committed' WHERE id=?", [$recordA->id]);
    });
    scopeCheck(ScopedRecord::using($databaseA)->find($recordA->id)->name === 'committed', 'A later transaction in another scope commits on its own');
    scopeReject(static fn () => $databaseA->transaction(static fn () => $databaseA->transaction(static fn () => null)), 'Nested transactions');
    try {
        $databaseA->transaction(static function () use ($databaseA): void {
            $databaseA->execute("INSERT INTO scope_records(name) VALUES('pending')");
            throw new RuntimeException('abort cleanup');
        });
    } catch (RuntimeException $error) {
        scopeCheck($error->getMessage() === 'abort cleanup', 'Cleanup transaction preserves its error');
    }
    scopeCheck($databaseA->query("SELECT id FROM scope_records WHERE name='pending'") === [], 'A failed transaction leaves no pending write');

    // 妯″瀷闈欐€佹煡璇娇鐢?run() 缁戝畾鐨勪綔鐢ㄥ煙 Database銆傜浜屼釜浣滅敤鍩熸敼杩炲彟涓€涓簱锛屼互渚垮尯鍒嗐€?
    $otherName = preg_match('/dbname=([^;]+)/', $dsn, $matches) === 1 ? $matches[1] . '_b' : null;
    scopeCheck($otherName !== null, 'LOOM_DATABASE names a database');
    $databaseA->execute('CREATE DATABASE ' . $otherName);
    $databaseA->execute("UPDATE scope_records SET name='A'");
    $databaseB->connect(preg_replace('/dbname=[^;]+/', 'dbname=' . $otherName, $dsn));
    $databaseB->execute('CREATE TABLE scope_records(id BIGINT PRIMARY KEY AUTO_INCREMENT, name TEXT NOT NULL)');
    $databaseB->execute("INSERT INTO scope_records(name) VALUES('B')");
    scopeReject(static fn () => ScopedRecord::first(), 'active service scope');
    $dbg = $a->run(static fn (Loom\ServiceScope $scope): array => [$scope === $a, ScopedRecord::first()->name, count(ScopedRecord::all())]);
    fwrite(STDERR, json_encode($dbg) . "\n");
    scopeCheck($a->run(static fn (Loom\ServiceScope $scope): string => $scope === $a ? ScopedRecord::first()->name : 'wrong scope') === 'A', 'run() passes the scope and binds its database');
    scopeCheck($b->run(static fn (): string => ScopedRecord::first()->name) === 'B', 'Each scope binds its own database');
    scopeCheck($b->run(static fn (): string => ScopedRecord::using($databaseA)->first()->name) === 'A', 'An explicit database overrides the bound one');
    scopeReject(static fn () => ScopedRecord::first(), 'active service scope');

    $nested = $a->run(static function () use ($provider, $databaseA, $otherName, $dsn): array {
        $inner = $provider->withScope(static function (Loom\ServiceScope $scope) use ($otherName, $dsn): array {
            $database = $scope->get(Loom\Database::class);
            $database->connect(preg_replace('/dbname=[^;]+/', 'dbname=' . $otherName, $dsn));
            return [Loom\ModelContext::database() === $database, ScopedRecord::first()->name];
        });
        return [$inner, Loom\ModelContext::database() === $databaseA, ScopedRecord::first()->name];
    });
    scopeCheck($nested[0] === [true, 'B'], 'Nested withScope binds the inner scope database');
    scopeCheck($nested[1] === true && $nested[2] === 'A', 'Nested withScope restores the outer binding');
    scopeReject(static fn () => $a->run(static function (): void {
        throw new LogicException('run failed');
    }), 'run failed');
    scopeReject(static fn () => ScopedRecord::first(), 'active service scope');

    $finished = new \Channel(4);
    foreach ([[$a, 'A'], [$b, 'B']] as $case) {
        \spawn(static function () use ($case, $finished): void {
            try {
                $case[0]->run(static function () use ($case, $finished): void {
                    \spawn(static function () use ($case, $finished): void {
                        try {
                            usleep(2000);
                            scopeCheck(ScopedRecord::first()->name === $case[1], 'spawn inherits the model binding');
                            $finished->send('ok');
                        } catch (Throwable $error) {
                            $finished->send($error->getMessage());
                        }
                    });
                    for ($i = 0; $i < 10; $i++) {
                        usleep(1000);
                        scopeCheck(ScopedRecord::first()->name === $case[1], 'concurrent model scopes are isolated');
                    }
                });
                $finished->send('ok');
            } catch (Throwable $error) {
                $finished->send($error->getMessage());
            }
        });
    }
    $results = [$finished->receive(), $finished->receive(), $finished->receive(), $finished->receive()];
    scopeCheck($results === ['ok', 'ok', 'ok', 'ok'], 'concurrent scope checks: ' . implode(', ', $results));

    // 瀹氭椂鍣ㄦ瘡娆¤妭鎷嶅湪鑷繁鐨勪綔鐢ㄥ煙閲岃В鏋愬苟杩愯銆?
    $timers = new Loom\TimerRunner($provider);
    $timers->register(FirstScopeTimer::class);
    $timers->register(SecondScopeTimer::class);
    $timers->start();
    try {
        usleep(1250000);
    } finally {
        $timers->stop();
    }
    usleep(100000);
    scopeCheck(count($log->timers) === 2 && $log->timers[0][0] !== $log->timers[1][0], 'Both timers ticked once: ' . json_encode($log->timers));
    scopeCheck($log->timers[0][1] !== $log->timers[1][1], 'Concurrent timers own independent databases');
    scopeCheck($log->timers[0][2] && $log->timers[1][2], 'Timer ticks bind their scope database for models');
    $timers->tick(FirstScopeTimer::class);
    scopeCheck(count($log->timers) === 3 && $log->timers[2][1] !== $log->timers[0][1], 'Each tick opens a new scope');

    // 閲婃斁涓€涓綔鐢ㄥ煙鍙噴鏀惧畠鍒涘缓鐨勬湇鍔★紱杩炴帴姹犲睘浜庤繘绋嬶紝鍙︿竴涓綔鐢ㄥ煙鐓у父鍙敤銆?
    $trackedA = $a->get(ScopeTracked::class);
    $b->get(ScopeTracked::class);
    $a->dispose();
    $a->dispose();
    scopeCheck($log->entries === ['disposed:' . spl_object_id($databaseA)], 'Disposing a scope disposes only its own services, once');
    scopeReject(static fn () => $a->get(Loom\Database::class), 'disposed');
    scopeReject(static fn () => $a->run(static fn () => null), 'disposed');
    scopeCheck($b->run(static fn (): string => ScopedRecord::first()->name) === 'B', 'Disposing one scope leaves the other usable');
    scopeCheck($b->get(ScopedDependencies::class) === $second, 'The other scope keeps its instances');
    $b->dispose();
    scopeCheck(count($log->entries) === 2, 'The other scope disposes its own services');
    scopeCheck($provider->withScope(static fn (Loom\ServiceScope $scope): int => count($scope->get(Loom\Database::class)->query('SELECT id FROM scope_records'))) === 1, 'A new scope reuses the process pool');
} finally {
    if ($otherName !== null && $app !== null) {
        $app->services->withScope(static function (Loom\ServiceScope $scope) use ($otherName): void {
            $scope->get(Loom\Database::class)->execute('DROP DATABASE IF EXISTS ' . $otherName);
        });
    }
    if ($app !== null) {
        $app->dispose();
    }
    foreach (glob($root . '/config/*') as $file) {
        unlink($file);
    }
    rmdir($root . '/config');
    rmdir($root);
}
echo "loom_scope_test: OK\n";
