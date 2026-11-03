<?php

declare(strict_types=1);
$modules = $services->get(\Loom\ModuleRegistry::class)->configured();
$modules->services($services);
$app = $modules->application($services);
$app->middleware(\Loom\Http\Middleware\RequestLog::class);
return $app;
