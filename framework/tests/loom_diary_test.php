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
    $fileDiary = new Loom\Logging\Diary(
        new Loom\Logging\FileJournalWriter(static fn (Loom\Logging\JournalEntry $e): string => $tempFile),
        $plain,
    );
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
        return new Loom\Logging\FileJournalWriter(static fn (Loom\Logging\JournalEntry $e): string => $path);
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
$stackProbe = Loom\Logging\JournalEntry::now('info', 'stack.writer', []);
$stackWriter->write("line\n", $stackProbe);
if (count($primary) !== 1) {
    throw new RuntimeException('StackJournalWriter must continue after one writer fails');
}

// pattern 格式化器与 {field.xxx}。
$patternFormatter = new Loom\Logging\PatternJournalFormatter('[{time}] {level} {event} id={field.order_id} {fields}');
$patternEntry = new Loom\Logging\JournalEntry('2026-01-02T03:04:05Z', 'info', 'order.placed', ['order_id' => 9, 'shop' => 'east']);
$patternLine = rtrim($patternFormatter->format($patternEntry), "\n");
if (!str_contains($patternLine, 'id=9') || !str_contains($patternLine, 'shop=east') || !str_contains($patternLine, 'order.placed')) {
    throw new RuntimeException('PatternJournalFormatter must expand field placeholders');
}

// {date} 路径按 Asia/Shanghai：UTC 16:00 对应 CST 次日 00:00。
$tzRoot = sys_get_temp_dir() . '/loom-diary-tz-' . bin2hex(random_bytes(6));
mkdir($tzRoot . '/storage/logs', 0777, true);
$config = new Loom\Config($tzRoot);
$options = new Loom\Logging\DiaryOptions();
$options->timezone = 'Asia/Shanghai';
$options->date = 'Y-m-d';
$namer = Loom\Logging\TemplateJournalNamer::callable($options, $config, []);
$tzNamerFile = $namer(new Loom\Logging\JournalEntry('2024-03-14T16:00:00Z', 'info', 'tz.edge', []));
if (!str_ends_with($tzNamerFile, 'diary-2024-03-15.log')) {
    throw new RuntimeException('Diary {date} must use configured timezone at CST midnight boundary');
}
$wrongTz = Loom\Logging\TemplateJournalNamer::expand(
    new Loom\Logging\JournalEntry('2024-03-14T16:00:00Z', 'info', 'tz.edge', []),
    'diary-{date}.log',
    'Y-m-d',
    'UTC',
);
if ($wrongTz !== 'diary-2024-03-14.log') {
    throw new RuntimeException('UTC timezone control must keep same calendar date for boundary probe');
}
$tzSink = new Loom\Logging\LaneJournalSink($plain, new Loom\Logging\FileJournalWriter($namer));
$tzSink->emit(new Loom\Logging\JournalEntry('2024-03-14T16:00:00Z', 'info', 'tz.write', []));
$tzPath = $config->path('storage/logs/diary-2024-03-15.log');
if (!is_file($tzPath) || !str_contains((string) file_get_contents($tzPath), 'tz.write')) {
    throw new RuntimeException('FileJournalWriter must resolve template path per entry');
}
@unlink($tzPath);
@rmdir($tzRoot . '/storage/logs');
@rmdir($tzRoot . '/storage');
@rmdir($tzRoot);

// {level} 分到不同文件。
$levelRoot = sys_get_temp_dir() . '/loom-diary-level-' . bin2hex(random_bytes(6));
mkdir($levelRoot . '/storage/logs', 0777, true);
$levelConfig = new Loom\Config($levelRoot);
$levelOptions = new Loom\Logging\DiaryOptions();
$levelOptions->file = 'storage/logs/{level}.log';
$levelNamer = Loom\Logging\TemplateJournalNamer::callable($levelOptions, $levelConfig, []);
$levelSink = new Loom\Logging\LaneJournalSink($plain, new Loom\Logging\FileJournalWriter($levelNamer));
$levelSink->emit(new Loom\Logging\JournalEntry('2026-05-01T00:00:00Z', 'info', 'level.split', ['n' => 1]));
$levelSink->emit(new Loom\Logging\JournalEntry('2026-05-01T00:00:01Z', 'error', 'level.split', ['n' => 2]));
$infoPath = $levelConfig->path('storage/logs/info.log');
$errorPath = $levelConfig->path('storage/logs/error.log');
if (!is_file($infoPath) || !is_file($errorPath)) {
    throw new RuntimeException('Diary {level} template must split entries into separate files');
}
@unlink($infoPath);
@unlink($errorPath);
@rmdir($levelRoot . '/storage/logs');
@rmdir($levelRoot . '/storage');
@rmdir($levelRoot);

