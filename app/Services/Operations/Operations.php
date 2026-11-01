<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Loom\Database;
use Loom\HttpError;
use Loom\Input;
use App\Models\Orders\Order;
use App\Models\Orders\OrderItem;
use App\Models\Orders\OrderEvent;
use Loom\Models\Job;
use Loom\Models\ScheduleRun;
use App\Models\Identity\Account;
use App\Models\Access\Role;
use App\Models\Notifications\Delivery;

final class Operations
{
    private \Loom\RuntimeStatus $runtimeStatus;
    private \Loom\Database $database;
    private \App\Services\Notifications\Notifications $notifications;
    private \Loom\ModuleRegistry $moduleRegistry;
    private \App\Services\Operations\OperationsData $operationsData;
    private \App\Services\Access\Admin $admin;

    public function __construct(\Loom\RuntimeStatus $runtimeStatus, \Loom\Database $database, \App\Services\Notifications\Notifications $notifications, \Loom\ModuleRegistry $moduleRegistry, \App\Services\Operations\OperationsData $operationsData, \App\Services\Access\Admin $admin)
    {
        $this->runtimeStatus = $runtimeStatus;
        $this->database = $database;
        $this->notifications = $notifications;
        $this->moduleRegistry = $moduleRegistry;
        $this->operationsData = $operationsData;
        $this->admin = $admin;
    }

    public static function permissions(): array
    {
        return ['orders.view' => '查看订单', 'orders.manage' => '管理订单', 'queue.view' => '查看全局队列', 'queue.retry' => '重试失败任务', 'schedules.view' => '查看定时器', 'notifications.view' => '查看通知投递', 'notifications.send' => '发送通知', 'notifications.retry' => '重试通知投递', 'channels.manage' => '配置通知渠道', 'monitor.view' => '查看运行监控'];
    }





    public function listing(string $resource, int $actor, array $query): array
    {
        $this->admin->assertActive($actor);
        $page = (int)($query['page'][0] ?? 1);
        $id = (int)($query['id'][0] ?? 0);
        $q = substr($query['q'][0] ?? '', 0, 100);
        if ($resource === 'inbox') {
            return $this->notifications->inbox($actor, $page, $query['status'][0] ?? 'all');
        }
        if ($resource === 'channels') {
            $this->admin->require($actor, 'channels.manage');
            return $this->notifications->channels();
        }
        if (!in_array($resource, ['orders', 'queue', 'schedules', 'notifications', 'monitor', 'users'], true)) {
            throw new HttpError(404, '模块不存在');
        }
        $this->admin->require($actor, $resource === 'users' ? 'users.manage' : $resource . '.view');
        if ($resource === 'users') {
            $user = $this->operationsData->user($id);
            $user['account'] = Account::using($this->database)->whereUserId($id)->firstRow(['enabled', 'verified_at']);
            $user['roles'] = [];
            foreach (Role::forUser($this->database, $id) as $role) {
                $user['roles'][] = ['id' => (int)$role->id, 'name' => $role->name];
            }
            return $user;
        }
        if ($resource === 'monitor') {
            return $this->monitor();
        }
        if ($resource === 'notifications') {
            $result = Delivery::listing($this->database, $page, $q);
            $result['channels'] = $this->notifications->channelStatus();
            return $result;
        }
        if ($resource === 'schedules') {
            $items = [];
            foreach ($this->timers() as $timer) {
                if ($q === '' || stripos($timer['class'], $q) !== false) {
                    $items[] = $timer;
                }
            }
            $page = max(1, $page);
            return ['items' => array_slice($items, ($page - 1) * 25, 25), 'total' => count($items), 'page' => $page];
        }
        $records = $resource === 'orders' ? Order::using($this->database) : Job::using($this->database);
        $status = $query['status'][0] ?? '';
        $owner = $query['owner'][0] ?? '';
        if ($status !== '') {
            $records = $records->where($resource === 'orders' ? 'status' : 'state', $status);
        }
        if ($owner !== '') {
            $records = $records->where($resource === 'orders' ? 'user_id' : 'owner', (int)$owner);
        }
        if ($q !== '') {
            $records = $records->where($resource === 'orders' ? 'number' : 'kind', 'LIKE', '%' . $q . '%');
        }
        if ($id > 0) {
            $records = $records->whereId($id);
        }
        $fields = $resource === 'orders' ? 'id,number,user_id,currency,total,status,created' : 'id,kind,owner,state,attempts,available,created,lease_until';
        $result = $records->orderBy('id', 'DESC')->paginate($page, explode(',', $fields));
        if ($id > 0 && $result['items'] === []) {
            throw new HttpError(404, '记录不存在');
        }
        if ($id > 0 && $resource === 'orders') {
            $result['details'] = OrderItem::using($this->database)->whereOrderId($id)->orderBy('id')->rows(['name', 'quantity', 'unit_price']);
            $result['events'] = OrderEvent::using($this->database)->whereOrderId($id)->orderBy('id')->rows(['actor_id', 'status', 'created']);
        }
        if ($id > 0 && $resource === 'queue') {
            $job = Job::using($this->database)->findOrFail($id)->toArray();
            // 任意处理器的自由文本输出可能包含凭据，因此仅公开白名单中的结构化字段。
            $result['output'] = self::safeOutput($job['result']);
            $result['error'] = $job['error'] === null ? null : '任务执行失败；原始错误仅供服务器日志诊断';
        }
        return $result;
    }

