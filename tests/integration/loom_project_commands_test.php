<?php

declare(strict_types=1);

$scope = (require dirname(__DIR__, 2) . '/framework/bootstrap/autoload.php')(dirname(__DIR__, 2));
$services = $scope->services();
$services->alias(\Loom\Console\CommandRegistrar::class, \App\Console\ApplicationCommands::class);
$services->get(\Loom\Database::class)->connect($services->get(\Loom\Config::class)->get('database.dsn'));
$scope->load('framework/bootstrap/database.php');
$out = '';
$err = '';
$services->instance(\Loom\Console\Output::class, new \Loom\Console\Output(function ($text, $stderr) use (&$out, &$err) {
    if ($stderr) {
        $err .= $text;
    } else {
        $out .= $text;
    }
}));
$console = $services->get(\Loom\Console\ConsoleKernel::class)->console();

function projectCommandCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

try {
    $owner = \Loom\Models\User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'unused']);
    $other = \Loom\Models\User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'unused']);
    $project = \App\Models\Projects\Project::create(['user_id' => $owner->id, 'name' => 'Release']);
    $foreign = \App\Models\Projects\Project::create(['user_id' => $other->id, 'name' => 'Other release']);
    foreach ([$owner->id, $other->id] as $userId) {
        \App\Models\Projects\Task::create(['user_id' => $userId, 'title' => 'Pending', 'completed' => 0]);
    }
    \App\Models\Projects\Task::create(['user_id' => $owner->id, 'project_id' => $project->id, 'title' => 'Accepted', 'completed' => 1]);
    projectCommandCheck($console->run(['projects:tasks', (string) $owner->id, '--json']) === 0, 'List command succeeds: ' . $err);
    projectCommandCheck(count(json_decode($out, true)) === 2, 'Tasks are scoped to owner');
    $out = '';
    projectCommandCheck($console->run(['projects:tasks', (string) $owner->id, '--completed', '--project=' . $project->id, '--json']) === 0, 'Filtered command succeeds');
    $rows = json_decode($out, true);
    projectCommandCheck(count($rows) === 1 && $rows[0]['title'] === 'Accepted', 'Completion and project filters combine');
    projectCommandCheck($console->run(['projects:tasks', (string) $owner->id, '--project=' . $foreign->id]) !== 0, 'Reject another owner project');
    projectCommandCheck($console->run(['projects:tasks', '999999']) === 2, 'Missing user is an input error');
    projectCommandCheck($console->run(['reports:dispatch', (string) $owner->id, '--dry-run']) === 0, 'Report dry run');
    projectCommandCheck(\Loom\Models\Job::query()->count() === 0, 'Dry run does not enqueue');
    projectCommandCheck($console->run(['reports:dispatch', (string) $owner->id, '--delay=-1']) === 2, 'Reject invalid delay');
    $before = time();
    projectCommandCheck($console->run(['reports:dispatch', (string) $owner->id, '--delay=60']) === 0, 'Enqueue report');
    $job = \Loom\Models\Job::whereOwner($owner->id)->firstOrFail();
    projectCommandCheck($job->kind === 'studio.digest' && $job->available >= $before + 60, 'Report retains worker contract and delay');
    echo "loom_project_commands_test: OK\n";
} finally {
    $scope->close();
}
