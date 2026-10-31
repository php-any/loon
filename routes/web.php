<?php
declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\HelloController;
use App\Http\Controllers\CreateGreetingController;
use App\Http\Controllers\PlaygroundController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\IsolationProbeController;

require __DIR__ . '/studio.php';
$app->route('GET', '/', HomeController::class);
$app->route('GET', '/welcome', WelcomeController::class);
$app->route('GET', '/playground', PlaygroundController::class);
$app->route('GET', '/guide', GuideController::class);
$app->route('GET', '/api/isolation', IsolationProbeController::class);
$app->route('GET', '/hello/{name}', HelloController::class);
$app->route('POST', '/greetings', CreateGreetingController::class);
require __DIR__ . '/websocket.php';
