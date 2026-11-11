<?php
/** @var string $list_path */
/** @var int $page */
/** @var int $per_page */
/** @var int $total */
/** @var array<string, string> $filters */
$listPath = htmlspecialchars($list_path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pages = $per_page > 0 ? (int)max(1, (int)ceil($total / $per_page)) : 1;
$query = [];
foreach ($filters as $key => $value) {
    if ($value !== '') {
        $query[$key] = $value;
    }
}
$prev = max(1, $page - 1);
$next = min($pages, $page + 1);
$build = static function (int $target) use ($query, $per_page): string {
    $params = array_merge($query, ['page' => (string)$target, 'per_page' => (string)$per_page]);
    return '?' . http_build_query($params);
};
?>
<nav class="muted" style="margin-top:1rem;display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
    <span>第 <?= (int)$page ?> / <?= $pages ?> 页 · 共 <?= (int)$total ?> 条</span>
    <?php if ($page > 1): ?>
        <a href="<?= $listPath . htmlspecialchars($build($prev), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">上一页</a>
    <?php endif; ?>
    <?php if ($page < $pages): ?>
        <a href="<?= $listPath . htmlspecialchars($build($next), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">下一页</a>
    <?php endif; ?>
</nav>
