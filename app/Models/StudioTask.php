<?php
declare(strict_types=1);

namespace App\Models;

use Loom\Model;
use Loom\Query;

final class StudioTask extends Model
{
    protected static string $table = 'studio_tasks';
    protected static array $columns = ['id', 'user_id', 'title', 'completed', 'created', 'project_id'];
    protected static array $fillable = ['user_id', 'title', 'completed', 'project_id'];
    protected static array $casts = ['id' => 'int', 'user_id' => 'int', 'project_id' => 'int', 'completed' => 'int', 'created' => 'int'];
    public function project(): ?Model { return $this->get('project_id') === null ? null : StudioProject::query()->where('id', '=', $this->get('project_id'))->where('user_id', '=', $this->get('user_id'))->first(); }
}
