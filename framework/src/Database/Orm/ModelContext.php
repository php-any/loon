<?php

declare(strict_types=1);

namespace Loom;

/**
 * 模型静态查询（Model::query() 等）使用的 Database。绑定挂在 Go 侧这次调用的执行帧上，跟着子调用、include 和 spawn 传下去，
 * 回调结束后恢复，并发的请求互不可见。宿主通过 ServiceScope::run() 绑定作用域里的 Database，脚本不维护全局表。
 *
 * 需要绕开当前绑定时，把 Database 直接交给 Model::using()。
 *
 * @example ModelContext::during($database, static fn (): int => Task::query()->count());
 */
final class ModelContext
{
    /**
     * 在回调期间把模型查询绑定到这个 Database。返回回调的返回值。
     */
    public static function during(Database $database, callable $operation): mixed
    {
        return \Loom\Native\withModelDatabase($database, $operation);
    }

    /**
     * 当前执行帧上绑定的 Database。没有绑定时抛出 LogicException。
     */
    public static function database(): Database
    {
        $database = \Loom\Native\modelDatabase();
        if (!($database instanceof Database)) {
            throw new \LogicException('Models require an active service scope; run the work through ServiceScope::run() or pass a Database to Model::using()');
        }
        return $database;
    }
}
