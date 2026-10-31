<?php
declare(strict_types=1);

use App\Http\Controllers\Studio;
use App\Http\Middleware\RequireStudioUser;
use App\Http\Middleware\ParseJsonInput;

$app->get('/studio', Studio\PageController::class, 'studio');
$app->prefix('/api/studio')->group(function ($routes) {
    $routes->withMiddleware(ParseJsonInput::class)->group(function ($routes) {
        $routes->post('/register', Studio\RegisterController::class);
        $routes->post('/login', Studio\LoginController::class);
    });

    $routes->withMiddleware(RequireStudioUser::class)->group(function ($routes) {
        $routes->post('/logout', Studio\LogoutController::class);
        $routes->get('/state', Studio\StateController::class, 'studio.state');
        $routes->delete('/tasks/{id}', Studio\DeleteTaskController::class);
        $routes->post('/migrations/demo', Studio\DemoMigrationController::class);
        $routes->post('/jobs/{id}/retry', Studio\RetryJobController::class);

        $routes->withMiddleware(ParseJsonInput::class)->group(function ($routes) {
            $routes->post('/tasks', Studio\CreateTaskController::class);
            $routes->patch('/tasks/{id}', Studio\UpdateTaskController::class, 'studio.task');
            $routes->post('/reports', Studio\DispatchReportController::class);
            $routes->post('/projects', Studio\CreateProjectController::class);
            $routes->post('/cron/preview', Studio\PreviewCronController::class);
            $routes->post('/cron', Studio\SaveCronController::class);
            $routes->post('/schedule', Studio\UpdateScheduleController::class);
        });
    });
});
