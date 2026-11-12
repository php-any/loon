<?php

declare(strict_types=1);

namespace App\Models\Im;

#[\Database\Annotation\Table('im_conversations')]
final class Conversation extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('id', false)]
    public int $id;

    #[\Database\Annotation\Column('type', false)]
    public string $type;

    #[\Database\Annotation\Column('title', true)]
    public ?string $title = null;

    #[\Database\Annotation\GeneratedValue]
    #[\Database\Annotation\Column('created_at', false)]
    public int $created_at;

    #[\Database\Annotation\Column('updated_at', false)]
    public int $updated_at;
}
