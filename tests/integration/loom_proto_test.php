<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/framework/bootstrap.php';

function protoAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function protoRun(string $entry, array $args): array
{
    global $protoProcess;
    return $protoProcess->run(array_merge([PHP_BINARY, $entry], $args));
}
function protoRemove(string $directory): void
{
    foreach (scandir($directory) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $directory . '/' . $name;
        if (is_dir($path) && !is_link($path)) {
            protoRemove($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}
$app = (static function () { return require dirname(__DIR__, 2) . '/loom.php'; })();
$protoProcess = $app->services->get(\Loom\Process::class);
$root = $app->configuration->root();
$fixture = $root . '/storage/testing/proto-' . bin2hex(random_bytes(6));
mkdir($fixture . '/config', 0700, true);
mkdir($fixture . '/bin', 0700, true);
$entry = $fixture . '/loom.php';
// 独立项目根目录，只有框架内置命令。
file_put_contents($entry, '<?php require ' . var_export($root . '/framework/bootstrap.php', true) . '; $app = \\Loom\\Application::createBuilder(' . var_export($fixture, true) . ')->build(); try { $code = $app->run(array_slice($argv, 1)); } finally { $app->dispose(); } exit($code);');
foreach (['modules' => [], 'database' => ['dsn' => 'mysql:host=127.0.0.1;dbname=loom_test'], 'grpc' => ['import_paths' => ['proto'], 'proto_files' => [], 'package_prefix' => 'app']] as $name => $values) {
    file_put_contents($fixture . '/config/' . $name . '.php', '<?php return ' . var_export($values, true) . ';');
}
try {
    $result = protoRun($entry, ['make:proto', 'Widget', '--package=app.users']);
    protoAssert($result['code'] === 0, 'create proto: ' . $result['stdout'] . $result['stderr']);
    $proto = $fixture . '/proto/widget.proto';
    $contract = file_get_contents($proto);
    protoAssert(str_contains($contract, 'package app.users;') && str_contains($contract, 'service WidgetService'), 'template substitutions');
    $result = protoRun($entry, ['make:proto', 'Widget', '--package=app.users']);
    protoAssert($result['code'] !== 0 && file_get_contents($proto) === $contract, 'no overwrite');
    foreach ([['make:proto', '../Escape'], ['make:proto', 'Valid', '--package=app.bad-name'], ['make:proto', 'Class'], ['make:proto', 'Valid', '--unknown']] as $args) {
        protoAssert(protoRun($entry, $args)['code'] !== 0, 'invalid argument accepted');
    }
    $result = protoRun($entry, ['proto:check']);
    protoAssert($result['code'] === 0 && !is_dir($fixture . '/generated'), 'check must be read-only: ' . $result['stdout'] . $result['stderr']);
    $result = protoRun($entry, ['make:grpc', 'proto/widget.proto', '--dry-run']);
    protoAssert($result['code'] === 0 && str_contains($result['stdout'], 'CREATE generated/grpc/Users/WidgetServiceBase.php') && str_contains($result['stdout'], '::getWidget'), 'preview and TODO');
    protoAssert(!is_dir($fixture . '/generated') && !is_dir($fixture . '/app'), 'preview created directories');
    $result = protoRun($entry, ['make:grpc', 'proto/widget.proto']);
    protoAssert($result['code'] === 0, 'generate: ' . $result['stdout'] . $result['stderr']);
    $business = $fixture . '/app/Grpc/Services/Users/WidgetService.php';
    $base = $fixture . '/generated/grpc/Users/WidgetServiceBase.php';
    $manifest = $fixture . '/generated/grpc/routes.json';
    $result = protoRun($entry, ['make:grpc', 'proto/widget.proto', '--dry-run']);
    protoAssert(str_contains($result['stdout'], 'unimplemented'), 'existing stub diagnostic');
    $source = file_get_contents($business);
    $source = str_replace("throw new \\Loom\\Grpc\\RpcException('unimplemented', 'Implement getWidget in WidgetService');", "return ['id'=>\$request['id'],'name'=>'Implemented'];", $source);
    // 注释中的伪方法不得掩盖缺失方法的诊断。
    $source .= "\n// public function check(array \$request) {}\nfile_put_contents(" . var_export($fixture . '/executed', true) . ",'unsafe');\n";
    file_put_contents($business, $source);
    $updated = str_replace('service WidgetService {', "service WidgetService {\n  rpc Check(GetWidgetRequest) returns (GetWidgetReply);", $contract);
    file_put_contents($proto, $updated);
    $beforeBase = file_get_contents($base);
    $beforeManifest = file_get_contents($manifest);
    $result = protoRun($entry, ['make:grpc', 'proto/widget.proto', '--dry-run']);
    protoAssert($result['code'] === 0 && str_contains($result['stdout'], '::check') && !str_contains($result['stdout'], '::getWidget'), 'new method diagnostic: ' . $result['stdout'] . $result['stderr']);
    protoAssert(!file_exists($fixture . '/executed') && file_get_contents($base) === $beforeBase && file_get_contents($manifest) === $beforeManifest, 'preview executed or changed source');
    $result = protoRun($entry, ['make:grpc', 'proto/widget.proto']);
    protoAssert($result['code'] === 0 && file_get_contents($business) === $source && str_contains(file_get_contents($base), 'function check('), 'regenerate must preserve business and add base RPC');
    protoAssert(!file_exists($fixture . '/executed'), 'generation executed business code');
    $cases = [
        'syntax' => 'syntax="proto3"; invalid syntax;',
        'import' => 'syntax="proto3"; import "missing.proto";',
        'stream' => 'syntax="proto3"; message X {} service StreamService { rpc Read(stream X) returns (X); }',
        'method' => 'syntax="proto3"; message X {} service Methods { rpc Check(X) returns (X); rpc check(X) returns (X); }',
        'class' => 'syntax="proto3"; package app.users; message X {} service widgetService { rpc Run(X) returns (X); }',
    ];
    foreach ($cases as $name => $content) {
        file_put_contents($fixture . '/proto/invalid.proto', $content);
        $result = protoRun($entry, ['proto:check']);
        protoAssert($result['code'] !== 0 && str_contains($result['stdout'] . $result['stderr'], 'invalid.proto'), 'missing contextual error: ' . $name . ' ' . $result['stdout'] . $result['stderr']);
        unlink($fixture . '/proto/invalid.proto');
    }
    $result = protoRun($entry, ['proto:check', 'proto/widget.proto']);
    protoAssert($result['code'] === 0, 'valid single file check');
    protoAssert(protoRun($entry, ['proto:check', 'proto/not-found.proto'])['code'] !== 0, 'missing file accepted');
    protoAssert(protoRun($entry, ['make:grpc', 'proto/widget.proto', '--dry-run=false'])['code'] !== 0, 'invalid flag accepted');
} finally {
    $resolved = realpath($fixture);
    $allowed = realpath($root . '/storage/testing');
    if ($resolved !== false && $allowed !== false && str_starts_with($resolved, $allowed . DIRECTORY_SEPARATOR)) {
        protoRemove($resolved);
    }
    $app->dispose();
}
echo "loom_proto_test: OK\n";
