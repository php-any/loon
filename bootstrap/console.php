<?php
declare(strict_types=1);

use Loom\Console\Console;

require_once __DIR__ . '/autoload.php';
$console = new Console();
// 参照 Artisan 的 routes/console.php，在这里显式注册脚本命令。
require dirname(__DIR__) . '/routes/console.php';
// 仅从应用自己的命令目录加载生成的命令类。
foreach (glob(dirname(__DIR__) . '/app/Console/Commands/*.php') as $file) {
    $console->add('App\\Console\\Commands\\' . basename($file, '.php'));
}
return $console->run();
