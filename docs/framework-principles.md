# Loom 框架原理与 PHP 文件加载流程

本文面向第一次阅读项目代码的开发者，按当前源码解释启动、文件加载、请求执行、数据访问和后台任务。具体 API 用法见 [框架能力](framework.md)，部署与功能边界见 [架构说明](architecture.md)。

## 1. 四层结构

Loom 的运行关系是：Go 宿主创建 Origami 虚拟机，Origami 执行 PHP 兼容脚本，Loom 框架组织基础能力，应用代码实现业务。

- `runtime/`：Go 宿主、标准库装配和原生扩展，提供脚本执行与底层 I/O 能力。
- `framework/`：脚本框架，负责容器、模块发现、路由、中间件、数据库适配、认证、队列和调度规则。
- `app/`：业务控制器、服务、模型、任务和命令，使用 `App` 命名空间。
- `frontend/vben-admin/`：后台前端源码，构建到 `public/admin/`，通过 JSON API 调用后端。官网和演示页面使用 `resources/views/` 中的服务端模板。

项目默认执行程序是 `build/loom.exe`（Linux/macOS 为 `build/loom`）。业务 `.php` 文件由其中的 Origami 执行；脚本没有全部嵌入二进制，部署必须携带匹配的脚本和资源。

## 2. 从命令到 HTTP 监听

执行 `./build/loom.exe serve` 时：

```text
runtime/cmd/loom/main.go
  → host.Run() 定位入口脚本
  → parser.NewParser() + runtime.NewVM()
  → loadModules() 加载标准库和 Loom 原生扩展
  → vm.LoadAndRun(loom.php)
  → 加载框架、配置与自动加载器，创建 ServiceScope
  → 容器创建 Cli，Cli::run() 分发 serve
  → Doctor 检查运行能力
  → Server::run() 初始化数据库、预先校验路由、启动定时器
  → 原生 HTTP 服务开始监听
```

[host/main.go](../runtime/internal/host/main.go) 支持两种入口：

- **命令模式**：例如 `serve`，定位项目 `loom.php`，把命令参数传给它。项目定位优先使用 `LOOM_ROOT`，否则从当前目录向上寻找，再从可执行文件目录向上寻找。
- **脚本模式**：例如 `./build/loom.exe tests/run.php`，直接执行指定脚本。首参数以 `.php`、`.zy` 结尾、包含路径分隔符或指向现有文件时，会走这条路径；相对路径按当前工作目录解析。

直接执行脚本不会自动替脚本完成 Loom 应用初始化。需要框架的自建脚本应显式调用应用加载函数，并在 `finally` 中关闭返回的作用域。

## 3. PHP 文件具体如何加载

需要区分四件事：**加载文件、声明类、注册组件、创建对象**。文件被加载时，其顶层代码会执行；声明一个类不等于创建该类的对象，注册路由也不等于调用控制器。

### 3.1 原生类先由 Go 注册

[host/modules.go](../runtime/internal/host/modules.go) 在执行入口脚本之前加载 `std`、PHP 兼容函数、Redis、HTTP、WebSocket、TCP、gRPC、system 和项目扩展。

因此，脚本中的 `Container\Container`、`Net\Http\Server` 等原生能力来自运行时注册，不需要在项目中找到一个同名 PHP 文件。`Loom\Native` 的能力由项目原生扩展提供。

### 3.2 命令入口加载框架并取得初始化函数

[loom.php](../loom.php) 的关键调用是：

```php
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
$initialize = require __DIR__ . '/framework/bootstrap/autoload.php';
$scope = $initialize(__DIR__);
```

可以拆成两步理解：

```php
$initialize = require $root . '/framework/bootstrap/autoload.php';
$scope = $initialize($root);
```

`require` 执行文件，并取得文件 `return` 的值。这里返回的是闭包，随后传入项目根目录执行闭包，得到 `ServiceScope`。

### 3.3 框架自身使用显式清单加载

[bootstrap/autoload.php](../framework/bootstrap/autoload.php) 首先执行：

```php
require_once dirname(__DIR__) . '/bootstrap.php';
```

[framework/bootstrap.php](../framework/bootstrap.php) 再按照固定清单加载框架文件：

