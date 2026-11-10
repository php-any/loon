<?php
/** @var string $title */
/** @var string $content */
/** @var string $base */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · Loom Monitoring</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; background: #0f1419; color: #e7e9ea; }
        header { padding: 1rem 1.5rem; border-bottom: 1px solid #2f3336; display: flex; gap: 1rem; flex-wrap: wrap; align-items: center; }
        header a { color: #8b98a5; text-decoration: none; }
        header a:hover { color: #e7e9ea; }
        main { padding: 1.5rem; max-width: 1200px; margin: 0 auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        th, td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid #2f3336; vertical-align: top; }
        th { color: #8b98a5; font-weight: 600; }
        code, pre { background: #192734; padding: 0.15rem 0.35rem; border-radius: 4px; font-size: 0.85rem; }
        pre { padding: 0.75rem; overflow-x: auto; white-space: pre-wrap; word-break: break-word; }
        .muted { color: #8b98a5; }
    </style>
</head>
<body>
<header>
    <strong>Loom Monitoring</strong>
    <a href="<?= htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">概览</a>
    <a href="<?= htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/http">HTTP</a>
    <a href="<?= htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/console">Console</a>
    <a href="<?= htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/sql">SQL</a>
    <a href="<?= htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/queue">Queue</a>
    <a href="<?= htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/schedule">Schedule</a>
    <a href="<?= htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/events">Events</a>
</header>
<main>
    <h1><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
    <?= $content ?>
</main>
</body>
</html>
