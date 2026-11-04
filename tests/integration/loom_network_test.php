<?php

declare(strict_types=1);
$runtimeScope = (require dirname(__DIR__, 2) . '/framework/bootstrap/autoload.php')(dirname(__DIR__, 2));
$runtimeServices = $runtimeScope->container();
use Loom\TcpServer;
use Loom\GrpcGenerator;
use Loom\Config;

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
$suffix = 'Probe' . bin2hex(random_bytes(5));
$proto = 'proto/' . strtolower($suffix) . '.proto';
$manifest = $runtimeServices->get(\Loom\Config::class)->root() . '/generated/grpc/routes.json';
$original = file_get_contents($manifest);
$base = $runtimeServices->get(\Loom\Config::class)->root() . '/generated/grpc/' . $suffix . 'Base.php';
$business = $runtimeServices->get(\Loom\Config::class)->root() . '/app/Grpc/Services/' . $suffix . '.php';
try {
    file_put_contents($runtimeServices->get(\Loom\Config::class)->root() . '/' . $proto, 'syntax="proto3"; import "google/protobuf/wrappers.proto"; service ' . $suffix . ' { rpc RepeatMessage(google.protobuf.StringValue) returns (google.protobuf.StringValue); }');
    $runtimeServices->get(\Loom\GrpcGenerator::class)->generate($proto);
    networkCheck(is_file($base) && is_file($business), 'generated source files');
    networkCheck(str_contains(file_get_contents($base), 'repeatMessage(string $request)'), 'wrapper scalar input');
    file_put_contents($business, file_get_contents($business) . "\n// keep business edits\n");
    $edited = file_get_contents($business);
    $runtimeServices->get(\Loom\GrpcGenerator::class)->generate($proto);
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
        $runtimeServices->get(\Loom\GrpcGenerator::class)->generate($proto);
    } catch (RuntimeException $error) {
        $failed = str_contains($error->getMessage(), 'non-generated');
    }
    networkCheck($failed && file_get_contents($base) === '<?php // handwritten', 'preserve handwritten base');
} finally {
    file_put_contents($manifest, $original);
    foreach ([$base, $business, $runtimeServices->get(\Loom\Config::class)->root() . '/' . $proto] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
echo "loom_network_test: OK\n";
