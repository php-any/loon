<?php

declare(strict_types=1);

namespace App\Queries\Notifications;

final class Message
{
    public int $id;
    public string $event_key;
    public int $user_id;
    public string $title;
    public string $body;
    public string $email;

    public function toArray(): array
    {
        return ['id' => (int)$this->id, 'event_key' => $this->event_key, 'user_id' => (int)$this->user_id, 'title' => $this->title, 'body' => $this->body, 'email' => $this->email];
    }
}
