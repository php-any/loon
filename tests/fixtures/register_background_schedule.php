<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/framework/bootstrap.php';

$app = (static function () { return require dirname(__DIR__, 2) . '/loom.php'; })();
try {
    $email = $argv[1];
    $app->services->withScope(static function (\Loom\ServiceScope $scope) use ($email): void {
        $user = $scope->get(\Loom\Database::class)->query('SELECT id FROM loom_users WHERE email=?', [$email])[0];
        $scope->get(\Loom\Schedule::class)->cron('test.background.digest', '* * * * *', 'Asia/Shanghai', 'studio.digest', [], (int) $user['id']);
    });
} finally {
    $app->dispose();
}
