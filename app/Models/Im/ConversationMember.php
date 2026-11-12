<?php

declare(strict_types=1);

namespace App\Models\Im;

#[\Database\Annotation\Table('im_conversation_members')]
final class ConversationMember extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('conversation_id', false)]
    public int $conversation_id;

    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('user_id', false)]
    public int $user_id;

    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('joined_at', false)]
    public int $joined_at;

    #[\Database\Annotation\Column('last_read_message_id', true)]
    public ?int $last_read_message_id = null;
}
