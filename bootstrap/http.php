<?php
declare(strict_types=1);

use App\Http\Controllers\CreateGreetingController;
use App\Services\GreetingService;

require_once __DIR__ . '/autoload.php';

$app = new \Loom\Application();
$app->services()->bind(CreateGreetingController::class, function ($services) {
    return new CreateGreetingController($services->get(GreetingService::class));
});
require dirname(__DIR__) . '/routes/web.php';
return $app->handle(\Loom\Request::capture())->toArray();
