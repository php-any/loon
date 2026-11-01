<?php

require_once dirname(__DIR__) . '/bootstrap.php';

$level = ob_get_level();
$text = Loom\View::render(__DIR__ . '/fixtures/loom_view/text.php', ['name' => '<Ada>', 'template' => 'not-a-path']);
if (trim($text) !== '<p>&lt;Ada&gt;</p>') {
    throw new RuntimeException('view data or escaping');
}
if (ob_get_level() !== $level) {
    throw new RuntimeException('buffer leaked after success');
}
ob_start();
echo 'outer';
$caught = false;
try {
    Loom\View::render(__DIR__ . '/fixtures/loom_view/failure.php');
} catch (RuntimeException $error) {
    $caught = $error->getMessage() === 'template failed';
}
if (!$caught) {
    throw new RuntimeException('template exception was swallowed');
}
if (ob_get_level() !== $level + 1 || ob_get_clean() !== 'outer') {
    throw new RuntimeException('partial output escaped');
}
$missing = false;
try {
    Loom\View::render(__DIR__ . '/fixtures/loom_view/missing.php');
} catch (LogicException $error) {
    $missing = true;
}
if (!$missing || ob_get_level() !== $level) {
    throw new RuntimeException('missing template contract');
}
echo "loom_view_test: OK\n";
