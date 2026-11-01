<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7f6f2">
    <meta name="description" content="Loom 通用型后台，为不同业务提供可扩展的管理工作台。了解账号认证、项目任务、队列调度等现有能力，体验后台并查看开发指南。">
    <title><?php echo \Loom\escape($data['title']); ?> — Loom</title>
    <link rel="icon" href="/assets/mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/official/site.css">
    <script src="/assets/official/site.js" defer></script>
</head>
<body>
<a class="skip-link" href="#main">跳转到内容</a>
<header class="site-header wrap">
    <a class="brand" href="/" aria-label="Loom 首页"><svg viewBox="0 0 32 32" aria-hidden="true"><path d="M3 5h12v10h14v12H3z" fill="currentColor"/><path d="M15 5v10H3M15 15l14 12" fill="none" stroke="var(--paper)" stroke-width="1.3"/></svg><span>loom<span class="brand-dot">.</span></span></a>
    <nav aria-label="主导航">
        <a href="/" <?php if ($data['page'] === 'home') { echo 'aria-current="page"'; } ?>>概览</a>
        <a href="/guide" <?php if ($data['page'] === 'guide') { echo 'aria-current="page"'; } ?>>开发指南</a>
        <a href="/admin" target="_blank" rel="noopener noreferrer">管理员入口 ↗</a>
    </nav>
    <a class="header-cta" href="/account">用户登录 / 注册 <span aria-hidden="true">→</span></a>
</header>
<main id="main"><?php echo $data['content']; ?></main>
<footer class="site-footer wrap">
    <a class="footer-wordmark" href="/">loom.</a>
    <p>让业务与体验，都井然有序。</p>
    <div><span class="mono">LOOM / OFFICIAL</span><a href="/admin" target="_blank" rel="noopener noreferrer">管理员入口 ↗</a></div>
</footer>
<div id="toast" class="toast" role="status" aria-live="polite" hidden></div>
</body>
</html>

