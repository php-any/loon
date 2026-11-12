<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * @param list<string> $lines
 */
function diaryJsonLines(array $lines): array
{
    return array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
}

// Formatter 可插拔：JSON 行与纯文本。
$json = new Loom\Logging\JsonLineJournalFormatter();
$plain = new Loom\Logging\PlainTextJournalFormatter();
$entry = Loom\Logging\JournalEntry::now('warning', 'test.event', ['n' => 1]);
$decoded = json_decode(rtrim($json->format($entry), "\n"), true, 512, JSON_THROW_ON_ERROR);
if ($decoded['event'] !== 'test.event' || $decoded['level'] !== 'warning' || $decoded['n'] !== 1) {
    throw new RuntimeException('JsonLineJournalFormatter must merge entry fields');
}
if (!str_starts_with($plain->format($entry), $entry->time . ' warning test.event n=1')) {
    throw new RuntimeException('PlainTextJournalFormatter must render scalars');
}

// Writer 可插拔：内存、空实现与文件。
$captured = [];
$diary = new Loom\Logging\Diary(
    new Loom\Logging\CallableJournalWriter(static function (string $line) use (&$captured): void {
        $captured[] = $line;
    }),
    $json,
);
$diary->event('scope.open', ['id' => 'a']);
if (count($captured) !== 1 || diaryJsonLines($captured)[0]['event'] !== 'scope.open') {
    throw new RuntimeException('CallableJournalWriter must receive formatted output');
}
(new Loom\Logging\Diary(new Loom\Logging\NullJournalWriter(), $json))->event('dropped', []);
$tempFile = sys_get_temp_dir() . '/loom-diary-' . bin2hex(random_bytes(6)) . '.log';
try {
    $fileDiary = new Loom\Logging\Diary(new Loom\Logging\FileJournalWriter($tempFile), $plain);
    $fileDiary->record('info', 'file.check', ['ok' => true]);
    $body = file_get_contents($tempFile);
    if ($body === false || !str_contains($body, 'file.check ok=1')) {
        throw new RuntimeException('FileJournalWriter must append formatted lines');
    }
} finally {
    if (is_file($tempFile)) {
        unlink($tempFile);
    }
}

// LoggingMonitoring 收尾经 Diary 写出 monitoring.session，与 RequestLog 共用 Writer/Formatter。
$sessionLines = [];
$sharedDiary = new Loom\Logging\Diary(
    new Loom\Logging\CallableJournalWriter(static function (string $line) use (&$sessionLines): void {
        $sessionLines[] = $line;
    }),
    $json,
);
$monitoring = new Loom\Monitoring\LoggingMonitoring(null, $sharedDiary);
$monitoring->begin(Loom\Monitoring\MonitoringContext::console('diary-probe'));
$monitoring->recordEvent('diary.linked', ['ok' => true]);
$monitoring->dispose();
if (count($sessionLines) !== 1) {
    throw new RuntimeException('LoggingMonitoring must flush through Diary');
}
$session = json_decode($sessionLines[0], true, 512, JSON_THROW_ON_ERROR);
if ($session['event'] !== 'monitoring.session' || $session['level'] !== 'info' || $session['channel'] !== 'console') {
    throw new RuntimeException('Monitoring session must use Diary JSON envelope');
}
if (($session['entries'][0]['event'] ?? '') !== 'diary.linked') {
    throw new RuntimeException('Monitoring entries must survive Diary formatting');
}

// RequestLog 经 Diary 写入时与 JSON 字段兼容（新增 level）。
$lines = [];
$logger = new Loom\Http\Middleware\RequestLog(null, new Loom\Logging\Diary(
    new Loom\Logging\CallableJournalWriter(static function (string $line) use (&$lines): void {
        $lines[] = $line;
    }),
    $json,
));
$response = $logger->handle(new Loom\Request(['method' => 'GET', 'path' => '/probe']), static fn (): Loom\Response => new Loom\Response('ok', 204));
if ($response->toArray()['status'] !== 204 || count($lines) !== 1) {
    throw new RuntimeException('RequestLog must log through Diary without changing response');
}
$httpRecord = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
if ($httpRecord['event'] !== 'http.request' || $httpRecord['status'] !== 204 || $httpRecord['level'] !== 'info') {
    throw new RuntimeException('RequestLog diary record must include http.request metadata');
}

// 未提供 config/diary.php 时 bindOptional 回落到类默认值（默认 writer 为 stack）。
$bareRoot = sys_get_temp_dir() . '/loom-diary-bare-' . bin2hex(random_bytes(6));
mkdir($bareRoot . '/config', 0700, true);
try {
    $bare = (new Loom\Options(new Loom\Config($bareRoot)))->bindOptional(Loom\Logging\DiaryOptions::class, 'diary');
    if (!($bare instanceof Loom\Logging\DiaryOptions) || $bare->writer !== 'stack' || $bare->formatter !== 'json' || $bare->file !== '' || count($bare->stack) < 2) {
        throw new RuntimeException('Missing diary config must keep DiaryOptions defaults');
    }
} finally {
    rmdir($bareRoot . '/config');
    rmdir($bareRoot);
}

