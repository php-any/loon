<?php

declare(strict_types=1);

namespace Loom;

// 管理单次请求、命令、任务或协议调用的所有可变服务。
/**
 * 持有一次请求、命令、任务或协议调用的容器。根作用域登记配置、模块发现、选项和宿主；fork 出去的作用域另有一份 scoped 实例。
 *
 * 构造参数：
 * - $config（Config）：根作用域放进容器的配置。派生作用域继续保存同一份。
 * - $configure（?callable）：只允许在没有父容器时执行，用来继续登记服务。与 $parent 同时传入会失败。默认 null。
 * - $parent（?Container）：非 null 时，容器改为这个父容器的 fork，并且不再登记根服务。默认 null。
 *
 * 返回值：使用 new 构造时得到已把自身登记进容器的 ServiceScope。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $scope = new ServiceScope($config);
 */
final class ServiceScope
{
    private Container $container;
    private Config $config;
    /**
     * 父容器和注册回调同时出现时抛出 LogicException，此时还没有创建容器。
     * 没有父容器时新建容器，并登记 Config 实例、ModuleDiscovery、Options，以及用该容器构造的 Host。
     * 有父容器时改用 parent 的 fork，跳过上述四项根登记。两种情况都把自身登记为 scopedInstance；回调不是 null 时立刻把容器交给它，最后调用 ModelContext::register。
     *
     * @param Config $config 根容器中的 Config 实例；派生作用域也保存这一引用。
     * @param ?callable $configure 接收新容器的注册回调。只有根作用域可以传入。默认 null。
     * @param ?Container $parent 派生时的父容器。null 表示新建根容器。默认 null。
     * @return void 无直接返回值。回调抛错时异常继续抛出，此时根服务可能已经登记。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $scope = new ServiceScope($config, static function (Container $services): void { $services->singleton(Clock::class); });
     */
    public function __construct(Config $config, ?callable $configure = null, ?Container $parent = null)
    {
        if ($parent !== null && $configure !== null) {
            throw new \LogicException('Service registration runs only on the root scope');
        }
        $this->config = $config;
        $this->container = $parent === null ? new Container() : $parent->fork();
        if ($parent === null) {
            $this->container->instance(\Loom\Config::class, $config);
            $this->container->singleton(\Loom\ModuleDiscovery::class, static function (Container $services): ModuleDiscovery {
                return new ModuleDiscovery($services->get(Config::class));
            });
            $this->container->singleton(\Loom\Options::class);
            // 工厂直接拿到根容器。Host 不能通过构造参数解析 Container，那会被当成 scoped 依赖。
            $this->container->singleton(\Loom\Host::class, static function (Container $services): Host {
                return new Host($services);
            });
        }
        $this->container->scopedInstance(self::class, $this);
        if ($configure !== null) {
            $configure($this->container);
        }
        ModelContext::register($this);
    }
    /**
     * 用当前配置和当前容器作为父容器，构造一个不再执行根注册回调的子作用域。
     *
     * 参数：无。
     *
     * @return self 新的 ServiceScope。调用方负责 close。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $child = $scope->fork();
     */
    public function fork(): self
    {
        return new self($this->config, null, $this->container);
    }
    /**
     * 返回本作用域构造时新建或 fork 出来的容器。
     *
     * 参数：无。
     *
     * @return Container 该作用域持有的容器。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $container = $scope->container();
     */
    public function container(): Container
    {
        return $this->container;
    }
    /**
     * 先把 $scope、$container 和从容器取出的 $config 放在本方法里，再 require 入口脚本，脚本能读到这三个局部变量。
     * 路径经 Config::path 解析：相对路径相对项目根，绝对路径保持原样。
     *
     * @param string $entry 入口脚本。可以是相对项目根的路径，也可以是绝对路径。
     * @return mixed 入口脚本 return 的值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app = $scope->load('framework/bootstrap/http.php');
     */
    public function load(string $entry): mixed
    {
        $scope = $this;
        $container = $this->container;
        $config = $container->get(\Loom\Config::class);
        return require $config->path($entry);
    }
    /**
     * 关闭本容器里已经解析出来的 RequestScope 和 Database。
     * finally 里调用 ModelContext::forget，并把已经解析出来的 RequestContext 绑回 null。尚未解析的服务不会在这里被创建。
     * 前面的 close 抛错时，finally 仍会执行。
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
            $requestScope = $this->container->resolvedLocal(\Loom\RequestScope::class);
            if ($requestScope !== null) {
                $requestScope->close();
            }
            $database = $this->container->resolvedLocal(\Loom\Database::class);
            if ($database !== null) {
                $database->close();
            }
        } finally {
            ModelContext::forget($this);
            $context = $this->container->resolvedLocal(\Loom\RequestContext::class);
            if ($context !== null) {
                $context->bind(null);
            }
        }
    }
}
