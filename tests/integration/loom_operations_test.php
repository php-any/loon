<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/framework/bootstrap.php';
use Loom\Database;
use Loom\Input;
use Loom\HttpError;
use App\Services\Access\Admin;
use App\Services\Operations\Operations;
use App\Services\Notifications\Notifications;

function opCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function opDenied(callable $call, int $status): void
{
    try {
        $call();
    } catch (HttpError $e) {
        opCheck($e->status === $status, 'Wrong status: ' . $e->status . ' ' . $e->getMessage());
        return;
    } throw new RuntimeException('Expected ' . $status);
}
function opChange(\Loom\ServiceScope $scope, string $resource, array $body, int $actor = 1): array
{
    return $scope->get(\App\Services\Operations\Operations::class)->mutate($resource, $actor, new Input($body));
}
$app = (static function () { return require dirname(__DIR__, 2) . '/loom.php'; })();
try {
    $app->services->withScope(static function (\Loom\ServiceScope $scope): void {
        $scope->get(\Loom\Migrations::class)->initialize();
        $migrationPlan = $scope->get(\Loom\Migrations::class)->files(dirname(__DIR__, 2) . '/database/migrations');
        $migrationNamespace = $scope->get(\Loom\Config::class)->get('migrations')['namespace'];
        $scope->get(\Loom\Migrations::class)->run($migrationNamespace, array_slice($migrationPlan, 0, 5));
        $scope->get(\Loom\Database::class)->execute("INSERT INTO admin_roles(id,name,permissions) VALUES(77,'Existing custom role','[\"audit.view\"]')");
        $scope->get(\Loom\Database::class)->execute("INSERT INTO admin_menus(label,path,permission,sort_order) VALUES('Existing orders menu','/admin#orders','audit.view',99)");
        $scope->get(\Loom\Migrations::class)->migrate();
        require dirname(__DIR__) . '/fixtures/remove_default_admin.php';
        opCheck($scope->get(\Loom\Database::class)->query('SELECT name FROM admin_roles WHERE id=77')[0]['name'] === 'Existing custom role', 'Upgrade preserves custom roles');
        opCheck($scope->get(\Loom\Database::class)->query("SELECT permission FROM admin_menus WHERE path='/admin#orders'")[0]['permission'] === 'audit.view', 'Upgrade preserves custom menus');
        foreach (['root', 'member', 'another'] as $name) {
            $id = $scope->get(\Loom\Database::class)->execute('INSERT INTO loom_users(name,email,password) VALUES(?,?,?)', [$name, $name . '@example.test', 'unused'])['id'];
            $scope->get(\App\Services\Access\Admin::class)->enroll($id);
        }
        $scope->get(\App\Services\Access\Admin::class)->bootstrap('root@example.test');
        $before = $scope->get(\Loom\Database::class)->query('SELECT * FROM admin_roles');
        $scope->get(\Loom\Migrations::class)->migrate();
        opCheck($before === $scope->get(\Loom\Database::class)->query('SELECT * FROM admin_roles'), 'Migration repeat preserves roles');
        opCheck(count($scope->get(\Loom\Database::class)->query("SELECT id FROM admin_menus WHERE path='/admin#orders'")) === 1, 'Menu seeded once');
        foreach (['orders', 'queue', 'schedules', 'notifications', 'monitor', 'channels'] as $resource) {
            opDenied(fn () => $scope->get(\App\Services\Operations\Operations::class)->listing($resource, 2, []), 403);
        }
        $new = $scope->get(\App\Services\Access\Admin::class)->mutate('users', 1, new Input(['action' => 'save', 'id' => 0, 'name' => 'New', 'email' => 'new@example.test', 'password' => 'safe password here', 'enabled' => true, 'roles' => '2']));
        opCheck($scope->get(\App\Services\Operations\Operations::class)->listing('users', 1, ['id' => [$new['id']]])['email'] === 'new@example.test', 'Create/detail user');
        opDenied(fn () => $scope->get(\App\Services\Access\Admin::class)->mutate('users', 1, new Input(['action' => 'save', 'id' => 0, 'name' => 'New', 'email' => 'new@example.test', 'password' => 'safe password here', 'enabled' => true, 'roles' => '2'])), 409);
        $payload = ['action' => 'create', 'user_id' => 2, 'items' => [['name' => 'Service', 'quantity' => 2, 'unit_price' => 123]], 'request_key' => 'order-test-request-0001'];
        opDenied(fn () => opChange($scope, 'orders', $payload, 2), 403);
        $order = opChange($scope, 'orders', $payload);
        opCheck((int)$scope->get(\App\Services\Operations\Operations::class)->listing('orders', 1, ['id' => [$order['id']]])['items'][0]['total'] === 246, 'Server calculated cents');
        opDenied(fn () => opChange($scope, 'orders', $payload), 409);
        $invalid = $payload;
        $invalid['request_key'] = 'invalid-test-request-0001';
        $invalid['items'][0]['unit_price'] = 1.5;
        opDenied(fn () => opChange($scope, 'orders', $invalid), 422);
        opChange($scope, 'orders', ['action' => 'transition', 'id' => $order['id'], 'expected' => 'pending', 'status' => 'processing']);
        opDenied(fn () => opChange($scope, 'orders', ['action' => 'transition', 'id' => $order['id'], 'expected' => 'pending', 'status' => 'processing']), 409);
        opChange($scope, 'orders', ['action' => 'transition', 'id' => $order['id'], 'expected' => 'processing', 'status' => 'completed']);
        opDenied(fn () => opChange($scope, 'orders', ['action' => 'transition', 'id' => $order['id'], 'expected' => 'completed', 'status' => 'cancelled']), 422);
        $inbox = $scope->get(\App\Services\Operations\Operations::class)->listing('inbox', 2, []);
        opCheck($inbox['unread'] === 2, 'Order notifications');
        $nid = (int)$inbox['items'][0]['id'];
        opDenied(fn () => opChange($scope, 'inbox', ['action' => 'read', 'id' => $nid], 3), 404);
        opChange($scope, 'inbox', ['action' => 'read', 'id' => $nid], 2);
        \App\Models\Notifications\Notification::whereId($nid)->update(['read_at' => 123]);
        opChange($scope, 'inbox', ['action' => 'read', 'id' => $nid], 2);
        opCheck(\App\Models\Notifications\Notification::find($nid)->read_at === 123, 'Repeated reads preserve the first read timestamp');
        opCheck($scope->get(\App\Services\Operations\Operations::class)->listing('inbox', 2, [])['unread'] === 1, 'Read ownership');
        $job = $scope->get(\Loom\Queue::class)->dispatch('studio.digest', [], 2);
        $scope->get(\Loom\Database::class)->execute("UPDATE loom_jobs SET state='failed',attempts=3,error='password=secret' WHERE id=?", [$job]);
        $scope->get(\App\Services\Notifications\Notifications::class)->collectFailures();
        $scope->get(\App\Services\Notifications\Notifications::class)->collectFailures();
        opCheck(count($scope->get(\Loom\Database::class)->query("SELECT id FROM admin_notifications WHERE event_key=?", ['job.failed:' . $job])) === 1, 'Failure deduplicated to monitors');
        opCheck(!str_contains(json_encode($scope->get(\App\Services\Operations\Operations::class)->listing('queue', 1, ['id' => [$job]])), 'password'), 'Queue details hide secrets');
        opChange($scope, 'queue', ['action' => 'retry', 'id' => $job]);
        opDenied(fn () => opChange($scope, 'queue', ['action' => 'retry', 'id' => $job]), 409);
        $claimed = $scope->get(\Loom\Queue::class)->claim();
        opDenied(fn () => opChange($scope, 'queue', ['action' => 'retry', 'id' => $job]), 409);
        $scope->get(\Loom\Queue::class)->finish($claimed, '{}', null);
        $operations = $scope->get(\App\Services\Operations\Operations::class);
        $timers = $operations->listing('schedules', 1, []);
        opCheck($timers['items'] === $operations->timers(), 'Only code-declared timers are listed');
        opCheck($operations->listing('schedules', 1, ['q' => ['Heartbeat']])['total'] === 1, 'Search timer class');
        opCheck($operations->listing('schedules', 1, ['q' => ['missing-timer']])['total'] === 0, 'Unknown timer search');
        opCheck($operations->listing('schedules', 1, ['page' => [2]])['items'] === [], 'Timer pagination');
        $beforeSchedules = $scope->get(\Loom\Database::class)->query('SELECT * FROM loom_schedules');
        foreach (['create', 'toggle', 'edit', 'delete', 'preview'] as $action) {
            opDenied(fn () => opChange($scope, 'schedules', ['action' => $action]), 405);
            opDenied(fn () => opChange($scope, 'schedules', ['action' => $action], 2), 403);
        }
        opCheck($beforeSchedules === $scope->get(\Loom\Database::class)->query('SELECT * FROM loom_schedules'), 'Read-only API preserves schedules');
        opCheck(!isset(Operations::permissions()['schedules.manage']), 'No timer management permission');
        $scope->get(\Loom\Database::class)->execute("UPDATE admin_channels SET enabled=1 WHERE channel='mail'");
        $send = ['action' => 'send', 'user_id' => 2, 'title' => 'Hello', 'body' => 'Test body', 'request_key' => 'notify-request-0001'];
        opChange($scope, 'notifications', $send);
        opChange($scope, 'notifications', $send);
        opCheck(count($scope->get(\Loom\Database::class)->query('SELECT id FROM admin_deliveries')) === 1, 'Outbox deduplication');
        for ($i = 1;$i <= 3;$i++) {
            $scope->get(\Loom\Database::class)->execute('UPDATE admin_deliveries SET available=0');
            $sent = $scope->get(\App\Services\Notifications\Notifications::class)->deliver(fn ($delivery, $message) => false);
            opCheck($sent['state'] === ($i === 3 ? 'failed' : 'pending'), 'Bounded retry');
        }
        opChange($scope, 'notifications', ['action' => 'retry', 'id' => $sent['id']]);
        opDenied(fn () => opChange($scope, 'notifications', ['action' => 'retry', 'id' => $sent['id']]), 409);
        opCheck($scope->get(\App\Services\Notifications\Notifications::class)->deliver(fn ($delivery, $message) => $message['title'] === 'Hello')['state'] === 'sent', 'Transport success');
        opCheck($scope->get(\App\Services\Notifications\Notifications::class)->deliver()['state'] === 'empty', 'No redelivery');
        opCheck($scope->get(\App\Services\Operations\Operations::class)->listing('notifications', 1, [])['total'] === 1, 'Delivery listing');
        opCheck(count($scope->get(\App\Services\Operations\Operations::class)->listing('monitor', 1, [])['services']) === 4, 'Missing services explicit');
        opCheck(count($scope->get(\App\Services\Operations\Operations::class)->listing('schedules', 1, [])['items']) >= 1, 'Timer declarations');
        for ($i = 0;$i < 26;$i++) {
            $scope->get(\App\Services\Notifications\Notifications::class)->emit('pagination:' . $i, 3, 'Page test', 'Notification');
        }
        opCheck(count($scope->get(\App\Services\Notifications\Notifications::class)->inbox(3, 1)['items']) === 25 && count($scope->get(\App\Services\Notifications\Notifications::class)->inbox(3, 2)['items']) === 1, 'Inbox pagination boundary');
        opCheck($scope->get(\App\Services\Notifications\Notifications::class)->inbox(2, 1)['total'] !== $scope->get(\App\Services\Notifications\Notifications::class)->inbox(3, 1)['total'], 'Inbox total is isolated');
        $notifications = $scope->get(\App\Services\Notifications\Notifications::class);
        opCheck($notifications->inbox(3, 1, 'unread')['total'] === 26, 'Unread filter spans all pages');
        opCheck($notifications->inbox(3, 1, 'read')['total'] === 0, 'Read filter excludes unread');
        $otherUnread = $notifications->inbox(2, 1)['unread'];
        opChange($scope, 'inbox', ['action' => 'read_all'], 3);
        opCheck($notifications->inbox(3, 1, 'unread')['total'] === 0, 'Read all clears all pages');
        opCheck($notifications->inbox(3, 2, 'read')['total'] === 26, 'Read filter retains pagination total');
        opCheck($notifications->inbox(2, 1)['unread'] === $otherUnread, 'Read all preserves other users');
        opDenied(fn () => $notifications->inbox(3, 1, 'invalid'), 422);
        $scope->get(\App\Services\Notifications\Notifications::class)->emit('stale-lease', 2, 'Lease test', 'Content');
        $stale = $scope->get(\App\Services\Notifications\Notifications::class)->deliver(function ($delivery, $message) use ($scope): bool {
            $scope->get(\Loom\Database::class)->execute("UPDATE admin_deliveries SET lease='new-worker' WHERE id=?", [$delivery['id']]);
            return true;
        });
        opCheck($scope->get(\Loom\Database::class)->query('SELECT state FROM admin_deliveries WHERE id=?', [$stale['id']])[0]['state'] === 'sending', 'Stale worker cannot finalize a replaced lease');
        $db = $scope->get(\Loom\Database::class);
        $jobsBefore = \Loom\Models\Job::using($db)->count();
        $noticesBefore = \App\Models\Notifications\Notification::using($db)->count();
        try {
            $db->transaction(function () use ($notifications): void {
                $notifications->emit('rollback-notification', 1, 'Rollback', 'Must not be committed');
                throw new RuntimeException('Rollback probe');
            });
        } catch (RuntimeException $rollback) {
            opCheck($rollback->getMessage() === 'Rollback probe', 'Unexpected transaction failure');
        }
        opCheck(\Loom\Models\Job::using($db)->count() === $jobsBefore, 'Rollback removes push job');
        opCheck(\App\Models\Notifications\Notification::using($db)->count() === $noticesBefore, 'Rollback removes notification');
        $failedPush = $scope->get(\Loom\Queue::class)->dispatch('notification.push', ['notification_id' => 1], 1);
        \Loom\Models\Job::using($db)->whereId($failedPush)->update(['state' => 'failed', 'attempts' => 3]);
        $notifications->collectFailures();
        opCheck(\Loom\Models\Job::using($db)->count() === $jobsBefore + 1, 'Push failures do not recursively create more push jobs');
        $scope->get(\Loom\Migrations::class)->run($migrationNamespace, $migrationPlan, true);
        opCheck($scope->get(\Loom\Database::class)->query("SELECT label FROM admin_menus WHERE path='/admin#orders'")[0]['label'] === 'Existing orders menu', 'Rollback preserves pre-existing menu');
        opCheck($scope->get(\Loom\Database::class)->query("SELECT id FROM admin_menus WHERE path='/admin#queue'") === [], 'Rollback removes only seeded menu');
    });
    echo "loom_operations_test: OK\n";
} finally {
    $app->dispose();
}
