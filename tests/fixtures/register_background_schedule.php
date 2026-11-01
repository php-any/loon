<?php

declare(strict_types=1);

$scope = (require dirname(__DIR__, 2) . '/framework/bootstrap/autoload.php')(dirname(__DIR__, 2));
try {
    $services = $scope->services();
    $user = $services->get(\Loom\Database::class)->query('SELECT id FROM loom_users WHERE email=?', [$argv[1]])[0];
    $services->get(\Loom\Schedule::class)->cron('test.background.digest', '* * * * *', 'Asia/Shanghai', 'studio.digest', [], (int)$user['id']);
} finally {
    $scope->close();
}
