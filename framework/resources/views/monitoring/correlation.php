<?php
/** @var string $correlation_id */
/** @var list<array<string, mixed>> $sessions */
/** @var string $base */
?>
<p>同一 <code><?= htmlspecialchars($correlation_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code> 下的会话（HTTP → 队列 → _job 等）。</p>
<table>
    <thead><tr><th>ID</th><th>通道</th><th>耗时 ms</th><th>标签</th></tr></thead>
    <tbody>
    <?php foreach ($sessions as $row): ?>
        <tr>
            <td><a href="<?= htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/sessions/<?= (int)($row['id'] ?? 0) ?>"><?= (int)($row['id'] ?? 0) ?></a></td>
            <td><?= htmlspecialchars((string)($row['channel'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($row['duration_ms'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><code><?= htmlspecialchars(json_encode($row['tags'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
