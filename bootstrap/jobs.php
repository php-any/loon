<?php
declare(strict_types=1);

// 后台入口与 HTTP 入口分开，每次任务由独立 RequestVM 执行。
require_once __DIR__ . '/autoload.php';
return (new \App\Jobs\Digest())->handle(\Loom\Request::capture())->toArray();
