<?php

$runtimeScope = (require dirname(__DIR__, 3) . '/framework/bootstrap/autoload.php')(__DIR__);
$runtimeServices = $runtimeScope->services();
$runtimeServices->get(\Loom\Server::class)->run();
