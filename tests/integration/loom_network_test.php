<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/framework/bootstrap.php';
use Loom\TcpServer;

function networkCheck(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}
final class ChunkSource
{
    public function __construct(private array $chunks)
    {
    }
    public function read(int $size): string
    {
        if ($this->chunks === []) {
            return '';
        }
        $chunk = array_shift($this->chunks);
        if (strlen($chunk) > $size) {
            array_unshift($this->chunks, substr($chunk, $size));
        }
        return substr($chunk, 0, $size);
    }
}
$buffer = '';
$source = new ChunkSource(['a', "bc\nx\r\n"]);
networkCheck(TcpServer::readLine($source, $buffer, 3) === 'abc', 'split frame at exact limit');
networkCheck(TcpServer::readLine($source, $buffer, 3) === 'x', 'pipelined CRLF');
networkCheck(TcpServer::readLine($source, $buffer, 3) === null, 'clean EOF');
foreach (['abcd', "abcd\n", 'ab'] as $bytes) {
    $buffer = '';
    $failed = false;
    try {
        TcpServer::readLine(new ChunkSource([$bytes]), $buffer, 3);
    } catch (RuntimeException $error) {
        $failed = true;
    }
    networkCheck($failed, 'oversized/incomplete frame');
}
$app = (static function () { return require dirname(__DIR__, 2) . '/loom.php'; })();
try {
    $app->services->withScope(static function (\Loom\ServiceScope $scope): void {
        $root = $scope->get(\Loom\Config::class)->root();
        $generator = $scope->get(\Loom\GrpcGenerator::class);
        $suffix = 'Probe' . bin2hex(random_bytes(5));
        $proto = 'proto/' . strtolower($suffix) . '.proto';
        $manifest = $root . '/generated/grpc/routes.json';
        $original = file_get_contents($manifest);
        $base = $root . '/generated/grpc/' . $suffix . 'Base.php';
        $business = $root . '/app/Grpc/Services/' . $suffix . '.php';
        try {
            file_put_contents($root . '/' . $proto, 'syntax="proto3"; import "google/protobuf/wrappers.proto"; service ' . $suffix . ' { rpc RepeatMessage(google.protobuf.StringValue) returns (google.protobuf.StringValue); }');
            $generator->generate($proto);
            networkCheck(is_file($base) && is_file($business), 'generated source files');
            networkCheck(str_contains(file_get_contents($base), 'repeatMessage(string $request)'), 'wrapper scalar input');
            file_put_contents($business, file_get_contents($business) . "\n// keep business edits\n");
            $edited = file_get_contents($business);
            $generator->generate($proto);
            networkCheck(file_get_contents($business) === $edited, 'preserve business source');
            $class = 'App\\Grpc\\Services\\' . $suffix;
            $service = new $class();
            $unimplemented = false;
            try {
                $service->repeatMessage('test');
            } catch (\Loom\Grpc\RpcException $error) {
                $unimplemented = $error->status === 'unimplemented';
            }
            networkCheck($unimplemented, 'generated source executes');
            file_put_contents($base, '<?php // handwritten');
            $failed = false;
            try {
                $generator->generate($proto);
            } catch (RuntimeException $error) {
                $failed = str_contains($error->getMessage(), 'non-generated');
            }
            networkCheck($failed && file_get_contents($base) === '<?php // handwritten', 'preserve handwritten base');
        } finally {
            file_put_contents($manifest, $original);
            foreach ([$base, $business, $root . '/' . $proto] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    });
} finally {
    $app->dispose();
}
echo "loom_network_test: OK\n";
