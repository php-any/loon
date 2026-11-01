<?php

declare(strict_types=1);

namespace App\Queries\Identity;

final class AccountListItem
{
    public int $id;
    public string $name;
    public string $email;
    public int $enabled;
    public ?int $verified_at;

    public function toArray(): array
    {
        return ['id' => (int)$this->id, 'name' => $this->name, 'email' => $this->email, 'enabled' => (int)$this->enabled, 'verified_at' => $this->verified_at === null ? null : (int)$this->verified_at];
    }
}
