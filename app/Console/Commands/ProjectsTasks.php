<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\ConsoleUser;
use App\Models\Projects\Project;
use App\Models\Projects\Task;
use Loom\Console\Command;
use Loom\Console\InputError;

#[\Loom\Attributes\ConsoleCommand]
final class ProjectsTasks extends Command
{
    protected string $signature = 'projects:tasks {user : 用户 ID} {--completed : 仅显示已完成任务} {--P|project= : 按项目 ID 筛选} {--json : 输出 JSON}';
    protected string $description = '查询用户的项目任务';

    public function handle(): int
    {
        $id = ConsoleUser::requireId($this->argument('user'));
        $query = Task::whereUserId($id);
        if ($this->option('completed')) {
            $query = $query->where('completed', '=', 1);
        }
        if ($this->option('project') !== null) {
            $project = $this->option('project');
            if (!ctype_digit($project) || (int) $project < 1) {
                throw new InputError('project 必须是正整数');
            }
            Project::whereUserId($id)->whereId((int) $project)->firstOrFail();
            $query = $query->where('project_id', '=', (int) $project);
        }
        $rows = $query->orderBy('id', 'DESC')->rows();
        if ($this->option('json')) {
            $this->json($rows);
            return 0;
        }
        $table = [];
        foreach ($rows as $task) {
            $table[] = [$task['id'], $task['completed'] ? '已完成' : '待完成', $task['title']];
        }
        $this->table(['编号', '状态', '任务'], $table);
        $this->line(count($rows) . ' 个任务');
        return 0;
    }
}
