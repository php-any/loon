<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$lines = [];
$logger = new Loom\Http\Middleware\RequestLog(function ($line) use (&$lines): void {
    $lines[] = $line;
});
$app = new Loom\Application();
$app->middleware($logger);
$app->get('/ok', function ($request) {
    return new Loom\Response('created', 201, ['X-Test' => 'yes']);
});
$app->get('/invalid', function ($request) {
    throw new Loom\HttpError(422, 'Invalid');
});
$failure = new RuntimeException('original');
$app->get('/failure', function ($request) use ($failure) {
    throw $failure;
});
foreach ([['GET', '/ok', 201], ['HEAD', '/ok', 201], ['GET', '/missing', 404], ['POST', '/ok', 405], ['GET', '/invalid', 422], ['GET', '/failure', 500]] as $case) {
    try {
        $response = $app->handle(new Loom\Request(['method' => $case[0], 'path' => $case[1]]));
        if ($response->toArray()['status'] !== $case[2] || $case[2] === 500) {
            throw new RuntimeException('Unexpected response');
        }
        if ($case[1] === '/ok' && $case[2] === 201 && $response->toArray()['body'] !== 'created') {
            throw new RuntimeException('Response changed');
        }
    } catch (RuntimeException $error) {
        if ($case[2] !== 500 || $error !== $failure) {
            throw $error;
        }
    }
    $line = array_pop($lines);
    $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if ($lines !== [] || $record['event'] !== 'http.request' || $record['method'] !== $case[0] || $record['path'] !== $case[1] || $record['status'] !== $case[2] || $record['duration_ms'] < 0 || $record['time'] === '') {
        throw new RuntimeException('Invalid request log');
    }
}
// 全局中间件错误保留原有状态码，且路径不能注入日志行。
$app->middleware(function ($request, $next) {
    throw new Loom\HttpError(429, 'Slow down');
});
$app->handle(new Loom\Request(['method' => 'GET', 'path' => "/line\nbreak"]));
$line = array_pop($lines);
if (substr_count($line, "\n") !== 1 || json_decode($line, true)['status'] !== 429) {
    throw new RuntimeException('Invalid global error log');
}
$broken = new Loom\Http\Middleware\RequestLog(function ($line) {
    throw new RuntimeException('Log unavailable');
});
$request = new Loom\Request(['method' => 'GET', 'path' => '/']);
$expected = new Loom\Response('unchanged');
if ($broken->handle($request, function ($request) use ($expected) {
    return $expected;
}) !== $expected) {
    throw new RuntimeException('Logging failure changed response');
}
try {
    $broken->handle($request, function ($request) use ($failure) {
        throw $failure;
    });
    throw new RuntimeException('Exception swallowed');
} catch (RuntimeException $error) {
    if ($error !== $failure) {
        throw $error;
    }
}
echo "loom_request_log_test: OK\n";
