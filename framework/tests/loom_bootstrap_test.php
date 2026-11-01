<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
$runtimeScope = new \Loom\ServiceScope(new \Loom\Config());
$runtimeServices = $runtimeScope->services();

$unconfigured = false;
try {
    $runtimeServices->get(\Loom\Config::class)->root();
} catch (LogicException $error) {
    $unconfigured = true;
}
if (!$unconfigured) {
    throw new RuntimeException('Framework must not infer an application root');
}
$root = sys_get_temp_dir();
$runtimeServices->get(\Loom\Config::class)->setRoot($root);
if ($runtimeServices->get(\Loom\Config::class)->path('config/http.php') !== realpath($root) . '/config/http.php') {
    throw new RuntimeException('Paths must resolve against the supplied application root');
}
$runtimeServices->get(\Loom\Database::class)->connect((string)getenv('LOOM_DATABASE'));
try {
    $runtimeServices->get(\Loom\Migrations::class)->initialize();
    $runtimeServices->get(\Loom\Database::class)->query('SELECT * FROM loom_jobs');
} finally {
    $runtimeServices->get(\Loom\Database::class)->close();
}
echo "loom_bootstrap_test: OK\n";
