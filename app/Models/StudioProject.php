<?php
declare(strict_types=1);

namespace App\Models;

use Loom\Model;
use Loom\Query;

final class StudioProject extends Model
{
    protected static string $table = 'studio_projects';
    protected static array $columns = ['id', 'user_id', 'name', 'created'];
    protected static array $fillable = ['user_id', 'name'];
    protected static array $casts = ['id' => 'int', 'user_id' => 'int', 'created' => 'int'];
    public function tasks(): Query { return StudioTask::query()->where('user_id', '=', $this->get('user_id'))->where('project_id', '=', $this->get('id')); }
}
