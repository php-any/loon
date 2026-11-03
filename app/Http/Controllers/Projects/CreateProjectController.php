<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use Loom\Action;
use Loom\Database;
use Loom\Request;
use Loom\Response;
use Loom\HttpError;
use App\Models\Projects\Project;

#[\Loom\Attributes\Controller]
#[\Loom\Attributes\Route('POST', '/api/studio/projects', '', [\App\Http\Middleware\RequireWorkspaceAccess::class, \Loom\Http\Middleware\ParseJsonInput::class])]
final class CreateProjectController implements Action
{
    public function __construct(private Database $database)
    {
    }

    public function __invoke(Request $request): Response
    {
        $user = $request->attribute('user');
        $input = $request->attribute('input')->only(['name']);
        $name = trim($input->string('name', 1, 120));
        if ($name === '') {
            throw new HttpError(422, '请输入项目名称');
        }
        if (Project::using($this->database)->whereUserId($user['id'])->where('name', '=', $name)->count() > 0) {
            throw new HttpError(409, '项目名称已存在');
        }
        $project = Project::using($this->database)->create(['user_id' => $user['id'], 'name' => $name]);
        return Response::json($project->toArray(), 201);
    }
}
