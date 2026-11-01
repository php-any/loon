<?php

declare(strict_types=1);

/**
 * 应用服务的显式注册回调，返回接收 Loom\Container 的可调用对象，而不是配置数组。
 * 默认各协议及命令入口安装模块服务时调用；注册发生在 Service 注解的默认注册之前，
 * 因此可以优先指定同名服务的工厂。空回调表示全部使用默认注册和容器自动构造。
 * 容器按执行作用域隔离，singleton 只在当前容器中共享，不是跨请求的全局单例。
 * 修改此文件后应重启相关常驻进程；自定义入口也需调用 ModuleRegistry::services() 才会应用它。
 *
 * 支持的注册方式（按需选择，同一标识不能重复注册）：
 * - singleton($id, $factory = null)：首次获取时创建，在当前容器内复用。
 * - transient($id, $factory = null)：每次获取都创建新实例。
 * - bind($id, $factory)：本项目中等同于 singleton，不是 transient。
 * - instance($id, $object)：注册已经构造好的对象。
 * - alias($id, $target)：将别名指向目标服务，沿用目标生命周期。
 * 工厂接收当前容器，必须返回对象；省略工厂时按类名自动构造并注入依赖。
 *
 * 已由启动入口注册的 Config、Container、ServiceScope、Database 等标识不能再次注册，
 * 否则会报 Duplicate service；这里的“覆盖”仅指优先于尚未安装的注解默认注册。
 */
return static function (\Loom\Container $services): void {
    // 示例一：显式注册现有通知服务，并提供便于按名称获取的别名。
    // $services->singleton(\App\Services\Notifications\Notifications::class);
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
};