// extendFormatter：stack 子项 pattern 传到工厂。
$fmtRoot = sys_get_temp_dir() . '/loom-diary-fmt-' . bin2hex(random_bytes(6));
mkdir($fmtRoot . '/config', 0777, true);
$customPattern = '<evt>';
file_put_contents($fmtRoot . '/config/diary.php', "<?php\nreturn ['writer' => 'stack', 'formatter' => 'json', 'pattern' => '', 'file' => '', 'date' => 'Y-m-d', 'timezone' => 'Asia/Shanghai', 'level' => '', 'stack' => [['driver' => 'cap', 'formatter' => 'capfmt', 'pattern' => " . var_export($customPattern, true) . ']]];');
$capturedFmt = [];
try {
    $fmtBuilder = Loom\Application::createBuilder($fmtRoot);
    $fmtBuilder->diary->extend('cap', static function (Loom\Logging\DiaryOptions $options, Loom\Config $config, array $item) use (&$capturedFmt): Loom\Logging\JournalWriter {
        return new Loom\Logging\CallableJournalWriter(static function (string $line) use (&$capturedFmt): void {
            $capturedFmt[] = $line;
        });
    });
    $fmtBuilder->diary->extendFormatter('capfmt', static function (Loom\Logging\DiaryOptions $options, Loom\Config $config, array $item): Loom\Logging\JournalFormatter {
        $pattern = (string) ($item['pattern'] ?? '');
        if ($pattern === '') {
            throw new RuntimeException('extendFormatter must receive stack pattern');
        }
        return new Loom\Logging\PatternJournalFormatter($pattern);
    });
    $fmtApp = $fmtBuilder->build();
    $fmtApp->services->get(Loom\Logging\Diary::class)->event('fmt.custom', ['x' => 1]);
    $hasCustomFmt = false;
    foreach ($capturedFmt as $line) {
        if (str_starts_with(rtrim($line, "\n"), '<evt>')) {
            $hasCustomFmt = true;
            break;
        }
    }
    if (!$hasCustomFmt) {
        throw new RuntimeException('extendFormatter must format stack lane output');
    }
    $fmtApp->dispose();
    $fmtBuilderBad = Loom\Application::createBuilder($fmtRoot);
    $fmtBuilderBad->diary->extend('cap', static fn () => new Loom\Logging\NullJournalWriter());
    try {
        $fmtBuilderBad->build();
        throw new RuntimeException('Unknown diary formatter must be rejected at build time');
    } catch (LogicException) {
        // expected
    }
} finally {
    @unlink($fmtRoot . '/config/diary.php');
    @rmdir($fmtRoot . '/config');
    @rmdir($fmtRoot);
}

// extendNamer：自定义路径收到条目。
$namerRoot = sys_get_temp_dir() . '/loom-diary-namer-' . bin2hex(random_bytes(6));
mkdir($namerRoot . '/config', 0777, true);
$customNamerPath = sys_get_temp_dir() . '/loom-diary-custom-name-' . bin2hex(random_bytes(6)) . '.log';
file_put_contents($namerRoot . '/config/diary.php', "<?php\nreturn ['writer' => 'stack', 'formatter' => 'json', 'pattern' => '', 'file' => '', 'date' => 'Y-m-d', 'timezone' => 'Asia/Shanghai', 'level' => '', 'stack' => [['driver' => 'file', 'formatter' => 'text', 'namer' => 'custom']]];\n");
try {
    $namerBuilder = Loom\Application::createBuilder($namerRoot);
    $namerBuilder->diary->extendNamer('custom', static function (Loom\Logging\JournalEntry $entry, array $item): string {
        return $customNamerPath;
    });
    $namerApp = $namerBuilder->build();
    $namerApp->services->get(Loom\Logging\Diary::class)->event('namer.custom', ['k' => 1]);
    $namerBody = file_get_contents($customNamerPath);
    if ($namerBody === false || !str_contains($namerBody, 'namer.custom')) {
        throw new RuntimeException('extendNamer must receive entry for custom file path');
    }
    $namerApp->dispose();
    $namerBuilderBad = Loom\Application::createBuilder($namerRoot);
    try {
        $namerBuilderBad->build();
        throw new RuntimeException('Unknown diary namer must be rejected at build time');
    } catch (LogicException) {
        // expected
    }
} finally {
    @unlink($namerRoot . '/config/diary.php');
    @rmdir($namerRoot . '/config');
    @rmdir($namerRoot);
    @unlink($customNamerPath);
}

