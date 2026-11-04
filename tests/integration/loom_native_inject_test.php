<?php

declare(strict_types=1);
$runtimeScope = (require dirname(__DIR__, 2) . '/framework/bootstrap/autoload.php')(dirname(__DIR__, 2));
$runtimeServices = $runtimeScope->container();
require dirname(__DIR__) . '/fixtures/native-inject/valid.php';
$container = new \Container\Container();
$dependency = new \NativeInjectRegression\Dependency();
$inferred = new \NativeInjectRegression\Dependency();
$container->instance('native.probe', $dependency);
$container->instance(\NativeInjectRegression\Dependency::class, $inferred);
foreach ([\NativeInjectRegression\Ordinary::class, \NativeInjectRegression\Promoted::class, \NativeInjectRegression\NamedParent::class, \NativeInjectRegression\NamedChild::class] as $class) {
    if ($container->make($class)->dependency !== $dependency) {
        throw new RuntimeException('Native constructor injection failed: ' . $class);
    }
}
if ($container->make(\NativeInjectRegression\Inferred::class)->dependency !== $inferred) {
    throw new RuntimeException('Native Inject type inference failed');
}
foreach (['invalid-method.php', 'invalid-function.php', 'invalid-closure.php'] as $fixture) {
    $result = $runtimeServices->get(\Loom\Process::class)->run([PHP_BINARY, dirname(__DIR__) . '/fixtures/native-inject/' . $fixture]);
    if ($result['code'] === 0 || !str_contains($result['stderr'] . $result['stdout'], 'require a constructor parameter')) {
        throw new RuntimeException('Native injection must reject non-constructor target: ' . $fixture);
    }
}
echo "loom_native_inject_test: OK\n";
