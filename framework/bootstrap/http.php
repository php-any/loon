<?php

declare(strict_types=1);
$modules = $container->get(\Loom\ModuleRegistry::class)->configured();
$modules->services($container);
$app = $modules->application($container);
$app->middleware(\Loom\Http\Middleware\RequestLog::class);
return $app;
