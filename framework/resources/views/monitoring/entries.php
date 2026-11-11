<?php
/** @var string $title */
/** @var list<array<string, mixed>> $rows */
/** @var string $base */
/** @var array<string, string> $filters */
/** @var string $list_path */
/** @var int $page */
/** @var int $per_page */
/** @var int $total */
$list_path = $list_path ?? $base;
require __DIR__ . '/filters.php';
?>
<table>
    <thead>
    <tr>
        <th>会话</th>
        <th>事件</th>
        <th>关联</th>
        <th>载荷摘要</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td><a href="<?= htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/sessions/<?= (int)($row['session_id'] ?? 0) ?>">#<?= (int)($row['session_id'] ?? 0) ?></a></td>
            <td><code><?= htmlspecialchars((string)($row['event'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></td>
            <td>
                <?php $cid = (string)($row['correlation_id'] ?? ''); ?>
                <?php if ($cid !== ''): ?>
                    <a href="<?= htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/correlation/<?= rawurlencode($cid) ?>"><code><?= htmlspecialchars($cid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></a>
                <?php endif; ?>
            </td>
            <td><pre><?= htmlspecialchars(json_encode($row['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?>
        <tr><td colspan="4" class="muted">无记录。</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php require __DIR__ . '/pagination.php'; ?>
