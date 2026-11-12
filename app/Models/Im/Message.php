<?php

declare(strict_types=1);

namespace App\Models\Im;

#[\Database\Annotation\Table('im_messages')]
final class Message extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('conversation_id', false)]
    public int $conversation_id;

    #[\Database\Annotation\Column('user_id', false)]
    public int $user_id;

    #[\Database\Annotation\Column('body', false)]
    public string $body;

    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('created_at', false)]
    public int $created_at;
}
