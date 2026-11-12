<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Services\Access\Admin;
use Loom\Database;
use Loom\HttpError;
use Loom\Input;
use App\Models\Notifications\Notification;
use App\Models\Notifications\Delivery;
use App\Models\Notifications\Channel;
use App\Models\Notifications\FailureEvent;

final class Notifications
{
    private \Loom\Database $database;
    private \App\Services\Access\Admin $admin;
    private \App\Services\Operations\OperationsData $operationsData;
    private \Loom\Queue $queue;
    private \Loom\Mail\Mailer $mailer;

    public function __construct(\Loom\Database $database, \App\Services\Access\Admin $admin, \App\Services\Operations\OperationsData $operationsData, \Loom\Queue $queue, \Loom\Mail\Mailer $mailer)
    {
        $this->database = $database;
        $this->admin = $admin;
        $this->operationsData = $operationsData;
        $this->queue = $queue;
        $this->mailer = $mailer;
    }

    // 事务由调用方管理；通知与队列任务在提交后才进入 Stream。
    public function emit(string $event, int $user, string $title, string $body): void
    {
        $created = Notification::insertOrIgnore(['event_key' => $event, 'user_id' => $user, 'title' => $title, 'body' => $body], ['event_key', 'user_id']);
        if ($created->rowsAffected !== 1) {
            return;
        }
        // 队列任务在事务提交后 XADD，与通知同行一起回滚。
        $this->queue->dispatch('notification.push', ['notification_id' => $created->insertId], $user);
        foreach (Channel::whereEnabled(1)->rows(['channel']) as $channel) {
            Delivery::create(['notification_id' => $created->insertId, 'channel' => $channel['channel']]);
        }
    }
    public function inbox(int $actor, int $page, string $status = 'all'): array
    {
        if (!in_array($status, ['all', 'unread', 'read'], true)) {
            throw new HttpError(422, '通知状态无效');
        }
        $query = Notification::whereUserId($actor);
        if ($status !== 'all') {
            $query = $query->where('read_at', $status === 'unread' ? '=' : '!=', null);
        }
        $result = $query->orderBy('id', 'DESC')->paginate($page, ['id', 'title', 'body', 'read_at', 'created']);
        $result['unread'] = Notification::whereUserId($actor)->whereReadAt(null)->count();
        return $result;
    }
    public function read(int $actor, Input $input): array
    {
        if ($input->string('action') === 'read_all') {
            $input->only(['action']);
            Notification::whereUserId($actor)->whereReadAt(null)->update(['read_at' => time()]);
            return ['ok' => true];
        }
        $input->only(['action', 'id']);
        if ($input->string('action') !== 'read') {
            throw new HttpError(422, '操作无效');
        }
        $id = $input->integer('id', 1);
        $changed = Notification::whereId($id)->whereUserId($actor)->whereReadAt(null)->update(['read_at' => time()]);
        if ($changed !== 1 && !Notification::whereId($id)->whereUserId($actor)->exists()) {
            throw new HttpError(404, '通知不存在');
        }
        return ['ok' => true];
    }
    public function channelStatus(): array
    {
        $out = [];
        foreach (Channel::orderBy('channel')->rows() as $row) {
            $configured = $row['channel'] === 'mail' ? (getenv('LOOM_SMTP_ADDRESS') !== false && getenv('LOOM_SMTP_ADDRESS') !== '' && getenv('LOOM_SMTP_FROM') !== false && getenv('LOOM_SMTP_FROM') !== '') : ($row['url'] !== '' && $row['secret_env'] !== '' && strlen((string)getenv($row['secret_env'])) >= 32);
            $out[] = ['channel' => $row['channel'], 'enabled' => (bool)$row['enabled'], 'configured' => $configured];
        }
        return $out;
    }
    public function channels(): array
    {
        return ['items' => Channel::orderBy('channel')->rows(['channel', 'enabled', 'url', 'secret_env']), 'status' => $this->channelStatus()];
    }
    public function configure(int $actor, Input $input): array
    {
        $input->only(['action', 'channel', 'enabled', 'url', 'secret_env']);
        if ($input->string('action') !== 'save') {
            throw new HttpError(422, '操作无效');
        }
        $channel = $input->string('channel');
        $enabled = $input->boolean('enabled');
        if (!in_array($channel, ['mail', 'webhook'], true)) {
            throw new HttpError(422, '渠道无效');
        }
        $url = '';
        $secret = '';
        if ($channel === 'webhook') {
            $url = $input->string('url', 0, 2000);
            $secret = $input->string('secret_env', 0, 100);
            if ($enabled || $url !== '') {
                if (!preg_match('/^LOOM_WEBHOOK_SECRET_[A-Z0-9_]+$/D', $secret)) {
                    throw new HttpError(422, '密钥变量必须以 LOOM_WEBHOOK_SECRET_ 开头');
                }
                $checked = json_decode(\Loom\Native\webhook('validate', $url, $secret, ''), true);
                if (!$checked['ok']) {
                    throw new HttpError(422, '需要 HTTPS 公网地址和至少 32 字节的环境密钥');
                }
            }
        } elseif ($enabled && (!(string)getenv('LOOM_SMTP_ADDRESS') || !(string)getenv('LOOM_SMTP_FROM'))) {
            throw new HttpError(422, '请先配置 SMTP 环境变量');
        }
        return $this->database->transaction(function () use ($actor, $channel, $enabled, $url, $secret): array {
            Channel::whereChannel($channel)->update(['enabled' => $enabled ? 1 : 0, 'url' => $url, 'secret_env' => $secret]);
            $this->admin->audit($actor, 'channels.save', $channel, ['enabled' => $enabled]);
            return ['ok' => true];
        });
    }
    public function mutate(int $actor, Input $input): array
    {
        $action = $input->string('action');
        if ($action === 'send') {
            $this->admin->require($actor, 'notifications.send');
            $input->only(['action', 'user_id', 'title', 'body', 'request_key']);
            $user = $input->integer('user_id', 1);
            $this->operationsData->user($user);
            $title = trim($input->string('title', 1, 150));
            $body = trim($input->string('body', 1, 10000));
            $key = $input->string('request_key', 16, 80);
            if ($title === '' || $body === '' || str_contains($title, "\n") || str_contains($title, "\r")) {
                throw new HttpError(422, '标题和内容无效');
            }
            return $this->database->transaction(function () use ($actor, $user, $title, $body, $key): array {
                $this->emit('manual:' . $actor . ':' . $key, $user, $title, $body);
                $this->admin->audit($actor, 'notifications.send', (string)$user);
                return ['ok' => true];
            });
        }
        if ($action !== 'retry') {
            throw new HttpError(422, '操作无效');
        }
        $this->admin->require($actor, 'notifications.retry');
        $input->only(['action', 'id']);
        $id = $input->integer('id', 1);
        return $this->database->transaction(function () use ($actor, $id): array {
            $changed = Delivery::whereId($id)->whereState('failed')->update(['state' => 'pending', 'attempts' => 0, 'available' => time(), 'lease' => null, 'lease_until' => null, 'error' => null]);
            if ($changed !== 1) {
                throw new HttpError(409, '仅失败投递可以重试');
            }
            $this->admin->audit($actor, 'notifications.retry', (string)$id);
            return ['ok' => true];
        });
    }
    public function collectFailures(): void
    {
        $this->database->transaction(function (): void {
            $jobs = FailureEvent::unreportedJobs($this->database);
            if ($jobs === []) {
                return;
            }
            $recipients = [];
            foreach (\App\Models\Identity\Account::enabledUserIds($this->database) as $user) {
                if (Admin::allows($this->admin->grants($user), 'monitor.view')) {
                    $recipients[] = $user;
                }
            }
            foreach ($jobs as $job) {
                foreach ($recipients as $user) {
                    $this->emit('job.failed:' . $job['id'], $user, '任务最终失败', '任务 #' . $job['id'] . ' 已达到失败状态，请在队列管理中查看。');
                }
                FailureEvent::create(['job_id' => (int)$job['id']]);
            }
        });
    }
    public function deliver(?callable $transport = null): array
    {
        $lease = bin2hex(random_bytes(24));
        $rows = Delivery::claim($this->database, $lease);
        if ($rows === []) {
            return ['state' => 'empty'];
        }
        $delivery = $rows[0];
        $ok = false;
        try {
            $message = Notification::message($this->database, (int)$delivery['notification_id']);
            if ($transport !== null) {
                $ok = (bool)$transport($delivery, $message);
            } elseif ($delivery['channel'] === 'mail') {
                $ok = $this->mailer->send($this->mailer->defaultTransport(), $message['email'], $message['title'], $message['body']);
            } else {
                $channel = Channel::whereChannel('webhook')->firstOrFail()->toArray();
                $body = json_encode(['event_id' => 'notification-' . $message['id'], 'user_id' => (int)$message['user_id'], 'title' => $message['title'], 'body' => $message['body']], JSON_THROW_ON_ERROR);
                $ok = (bool)json_decode(\Loom\Native\webhook('send', $channel['url'], $channel['secret_env'], $body), true)['ok'];
            }
        } catch (\Throwable $error) {
            $ok = false;
        }
        $state = $ok ? 'sent' : ((int)$delivery['attempts'] >= 3 ? 'failed' : 'pending');
        $changed = Delivery::whereId($delivery['id'])->whereLease($lease)->update(['state' => $state, 'available' => time() + 30 * (int)$delivery['attempts'], 'lease' => null, 'lease_until' => null, 'error' => $ok ? null : '渠道投递失败，请检查服务配置或接收端']);
        return ['id' => (int)$delivery['id'], 'state' => $changed === 1 ? $state : 'stale'];
    }
}
