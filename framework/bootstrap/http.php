<?php

declare(strict_types=1);
$modules = $services->get(\Loom\ModuleRegistry::class)->configured();
$modules->services($services);
$app = new \Loom\Application($services);
$app->middleware(\Loom\Http\Middleware\RequestLog::class);
$modules->routes($app);
return $app;