    public static function safeOutput(?string $output): array
    {
        $value = json_decode($output ?? '', true);
        if (!is_array($value)) {
            return ['message' => '无可公开的结构化结果'];
        }
        $safe = [];
        foreach (['id', 'job_id', 'report_id', 'count', 'completed', 'ok'] as $key) {
            if (isset($value[$key]) && (is_int($value[$key]) || is_bool($value[$key]))) {
                $safe[$key] = $value[$key];
            }
        }
        return $safe;
    }

    public function mutate(string $resource, int $actor, Input $input): array
    {
        $this->admin->assertActive($actor);
        if ($resource === 'inbox') {
            return $this->notifications->read($actor, $input);
        }
        if ($resource === 'channels') {
            $this->admin->require($actor, 'channels.manage');
            return $this->notifications->configure($actor, $input);
        }
        if ($resource === 'notifications') {
            return $this->notifications->mutate($actor, $input);
        }
        if ($resource === 'schedules') {
            $this->admin->require($actor, 'schedules.view');
            throw new HttpError(405, '定时器由代码声明，仅支持查看');
        }
        if (!in_array($resource, ['orders', 'queue'], true)) {
            throw new HttpError(404, '模块不存在');
        }
        $this->admin->require($actor, $resource === 'queue' ? 'queue.retry' : $resource . '.manage');
        return $this->database->transaction(function () use ($resource, $actor, $input): array {
            $action = $input->string('action', 1, 20);
            if ($resource === 'orders') {
                return $this->order($actor, $action, $input);
            }
            if ($resource === 'queue') {
                $input->only(['action', 'id']);
                if ($action !== 'retry') {
                    throw new HttpError(422, '操作无效');
                }
                $id = $input->integer('id', 1);
                $changed = Job::using($this->database)->whereId($id)->whereState('failed')
                    ->update(['state' => 'pending', 'attempts' => 0, 'available' => time(), 'lease' => null, 'lease_until' => null, 'error' => null]);
                if ($changed !== 1) {
                    throw new HttpError(409, '仅失败任务可以重试，任务状态已变化');
                }
                $this->admin->audit($actor, 'queue.retry', (string)$id);
                return ['ok' => true];
            }
            throw new HttpError(422, '操作无效');
        });
    }

