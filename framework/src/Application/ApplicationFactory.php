<?php

declare(strict_types=1);

namespace Loom;

// 每次 create 调用都拥有新的容器和路由表。元数据可以安全复用。
/**
 * 复用已发现的模块元数据，为每次 create 构造独立容器和路由表，失败时清理部分创建的应用。
 *
 * 构造参数：
 * - $modules（ModuleRegistry）：已配置的模块注册表。
 *
 * 返回值：使用 new 构造时得到 ApplicationFactory 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $factory = new ApplicationFactory($modules); $app = $factory->create();
 */
final class ApplicationFactory
{
    private ModuleRegistry $modules;
    private array $routes = [];
    /**
     * 初始化 ApplicationFactory 实例及其依赖。复用已发现的模块元数据，为每次 create 构造独立容器和路由表，失败时清理部分创建的应用。
     *
     * @param ModuleRegistry $modules 已配置的模块注册表。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $factory = new ApplicationFactory($modules); $app = $factory->create();
     */
    public function __construct(ModuleRegistry $modules)
    {
        $this->modules = $modules;
    }
    /**
     * 追加创建应用时执行的额外路由注册回调。
     *
     * @param callable $register 接收 Router 和 Application 的回调，在每次 create 时执行。
     * @return self 当前工厂，便于链式配置。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $factory->routes(static function (Router $r, Application $a): void { $r->get('/ping', fn (Request $q) => Response::json(['ok' => true])); });
     */
    public function routes(callable $register): self
    {
        $this->routes[] = $register;
        return $this;
    }
    /**
     * 创建新服务容器和应用，安装模块路由并执行附加回调；出错时关闭应用后重新抛出。
     *
     * 参数：无。
     *
     * @return Application 具有独立容器和路由的 Application。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $app = $factory->create();
     */
    public function create(): Application
    {
        $app = new Application($this->modules->container());
        try {
            $this->modules->routes($app);
            foreach ($this->routes as $register) {
                $register($app->router(), $app);
            }
            return $app;
        } catch (\Throwable $error) {
            $app->close();
            throw $error;
        }
    }
}
