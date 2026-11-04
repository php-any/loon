<?php

declare(strict_types=1);

// 后台入口与 HTTP 分开，每次任务由独立 Origami 进程执行。
$container->get(\Loom\ModuleRegistry::class)->configured()->services($container);
$request = $container->get(\Loom\RequestContext::class)->current();
$job = json_decode($request->body(), true, 64, JSON_THROW_ON_ERROR);
return $container->get(\Loom\ModuleRegistry::class)->configured()->job($job['kind'], $request, $container)->toArray();
