<?php
/** @var array<string, mixed> $session */
/** @var string $base */
$tags = is_array($session['tags'] ?? null) ? $session['tags'] : [];
$entries = is_array($session['entries'] ?? null) ? $session['entries'] : [];
$cid = (string)($session['correlation_id'] ?? '');
?>
<dl>
    <dt>通道</dt><dd><?= htmlspecialchars((string)($session['channel'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
    <dt>关联 ID</dt>
    <dd>
        <?php if ($cid !== ''): ?>
            <a href="<?= htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/correlation/<?= rawurlencode($cid) ?>"><code><?= htmlspecialchars($cid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></a>
        <?php else: ?>
            <span class="muted">—</span>
        <?php endif; ?>
    </dd>
    <dt>耗时 ms</dt><dd><?= htmlspecialchars((string)($session['duration_ms'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
    <dt>标签</dt><dd><pre><?= htmlspecialchars(json_encode($tags, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre></dd>
</dl>
<h2>时间线</h2>
<table>
    <thead><tr><th>#</th><th>事件</th><th>载荷</th></tr></thead>
    <tbody>
    <?php foreach ($entries as $entry): ?>
        <tr>
            <td><?= (int)($entry['id'] ?? 0) ?></td>
            <td><code><?= htmlspecialchars((string)($entry['event'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></td>
            <td><pre><?= htmlspecialchars(json_encode($entry['payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
