<?php
declare(strict_types=1);

namespace App\Http\Controllers\Studio;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use Loom\HttpError;
use App\Models\StudioProject;

final class CreateProjectController implements Action
{
    public function __invoke(Request $request): Response
    {
        $user = $request->attribute('user'); $input = $request->attribute('input')->only(['name']); $name = trim($input->string('name', 1, 120));
        if ($name === '') { throw new HttpError(422, '请输入项目名称'); }
        if (StudioProject::query()->where('user_id', '=', $user['id'])->where('name', '=', $name)->count() > 0) { throw new HttpError(409, '项目名称已存在'); }
        return Response::json(StudioProject::create(['user_id' => $user['id'], 'name' => $name])->toArray(), 201);
    }
}