```php
foreach ([
    // 省略其他框架文件
    'Container/Container.php',
    // ...
] as $module) {
    require_once __DIR__ . '/src/' . $module;
}
```

这是显式加载，不是扫描 `framework/src/`，也不是按 `Loom` 命名空间自动推导路径。这样可以保持 Origami 兼容性，并让目录分类与公共类名分离。例如 `framework/src/Http/Server.php` 声明的是 `Loom\Server`。

新增框架文件时，仅把文件放进目录不会自动加载，通常还需要加入这份清单，并注意接口、基类等声明的依赖顺序。`require_once` 避免同一执行环境内重复包含已经加载的文件；它不表示整个系统只会有一个该类的对象，也不跨独立进程共享加载状态。

### 3.4 应用初始化函数注册业务类自动加载

返回的初始化函数依次：

1. 创建以项目目录为根的 `Config`。
2. 使用 `spl_autoload_register()` 注册 Loom 自己的业务类加载器。
3. 加载 PHP 配置文件。
4. 创建并返回 `ServiceScope`。

Composer 依赖由根入口 `loom.php` 在加载框架前显式安装；框架初始化不读取应用的 `vendor/autoload.php`。自定义脚本或测试入口使用第三方包时，也应显式加载对应的 Composer 自动加载器。

内置业务类加载器有两条固定映射：

```text
App\                    → app/
Generated\Grpc\         → generated/grpc/
```

它去掉匹配的命名空间前缀，把剩余反斜杠替换为目录分隔符，追加 `.php`，文件存在时执行 `require_once`。例如：

```text
App\Http\Controllers\Projects\CreateTaskController
  → app/Http/Controllers/Projects/CreateTaskController.php

App\Models\Projects\Task
  → app/Models/Projects/Task.php
```

当运行时需要解析尚未加载的类时，自动加载机制负责查找文件。`use App\Models\Projects\Task;` 只是引入类名别名，不是 `require`；`Task::class` 提供类名字符串，也不能当成对象构造或组件注册。

Composer 是可选入口：项目 `composer.json` 也声明了上述 PSR-4 映射，但内置应用不依赖先安装 Composer 才能加载自己的类。安装的第三方 PHP 包仍需与 Origami 的实际能力兼容。

### 3.5 配置文件通过 require 读取返回值

[Config::load()](../framework/src/Config/Config.php) 扫描 `config/` 直属的 `*.php`，排序后逐个执行 `require`，以文件名作为配置键保存返回值。

```php
// config/modules.php
return [\App\AppModule::class];
```

结果可通过 `$config->get('modules')` 获取。`config/services.php` 返回的闭包也先作为配置值保存，之后由 `ModuleRegistry::services()` 调用，完成容器注册。

PHP 配置缓存在 `Config` 实例中，重复 `load()` 不会重新执行这些文件。所有配置（包括 HTTP、数据库和协议配置）都走同一条 PHP 加载路径；环境变量在各配置文件中读取，一并缓存。`get()` 返回全部文件命名空间，`get('http.address')` 按点号读取。`Config` 不保存或执行容器注册回调，入口回调由 `ServiceScope` 负责，通过 `fork()` 传递给独立作用域。配置文件应只返回配置，不要依赖加载过程中读取其他配置。

### 3.6 模块扫描会主动触发业务类加载

模块声明如：

```php
#[\Loom\Attributes\Module(['App' => 'app'])]
final class AppModule {}
```

其执行链是：

```text
config/modules.php 返回模块类名
  → ModuleDiscovery 对模块做 ReflectionClass
  → 读取 Module 注解中的扫描范围
  → ClassDiscovery 递归枚举 PHP 路径，推导完整类名
  → 对每个类做 ReflectionClass，触发必要的自动加载
  → 读取并校验 Controller、Route、Service、Job 等注解
  → ModuleRegistry 把声明注册到容器、路由等系统
```

[ClassDiscovery](../framework/src/Module/ClassDiscovery.php) 根据路径推导类名，不通过解析文件内容寻找任意类名；它排序路径、跳过符号链接，并递归处理目录。因此文件路径、命名空间和类名必须一致，扫描目录不适合存放不声明对应类的普通执行脚本。

