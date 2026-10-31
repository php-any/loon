<?php
declare(strict_types=1);

use App\Http\Controllers\Studio;
use App\Http\Middleware\RequireStudioUser;
use App\Http\Middleware\ParseJsonInput;

$app->get('/studio', Studio\PageController::class, 'studio');
$app->group('/api/studio', function ($routes) {
    $routes->post('/register', Studio\RegisterController::class, '', [ParseJsonInput::class]);
    $routes->post('/login', Studio\LoginController::class, '', [ParseJsonInput::class]);
    $routes->group('', function ($routes) {
        $routes->post('/logout', Studio\LogoutController::class);
        $routes->get('/state', Studio\StateController::class, 'studio.state');
        $routes->post('/tasks', Studio\CreateTaskController::class, '', [ParseJsonInput::class]);
        $routes->patch('/tasks/{id}', Studio\UpdateTaskController::class, 'studio.task', [ParseJsonInput::class]);
        $routes->delete('/tasks/{id}', Studio\DeleteTaskController::class);
        $routes->post('/reports', Studio\DispatchReportController::class, '', [ParseJsonInput::class]);
        $routes->post('/projects', Studio\CreateProjectController::class, '', [ParseJsonInput::class]);
        $routes->post('/cron/preview', Studio\PreviewCronController::class, '', [ParseJsonInput::class]);
        $routes->post('/cron', Studio\SaveCronController::class, '', [ParseJsonInput::class]);
        $routes->post('/migrations/demo', Studio\DemoMigrationController::class);
        $routes->post('/schedule', Studio\UpdateScheduleController::class, '', [ParseJsonInput::class]);
        $routes->post('/jobs/{id}/retry', Studio\RetryJobController::class);
    }, [RequireStudioUser::class]);
});
