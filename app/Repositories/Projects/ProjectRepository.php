<?php

declare(strict_types=1);

namespace App\Repositories\Projects;

// 关联同时保留外键约束与租户归属约束。
final class ProjectRepository
{
    public function __construct(private \Loom\Database $database)
    {
    }

    public function tasksFor(\App\Models\Projects\Project $project): \Loom\ModelQuery
    {
        if (!isset($project->id)) {
            throw new \LogicException('Project has not been persisted');
        }
        return \App\Models\Projects\Task::using($this->database)->whereUserId($project->user_id)->where('project_id', '=', $project->id);
    }
    public function projectFor(\App\Models\Projects\Task $task): ?\App\Models\Projects\Project
    {
        return $task->project_id === null ? null : \App\Models\Projects\Project::using($this->database)->whereId($task->project_id)->whereUserId($task->user_id)->first();
    }
}
