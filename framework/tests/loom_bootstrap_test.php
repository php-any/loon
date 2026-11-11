<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

function bootstrapCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = new Loom\Config();
$unconfigured = false;
try {
    $config->root();
} catch (LogicException $error) {
    $unconfigured = true;
}
bootstrapCheck($unconfigured, 'Framework must not infer an application root');
$root = sys_get_temp_dir() . '/loom-bootstrap-' . bin2hex(random_bytes(6));
mkdir($root . '/config', 0700, true);
$app = null;
try {
    $config->setRoot($root);
    bootstrapCheck($config->path('config/http.php') === realpath($root) . '/config/http.php', 'Paths must resolve against the supplied application root');
    $app = Loom\Application::createBuilder($root)->build();
    bootstrapCheck($app->services->get(Loom\Config::class)->path('config/http.php') === realpath($root) . '/config/http.php', 'Application configuration uses the builder root');
    // 数据库是作用域服务：构造时不连接，每个工作单元各一份。
    $databases = $app->services->withScope(static function (Loom\ServiceScope $scope): array {
        return [$scope->get(Loom\Database::class), $scope->get(Loom\Database::class), $scope->get(Loom\Migrations::class)];
    });
    $other = $app->services->withScope(static fn (Loom\ServiceScope $scope): Loom\Database => $scope->get(Loom\Database::class));
    bootstrapCheck($databases[0] === $databases[1] && $databases[0] !== $other, 'Database is scoped to one unit of work');
    bootstrapCheck($databases[2] instanceof Loom\Migrations, 'Migrations resolve from a scope without opening a connection');
} finally {
    if ($app !== null) {
        $app->dispose();
    }
    rmdir($root . '/config');
    rmdir($root);
}
echo "loom_bootstrap_test: OK\n";
