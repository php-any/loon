<?php

declare(strict_types=1);

// 后台入口与 HTTP 分开，每次任务由独立 Origami 进程执行。
$services->get(\Loom\ModuleRegistry::class)->configured()->services($services);
$request = $services->get(\Loom\RequestContext::class)->current();
$job = json_decode($request->body(), true, 64, JSON_THROW_ON_ERROR);
return $services->get(\Loom\ModuleRegistry::class)->configured()->job($job['kind'], $request, $services)->toArray();
