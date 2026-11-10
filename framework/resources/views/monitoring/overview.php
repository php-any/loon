<?php
/** @var list<array<string, mixed>> $sessions */
/** @var string $base */
?>
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
            <td><a href="<?= htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/sessions/<?= (int)($row['id'] ?? 0) ?>"><?= (int)($row['id'] ?? 0) ?></a></td>
            <td><?= htmlspecialchars((string)($row['channel'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td>
                <?php $cid = (string)($row['correlation_id'] ?? ''); ?>
                <?php if ($cid !== ''): ?>
                    <a href="<?= htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/correlation/<?= rawurlencode($cid) ?>"><code><?= htmlspecialchars($cid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></a>
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
