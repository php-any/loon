<?php

declare(strict_types=1);

namespace Loom;

/**
 * 一次写入语句的结果。自增主键来自该语句本身，不来自事后再查的连接。
 *
 * 构造参数：
 * - $insertId（int）：语句返回的自增主键，没有时为 0。
 * - $rowsAffected（int）：受影响行数。
 *
 * 返回值：使用 new 构造时得到 StatementResult 实例。
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
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $result = new StatementResult(1, 1);
     */
    public function __construct(public int $insertId, public int $rowsAffected)
    {
        $this->lastInsertId = $insertId;
    }
}
