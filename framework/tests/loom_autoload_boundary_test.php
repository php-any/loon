<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$root = sys_get_temp_dir() . '/loom-autoload-' . bin2hex(random_bytes(6));
mkdir($root . '/vendor', 0700, true);
mkdir($root . '/config', 0700, true);
file_put_contents($root . '/vendor/autoload.php', '<?php throw new RuntimeException("Framework loaded application dependencies implicitly");');
$app = null;
try {
    $app = Loom\Application::createBuilder($root)->build();
    if ($app->configuration->root() !== realpath($root) || $app->services->get(Loom\Config::class)->root() !== realpath($root)) {
        throw new RuntimeException('Bootstrap must preserve the selected application root');
    }
} finally {
    if ($app !== null) {
        $app->dispose();
    }
    unlink($root . '/vendor/autoload.php');
    rmdir($root . '/vendor');
    rmdir($root . '/config');
    rmdir($root);
}
echo "loom_autoload_boundary_test: OK\n";
