<?php

declare(strict_types=1);

namespace App\Queries\Notifications;

final class DeliveryListItem
{
    public int $id;
    public int $notification_id;
    public string $channel;
    public string $state;
    public int $attempts;
    public ?string $error;
    public int $created;
    public int $user_id;
    public string $title;

    public function toArray(): array
    {
        return ['id' => (int)$this->id, 'notification_id' => (int)$this->notification_id, 'channel' => $this->channel, 'state' => $this->state, 'attempts' => (int)$this->attempts, 'error' => $this->error, 'created' => (int)$this->created, 'user_id' => (int)$this->user_id, 'title' => $this->title];
    }
}
