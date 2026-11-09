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

echo "loom_diary_test: OK\n";
