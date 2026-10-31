<?php
declare(strict_types=1);

use App\Http\Controllers\Studio;

$app->get('/studio', Studio\PageController::class, 'studio');
$app->group('/api/studio', function ($routes) {
    $routes->post('/register', Studio\RegisterController::class);
    $routes->post('/login', Studio\LoginController::class);
    $routes->post('/logout', Studio\LogoutController::class);
    $routes->get('/state', Studio\StateController::class, 'studio.state');
    $routes->post('/tasks', Studio\CreateTaskController::class);
    $routes->patch('/tasks/{id}', Studio\UpdateTaskController::class, 'studio.task');
    $routes->delete('/tasks/{id}', Studio\DeleteTaskController::class);
    $routes->post('/reports', Studio\DispatchReportController::class);
    $routes->post('/projects', Studio\CreateProjectController::class);
    $routes->post('/cron/preview', Studio\PreviewCronController::class);
    $routes->post('/cron', Studio\SaveCronController::class);
    $routes->post('/migrations/demo', Studio\DemoMigrationController::class);
    $routes->post('/schedule', Studio\UpdateScheduleController::class);
    $routes->post('/jobs/{id}/retry', Studio\RetryJobController::class);
});
