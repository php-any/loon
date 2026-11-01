<?php

declare(strict_types=1);

namespace App\Models\Notifications;

#[\Database\Annotation\Table('admin_channels')]
final class Channel extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('channel', false)]
    public string $channel;

    #[\Database\Annotation\Column('enabled', false)]
    public int $enabled = 0;

    #[\Database\Annotation\Column('url', false)]
    public string $url = '';

    #[\Database\Annotation\Column('secret_env', false)]
    public string $secret_env = '';
}
