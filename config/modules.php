<?php

declare(strict_types=1);

/**
 * 启用的应用模块列表，返回“模块完整类名”组成的数组，不是目录列表或配置键值对。
 * 默认 AppModule 扫描 App 命名空间下的 app 目录，发现服务、控制器、任务、命令，以及 app/Console/Timers 下的 #[Timer] 声明。
 * 每个模块类必须可自动加载，并带有 Loom\Attributes\Module 注解；普通服务类不能直接填入。
 * 修改后重启相关常驻进程，新的模块声明才会重新参与发现和注册。
 *
 * 扩展示例（需先创建并配置自动加载的 BillingModule）：
 * return [\App\AppModule::class, \Billing\BillingModule::class];
 *
 * 模块类的声明示例：
 * #[\Loom\Attributes\Module(['Billing' => 'modules/Billing'], [])]
 * final class BillingModule {}
 * 第一个参数 scan 是“命名空间 => 扫描目录”的映射，目录相对项目根目录，也支持绝对路径。
 * 第二个参数 imports 是依赖模块类名数组，例如 [\Shared\SharedModule::class]；依赖先加载。
 * 同一模块重复导入会去重，循环依赖会报错；重复服务标识、无效组件声明也会报错。
 *
 * 可选 return []，表示不发现任何应用模块；依赖模块注解注册的路由、任务和命令将不再提供。
 * 模块发现本身只检查声明，不构造业务服务实例；此文件没有 enabled、path 等命名配置键。
 */
return [\App\AppModule::class];
