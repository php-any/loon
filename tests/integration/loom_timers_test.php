<?php

declare(strict_types=1);

$scope = (require dirname(__DIR__, 2) . '/framework/bootstrap/autoload.php')(dirname(__DIR__, 2));
$services = $scope->container();
function timerCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
try {
    $scope->load('framework/bootstrap/database.php');
    $db = $services->get(\Loom\Database::class);
    $admin = $services->get(\App\Services\Access\Admin::class);
    $id = $db->execute('INSERT INTO loom_users(name,email,password) VALUES(?,?,?)', ['Timer test', 'timers@example.test', 'unused'])['id'];
    $admin->enroll($id);
    $admin->bootstrap('timers@example.test');
    $operations = $services->get(\App\Services\Operations\Operations::class);
    $declarations = $operations->timers();
    $rows = $operations->listing('schedules', $id, []);
    timerCheck($rows['items'] === $declarations && $rows['total'] === count($declarations), 'List code declarations');
    $heartbeat = $operations->listing('schedules', $id, ['q' => ['Heartbeat']]);
    timerCheck($heartbeat['total'] === 1 && $heartbeat['items'][0]['seconds'] === 5, 'Search declared period');
    timerCheck($operations->listing('schedules', $id, ['page' => [2]])['items'] === [], 'Pagination');
    timerCheck($operations->listing('schedules', $id, ['q' => ['missing-timer']])['total'] === 0, 'Empty search');
    $services->get(\Loom\Schedule::class)->every('test.legacy.schedule', 60, 'studio.digest', [], $id);
    timerCheck($operations->listing('schedules', $id, []) === $rows, 'Database schedules are not code timers');
    $before = $db->query('SELECT * FROM loom_schedules');
    foreach (['create', 'toggle', 'edit', 'delete', 'preview'] as $action) {
        $status = 0;
        try {
            $operations->mutate('schedules', $id, new \Loom\Input(['action' => $action]));
        } catch (\Loom\HttpError $error) {
            $status = $error->status;
        }
        timerCheck($status === 405, 'Reject timer mutation: ' . $action);
    }
    timerCheck($before === $db->query('SELECT * FROM loom_schedules'), 'No mutation');
    timerCheck(!isset(\App\Services\Operations\Operations::permissions()['schedules.manage']), 'No management permission');
    $routes = $services->get(\Loom\ModuleDiscovery::class)->configured()->routes();
    foreach ($routes as $route) {
        timerCheck(!in_array($route['path'], ['/api/studio/cron', '/api/studio/schedule'], true), 'Legacy write route removed');
    }
    echo "loom_timers_test: OK\n";
} finally {
    $scope->close();
}
