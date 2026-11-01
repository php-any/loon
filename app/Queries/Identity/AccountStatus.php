<?php

declare(strict_types=1);

namespace App\Queries\Identity;

final class AccountStatus
{
    public int $id;
    public ?int $enabled;
    public ?int $verified_at;

    public function toArray(): array
    {
        return ['id' => (int)$this->id, 'enabled' => $this->enabled === null ? null : (int)$this->enabled, 'verified_at' => $this->verified_at === null ? null : (int)$this->verified_at];
    }
}