**自动加载器支持按需加载，但模块发现会提前加载扫描范围内的类来读取元数据。** 不能理解为只有某个路由被访问时，对应控制器文件才第一次加载。发现阶段不创建业务服务对象，但会实例化注解对象、校验模型等声明；文件顶层语句也会随加载执行，所以业务文件顶层应避免数据库写入、输出内容或启动后台任务。

模块声明的扫描映射本身不会注册新的自动加载规则。新增 `App`、`Generated\Grpc` 之外的命名空间时，必须另外提供对应自动加载器，例如 Composer PSR-4 配置。

### 3.7 引导脚本和路由文件需要重复执行

[ServiceScope::load()](../framework/src/Application/ServiceScope.php) 的核心是：

```php
$scope = $this;
$services = $this->services;
$config = $services->get(\Loom\Config::class);
return require $config->path($entry);
```

PHP 文件在这个调用作用域中执行，因此 `framework/bootstrap/http.php` 可以直接使用 `$scope`、`$services` 和 `$config`。这些变量来自引导入口，不是依靠业务代码访问全局变量。

HTTP 引导脚本发现模块、注册服务、创建 `Application`，由 `ModuleRegistry::routes()` 装配注解路由，再从当前应用容器解析 `Loom\Http\RouteProvider` 并调用 `register(Router $routes)`，统一扩展 HTTP 和 WebSocket 路由。应用实现 `App\Http\ApplicationRoutes` 在 `loom.php` 中显式绑定；每个应用作用域重新解析并装配，不再加载 `routes/web.php` 或 `routes/websocket.php`，不依赖文件继承局部变量。`ApplicationFactory` 使用相同接口和当前应用容器。

当前默认 HTTP 链路是 `Server → ServiceScope::load() → framework/bootstrap/http.php`。框架还提供 `ApplicationFactory` 作为可复用的应用创建入口，但默认入口没有直接调用它。当前 HTTP 引导每次调用 `configured()` 都创建新的发现结果，不能假设所有模块元数据已被全进程永久缓存。

### 3.8 模板也通过 require 执行

[View::render()](../framework/src/View/View.php) 在闭包内 `require $template`，向模板提供 `$data`，用输出缓冲收集 HTML。每次渲染都需要重新执行模板，因此这里也不使用 `require_once`。

模板中的 HTML 和 PHP 表达式共同产生响应正文。模板路径由应用选择，展示用户输入时使用 `Loom\escape()` 转义。

### 3.9 文件加载与请求生命周期的区别

服务启动时加载框架声明，并预先装配应用来校验路由；每次请求创建新作用域，再执行 HTTP 引导脚本装配本次应用。重新创建对象不意味着重新启动虚拟机，也不意味着所有类文件都重新从磁盘执行一次。

修改框架或业务类、注解后，应重启长期运行的服务；不要把重复装配当作源码热重载。队列作业使用独立宿主进程，会重新走自己的脚本入口和初始化链路。

## 4. 一次请求怎样执行

```text
原生 HTTP 服务接收请求
  → Server 创建本次请求的 ServiceScope
  → HttpRuntime / RequestFactory 转换 Request
  → 普通业务请求装配 Application（/assets/ 由 StaticFiles 处理）
  → HttpKernel 绑定模型数据库和当前请求
  → 全局中间件
  → Router 匹配方法和路径
  → 绑定路径参数，执行路由中间件
  → ActionResolver 从容器解析控制器
  → 执行业务，返回 Response
  → HttpTransport 写出响应
  → finally 清理数据库连接、请求和传输上下文
```

[Router](../framework/src/Routing/Router.php) 只匹配路由，返回定义和参数，不创建控制器。[HttpKernel](../framework/src/Http/HttpKernel.php) 组织中间件与处理器执行。路由中间件可以提前返回响应，此时后续控制器无需实例化。WebSocket 也在路由中间件放行后才升级连接。

全局中间件包裹 404、405 等路由响应；未捕获异常由 HTTP 运行层返回通用 500，细节写入标准错误。

## 5. 作用域与依赖注入

