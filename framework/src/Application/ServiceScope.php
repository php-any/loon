<?php

declare(strict_types=1);

namespace Loom;

// 管理单次请求、命令、任务或协议调用的所有可变服务。
/**
 * 拥有一次请求、命令、任务或协议调用的服务容器；派生作用域共享配置但不共享可变服务。
 *
 * 构造参数：
 * - $config（Config）：项目配置实例，提供配置读取及项目路径解析。
 * - $configure（?callable）：可选服务注册回调，接收新 Container；fork 时会再次执行。默认值为 null。
 *
 * 返回值：使用 new 构造时得到 ServiceScope 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $scope = new ServiceScope($config); try { $scope->run($operation); } finally { $scope->close(); }
 */
final class ServiceScope
{
    private Container $services;
    private Config $config;
    private mixed $configure;
    /**
     * 初始化 ServiceScope 实例及其依赖。拥有一次请求、命令、任务或协议调用的服务容器；派生作用域共享配置但不共享可变服务。
     *
     * @param Config $config 项目配置实例，提供配置读取及项目路径解析。
     * @param ?callable $configure 可选服务注册回调，接收新 Container；fork 时会再次执行。默认值为 null。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $scope = new ServiceScope($config); try { $scope->run($operation); } finally { $scope->close(); }
     */
    public function __construct(Config $config, ?callable $configure = null)
    {
        $this->config = $config;
        $this->configure = $configure;
        $this->services = new Container();
        $this->services->instance(\Loom\Config::class, $config);
        $this->services->instance(\Loom\Container::class, $this->services);
        $this->services->instance(self::class, $this);
        if ($configure !== null) {
            $configure($this->services);
        }
        ModelContext::register($this);
    }
    /**
     * 使用同一配置及注册回调创建新的服务作用域，重新构造容器。
     *
     * 参数：无。
     *
     * @return self 独立的 ServiceScope，调用方负责 close。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $child = $scope->fork();
     */
    public function fork(): self
    {
        return new self($this->config, $this->configure);
    }
    /**
     * 取得当前作用域拥有的容器。
     *
     * 参数：无。
     *
     * @return Container 当前 Container 实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services = $scope->services();
     */
    public function services(): Container
    {
        return $this->services;
    }
    /**
     * 在显式绑定当前数据库的模型上下文中执行回调。
     *
     * @param callable $operation 无参回调，在当前模型数据库上下文中执行。
     * @return mixed operation 的原始返回值；异常向上传播。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $value = $scope->run(fn () => \Loom\Models\User::count());
     */
    public function run(callable $operation): mixed
    {
        return \Loom\Native\withModelDatabase($this->services->get(\Loom\Database::class), $operation);
    }
    /**
     * 在提供 scope、services、config 局部变量的环境中加载入口脚本。
     *
     * @param string $entry 入口文件路径，支持项目根目录相对路径或绝对路径。
     * @return mixed 被 require 的脚本返回值，类型由入口约定。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app = $scope->load('framework/bootstrap/http.php');
     */
    public function load(string $entry): mixed
    {
        $scope = $this;
        $services = $this->services;
        $config = $services->get(\Loom\Config::class);
        return require $config->path($entry);
    }
    /**
     * 清理已解析的请求作用域及数据库，注销模型上下文并清空请求绑定。
     *
     * 参数：无。
     *
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $scope->close();
     */
    public function close(): void
    {
        try {
            $requestScope = $this->services->resolved(\Loom\RequestScope::class);
            if ($requestScope !== null) {
                $requestScope->close();
            }
            $database = $this->services->resolved(\Loom\Database::class);
            if ($database !== null) {
                $database->close();
            }
        } finally {
            ModelContext::forget($this);
            $context = $this->services->resolved(\Loom\RequestContext::class);
            if ($context !== null) {
                $context->bind(null);
            }
        }
    }
}
