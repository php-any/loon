<?php
declare(strict_types=1);

namespace App\Http\Controllers\Studio;

use Loom\Action;
use Loom\Request;
use Loom\Response;

final class DemoMigrationController implements Action
{
    public function __invoke(Request $request): Response
    {
        return Response::json(\Loom\Host::call(['op' => 'migration.demo']));
    }
}
