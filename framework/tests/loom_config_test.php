<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
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
    file_put_contents($root . '/config/factory.php', '<?php return static function (): string { return "registered"; };');
    file_put_contents($root . '/config/modules.php', '<?php return [];');
    $config = Loom\Application::createBuilder($root)->configuration;
    // 构建器创建后修改源配置，以证明配置已提前加载并缓存。
    file_put_contents($root . '/config/payment.php', '<?php return ["timeout"=>60];');
    checkConfig($config->get('payment.timeout') === 30, 'builder must eagerly load PHP config');
    checkConfig($config->get('payment')['timeout'] === 30, 'whole file lookup');
    checkConfig($config->get('payment.nested.enabled', true) === false, 'nested false preservation');
    checkConfig($config->get('payment.nullable', 'fallback') === null, 'null preservation');
    checkConfig($config->get('payment.zero', 1) === 0, 'zero preservation');
    checkConfig($config->get('missing.key', 42) === 42, 'missing file default');
    checkConfig($config->get('payment.missing', 42) === 42, 'missing key default');
    checkConfig($config->get('payment.timeout.value', 42) === 42, 'scalar traversal default');
    $register = $config->get('factory');
    checkConfig($register() === 'registered', 'config closure must remain callable');
    checkConfig($config->get()['payment']['timeout'] === 30, 'get without key returns all namespaces');
    checkConfig($config->get('http.address') === $config->get()['http']['address'], 'runtime dotted lookup');
    $config = Loom\Application::createBuilder($root)->configuration;
    checkConfig($config->get('payment.timeout') === 60, 'a new builder must not reuse stale configuration');
    // 不存在保留的配置名称，也不会隐式加载 JSON。
    file_put_contents($root . '/config/loom.php', '<?php return ["custom" => true];');
    file_put_contents($root . '/config/ignored.json', '{invalid json');
    $config->setRoot($root);
    checkConfig($config->get('loom.custom') === true, 'loom is an ordinary namespace');
    checkConfig($config->get('ignored', 'missing') === 'missing', 'only PHP config is loaded');
    file_put_contents($root . '/config/broken.php', '<?php throw new \LogicException("broken config");');
    $config->setRoot($root);
    $rejected = false;
    try {
        $config->load();
    } catch (LogicException $error) {
        $rejected = true;
    }
    checkConfig($rejected, 'failed configuration must propagate');
    $rejected = false;
    try {
        Loom\Application::createBuilder($root);
    } catch (LogicException $error) {
        $rejected = true;
    }
    checkConfig($rejected, 'builder must not start with broken configuration');
    unlink($root . '/config/broken.php');
    checkConfig($config->get('payment.timeout') === 60, 'failed load must allow retry');
    $config->setRoot($root . '/config');
    checkConfig($config->get('payment.timeout', 99) === 99, 'root change must clear cache; missing config directory is allowed');
} finally {
    foreach (glob($root . '/config/*') as $file) {
        unlink($file);
    }
    rmdir($root . '/config');
    rmdir($root);
}
echo "loom_config_test: OK\n";
