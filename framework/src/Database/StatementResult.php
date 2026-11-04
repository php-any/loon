<?php

declare(strict_types=1);

namespace Loom;

/**
 * 一次写入语句的结果。自增主键来自该语句本身，不来自事后再查的连接。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $result = new StatementResult(1, 1);
 */
final class StatementResult
{
    public int $lastInsertId;

    /**
     * 保存插入标识和影响行数。
     *
     * @param int $insertId 语句返回的自增主键，没有时为 0。
     * @param int $rowsAffected 受影响行数。
     * @return void 不返回值。insertId 同时写到 $lastInsertId。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $result = new StatementResult(1, 1);
     */
    public function __construct(public int $insertId, public int $rowsAffected)
    {
        $this->lastInsertId = $insertId;
    }
}
