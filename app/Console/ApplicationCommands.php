<?php

declare(strict_types=1);

namespace App\Console;

use App\Models\Projects\Task;
use App\Models\Projects\Project;
use Loom\Console\Input;
use Loom\Console\Output;
use Loom\Console\InputError;
use Loom\Database;
use Loom\Models\User;
use Loom\Queue;

use Loom\Console\Console;
use Loom\Console\CommandRegistrar;

final class ApplicationCommands implements CommandRegistrar
{
    public function register(Console $console): void
    {
        $console->command('projects:tasks {user : 用户 ID} {--completed : 仅显示已完成任务} {--P|project= : 按项目 ID 筛选} {--json : 输出 JSON}', function (Input $input, Output $output) use ($console) {
            $database = $console->services()->get(Database::class);
            $id = self::consoleUser($input->argument('user'), $database);
            $query = Task::using($database)->whereUserId($id);
            if ($input->option('completed')) {
                $query = $query->where('completed', '=', 1);
            }
            if ($input->option('project') !== null) {
                $project = $input->option('project');
                if (!ctype_digit($project) || (int) $project < 1) {
                    throw new InputError('project 必须是正整数');
                }
                Project::using($database)->whereUserId($id)->whereId((int) $project)->firstOrFail();
                $query = $query->where('project_id', '=', (int) $project);
            }
            $rows = $query->orderBy('id', 'DESC')->rows();
            if ($input->option('json')) {
                $output->json($rows);
                return 0;
            }
            $table = [];
            foreach ($rows as $task) {
                $table[] = [$task['id'], $task['completed'] ? '已完成' : '待完成', $task['title']];
            }
            $output->table(['编号', '状态', '任务'], $table);
            $output->line(count($rows) . ' 个任务');
            return 0;
        }, '查询用户的项目任务');

        $console->command('reports:dispatch {user : 用户 ID} {--delay=0 : 延迟秒数，0–86400} {--dry-run : 只检查，不入队}', function (Input $input, Output $output) use ($console) {
            $id = self::consoleUser($input->argument('user'), $console->services()->get(Database::class));
            $delay = $input->option('delay');
            if (!ctype_digit($delay) || (int) $delay > 86400) {
                throw new InputError('delay 必须是 0–86400 的整数');
            }
            if ($input->option('dry-run')) {
                $output->info('检查通过：将为用户 ' . $id . ' 生成摘要，延迟 ' . $delay . ' 秒。');
                return 0;
            }
            $job = $console->services()->get(Queue::class)->dispatch('studio.digest', [], $id, (int) $delay);
            $output->info('已入队 Job #' . $job . '；由运行中的 队列消费者 执行。');
            return 0;
        }, '把进展摘要交给持久化队列');
    }

    private static function consoleUser(string $value, Database $database): int
    {
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new InputError('user 必须是正整数');
        }
        $id = (int) $value;
        if (User::using($database)->find($id) === null) {
            throw new InputError('用户不存在：' . $value);
        }
        return $id;
    }
}
