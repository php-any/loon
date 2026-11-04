<?php

declare(strict_types=1);
$migrator = $container->get(\Loom\Migrations::class);
$migrator->initialize();
$migrations = $config->get('migrations');
$migrator->run($migrations['namespace'], $migrator->files($config->path($migrations['path'])));
