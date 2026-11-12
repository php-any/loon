<?php

declare(strict_types=1);

namespace App\Queries\Im;

final class ConversationRow
{
    public int $id;
    public string $type;
    public ?string $title;
    public int $updated_at;
    public ?int $last_read_message_id;
}
