<?php

declare(strict_types=1);

/**
 * 应用服务的显式注册回调，返回接收 Loom\Container 的可调用对象，而不是配置数组。
 * 在各协议及命令入口首次安装模块服务时调用，每个应用根容器仅执行一次；注册发生在 Service 注解的默认注册之前，
 * 因此可以优先指定同名服务的工厂。空回调表示全部使用默认注册和容器自动构造。
 * singleton 在同一应用的所有作用域中共享；scoped 按请求、任务或协议调用隔离。
 * 修改此文件后应重启相关常驻进程；自定义入口也需调用 ModuleRegistry::services() 才会应用它。
 *
 * 支持的注册方式（按需选择，同一标识不能重复注册）：
 * - singleton($id, $implementation = null)：首次获取时创建，所有子作用域复用。
 * - scoped($id, $implementation = null)：首次获取时创建，仅当前作用域复用。
 * - transient($id, $implementation = null)：每次获取都创建新实例。
 * - bind($id, $factory)：本项目中等同于 scoped，不是 transient。
 * - instance($id, $object)：注册已经构造好的对象。
 * - alias($id, $target)：将别名指向目标服务，沿用目标生命周期。
 * 三种生命周期均接受实现类名或工厂；省略时按服务类名构造。
 * 工厂接收解析容器，singleton 使用根容器，scoped/transient 使用当前容器。
 * singleton 不得依赖 scoped 服务（包括自动装配的类、Container 和 ServiceScope）。
 *
 * 已由启动入口注册的 Config、Container、ServiceScope、Options、Host、Database 等标识不能再次注册，
 * 否则会报 Duplicate service；这里的“覆盖”仅指优先于尚未安装的注解默认注册。
 * HTTP 服务在执行本回调之后才启动 Host。后台服务用 Host::service() 登记，选项用 options() 绑定。
 */
return static function (\Loom\Container $services): void {
    // 示例一：显式注册现有通知服务，并提供便于按名称获取的别名。
    // $services->scoped(\App\Services\Notifications\Notifications::class);
    // $services->alias('notifications', \App\Services\Notifications\Notifications::class);

    // 示例二：使用工厂注册对象，读取配置并传入构造函数。不要与示例一重复注册同一标识。
    // $services->singleton('application.timezone', static function (\Loom\Container $container): \DateTimeZone {
    //     return new \DateTimeZone($container->get(\Loom\Config::class)->get('app.timezone', 'Asia/Shanghai'));
    // });
    // app.timezone 是示例自定义键，需自行新增 config/app.php 并返回 ['timezone' => 'Asia/Shanghai']；
    // 若未新增文件，示例工厂会使用给定的默认时区。

    // 示例三：每次获取创建新对象；也可改用 instance 注册已经创建的对象，二者任选其一。
    // $services->transient('application.clock', static fn (\Loom\Container $container): \DateTimeImmutable => new \DateTimeImmutable());
    // $services->instance('application.clock', new \DateTimeImmutable());

    // 示例四：绑定配置节，并登记随 HTTP 进程启动和停止的后台服务。服务类要是 singleton。
    // $services->options(\App\Options\PaymentOptions::class, 'payment');
    // $services->singleton(\App\Workers\HeartbeatHost::class);
    // $services->get(\Loom\Host::class)->service(\App\Workers\HeartbeatHost::class);
};
