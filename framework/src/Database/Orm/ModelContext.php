<?php

declare(strict_types=1);

namespace Loom;

/**
 * 管理活动服务作用域与模型数据库选择，优先使用原生显式绑定，回退到最近注册的作用域。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 ModelContext 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $database = ModelContext::database();
 */
final class ModelContext
{
    private static array $scopes = [];

    /**
     * 按对象身份登记作用域，供没有显式模型绑定的调用回退使用。
     *
     * @param ServiceScope $scope 当前执行单元拥有的服务作用域。
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
     * @param ServiceScope $scope 当前执行单元拥有的服务作用域。
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
     * 读取原生绑定数据库；不存在时取最近注册作用域的 Database，无活动作用域时报错。
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
        $bound = \Loom\Native\modelDatabase();
        if ($bound !== null) {
            return $bound;
        }
        $scopes = array_values(self::$scopes);
        if ($scopes === []) {
            throw new \LogicException('Models require an active framework service scope');
        }
        return $scopes[count($scopes) - 1]->services()->get(\Loom\Database::class);
    }
}
