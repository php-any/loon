<?php

declare(strict_types=1);
$runtimeScope = (require dirname(__DIR__, 2) . '/framework/bootstrap/autoload.php')(dirname(__DIR__, 2));
$runtimeServices = $runtimeScope->container();
use Loom\Database;
use Loom\Queue;
use Loom\Redis;
use Loom\Auth;
use Loom\Request;
use Loom\HttpError;

if (getenv('LOOM_TEST_MYSQL') !== '1') {
    echo "loom_mysql_redis_test: OK (MySQL/Redis integration requires LOOM_TEST_MYSQL=1)\n";
    return;
}
function mrCheck(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}
$runtimeServices->get(\Loom\Database::class)->connect($runtimeServices->get(\Loom\Database::class)->pdoDsn());
try {
    $runtimeScope->load('framework/bootstrap/database.php');
    mrCheck(Redis::command(['PING']) === 'PONG', 'Redis connection');
    mrCheck(extension_loaded('redis'), 'Origami Redis driver is registered');
    mrCheck($runtimeServices->get(\Loom\Database::class)->query("SELECT 'rowid key unixepoch() excluded.value ON CONFLICT(id) DO NOTHING' AS value")[0]['value'] === 'rowid key unixepoch() excluded.value ON CONFLICT(id) DO NOTHING', 'MySQL raw SQL is not rewritten');
    $database = $runtimeServices->get(\Loom\Database::class);
    $database->transaction(function () use ($database): void {
        mrCheck($database->inTransaction(), 'Callback runs inside its transaction');
    });
    $otherScope = new \Loom\ServiceScope($runtimeServices->get(\Loom\Config::class));
    $otherDatabase = $otherScope->container()->get(\Loom\Database::class);
    $database = $runtimeServices->get(\Loom\Database::class);
    try {
    $runtimeServices->get(\Loom\Database::class)->transaction(function () use ($runtimeServices): void {
        $runtimeServices->get(\Loom\Database::class)->execute("INSERT INTO loom_users(name,email,password) VALUES('Uncommitted','scope-uncommitted@example.test','unused')");
        mrCheck(count($runtimeServices->get(\Loom\Database::class)->query("SELECT id FROM loom_users WHERE email='scope-uncommitted@example.test'")) === 1, 'Statements in the callback see the transaction');
        throw new RuntimeException('scope rollback');
    });
    } catch (RuntimeException $error) {
        mrCheck($error->getMessage() === 'scope rollback', 'Transaction error is preserved');
    }
    mrCheck($database->query("SELECT id FROM loom_users WHERE email='scope-uncommitted@example.test'") === [], 'Scope transaction rolled back');
    $otherDatabase->transaction(function () use ($otherDatabase): void {
        $otherDatabase->execute("INSERT INTO loom_users(name,email,password) VALUES('Committed','scope-committed@example.test','unused')");
    });
    mrCheck(count($database->query("SELECT id FROM loom_users WHERE email='scope-committed@example.test'")) === 1, 'A later transaction commits on its own');
    $database->execute("DELETE FROM loom_users WHERE email='scope-committed@example.test'");
    } finally {
        $otherScope->close();
    }
    Redis::using(function ($client) use ($runtimeServices): void {
        $key = Redis::key('contract:string');
        $results = Redis::pipeline($client, [['SET', $key, ''], ['GET', $key], ['DEL', $key]]);
        mrCheck($results[1] === '' && $results[2] === 1, 'Native pipeline preserves order and empty values');
        mrCheck(Redis::execute($client, ['GET', $key]) === null, 'Missing value maps to null');
        mrCheck(Redis::execute($client, ['MGET', $key]) === [null], 'Nested Redis nil maps to null');
        Redis::execute($client, ['SET', $key, 'scalar']);
        $failed = false;
        try {
            Redis::execute($client, ['LPUSH', $key, 'value']);
        } catch (HttpError $e) {
            $failed = $e->status === 503;
        }
        mrCheck($failed && Redis::execute($client, ['PING']) === 'PONG', 'Command errors are detected without corrupting the connection');
        Redis::execute($client, ['DEL', $key]);
    });
    $owner = $runtimeServices->get(\Loom\Database::class)->execute('INSERT INTO loom_users(name,email,password) VALUES(?,?,?)', ['Redis test', 'redis-test@example.test', password_hash('redis testing password', PASSWORD_BCRYPT, ['cost' => 4])])['id'];
    $runtimeServices->get(\App\Services\Access\Admin::class)->enroll($owner);
    $queueKeys = $runtimeServices->get(\Loom\RedisQueue::class)->keys();
    try {
        $runtimeServices->get(\Loom\Database::class)->transaction(function () use ($owner, $runtimeServices): void {
            $runtimeServices->get(\Loom\Queue::class)->dispatch('rollback.probe', [], $owner);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException $error) {
        mrCheck($error->getMessage() === 'rollback', 'Transaction failure');
    }
    mrCheck($runtimeServices->get(\Loom\Database::class)->query("SELECT id FROM loom_jobs WHERE kind='rollback.probe'") === [], 'Rolled back job archive removed');
    mrCheck((int)Redis::command(['XLEN', $queueKeys[0]]) === 0 && (int)Redis::command(['ZCARD', $queueKeys[1]]) === 0, 'Rolled back dispatch never reaches Redis');
    $runtimeServices->get(\Loom\Database::class)->execute('INSERT INTO studio_tasks(user_id,title) VALUES(?,?)', [$owner, 'MySQL task']);
    $before = time();
    $delayed = $runtimeServices->get(\Loom\Queue::class)->dispatch('studio.digest', [], $owner, 10);
    $dsn = getenv('LOOM_DATABASE');
    try {
        putenv('LOOM_DATABASE=' . preg_replace('/dbname=([^;]+)/', 'dbname=$1_other', $dsn));
        $foreignConfig = new \Loom\Config(dirname(__DIR__, 2));
        $foreignConfig->load();
        $foreignQueue = new \Loom\RedisQueue($foreignConfig);
        $foreignKeys = $foreignQueue->keys();
    } finally {
        putenv('LOOM_DATABASE=' . $dsn);
    }
    Redis::eval(file_get_contents(dirname(__DIR__, 2) . '/framework/resources/queue/initialize.lua'), $foreignKeys);
    $foreignPayload = json_encode(['id' => $delayed, 'kind' => 'foreign.job', 'payload' => [], 'owner' => 999, 'attempt' => 1], JSON_THROW_ON_ERROR);
    Redis::eval(file_get_contents(dirname(__DIR__, 2) . '/framework/resources/queue/enqueue.lua'), $foreignKeys, [$foreignPayload, (string)time(), (string)time()]);
    mrCheck($runtimeServices->get(\Loom\Queue::class)->claim($before) === null, 'Delayed job not claimed early');
    mrCheck(Redis::command(['TYPE', $queueKeys[0]]) === 'stream', 'Queue uses Redis Stream');
    mrCheck((int)Redis::command(['ZCARD', $queueKeys[1]]) >= 1, 'Delayed job stays in Redis until due');
    $first = $runtimeServices->get(\Loom\Queue::class)->claim($before + 11);
    mrCheck($first !== null && $first['id'] === $delayed, 'Delayed job claimed after promote');
    mrCheck($first['kind'] === 'studio.digest' && $first['owner'] === $owner, 'Envelope preserved across delayed migration');
    mrCheck((int)Redis::command(['XLEN', $foreignKeys[0]]) >= 1, 'Foreign database stream isolated');
    Redis::command(array_merge(['DEL'], $foreignKeys));
    mrCheck((int)Redis::command(['XPENDING', $queueKeys[0], 'loom'])[0] === 1, 'Claim creates a consumer-group pending entry');
    $runtimeServices->get(\Loom\Queue::class)->finish($first, '{}', null, $before + 12);
    mrCheck((int)Redis::command(['XPENDING', $queueKeys[0], 'loom'])[0] === 0, 'Acknowledgement removes pending delivery');

    $abandoned = $runtimeServices->get(\Loom\Queue::class)->dispatch('studio.digest', [], $owner);
    $envelope = json_encode(['id' => $abandoned, 'kind' => 'studio.digest', 'payload' => [], 'owner' => $owner, 'attempt' => 1], JSON_THROW_ON_ERROR);
    $message = Redis::eval(file_get_contents(dirname(__DIR__, 2) . '/framework/resources/queue/enqueue.lua'), $queueKeys, [$envelope, (string)time(), (string)time()]);
    Redis::command(['XREADGROUP', 'GROUP', 'loom', 'crashed-worker', 'COUNT', '1', 'STREAMS', $queueKeys[0], '>']);
    Redis::command(['XCLAIM', $queueKeys[0], 'loom', 'crashed-worker', '0', $message, 'IDLE', '61000']);
    $resumed = $runtimeServices->get(\Loom\Queue::class)->claim();
    mrCheck($resumed !== null && $resumed['id'] === $abandoned, 'XAUTOCLAIM recovers abandoned delivery');
    $runtimeServices->get(\Loom\Queue::class)->finish($resumed, '{}', null);

    $job = $runtimeServices->get(\Loom\Queue::class)->dispatch('studio.digest', [], $owner);
    mrCheck($runtimeServices->get(\Loom\Queue::class)->tick(), 'Real isolated worker ran');
    mrCheck($runtimeServices->get(\Loom\Database::class)->query('SELECT state FROM loom_jobs WHERE id=?', [$job])[0]['state'] === 'completed', 'MySQL job history completed');
    mrCheck(count($runtimeServices->get(\Loom\Database::class)->query('SELECT id FROM studio_reports WHERE job_id=?', [$job])) === 1, 'MySQL business result persisted');

    $failed = $runtimeServices->get(\Loom\Queue::class)->dispatch('unknown.handler', [], $owner);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $time = time() + $attempt * 100;
        $claimed = $runtimeServices->get(\Loom\Queue::class)->claim($time);
        mrCheck($claimed !== null, 'Failed task can be claimed');
        $runtimeServices->get(\Loom\Queue::class)->finish($claimed, null, 'expected failure', $time);
        if ($attempt < 3) {
            mrCheck($runtimeServices->get(\Loom\Queue::class)->claim($time + 1) === null, 'Retry backoff is respected');
        }
    }
    mrCheck($runtimeServices->get(\Loom\Database::class)->query('SELECT state FROM loom_jobs WHERE id=?', [$failed])[0]['state'] === 'failed', 'Retry limit persists to audit history');
    mrCheck((int)Redis::command(['XLEN', $queueKeys[2]]) >= 1, 'Final failure moves payload to dead stream');

    $runtimeServices->get(\Loom\RequestContext::class)->bind(new Request(['origin' => 'http://localhost', 'method' => 'POST', 'path' => '/api/studio/login', 'headers' => ['host' => ['localhost'], 'origin' => ['http://localhost']], 'query' => [], 'body' => '']));
    $session = $runtimeServices->get(\Loom\Auth::class)->login('redis-test@example.test', 'redis testing password');
    $cookie = explode(';', $session['cookie'])[0];
    $token = explode('=', $cookie, 2)[1];
    $key = Redis::key('session:' . hash('sha256', $token));
    mrCheck((int)Redis::command(['TTL', $key]) > 86000, 'Redis session expires');
    mrCheck($database->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='loom_sessions'") === [], 'Database session table is removed');
    $runtimeServices->get(\Loom\RequestContext::class)->bind(new Request(['method' => 'GET', 'path' => '/api/admin/session', 'headers' => ['cookie' => [$cookie]], 'query' => [], 'body' => '']));
    mrCheck($runtimeServices->get(\Loom\Auth::class)->user()['id'] === $owner, 'Redis session authenticates');
    $otherScope = new \Loom\ServiceScope($runtimeServices->get(\Loom\Config::class));
    $otherServices = $otherScope->container();
    $otherServices->get(\Loom\RequestContext::class)->bind(new Request(['origin' => 'http://localhost', 'method' => 'POST', 'path' => '/', 'headers' => ['origin' => ['http://localhost']]]));
    $otherSession = $otherServices->get(\Loom\Auth::class)->register('Other scope', 'scope-session@example.test', 'another testing password');
    $otherCookie = explode(';', $otherSession['cookie'])[0];
    $otherServices->get(\Loom\RequestContext::class)->bind(new Request(['method' => 'GET', 'path' => '/', 'headers' => ['cookie' => [$otherCookie]]]));
    mrCheck($otherServices->get(\Loom\Auth::class)->user()['id'] === $otherSession['id'], 'Second scope uses its own Redis session');
    mrCheck($runtimeServices->get(\Loom\Auth::class)->user()['id'] === $owner, 'Second request context cannot replace first authentication');
    $runtimeServices->get(\Loom\Auth::class)->revoke($owner);
    $denied = false;
    try {
        $runtimeServices->get(\Loom\Auth::class)->user();
    } catch (HttpError $error) {
        $denied = $error->status === 401;
    }
    mrCheck($denied, 'Revocation invalidates Redis session immediately');
    mrCheck($otherServices->get(\Loom\Auth::class)->user()['id'] === $otherSession['id'], 'Revoking first session does not revoke second scope');
    $otherScope->close();
    Redis::command(['DEL', Redis::key('session:' . hash('sha256', explode('=', $otherCookie, 2)[1]))]);
    Redis::command(['DEL', $key]);
    echo "loom_mysql_redis_test: OK\n";
} finally {
    $runtimeServices->get(\Loom\Database::class)->close();
}
