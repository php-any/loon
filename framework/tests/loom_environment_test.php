<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
$path = tempnam(sys_get_temp_dir(), 'loom-env-');
$names = ['LOOM_ENV_TEST_OVERRIDE', 'LOOM_ENV_TEST_EMPTY', 'LOOM_ENV_TEST_QUOTED', 'LOOM_ENV_TEST_LITERAL', 'LOOM_ENV_TEST_KEEP'];
$previous = [];
foreach ($names as $name) {
    $previous[$name] = getenv($name);
}
try {
    putenv('LOOM_ENV_TEST_OVERRIDE=process');
    putenv('LOOM_ENV_TEST_KEEP=retained');
    file_put_contents($path, "# comment\nLOOM_ENV_TEST_OVERRIDE=file # comment\nLOOM_ENV_TEST_EMPTY=\nexport LOOM_ENV_TEST_QUOTED=\" a # b = c \" # comment\nLOOM_ENV_TEST_LITERAL='literal\$value'\n");
    Loom\Environment::load($path);
    if (getenv($names[0]) !== 'file' || getenv($names[1]) !== '' || getenv($names[2]) !== ' a # b = c ' || getenv($names[3]) !== 'literal$value' || getenv($names[4]) !== 'retained') {
        throw new RuntimeException('Environment values or precedence incorrect');
    }
    foreach (["INVALID-NAME=secret", "LOOM_ENV_TEST_QUOTED='secret", 'LOOM_ENV_TEST_QUOTED="secret"suffix'] as $invalid) {
        file_put_contents($path, $invalid);
        $rejected = false;
        try {
            Loom\Environment::load($path);
        } catch (InvalidArgumentException $error) {
            $rejected = !str_contains($error->getMessage(), 'secret');
        }
        if (!$rejected) {
            throw new RuntimeException('Invalid environment entry was not safely rejected');
        }
    }
    Loom\Environment::load($path . '.missing');
} finally {
    unlink($path);
    foreach ($previous as $name => $value) {
        putenv($value === false ? $name : $name . '=' . $value);
    }
}
// 入口注册会传播到子作用域，但不会共享单例实例。
/**
 * 保存注册值，验证服务作用域间的注册隔离。
 *
 * 构造参数：
 * - $value（string）：夹具保存或使用的测试值；具体注入来源由参数属性声明。
 *
 * 返回值：使用 new 构造时得到 ScopeRegistrationProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(ScopeRegistrationProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
final class ScopeRegistrationProbe
{
    public string $value;
    /**
     * 初始化 ScopeRegistrationProbe 实例及其依赖。保存注册值，验证服务作用域间的注册隔离。
     *
     * @param string $value 夹具保存或使用的测试值；具体注入来源由参数属性声明。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = $services->get(ScopeRegistrationProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
     */
    public function __construct(string $value)
    {
        $this->value = $value;
    }
}
$config = new Loom\Config(dirname(__DIR__, 2));
$first = new Loom\ServiceScope($config, static function (Loom\Container $services): void {
    $services->singleton(ScopeRegistrationProbe::class, static function (): ScopeRegistrationProbe {
        return new ScopeRegistrationProbe('from entry');
    });
    $services->singleton(Loom\RequestContext::class);
    $services->singleton(Loom\Database::class, static function (Loom\Container $services): Loom\Database {
        return new Loom\Database($services->get(Loom\Config::class));
    });
});
$second = $first->fork();
try {
    $a = $first->services();
    $b = $second->services();
    $child = $a->get(Loom\ModuleRegistry::class)->container();
    try {
        if ($b->get(ScopeRegistrationProbe::class)->value !== 'from entry' || $child->get(ScopeRegistrationProbe::class)->value !== 'from entry' || $a->get(ScopeRegistrationProbe::class) === $child->get(ScopeRegistrationProbe::class)) {
            throw new RuntimeException('Scope and module factories must inherit entry registrations without sharing instances');
        }
    } finally {
        $child->get(Loom\ServiceScope::class)->close();
    }
    if ($a->get(Loom\Database::class) !== $a->get(Loom\Database::class) || $a->get(Loom\Database::class) === $b->get(Loom\Database::class) || $a->get(Loom\RequestContext::class) === $b->get(Loom\RequestContext::class)) {
        throw new RuntimeException('Entry singleton scope incorrect');
    }
} finally {
    $first->close();
    $second->close();
}
echo "loom_environment_test: OK\n";