// writer=file 时全局 options.namer（非 stack）可选用扩展命名器。
$globalNamerPath = sys_get_temp_dir() . '/loom-diary-global-namer-' . bin2hex(random_bytes(6)) . '.log';
$globalNamerRoot = sys_get_temp_dir() . '/loom-diary-global-namer-root-' . bin2hex(random_bytes(6));
mkdir($globalNamerRoot . '/config', 0777, true);
file_put_contents($globalNamerRoot . '/config/diary.php', "<?php\nreturn ['writer' => 'file', 'formatter' => 'json', 'pattern' => '', 'namer' => 'globalpath', 'file' => '', 'date' => 'Y-m-d', 'timezone' => 'Asia/Shanghai', 'level' => '', 'stack' => []];\n");
try {
    $globalBuilder = Loom\Application::createBuilder($globalNamerRoot);
    $globalBuilder->diary->extendNamer('globalpath', static function (Loom\Logging\JournalEntry $entry, array $item) use ($globalNamerPath): string {
        return $globalNamerPath;
    });
    $globalApp = $globalBuilder->build();
    $globalApp->services->get(Loom\Logging\Diary::class)->event('global.namer', ['ok' => true]);
    $globalBody = file_get_contents($globalNamerPath);
    if ($globalBody === false || !str_contains($globalBody, 'global.namer')) {
        throw new RuntimeException('Global diary namer must apply for writer=file without stack');
    }
    $globalApp->dispose();
} finally {
    @unlink($globalNamerRoot . '/config/diary.php');
    @rmdir($globalNamerRoot . '/config');
    @rmdir($globalNamerRoot);
    @unlink($globalNamerPath);
}

// extendNamer('template', ...) 覆盖内置模板命名器。
$templateOverridePath = sys_get_temp_dir() . '/loom-diary-template-override-' . bin2hex(random_bytes(6)) . '.log';
$templateOverrideRoot = sys_get_temp_dir() . '/loom-diary-template-override-root-' . bin2hex(random_bytes(6));
mkdir($templateOverrideRoot . '/config', 0777, true);
file_put_contents($templateOverrideRoot . '/config/diary.php', "<?php\nreturn ['writer' => 'file', 'formatter' => 'text', 'pattern' => '', 'namer' => 'template', 'file' => 'storage/logs/diary-{date}.log', 'date' => 'Y-m-d', 'timezone' => 'Asia/Shanghai', 'level' => '', 'stack' => []];\n");
try {
    $templateBuilder = Loom\Application::createBuilder($templateOverrideRoot);
    $templateBuilder->diary->extendNamer('template', static function (Loom\Logging\JournalEntry $entry, array $item) use ($templateOverridePath): string {
        return $templateOverridePath;
    });
    $templateApp = $templateBuilder->build();
    $templateApp->services->get(Loom\Logging\Diary::class)->event('template.override', []);
    $templateBody = file_get_contents($templateOverridePath);
    if ($templateBody === false || !str_contains($templateBody, 'template.override')) {
        throw new RuntimeException('extendNamer(template) must override built-in template namer');
    }
    $defaultPath = (new Loom\Config($templateOverrideRoot))->path('storage/logs');
    if (is_dir($defaultPath) && count(glob($defaultPath . '/diary-*.log')) > 0) {
        throw new RuntimeException('Template override must not write via built-in path');
    }
    $templateApp->dispose();
} finally {
    @unlink($templateOverrideRoot . '/config/diary.php');
    @rmdir($templateOverrideRoot . '/config');
    @rmdir($templateOverrideRoot);
    @unlink($templateOverridePath);
}

// 非法 timezone 在 build() 阶段失败。
$badTzRoot = sys_get_temp_dir() . '/loom-diary-bad-tz-' . bin2hex(random_bytes(6));
mkdir($badTzRoot . '/config', 0777, true);
file_put_contents($badTzRoot . '/config/diary.php', "<?php\nreturn ['writer' => 'file', 'formatter' => 'json', 'pattern' => '', 'namer' => 'template', 'file' => '', 'date' => 'Y-m-d', 'timezone' => 'Not/A/Zone', 'level' => '', 'stack' => []];\n");
try {
    try {
        Loom\Application::createBuilder($badTzRoot)->build();
        throw new RuntimeException('Invalid diary timezone must be rejected at build time');
    } catch (LogicException) {
        // expected
    }
} finally {
    @unlink($badTzRoot . '/config/diary.php');
    @rmdir($badTzRoot . '/config');
    @rmdir($badTzRoot);
}

echo "loom_diary_test: OK\n";