    private function order(int $actor, string $action, Input $input): array
    {
        if ($action === 'create') {
            $input->only(['action', 'user_id', 'items', 'request_key']);
            $owner = $input->integer('user_id', 1);
            $this->operationsData->user($owner);
            $key = $input->string('request_key', 16, 80);
            if (!preg_match('/^[a-zA-Z0-9-]+$/D', $key)) {
                throw new HttpError(422, '请求标识无效');
            }
            if (Order::using($this->database)->whereRequestKey($key)->exists()) {
                throw new HttpError(409, '订单已经创建，请刷新列表');
            }
            $items = $input->items('items', 100);
            $total = 0;
            $lines = [];
            if ($items === []) {
                throw new HttpError(422, '至少一项订单明细');
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new HttpError(422, '明细格式无效');
                }
                $line = (new Input($item))->only(['name', 'quantity', 'unit_price']);
                $name = trim($line->string('name', 1, 200));
                if ($name === '') {
                    throw new HttpError(422, '明细名称不能为空');
                }
                $quantity = $line->integer('quantity', 1, 10000);
                $price = $line->integer('unit_price', 0, 100000000);
                $total += $quantity * $price;
                if ($total > 1000000000000) {
                    throw new HttpError(422, '订单金额超限');
                }
                $lines[] = [$name, $quantity, $price];
            }
            $id = Order::using($this->database)->create(['number' => 'LO' . date('YmdHis') . strtoupper(bin2hex(random_bytes(6))), 'user_id' => $owner, 'total' => $total, 'request_key' => $key])->id;
            foreach ($lines as $line) {
                OrderItem::using($this->database)->create(['order_id' => $id, 'name' => $line[0], 'quantity' => $line[1], 'unit_price' => $line[2]]);
            }
            OrderEvent::using($this->database)->create(['order_id' => $id, 'actor_id' => $actor, 'status' => 'pending']);
            $this->admin->audit($actor, 'orders.create', (string)$id, ['total' => $total, 'user_id' => $owner]);
            return ['ok' => true, 'id' => $id];
        }
        $input->only(['action', 'id', 'expected', 'status']);
        if ($action !== 'transition') {
            throw new HttpError(422, '操作无效');
        }
        $id = $input->integer('id', 1);
        $expected = $input->string('expected', 1, 20);
        $status = $input->string('status', 1, 20);
        $allowed = ['pending' => ['processing', 'cancelled'], 'processing' => ['completed', 'cancelled']];
        if (!in_array($status, $allowed[$expected] ?? [], true)) {
            throw new HttpError(422, '不允许的状态变更');
        }
        $changed = Order::using($this->database)->whereId($id)->whereStatus($expected)->update(['status' => $status]);
        if ($changed !== 1) {
            throw new HttpError(409, '订单状态已变化');
        }
        OrderEvent::using($this->database)->create(['order_id' => $id, 'actor_id' => $actor, 'status' => $status]);
        $order = Order::using($this->database)->findOrFail($id)->toArray();
        $this->notifications->emit('order:' . $id . ':' . $status, (int)$order['user_id'], '订单状态更新', '订单 ' . $order['number'] . '：' . (['processing' => '处理中', 'completed' => '已完成', 'cancelled' => '已取消'][$status]));
        $this->admin->audit($actor, 'orders.transition', (string)$id, ['from' => $expected, 'to' => $status]);
        return ['ok' => true];
    }

    public function timers(): array
    {
        return $this->moduleRegistry->configured()->timerDefinitions();
    }

    private function monitor(): array
    {
        $pulses = $this->runtimeStatus->snapshot();
        $services = [];
        foreach (['timer', 'queue', 'scheduler', 'notifications'] as $name) {
            $found = null;
            foreach ($pulses as $pulse) {
                if ($pulse['name'] === $name) {
                    $found = $pulse;
                }
            }
            $services[] = $found ?? ['name' => $name, 'active' => false, 'seen' => null, 'ticks' => 0];
        }
        return ['services' => $services, 'queue' => Job::countsByState($this->database), 'deliveries' => Delivery::countsByState($this->database), 'recent_runs' => ScheduleRun::recent($this->database), 'channels' => $this->notifications->channelStatus(), 'now' => time()];
    }
}
