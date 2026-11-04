<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
$runtimeScope = new \Loom\ServiceScope(new \Loom\Config());
$runtimeServices = $runtimeScope->container();

use Loom\InjectConfig;
use Loom\Container;
use Loom\Config;

require __DIR__ . '/fixtures/config/services.php';
/**
 * 断言测试条件成立，失败时立即抛出异常以终止当前测试。
 *
 * @param bool $condition 待断言的条件；为假时抛出测试失败异常。
 * @param string $message 断言失败时抛出的说明。
 * @return void 无返回值；条件失败时抛出 RuntimeException。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example checkInjection(true, '验证说明');
 */
function checkInjection(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$root = sys_get_temp_dir() . '/loom-injection-' . bin2hex(random_bytes(6));
mkdir($root . '/config', 0777, true);
try {
    file_put_contents($root . '/config/probe.php', '<?php return ["name"=>"configured", "timeout"=>30, "enabled"=>false, "nullable"=>null, "zero"=>0];');
    $runtimeServices->get(\Loom\Config::class)->setRoot($root);
    $container = (new \Loom\ServiceScope($runtimeServices->get(\Loom\Config::class)))->container();
    $constructed = $container->get(ConfigConstructorProbe::class);
    checkInjection($constructed->received === [30, false, null, 17, null, 'ordinary'], 'configuration available inside constructor');
    checkInjection($constructed->property === 'configured', 'constructor and property injection coexist');
    checkInjection($container->get(ConfigConstructorProbe::class) === $constructed, 'constructor singleton');
    checkInjection($container->get(ConfigInheritedConstructorProbe::class)->received === $constructed->received, 'inherited constructor');
    checkInjection($container->get(ConfigOptionalConstructorProbe::class)->values === ['default', []], 'ordinary optional and variadic parameters');
    $container->bind(ConfigFactoryConstructorProbe::class, static function ($container) use ($runtimeServices) {
        return new ConfigFactoryConstructorProbe(71);
    });
    checkInjection($container->get(ConfigFactoryConstructorProbe::class)->value === 71, 'factory controls construction');
    $invalidType = false;
    try {
        $container->get(ConfigInvalidConstructorTypeProbe::class);
    } catch (TypeError $error) {
        $invalidType = true;
    }
    checkInjection($invalidType, 'constructor type checking');
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $thrown = false;
        try {
            $container->get(ConfigThrowingConstructorProbe::class);
        } catch (RuntimeException $error) {
            $thrown = $error->getMessage() === 'constructor failure';
        }
        checkInjection($thrown, 'constructor exceptions propagate and resolution can be retried');
    }
    foreach ([ConfigMissingConstructorProbe::class => 'probe.required', ConfigUnresolvedConstructorProbe::class => 'service factory', ConfigDuplicateConstructorProbe::class => 'Duplicate InjectConfig'] as $class => $message) {
        $failed = false;
        try {
            $container->get($class);
        } catch (LogicException $error) {
            $failed = str_contains($error->getMessage(), $message);
        }
        checkInjection($failed, 'constructor resolution failure: ' . $class);
    }
    $service = $container->get(ConfigServiceProbe::class);
    checkInjection($service->timeout === 30 && $service->childName() === 30, 'public and private property injection');
    checkInjection($service->parentName() === 'configured' && $service->enabled() === false, 'inherited private/protected properties');
    checkInjection($service->fallback === 12 && $service->nullableDefault === null, 'explicit defaults including named null');
    checkInjection($service->nullable === null && $service->zero === 0, 'null and zero must not trigger defaults');
    checkInjection($service->settings['timeout'] === 30, 'whole configuration injection');
    checkInjection($container->get(ConfigServiceProbe::class) === $service, 'singleton identity');
    $container->bind('factory', static function ($container) use ($runtimeServices) {
        return new ConfigServiceProbe();
    });
    checkInjection($container->get('factory')->timeout === 30, 'factory result injection');
    foreach ([MissingConfigProbe::class => 'probe.required', InvalidConfigTypeProbe::class => 'InvalidConfigTypeProbe::$value', DuplicateConfigProbe::class => 'Duplicate InjectConfig', EmptyConfigKeyProbe::class => 'must not be empty'] as $class => $message) {
        $failed = false;
        try {
            $container->get($class);
        } catch (LogicException $error) {
            $failed = str_contains($error->getMessage(), $message);
        }
        checkInjection($failed, 'expected actionable failure: ' . $class);
    }
    // 解析失败时，既不能缓存实例，也不能留下循环依赖标记。
    file_put_contents($root . '/config/probe.php', '<?php return ["required"=>"recovered", "name"=>"configured"];');
    $runtimeServices->get(\Loom\Config::class)->setRoot($root);
    checkInjection($container->get(MissingConfigProbe::class)->value === 'recovered', 'retry after failed injection');
    checkInjection($container->get(ConfigMissingConstructorProbe::class)->value === 'recovered', 'retry after missing constructor configuration');
    $app = new \Loom\Application($container);
    $app->get('/configured', ConfigControllerProbe::class);
    $response = $app->handle(new \Loom\Request(['method' => 'GET', 'path' => '/configured']));
    checkInjection($response->toArray()['body'] === 'configured', 'controller route injection');
} finally {
    unlink($root . '/config/probe.php');
    rmdir($root . '/config');
    rmdir($root);
}
echo "loom_config_injection_test: OK\n";
