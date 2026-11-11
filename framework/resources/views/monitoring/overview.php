<?php
/** @var list<array<string, mixed>> $sessions */
/** @var string $base */
/** @var array<string, string> $filters */
/** @var array<string, int> $counts */
/** @var int $page */
/** @var int $per_page */
/** @var int $total */
$baseEsc = htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$typeLabels = [
    'http' => 'HTTP',
    'console' => 'Console',
    'sql' => 'SQL',
    'queue' => 'Queue',
    'cache' => 'Cache',
    'schedule' => 'Schedule',
    'tcp' => 'TCP',
    'grpc' => 'gRPC',
    'events' => 'Events',
    'filesystem' => 'Filesystem',
];
$list_path = $base;
require __DIR__ . '/filters.php';
?>
<h2>各类型条目数量</h2>
<table style="margin-bottom:1.5rem;">
    <thead><tr><th>类型</th><th>条目数</th><th>列表</th></tr></thead>
    <tbody>
    <?php foreach ($typeLabels as $key => $label): ?>
        <tr>
            <td><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= (int)($counts[$key] ?? 0) ?></td>
            <td><a href="<?= $baseEsc ?>/<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">查看</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<p class="muted">最近会话（database 驱动）。点击 ID 查看时间线，或按 correlation 串联。</p>
<table>
    <thead>
    <tr>
        <th>ID</th>
        <th>通道</th>
        <th>关联 ID</th>
        <th>耗时 ms</th>
        <th>标签</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($sessions as $row): ?>
        <tr>
            <td><a href="<?= $baseEsc ?>/sessions/<?= (int)($row['id'] ?? 0) ?>"><?= (int)($row['id'] ?? 0) ?></a></td>
            <td><?= htmlspecialchars((string)($row['channel'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td>
                <?php $cid = (string)($row['correlation_id'] ?? ''); ?>
                <?php if ($cid !== ''): ?>
                    <a href="<?= $baseEsc ?>/correlation/<?= rawurlencode($cid) ?>"><code><?= htmlspecialchars($cid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></a>
                <?php else: ?>
                    <span class="muted">—</span>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars((string)($row['duration_ms'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><code><?= htmlspecialchars(json_encode($row['tags'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($sessions === []): ?>
        <tr><td colspan="5" class="muted">暂无数据。启用 driver=database 并执行 migrate:up。</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php
$list_path = $base;
require __DIR__ . '/pagination.php';
