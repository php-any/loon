<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#142334">
    <title><?php echo \Loom\escape($data['title']); ?> — Loom 发布验收</title>
    <link rel="icon" href="/assets/mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/demo/base.css">
    <link rel="stylesheet" href="/assets/demo/site.css">
    <?php if ($data['page'] === 'release') { ?><link rel="stylesheet" href="/assets/demo/release.css"><script src="/assets/demo/release.js" defer></script><?php } ?>
</head>
<body class="demo-site">
<a class="skip-link" href="#main">跳转到内容</a>
<aside class="demo-sidebar">
    <a class="demo-brand" href="/demo"><span class="demo-symbol">L</span><strong>loom<span> / demo</span></strong></a>
    <div class="demo-nav-label">发布验收场景</div>
    <nav aria-label="发布验收导航">
        <a href="/demo" aria-current="page"><span>01</span>发布计划与验收</a>
        <a href="/demo#release-delivery"><span>02</span>摘要交付</a>
        <a href="/demo#release-review"><span>03</span>自动回顾</a>
    </nav>
    <div class="demo-nav-label">Loom 后台</div>
    <nav aria-label="后台入口"><a href="/admin">进入管理后台</a><a href="/guide">开发指南</a></nav>
    <div class="demo-sidebar-bottom"><p>每一次操作，都连接<br>正在运行的应用。</p><a href="/">← 返回官网</a><a href="/guide">开发指南 ↗</a></div>
</aside>
<div class="demo-surface">
    <header class="demo-topbar"><span>LOOM <b>/</b> RELEASE ACCEPTANCE</span><a href="/">官网与文档 ↗</a></header>
    <main id="main" class="demo-content"><?php echo $data['content']; ?></main>
    <footer class="demo-footer"><span>LOOM DEMO · 真实服务 / 持久化数据</span><span>HTTP · TCP · gRPC</span></footer>
</div>
<div id="toast" class="toast" role="status" aria-live="polite" hidden></div>
</body>
</html>
