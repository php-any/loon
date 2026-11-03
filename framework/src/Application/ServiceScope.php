<?php

declare(strict_types=1);

namespace Loom;

// 管理单次请求、命令、任务或协议调用的所有可变服务。
/**
 * 拥有一次请求、命令、任务或协议调用的服务容器；派生作用域共享配置和 singleton，隔离 scoped 服务。
 *
 * 构造参数：
 * - $config（Config）：项目配置实例，提供配置读取及项目路径解析。
 * - $configure（?callable）：可选服务注册回调，接收新 Container；仅在应用根作用域执行。与 $parent 同时传入时抛出 LogicException。默认值为 null。
 *
 * 返回值：使用 new 构造时得到 ServiceScope 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $scope = new ServiceScope($config); try { $scope->services()->get(\Loom\Database::class); } finally { $scope->close(); }
 */
final class ServiceScope
{
    private Container $services;
    private Config $config;
    /**
     * 初始化 ServiceScope 实例及其依赖。拥有一次请求、命令、任务或协议调用的服务容器；派生作用域共享配置和 singleton，隔离 scoped 服务。
     *
     * @param Config $config 项目配置实例，提供配置读取及项目路径解析。
     * @param ?callable $configure 可选服务注册回调，接收新 Container；仅在应用根作用域执行。与 $parent 同时传入时抛出 LogicException。默认值为 null。
     * @param ?Container $parent 派生作用域的父容器；null 创建独立应用。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $scope = new ServiceScope($config); try { $scope->services()->get(\Loom\Database::class); } finally { $scope->close(); }
     */
    public function __construct(Config $config, ?callable $configure = null, ?Container $parent = null)
    {
        if ($parent !== null && $configure !== null) {
            throw new \LogicException('Service registration runs only on the root scope');
        }
        $this->config = $config;
        $this->services = $parent === null ? new Container() : $parent->fork();
        if ($parent === null) {
            $this->services->instance(\Loom\Config::class, $config);
            $this->services->singleton(\Loom\ModuleDiscovery::class, static function (Container $services): ModuleDiscovery {
                return new ModuleDiscovery($services->get(Config::class));
            });
            $this->services->singleton(\Loom\Options::class);
            // 工厂直接拿到根容器。Host 不能通过构造参数解析 Container，那会被当成 scoped 依赖。
            $this->services->singleton(\Loom\Host::class, static function (Container $services): Host {
                return new Host($services);
            });
        }
        $this->services->scopedInstance(self::class, $this);
        if ($configure !== null) {
            $configure($this->services);
        }
        ModelContext::register($this);
    }
    /**
     * 共享配置、注册规则和应用单例，创建独立的 scoped 实例缓存。
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
        return new self($this->config, null, $this->services);
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
            $requestScope = $this->services->resolvedLocal(\Loom\RequestScope::class);
            if ($requestScope !== null) {
                $requestScope->close();
            }
            $database = $this->services->resolvedLocal(\Loom\Database::class);
            if ($database !== null) {
                $database->close();
            }
        } finally {
            ModelContext::forget($this);
            $context = $this->services->resolvedLocal(\Loom\RequestContext::class);
            if ($context !== null) {
                $context->bind(null);
            }
        }
    }
}
