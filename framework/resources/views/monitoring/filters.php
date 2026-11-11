<?php
/** @var string $list_path */
/** @var array<string, string> $filters */
$listPath = htmlspecialchars($list_path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$tag = htmlspecialchars($filters['tag'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$correlation = htmlspecialchars($filters['correlation_id'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$path = htmlspecialchars($filters['path'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$status = htmlspecialchars($filters['status'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<form method="get" action="<?= $listPath ?>" style="margin-bottom:1rem;display:flex;flex-wrap:wrap;gap:0.5rem;align-items:flex-end;">
    <label>tag <input name="tag" value="<?= $tag ?>" size="12" /></label>
    <label>correlation_id <input name="correlation_id" value="<?= $correlation ?>" size="24" /></label>
    <label>path <input name="path" value="<?= $path ?>" size="16" /></label>
    <label>status <input name="status" value="<?= $status ?>" size="6" /></label>
    <button type="submit">筛选</button>
</form>
