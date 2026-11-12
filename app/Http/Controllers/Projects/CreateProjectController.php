<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use Loom\Request;
use Loom\Response;
use Loom\HttpError;
use App\Models\Projects\Project;

#[\Net\Annotation\Controller]
#[\Net\Annotation\Middleware(\App\Http\Middleware\RequireWorkspaceAccess::class)]
#[\Net\Annotation\Middleware(\Loom\Http\Middleware\ParseJsonInput::class)]
final class CreateProjectController
{
    #[\Net\Annotation\PostMapping('/api/studio/projects')]
    public function create(Request $request): Response
    {
        $user = $request->attribute('user');
        $input = $request->attribute('input')->only(['name']);
        $name = trim($input->string('name', 1, 120));
        if ($name === '') {
            throw new HttpError(422, '请输入项目名称');
        }
        if (Project::whereUserId($user['id'])->where('name', '=', $name)->count() > 0) {
            throw new HttpError(409, '项目名称已存在');
        }
        $project = Project::create(['user_id' => $user['id'], 'name' => $name]);
        return Response::json($project->toArray(), 201);
    }
}
