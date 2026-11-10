<?php
/** @var list<array<string, mixed>> $sessions */
/** @var string $base */
/** @var array<string, string> $filters */
$tag = htmlspecialchars($filters['tag'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$correlation = htmlspecialchars($filters['correlation_id'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$path = htmlspecialchars($filters['path'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$status = htmlspecialchars($filters['status'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$baseEsc = htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<form method="get" action="<?= $baseEsc ?>" style="margin-bottom:1rem;display:flex;flex-wrap:wrap;gap:0.5rem;align-items:flex-end;">
    <label>tag <input name="tag" value="<?= $tag ?>" size="12" /></label>
    <label>correlation_id <input name="correlation_id" value="<?= $correlation ?>" size="24" /></label>
    <label>path <input name="path" value="<?= $path ?>" size="16" /></label>
    <label>status <input name="status" value="<?= $status ?>" size="6" /></label>
    <button type="submit">筛选</button>
</form>
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