服务进程常驻，但每次请求拥有独立 `ServiceScope`，容器管理本次执行的服务实例、数据库和请求上下文。`finally` 保证异常路径也会清理资源。

[Loom\Container](../framework/src/Container/Container.php) 为每个作用域创建独立原生容器，委托它实现实例缓存、工厂执行和循环依赖检测；Loom 的对象工厂负责构造参数和配置属性注入。

- `singleton`：一个容器中共享实例，不是整个进程共享实例。
- `transient`：每次解析创建对象。
- `alias`：委托目标服务解析，保留目标生命周期。
- `bind`：Loom 中保留共享语义，相当于 `singleton` 注册。

控制器通过类型化构造参数表达依赖，容器递归解析；服务别名可用 `Inject`，配置使用 `InjectConfig` 或显式工厂。`config/services.php` 的显式注册优先于服务注解。

HTTP 请求、协议调用、作业分别使用自己的作用域。模块定时器各自保留独立作用域和处理器实例，每次执行后释放连接，因此实例计数可以保留，但不会与 HTTP 请求共享事务。

## 6. 数据访问与真实接口示例

模型查询的关系是：

```text
Task::whereUserId(...)->whereId(...)->first()
  → Loom\Model 从 ModelContext 获取当前 Database
  → ModelQuery 累积条件
  → Origami 原生 ORM 查询和回填实体
  → 原生 SQL 传输适配到当前 Database/PDO
  → MySQL
```

模型和直接 Database 操作复用当前作用域的连接，才能参与同一个事务。模型的 `Table`、`Column`、`Id`、`GeneratedValue` 注解描述映射；表结构由迁移维护。具体适配边界见 [模型文档](models.md)。

[CreateTaskController](../app/Http/Controllers/Projects/CreateTaskController.php) 是完整而简短的阅读示例：

1. 注解注册 `POST /api/studio/tasks`。
2. `RequireWorkspaceAccess` 检查工作台访问条件，`ParseJsonInput` 解析 JSON。
3. 控制器取出当前用户，校验标题和可选项目 ID。
4. 项目查询同时约束项目 ID 与用户 ID。
5. `Task::create()` 持久化任务，返回 201 JSON 响应。

简单接口可以直接使用 Model；复杂业务再用 Service 组织流程，Repository 封装关联查询。框架不会自动给所有查询加当前用户条件，资源归属仍需显式约束。

## 7. Timer、Queue 与 Schedule

- **Timer**：跟随 HTTP 服务进程运行，按固定周期触发；同一定时器不重叠执行，忙碌期间的触发跳过。多 HTTP 进程会各自执行自己的定时器。
- **Queue**：请求提交任务，MySQL 保存任务及待投递记录，Redis Stream 负责投递。独立 `queue:work` 消费，每个作业通过宿主子进程执行，提供租约、超时和重试机制。
- **Schedule**：独立 `schedule:work` 计算到期计划，在数据库事务中生成任务并推进下次执行时间，实际任务交给队列处理。

队列是至少一次交付，业务处理必须考虑幂等。启动 HTTP 不会自动启动队列和调度进程。

## 8. 建议阅读顺序

先阅读一条完整链路，再深入扩展机制：

1. [loom.php](../loom.php) 与 [bootstrap/autoload.php](../framework/bootstrap/autoload.php)：理解入口和加载。
2. [Server](../framework/src/Http/Server.php) 与 [ServiceScope](../framework/src/Application/ServiceScope.php)：理解进程和请求生命周期。
3. [HTTP 引导](../framework/bootstrap/http.php) 与 [HttpKernel](../framework/src/Http/HttpKernel.php)：理解装配与执行的区别。
4. [CreateTaskController](../app/Http/Controllers/Projects/CreateTaskController.php) 与 [Task](../app/Models/Projects/Task.php)：理解真实业务如何落库。
5. [ModuleDiscovery](../framework/src/Module/ModuleDiscovery.php) 与 [ModuleRegistry](../framework/src/Module/ModuleRegistry.php)：理解扫描、注解和注册。
6. [Queue](../framework/src/Queue/Queue.php) 与 [Schedule](../framework/src/Schedule/Schedule.php)：理解跨进程任务处理。
