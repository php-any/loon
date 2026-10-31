<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use Loom\Action;
use Loom\Request;
use Loom\Response;
use App\Services\GreetingService;

final class CreateGreetingController implements Action
{
    private GreetingService $service;
    public function __construct(GreetingService $service) { $this->service = $service; }
    public function __invoke(Request $request): Response
    {
        $input = $request->attribute('input')->only(['name', 'times']);
        $name = $input->string('name', 1, 80);
        $times = $input->integer('times', 1, 10);
        return Response::json($this->service->greet($name, $times), 201);
    }
}
