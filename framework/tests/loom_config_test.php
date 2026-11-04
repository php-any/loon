<?php

declare(strict_types=1);
$bootstrap = require dirname(__DIR__) . '/bootstrap/autoload.php';
/**
 * 断言测试条件成立，失败时立即抛出异常以终止当前测试。
 *
 * @param bool $condition 待断言的条件；为假时抛出测试失败异常。
 * @param string $message 断言失败时抛出的说明。
 * @return void 无返回值；条件失败时抛出 RuntimeException。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example checkConfig(true, '验证说明');
 */
function checkConfig(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$root = sys_get_temp_dir() . '/loom-config-' . bin2hex(random_bytes(6));
mkdir($root . '/config', 0777, true);
try {
    file_put_contents($root . '/config/http.php', '<?php return ["address" => "127.0.0.1:12345"];');
    file_put_contents($root . '/config/payment.php', '<?php return ["timeout"=>30,"nested"=>["enabled"=>false],"nullable"=>null,"zero"=>0];');
    file_put_contents($root . '/config/services.php', '<?php return static function (): string { return "registered"; };');
    $runtimeScope = $bootstrap($root);
    $runtimeServices = $runtimeScope->container();
    // 启动后修改源配置，以证明配置已提前加载并缓存。
    file_put_contents($root . '/config/payment.php', '<?php return ["timeout"=>60];');
    checkConfig($runtimeServices->get(\Loom\Config::class)->get('payment.timeout') === 30, 'bootstrap must eagerly load PHP config');
    checkConfig($runtimeServices->get(\Loom\Config::class)->get('payment')['timeout'] === 30, 'whole file lookup');
    checkConfig($runtimeServices->get(\Loom\Config::class)->get('payment.nested.enabled', true) === false, 'nested false preservation');
    checkConfig($runtimeServices->get(\Loom\Config::class)->get('payment.nullable', 'fallback') === null, 'null preservation');
    checkConfig($runtimeServices->get(\Loom\Config::class)->get('payment.zero', 1) === 0, 'zero preservation');
    checkConfig($runtimeServices->get(\Loom\Config::class)->get('missing.key', 42) === 42, 'missing file default');
    checkConfig($runtimeServices->get(\Loom\Config::class)->get('payment.missing', 42) === 42, 'missing key default');
    checkConfig($runtimeServices->get(\Loom\Config::class)->get('payment.timeout.value', 42) === 42, 'scalar traversal default');
    $register = $runtimeServices->get(\Loom\Config::class)->get('services');
    checkConfig($register() === 'registered', 'service closure must remain callable');
    checkConfig($runtimeServices->get(\Loom\Config::class)->get()['payment']['timeout'] === 30, 'get without key returns all namespaces');
    checkConfig($runtimeServices->get(\Loom\Config::class)->get('http.address') === $runtimeServices->get(\Loom\Config::class)->get()['http']['address'], 'runtime dotted lookup');
    $runtimeScope = $bootstrap($root);
    $runtimeServices = $runtimeScope->container();
    checkConfig($runtimeServices->get(\Loom\Config::class)->get('payment.timeout') === 60, 'bootstrap must clear stale configuration');
    // 不存在保留的配置名称，也不会隐式加载 JSON。
    file_put_contents($root . '/config/loom.php', '<?php return ["custom" => true];');
    file_put_contents($root . '/config/ignored.json', '{invalid json');
    $runtimeServices->get(\Loom\Config::class)->setRoot($root);
    checkConfig($runtimeServices->get(\Loom\Config::class)->get('loom.custom') === true, 'loom is an ordinary namespace');
    checkConfig($runtimeServices->get(\Loom\Config::class)->get('ignored', 'missing') === 'missing', 'only PHP config is loaded');
    file_put_contents($root . '/config/broken.php', '<?php throw new \LogicException("broken config");');
    $runtimeServices->get(\Loom\Config::class)->setRoot($root);
    $rejected = false;
    try {
        $runtimeServices->get(\Loom\Config::class)->load();
    } catch (LogicException $error) {
        $rejected = true;
    }
    checkConfig($rejected, 'failed configuration must propagate');
    unlink($root . '/config/broken.php');
    checkConfig($runtimeServices->get(\Loom\Config::class)->get('payment.timeout') === 60, 'failed load must allow retry');
    $runtimeServices->get(\Loom\Config::class)->setRoot($root . '/config');
    checkConfig($runtimeServices->get(\Loom\Config::class)->get('payment.timeout', 99) === 99, 'root change must clear cache; missing config directory is allowed');
} finally {
    foreach (glob($root . '/config/*') as $file) {
        unlink($file);
    }
    rmdir($root . '/config');
    rmdir($root);
}
echo "loom_config_test: OK\n";
