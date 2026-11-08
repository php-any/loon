<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
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
$path = tempnam(sys_get_temp_dir(), 'loom-env-');
$names = ['LOOM_ENV_TEST_OVERRIDE', 'LOOM_ENV_TEST_EMPTY', 'LOOM_ENV_TEST_QUOTED', 'LOOM_ENV_TEST_LITERAL', 'LOOM_ENV_TEST_KEEP'];
$previous = [];
foreach ($names as $name) {
    $previous[$name] = getenv($name);
}
try {
    putenv('LOOM_ENV_TEST_OVERRIDE=process');
    putenv('LOOM_ENV_TEST_KEEP=retained');
    $_ENV['LOOM_ENV_TEST_OVERRIDE'] = 'stale';
    $_SERVER['LOOM_ENV_TEST_OVERRIDE'] = 'stale-server';
    file_put_contents($path, "# comment\nLOOM_ENV_TEST_OVERRIDE=file # comment\nLOOM_ENV_TEST_EMPTY=\nexport LOOM_ENV_TEST_QUOTED=\" a # b = c \" # comment\nLOOM_ENV_TEST_LITERAL='literal\$value'\n");
    Loom\Environment::load($path);
    if (getenv($names[0]) !== 'file' || getenv($names[1]) !== '' || getenv($names[2]) !== ' a # b = c ' || getenv($names[3]) !== 'literal$value' || getenv($names[4]) !== 'retained') {
        throw new RuntimeException('Environment values or precedence incorrect');
    }
    if (env('LOOM_ENV_TEST_OVERRIDE') !== 'file' || env('LOOM_ENV_TEST_EMPTY', 'fallback') !== '' || ($_ENV['LOOM_ENV_TEST_OVERRIDE'] ?? null) !== 'file' || ($_SERVER['LOOM_ENV_TEST_OVERRIDE'] ?? null) !== 'file') {
        throw new RuntimeException('env() must see the file value stored for Laravel readers');
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
    putenv('LOOM_ENV_FN');
    if (env('LOOM_ENV_FN', 'fallback') !== 'fallback' || env('LOOM_ENV_FN', static fn (): string => 'called') !== 'called') {
        throw new RuntimeException('env() must return the default when the variable is absent');
    }
    putenv('LOOM_ENV_FN=(true)');
    if (env('LOOM_ENV_FN') !== true) {
        throw new RuntimeException('env() must cast true');
    }
    putenv('LOOM_ENV_FN=(false)');
    if (env('LOOM_ENV_FN') !== false) {
        throw new RuntimeException('env() must cast false');
    }
    putenv('LOOM_ENV_FN=(null)');
    if (env('LOOM_ENV_FN', 'fallback') !== null) {
        throw new RuntimeException('env() must cast null');
    }
    putenv('LOOM_ENV_FN=(empty)');
    if (env('LOOM_ENV_FN', 'fallback') !== '') {
        throw new RuntimeException('env() must cast empty');
    }
    putenv('LOOM_ENV_FN=plain');
    if (env('LOOM_ENV_FN') !== 'plain') {
        throw new RuntimeException('env() must return the raw string');
    }
    putenv('LOOM_ENV_FN');
    $_ENV['LOOM_ENV_FN'] = '(true)';
    if (env('LOOM_ENV_FN') !== true) {
        throw new RuntimeException('env() must read $_ENV when getenv has no value');
    }
    unset($_ENV['LOOM_ENV_FN']);
    $_SERVER['LOOM_ENV_FN'] = '(false)';
    if (env('LOOM_ENV_FN') !== false) {
        throw new RuntimeException('env() must read $_SERVER when getenv and $_ENV have no value');
    }
    unset($_SERVER['LOOM_ENV_FN']);
    putenv('LOOM_ENV_FN=""');
    if (env('LOOM_ENV_FN', 'fallback') !== '') {
        throw new RuntimeException('env() must unwrap quotes and keep an empty string');
    }
    putenv('LOOM_ENV_FN');
    unset($_ENV['LOOM_ENV_FN'], $_SERVER['LOOM_ENV_FN']);
    $bootRoot = sys_get_temp_dir() . '/loom-env-boot-' . bin2hex(random_bytes(4));
    mkdir($bootRoot . '/config', 0700, true);
    $bootPrevious = getenv('LOOM_BOOT_PROBE');
    putenv('LOOM_BOOT_PROBE=process');
    file_put_contents($bootRoot . '/.env', "LOOM_BOOT_PROBE=file\n");
    file_put_contents($bootRoot . '/config/probe.php', '<?php return ["value" => getenv("LOOM_BOOT_PROBE")];');
    file_put_contents($bootRoot . '/config/modules.php', '<?php return [];');
    $builder = Loom\Application::createBuilder($bootRoot, $bootRoot . '/.env');
    // 构建前的登记对每个作用域生效，但作用域之间不共享实例。
    $builder->services->addScoped(ScopeRegistrationProbe::class, static fn (): ScopeRegistrationProbe => new ScopeRegistrationProbe('from builder'));
    $app = $builder->build();
    try {
        if ($app->configuration->get('probe.value') !== 'file') {
            throw new RuntimeException('Builder must load the environment file before config');
        }
        $a = $app->services->createScope();
        $b = $app->services->createScope();
        try {
            if ($a->get(ScopeRegistrationProbe::class)->value !== 'from builder' || $b->get(ScopeRegistrationProbe::class)->value !== 'from builder' || $a->get(ScopeRegistrationProbe::class) === $b->get(ScopeRegistrationProbe::class)) {
                throw new RuntimeException('Scopes must inherit builder registrations without sharing instances');
            }
            if ($a->get(Loom\Database::class) !== $a->get(Loom\Database::class) || $a->get(Loom\Database::class) === $b->get(Loom\Database::class) || $a->get(Loom\RequestContext::class) === $b->get(Loom\RequestContext::class)) {
                throw new RuntimeException('Execution services must stay inside one scope');
            }
        } finally {
            $a->dispose();
            $b->dispose();
        }
    } finally {
        $app->dispose();
        putenv($bootPrevious === false ? 'LOOM_BOOT_PROBE' : 'LOOM_BOOT_PROBE=' . $bootPrevious);
        unlink($bootRoot . '/.env');
        unlink($bootRoot . '/config/probe.php');
        unlink($bootRoot . '/config/modules.php');
        rmdir($bootRoot . '/config');
        rmdir($bootRoot);
    }
} finally {
    unlink($path);
    putenv('LOOM_ENV_FN');
    unset($_ENV['LOOM_ENV_FN'], $_SERVER['LOOM_ENV_FN']);
    foreach ($previous as $name => $value) {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($value === false ? $name : $name . '=' . $value);
    }
}
echo "loom_environment_test: OK\n";