// Stack 同时写入多个目标；单项 formatter 覆盖全局 formatter。
$stackTextPath = sys_get_temp_dir() . '/loom-diary-stack-' . bin2hex(random_bytes(6)) . '.log';
$stackJsonPath = sys_get_temp_dir() . '/loom-diary-stack-json-' . bin2hex(random_bytes(6)) . '.log';
$stackSink = new Loom\Logging\StackJournalSink([
    new Loom\Logging\LaneJournalSink($plain, new Loom\Logging\CallableJournalWriter(static function (string $line) use ($stackTextPath): void {
        file_put_contents($stackTextPath, $line, FILE_APPEND);
    })),
    new Loom\Logging\LaneJournalSink($json, new Loom\Logging\CallableJournalWriter(static function (string $line) use ($stackJsonPath): void {
        file_put_contents($stackJsonPath, $line, FILE_APPEND);
    })),
]);
$stackDiary = new Loom\Logging\Diary($stackSink);
$stackDiary->event('stack.probe', ['n' => 2]);
$textBody = file_get_contents($stackTextPath);
$jsonBody = file_get_contents($stackJsonPath);
if ($textBody === false || !str_contains($textBody, 'stack.probe n=2')) {
    throw new RuntimeException('Stack stderr lane must use text formatter');
}
if ($jsonBody === false || diaryJsonLines([trim($jsonBody)])[0]['event'] !== 'stack.probe') {
    throw new RuntimeException('Stack file lane must use json formatter');
}
@unlink($stackTextPath);
@unlink($stackJsonPath);

// 级别过滤：低于阈值的条目被丢弃。
$levelLines = [];
$levelDiary = new Loom\Logging\Diary(
    new Loom\Logging\CallableJournalWriter(static function (string $line) use (&$levelLines): void {
        $levelLines[] = $line;
    }),
    $json,
    'warning',
);
$levelDiary->event('low', [], 'info');
$levelDiary->event('high', [], 'error');
if (count($levelLines) !== 1 || diaryJsonLines($levelLines)[0]['event'] !== 'high') {
    throw new RuntimeException('Diary level filter must drop entries below threshold');
}

// DiaryBuilder extend 与未知驱动在组装时拒绝。
$extendRoot = sys_get_temp_dir() . '/loom-diary-extend-' . bin2hex(random_bytes(6));
mkdir($extendRoot . '/config', 0777, true);
file_put_contents($extendRoot . '/config/diary.php', "<?php\nreturn ['writer' => 'custom', 'formatter' => 'json', 'file' => '', 'level' => '', 'stack' => []];\n");
try {
    $extendBuilder = Loom\Application::createBuilder($extendRoot);
    $extendBuilder->diary->extend('custom', static fn () => new Loom\Logging\NullJournalWriter());
    $extendApp = $extendBuilder->build();
    $extendApp->services->get(Loom\Logging\Diary::class)->event('custom.writer', []);
    $extendApp->dispose();
    $extendBuilderUnknown = Loom\Application::createBuilder($extendRoot);
    try {
        $extendBuilderUnknown->build();
        throw new RuntimeException('Unknown diary writer must be rejected at build time');
    } catch (LogicException) {
        // expected when extend not registered
    }
} finally {
    @unlink($extendRoot . '/config/diary.php');
    @rmdir($extendRoot . '/config');
    @rmdir($extendRoot);
}

// stack 内自定义驱动接收子项配置（如 file）。
$stackItemFile = sys_get_temp_dir() . '/loom-diary-item-' . bin2hex(random_bytes(6)) . '.log';
$stackItemRoot = sys_get_temp_dir() . '/loom-diary-item-root-' . bin2hex(random_bytes(6));
mkdir($stackItemRoot . '/config', 0777, true);
file_put_contents($stackItemRoot . '/config/diary.php', "<?php\nreturn ['writer' => 'stack', 'formatter' => 'json', 'file' => '', 'level' => '', 'stack' => [['driver' => 'itemfile', 'file' => " . var_export($stackItemFile, true) . "]]];\n");
try {
    $itemBuilder = Loom\Application::createBuilder($stackItemRoot);
    $itemBuilder->diary->extend('itemfile', static function (Loom\Logging\DiaryOptions $options, Loom\Config $config, array $item): Loom\Logging\JournalWriter {
        $path = (string)($item['file'] ?? '');
        if ($path === '') {
            throw new RuntimeException('Custom stack driver must receive item file');
        }
        return new Loom\Logging\FileJournalWriter($path);
    });
    $itemApp = $itemBuilder->build();
    $itemApp->services->get(Loom\Logging\Diary::class)->event('item.file', ['ok' => true]);
    $itemBody = file_get_contents($stackItemFile);
    if ($itemBody === false || !str_contains($itemBody, 'item.file')) {
        throw new RuntimeException('Stack item config must reach custom diary driver');
    }
    $itemApp->dispose();
} finally {
    @unlink($stackItemRoot . '/config/diary.php');
    @rmdir($stackItemRoot . '/config');
    @rmdir($stackItemRoot);
    @unlink($stackItemFile);
}

// StackJournalWriter：同一行写入多个 writer，单个失败不影响其余。
$primary = [];
$failing = new Loom\Logging\CallableJournalWriter(static function (): void {
    throw new RuntimeException('sink failed');
});
$stackWriter = new Loom\Logging\StackJournalWriter([
    $failing,
    new Loom\Logging\CallableJournalWriter(static function (string $line) use (&$primary): void {
        $primary[] = $line;
    }),
]);
$stackWriter->write("line\n");
if (count($primary) !== 1) {
    throw new RuntimeException('StackJournalWriter must continue after one writer fails');
}

echo "loom_diary_test: OK\n";
