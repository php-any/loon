<?php

declare(strict_types=1);

namespace Loom;

// 每次 create 调用都拥有新的容器和路由表。元数据可以安全复用。
/**
 * 每次 create 都向同一个模块注册器要新容器和新应用，再按追加顺序执行路由回调。
 *
 * 构造参数：
 * - $modules（ModuleRegistry）：提供 container 和 application 的注册器。
 *
 * 返回值：使用 new 构造时得到尚未创建应用的 ApplicationFactory。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $factory = new ApplicationFactory($modules);
 */
final class ApplicationFactory
{
    private ModuleRegistry $modules;
    private array $routes = [];
    /**
     * 保存模块注册器。构造时不创建容器，也不安装路由。
     *
     * @param ModuleRegistry $modules 之后 create 用来派生容器和应用的注册器。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $factory = new ApplicationFactory($modules);
     */
    public function __construct(ModuleRegistry $modules)
    {
        $this->modules = $modules;
    }
    /**
     * 记住一个回调。create 做出应用之后，会用该应用的路由器和应用本身调用它。返回当前工厂。
     *
     * @param callable $register 每次 create 成功后调用的回调，两个参数是 Router 和 Application。
     * @return self 当前 ApplicationFactory。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $factory->routes(static function (Router $router, Application $app): void { $router->get('/ping', fn (Request $request) => Response::json(['ok' => true])); });
     */
    public function routes(callable $register): self
    {
        $this->routes[] = $register;
        return $this;
    }
    /**
     * 向注册器要一个新容器，用它创建 Application，再按追加顺序调用路由回调。
     * 任一步抛错时，关闭该容器上的 ServiceScope，然后抛出原来的异常。关闭本身再抛错时，抛出的是关闭异常。
     *
     * 参数：无。
     *
     * @return Application 拥有独立容器和路由表的新应用。调用方负责之后关闭。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app = $factory->create();
     */
    public function create(): Application
    {
        $services = $this->modules->container();
        try {
            $app = $this->modules->application($services);
            foreach ($this->routes as $register) {
                $register($app->router(), $app);
            }
            return $app;
        } catch (\Throwable $error) {
            $services->get(ServiceScope::class)->close();
            throw $error;
        }
    }
}
