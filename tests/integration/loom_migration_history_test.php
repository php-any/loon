<?php

declare(strict_types=1);

// 固定已在数据库执行过的校验值，防止翻译注释或格式化再次破坏历史迁移。
// 本测试只读取文件，不连接数据库；可直接通过项目运行时执行。
$root = dirname(__DIR__, 2);
$history = [
    'framework/resources/migrations/0004_model_columns' => '96bb68e9a32bc774314f9343918bef87a9a4bd9618675405e8e0481648da9a11',
    'database/migrations/011_default_admin' => '363d55e5b33e995b015ef5e75c1ec36a2c16bf1ac17513c92501de0cc5f80747',
];
foreach ($history as $path => $expected) {
    $up = file_get_contents($root . '/' . $path . '.up.sql');
    $down = file_get_contents($root . '/' . $path . '.down.sql');
    if (hash('sha256', $up . "\0" . $down) !== $expected) {
        throw new RuntimeException('历史迁移内容已改变，请恢复原始字节：' . $path);
    }
}
echo "loom_migration_history_test: OK\n";
