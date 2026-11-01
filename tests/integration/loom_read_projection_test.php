<?php

declare(strict_types=1);

$scope = (require dirname(__DIR__, 2) . '/framework/bootstrap/autoload.php')(dirname(__DIR__, 2));
$database = $scope->services()->get(\Loom\Database::class);
$database->connect($scope->services()->get(\Loom\Config::class)->get('database.dsn'));
function projectionCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new LogicException($message);
    }
}
try {
    $scope->load('framework/bootstrap/database.php');
    require dirname(__DIR__) . '/fixtures/remove_default_admin.php';
    for ($i = 1; $i <= 26; $i++) {
        $user = \Loom\Models\User::using($database)->create(['name' => 'Projection ' . $i, 'email' => 'projection' . $i . '@example.test', 'password' => 'must-not-leak']);
        if ($i === 1) {
            \App\Models\Identity\Account::using($database)->create(['user_id' => $user->id, 'enabled' => 0]);
        }
        $notification = \App\Models\Notifications\Notification::using($database)->create(['event_key' => 'projection-' . $i, 'user_id' => $user->id, 'title' => 'Projection message', 'body' => 'private body']);
        \App\Models\Notifications\Delivery::using($database)->create(['notification_id' => $notification->id, 'channel' => 'mail']);
        \App\Models\System\AuditEvent::using($database)->create(['actor_id' => $user->id, 'action' => 'projection.create', 'target' => (string)$user->id, 'detail' => '{}']);
    }
    $users = \App\Models\Identity\Account::listing($database, 1, 'Projection');
    $second = \App\Models\Identity\Account::listing($database, 2, 'Projection');
    projectionCheck($users['total'] === 26 && count($users['items']) === 25 && count($second['items']) === 1, 'User pagination counts all matching rows');
    projectionCheck($users['items'][0]['id'] === 26 && $users['items'][0]['enabled'] === 1 && $second['items'][0]['enabled'] === 0, 'Left join preserves legacy users, ordering and disabled status');
    projectionCheck(!str_contains(json_encode($users), 'must-not-leak') && !array_key_exists('password', $users['items'][0]), 'User projection excludes password fields');
    projectionCheck(\App\Models\Identity\Account::listing($database, 1, "' OR 1=1 --")['total'] === 0, 'Search input stays bound');
    $deliveries = \App\Models\Notifications\Delivery::listing($database, 2, 'Projection');
    projectionCheck($deliveries['total'] === 26 && count($deliveries['items']) === 1 && $deliveries['items'][0]['title'] === 'Projection message', 'Delivery join and total use identical filters');
    projectionCheck(!array_key_exists('body', $deliveries['items'][0]), 'Delivery listing excludes message bodies');
    $audit = \App\Models\System\AuditEvent::listing($database, 2, 'projection.create');
    projectionCheck($audit['total'] === 26 && count($audit['items']) === 1, 'Audit count is independent of page limit');
    $counts = \App\Models\Notifications\Delivery::countsByState($database);
    projectionCheck($counts === [['state' => 'pending', 'total' => 26]], 'Grouped totals are typed native projections');
    echo "loom_read_projection_test: OK\n";
} finally {
    $scope->close();
}
