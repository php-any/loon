<?php

declare(strict_types=1);

namespace Loom;

/**
 * 记下仍存活的服务作用域，以及当前正在执行的作用域。database() 优先用正在执行的那个；都没有进入执行时，只有恰好一个存活作用域才返回它的数据库。查询参数和执行帧不参与选择。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $context = new ModelContext();
 */
final class ModelContext
{
    private static array $scopes = [];

    private static array $current = [];

    /**
     * 按对象身份登记仍存活的作用域。登记只表示对象尚未关闭，不改变当前模型连接。
     *
     * @param ServiceScope $scope 按对象身份登记的作用域。只表示它还没关闭。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example ModelContext::register($scope);
     */
    public static function register(ServiceScope $scope): void
    {
        self::$scopes[spl_object_id($scope)] = $scope;
    }

    /**
     * 删除指定作用域的登记，不关闭其资源。
     *
     * @param ServiceScope $scope 要删掉登记的作用域。这里不关闭它的资源。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example ModelContext::forget($scope);
     */
    public static function forget(ServiceScope $scope): void
    {
        unset(self::$scopes[spl_object_id($scope)]);
    }

    /**
     * 在 $scope 上执行 $operation。这段时间里的静态模型查询使用该作用域的数据库，即使还有别的作用域仍登记着。结束后恢复之前正在执行的作用域。
     *
     * @param ServiceScope $scope 这段回调应使用的作用域。
     * @param callable $operation 在该作用域上执行的回调。
     * @return mixed 回调的返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example ModelContext::during($scope, fn () => $scope->load($entry));
     */
    public static function during(ServiceScope $scope, callable $operation): mixed
    {
        self::$current[] = $scope;
        try {
            return $operation();
        } finally {
            array_pop(self::$current);
        }
    }

    /**
     * 返回正在执行的作用域的数据库。没有进入执行、且多个作用域并存时拒绝猜测，调用方把该作用域的 Database 传给 Model::using()。
     *
     * 参数：无。
     *
     * @return Database 当前模型调用应使用的 Database。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $database = ModelContext::database();
     */
    public static function database(): Database
    {
        if (self::$current !== []) {
            return self::$current[array_key_last(self::$current)]->container()->get(\Loom\Database::class);
        }
        $scopes = array_values(self::$scopes);
        if (count($scopes) === 1) {
            return $scopes[0]->container()->get(\Loom\Database::class);
        }
        if ($scopes === []) {
            throw new \LogicException('Models require an active framework service scope');
        }
        throw new \LogicException('Multiple service scopes are open; pass that scope\'s database to Model::using()');
    }
}
