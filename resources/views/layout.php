<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7f6f2">
    <meta name="description" content="Loom，一个正在生长的 PHP 框架。以清晰的 Action、隔离的执行和优雅的表达，让想法成为应用。">
    <title><?php echo \Loom\escape($data['title']); ?> — Loom</title>
    <link rel="icon" href="/assets/mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
    <link rel="stylesheet" href="/assets/studio.css">
    <script src="/assets/studio.js" defer></script>
</head>
<body>
<a class="skip-link" href="#main">跳转到内容</a>
<header class="site-header wrap">
    <a class="brand" href="/" aria-label="Loom 首页"><svg viewBox="0 0 32 32" aria-hidden="true"><path d="M3 5h12v10h14v12H3z" fill="currentColor"/><path d="M15 5v10H3M15 15l14 12" fill="none" stroke="var(--paper)" stroke-width="1.3"/></svg><span>loom<span class="brand-dot">.</span></span></a>
    <nav aria-label="主导航">
        <a href="/" <?php if ($data['page'] === 'home') { echo 'aria-current="page"'; } ?>>概览</a>
        <a href="/playground" <?php if ($data['page'] === 'playground') { echo 'aria-current="page"'; } ?>>交互实验室</a>
        <a href="/studio" <?php if ($data['page'] === 'studio') { echo 'aria-current="page"'; } ?>>Studio 工作台</a>
        <a href="/guide" <?php if ($data['page'] === 'guide') { echo 'aria-current="page"'; } ?>>开发指南</a>
    </nav>
    <a class="header-cta" href="/guide#start">开始构建 <span aria-hidden="true">↗</span></a>
</header>
<main id="main"><?php echo $data['content']; ?></main>
<footer class="site-footer wrap">
    <a class="footer-wordmark" href="/">loom.</a>
    <p>让代码清晰，让创造自由。</p>
    <div><span class="mono">BUILT ON ORIGAMI</span><span class="status-dot"></span> 开发预览</div>
</footer>
<div id="toast" class="toast" role="status" aria-live="polite" hidden></div>
</body>
</html>
