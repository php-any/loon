<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function httpExceptionCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param list<string> $lines
 * @return list<array<string, mixed>>
 */
function httpExceptionDiaryRecords(array $lines): array
{
    return array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
}

class HttpExceptionNativeRequest
{
    public function __construct(private string $path = '/boom', private string $method = 'GET')
    {
    }

    public function fullUrl(): string
    {
        return 'http://127.0.0.1:1' . $this->path;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isSecure(): bool
    {
        return false;
    }
}

class HttpExceptionNativeWriter
{
    public array $headers = [];
    public int $status = 0;
    public string $body = '';

    public function header(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function writeHeader(int $status): void
    {
        $this->status = $status;
    }

    public function write(string $data): void
    {
        $this->body .= $data;
    }
}

$diaryLines = [];
$json = new Loom\Logging\JsonLineJournalFormatter();
$sharedDiary = new Loom\Logging\Diary(
    new Loom\Logging\CallableJournalWriter(static function (string $line) use (&$diaryLines): void {
        $diaryLines[] = $line;
    }),
    $json,
);

$root = sys_get_temp_dir() . '/loom-http-exc-' . bin2hex(random_bytes(6));
mkdir($root . '/config', 0777, true);
try {
    file_put_contents($root . '/config/http.php', '<?php return ["address" => "127.0.0.1:1"];');
    $builder = Loom\Application::createBuilder($root);
    $builder->services->addSingleton(Loom\Logging\Diary::class, static fn (): Loom\Logging\Diary => $sharedDiary);
    $app = $builder->build();
    $app->mapGet('/boom', static function (): Loom\Response {
        throw new RuntimeException('route exploded');
    });
    $app->use(Loom\Http\Middleware\RequestLog::class);

    $correlationHeader = 'http-exc-correlation-deadbeef';
    $_SERVER['HTTP_X_LOOM_CORRELATION_ID'] = $correlationHeader;

    $writer = new HttpExceptionNativeWriter();
    $app->services->get(Loom\HttpServer::class)->handle(new HttpExceptionNativeRequest('/boom'), $writer);

    unset($_SERVER['HTTP_X_LOOM_CORRELATION_ID']);

    $payload = json_decode($writer->body, true, 64, JSON_THROW_ON_ERROR);
    httpExceptionCheck($writer->status === 500, 'Uncaught route error must return 500');
    httpExceptionCheck(($payload['title'] ?? '') === 'Internal Server Error', 'Client must see generic problem title');
    httpExceptionCheck(!str_contains($writer->body, 'route exploded'), 'Internal message must not leak to client');

    $records = httpExceptionDiaryRecords($diaryLines);
    $exceptionRecord = null;
    $accessRecord = null;
    $sessionRecord = null;
    foreach ($records as $record) {
        $event = (string) ($record['event'] ?? '');
        if ($event === 'http.exception') {
            $exceptionRecord = $record;
        }
        if ($event === 'http.request') {
            $accessRecord = $record;
        }
        if ($event === 'monitoring.session') {
            $sessionRecord = $record;
        }
    }
    httpExceptionCheck($exceptionRecord !== null, 'Diary must include http.exception');
    httpExceptionCheck(($exceptionRecord['level'] ?? '') === 'error', 'http.exception must use error level');
    httpExceptionCheck(($exceptionRecord['exception_class'] ?? '') === RuntimeException::class, 'http.exception must record class');
    httpExceptionCheck(($exceptionRecord['message'] ?? '') === 'route exploded', 'http.exception must record message');
    httpExceptionCheck(($exceptionRecord['file'] ?? '') !== '', 'http.exception must record file');
    httpExceptionCheck(is_int($exceptionRecord['line'] ?? null) || is_numeric($exceptionRecord['line'] ?? null), 'http.exception must record line');
    $stack = (string) ($exceptionRecord['stack'] ?? '');
    httpExceptionCheck($stack !== '', 'http.exception must record non-empty stack');
    httpExceptionCheck(
        str_contains($stack, 'Stack trace') || str_contains($stack, ' at '),
        'http.exception stack must include frame information',
    );
    httpExceptionCheck(($exceptionRecord['method'] ?? '') === 'GET', 'http.exception must record method');
    httpExceptionCheck(($exceptionRecord['path'] ?? '') === '/boom', 'http.exception must record path');
    httpExceptionCheck(($exceptionRecord['correlation_id'] ?? '') === $correlationHeader, 'http.exception must record correlation_id');

    httpExceptionCheck($accessRecord !== null && ($accessRecord['status'] ?? 0) === 500, 'RequestLog still records access with 500');

    httpExceptionCheck($sessionRecord !== null, 'monitoring.session must flush after request');
    $monitoringEvents = $sessionRecord['entries'] ?? [];
    $foundMonitoringException = false;
    foreach ($monitoringEvents as $entry) {
        if (($entry['event'] ?? '') === 'http.exception' && ($entry['message'] ?? '') === 'route exploded') {
            $foundMonitoringException = true;
            break;
        }
    }
    httpExceptionCheck($foundMonitoringException, 'monitoring.session must include http.exception recordEvent');

    $app->dispose();
} finally {
    foreach (glob($root . '/config/*') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($root . '/config');
    @rmdir($root);
}

echo "loom_http_exception_test: OK\n";
