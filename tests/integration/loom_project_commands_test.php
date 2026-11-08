<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/framework/bootstrap.php';

function projectCommandCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

// 命令的标准输出和标准错误只能在子进程里收集，因此通过 loom.php 执行，数据准备和断言在本进程的作用域里完成。
function projectCommand(\Loom\ServiceScope $scope, array $args): array
{
    return $scope->get(\Loom\Process::class)->run(array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/loom.php'], $args), 30);
}

$app = (static function () { return require dirname(__DIR__, 2) . '/loom.php'; })();
try {
    $app->services->withScope(static function (\Loom\ServiceScope $scope): void {
        $scope->get(\Loom\Migrations::class)->migrate();
        $owner = \Loom\Models\User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'unused']);
        $other = \Loom\Models\User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'unused']);
        $project = \App\Models\Projects\Project::create(['user_id' => $owner->id, 'name' => 'Release']);
        $foreign = \App\Models\Projects\Project::create(['user_id' => $other->id, 'name' => 'Other release']);
        foreach ([$owner->id, $other->id] as $userId) {
            \App\Models\Projects\Task::create(['user_id' => $userId, 'title' => 'Pending', 'completed' => 0]);
        }
        \App\Models\Projects\Task::create(['user_id' => $owner->id, 'project_id' => $project->id, 'title' => 'Accepted', 'completed' => 1]);
        $result = projectCommand($scope, ['projects:tasks', (string) $owner->id, '--json']);
        projectCommandCheck($result['code'] === 0, 'List command succeeds: ' . $result['stderr']);
        projectCommandCheck(count(json_decode($result['stdout'], true)) === 2, 'Tasks are scoped to owner');
        $result = projectCommand($scope, ['projects:tasks', (string) $owner->id, '--completed', '--project=' . $project->id, '--json']);
        projectCommandCheck($result['code'] === 0, 'Filtered command succeeds: ' . $result['stderr']);
        $rows = json_decode($result['stdout'], true);
        projectCommandCheck(count($rows) === 1 && $rows[0]['title'] === 'Accepted', 'Completion and project filters combine');
        projectCommandCheck(projectCommand($scope, ['projects:tasks', (string) $owner->id, '--project=' . $foreign->id])['code'] !== 0, 'Reject another owner project');
        $result = projectCommand($scope, ['projects:tasks', '999999']);
        projectCommandCheck($result['code'] === 2 && str_contains($result['stderr'], '用户不存在'), 'Missing user is an input error');
        $result = projectCommand($scope, ['reports:dispatch', (string) $owner->id, '--dry-run']);
        projectCommandCheck($result['code'] === 0 && str_contains($result['stdout'], '检查通过'), 'Report dry run');
        projectCommandCheck(\Loom\Models\Job::query()->count() === 0, 'Dry run does not enqueue');
        projectCommandCheck(projectCommand($scope, ['reports:dispatch', (string) $owner->id, '--delay=-1'])['code'] === 2, 'Reject invalid delay');
        $before = time();
        projectCommandCheck(projectCommand($scope, ['reports:dispatch', (string) $owner->id, '--delay=60'])['code'] === 0, 'Enqueue report');
        $job = \Loom\Models\Job::whereOwner($owner->id)->firstOrFail();
        projectCommandCheck($job->kind === 'studio.digest' && $job->available >= $before + 60, 'Report retains worker contract and delay');
    });
    echo "loom_project_commands_test: OK\n";
} finally {
    $app->dispose();
}
