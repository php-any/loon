<?php

declare(strict_types=1);

namespace Loom;

/**
 * 在进程里只存在一个活动作用域时，提供该作用域的数据库。查询参数和执行帧上的值不参与选择。
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
     * 按对象身份登记仍存活的作用域。登记只表示对象尚未关闭，不改变当前模型连接。
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
     * 返回唯一活动作用域的数据库。多个作用域并存时拒绝猜测，调用方把该作用域的 Database 传给 Model::using()。
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
        $scopes = array_values(self::$scopes);
        if (count($scopes) === 1) {
            return $scopes[0]->services()->get(\Loom\Database::class);
        }
        if ($scopes === []) {
            throw new \LogicException('Models require an active framework service scope');
        }
        throw new \LogicException('Multiple service scopes are open; pass that scope\'s database to Model::using()');
    }
}
