# Loom 框架原理与 PHP 文件加载流程

本文面向第一次阅读项目代码的开发者，按当前源码解释启动、文件加载、请求执行、数据访问和后台任务。具体 API 用法见 [框架能力](framework.md)，依赖注入和作用域见 [依赖注入与作用域](core-services.md)，应用组合、选项与后台服务见 [宿主、应用与选项](hosting.md)，部署与功能边界见 [架构说明](architecture.md)。

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
  → loom.php 顶层：Application::createBuilder() 登记服务，build() 扫描模块、装好路由表和命令表，再 use()/map*()
  → Application::run() 为这条命令开一个作用域，Console 分发 serve
  → Doctor 检查运行能力
  → Migrations::migrate() 执行框架和应用迁移
  → Host 按登记顺序启动后台服务和定时器
  → HttpServer::run() 开始监听
```

[host/main.go](../runtime/internal/host/main.go) 支持两种入口：

- **命令模式**：例如 `serve`，定位项目 `loom.php`，把命令参数传给它。项目定位优先使用 `LOOM_ROOT`，否则从当前目录向上寻找，再从可执行文件目录向上寻找。
- **脚本模式**：例如 `./build/loom.exe tests/run.php`，直接执行指定脚本。首参数以 `.php`、`.zy` 结尾、包含路径分隔符或指向现有文件时，会走这条路径；相对路径按当前工作目录解析。

直接执行脚本不会自动替脚本完成 Loom 应用初始化。需要框架的自建脚本先 `require` [framework/bootstrap.php](../framework/bootstrap.php)，再在闭包里 `require` `loom.php` 取得应用或调用 `Application::createBuilder()`，用 `$app->services->withScope()` 执行工作，并在 `finally` 中调用 `$app->dispose()`。

## 3. PHP 文件具体如何加载

需要区分四件事：**加载文件、声明类、注册组件、创建对象**。文件被加载时，其顶层代码会执行；声明一个类不等于创建该类的对象，注册路由也不等于调用控制器。

### 3.1 原生类先由 Go 注册

[host/modules.go](../runtime/internal/host/modules.go) 在执行入口脚本之前加载 `std`、PHP 兼容函数、Redis、HTTP、WebSocket、TCP、gRPC、system 和项目扩展。

因此，脚本中的 `Net\Http\Server`、`Net\Tcp\Server` 等原生能力来自运行时注册，不需要在项目中找到一个同名 PHP 文件。`Loom\Native` 的能力由项目原生扩展提供。

### 3.2 命令入口就是组合根

[loom.php](../loom.php) 加载框架清单后，用顶层语句显式声明主机和作用域，对应 ASP.NET Core 的 `Program.cs`：`Application::createBuilder()` 得到构建器，往 `$builder->services` 按生命周期登记，`build()` 得到应用，再 `use()` 中间件、`mapGrpc()`、`mapCommand()`，最后 `run()` 并 `dispose()`。直接执行时读取 `LOOM_ENV_FILE` 或项目根的 `.env`。入口不调用 `chdir`，也不探测 `vendor/autoload.php`。业务类由宿主在执行脚本前写入 Origami 类路径。见 [宿主、应用与选项](hosting.md)。

测试和自定义脚本在闭包里 `require` `loom.php`：被包含时它不读 `.env`、不运行命令，而是返回构建好的应用；闭包里先定义的 `$configureServices` 在构建前收到 `ServiceCollection`，用来替换服务。临时项目根没有 `loom.php` 时，用 `Application::createBuilder($root)`。

### 3.3 框架自身使用显式清单加载

[framework/bootstrap.php](../framework/bootstrap.php) 按照固定清单加载框架文件：

```php
foreach ([
    // 省略其他框架文件
    'DependencyInjection/ServiceCollection.php',
    // ...
    'Hosting/ApplicationBuilder.php',
    'Hosting/Application.php',
] as $module) {
    require_once __DIR__ . '/src/' . $module;
}
```

这是显式加载，不是扫描 `framework/src/`，也不是按 `Loom` 命名空间自动推导路径。这样可以保持 Origami 兼容性，并让目录分类与公共类名分离。例如 `framework/src/Http/HttpServer.php` 声明的是 `Loom\HttpServer`。

新增框架文件时，仅把文件放进目录不会自动加载，通常还需要加入这份清单，并注意接口、基类等声明的依赖顺序。`require_once` 避免同一执行环境内重复包含已经加载的文件；它不表示整个系统只会有一个该类的对象，也不跨独立进程共享加载状态。

### 3.4 ApplicationBuilder 初始化与业务类路径

[ApplicationBuilder](../framework/src/Hosting/ApplicationBuilder.php) 构造时依次：

1. 调用方传入环境文件时，先加载该文件。
2. 创建以项目目录为根的 `Config`。
3. 加载 PHP 配置文件。
4. 创建 `ServiceCollection`，登记框架默认服务。

它不注册自动加载，也不读取 `vendor/autoload.php`。宿主在执行脚本前，从脚本所在目录向上寻找 `loom.php`，把存在的目录写进 Origami 类路径：

```text
App\             → <项目>/app
Generated\Grpc\  → <项目>/generated/grpc
```

因此 `App\Http\Controllers\Projects\CreateTaskController` 对应 `app/Http/Controllers/Projects/CreateTaskController.php`。目录不存在就跳过，避免在临时脚本或夹具项目上打印警告。工作目录保持调用方原来的位置。`use` 只是类名别名，`::class` 只是字符串；真正加载发生在 Origami 解析这个类的时候。框架类仍然走显式清单，因为 `Loom\HttpServer` 这类名字和 `framework/src/Http/HttpServer.php` 的路径并不对应。

### 3.5 配置文件通过 require 读取返回值

[Config::load()](../framework/src/Config/Config.php) 扫描 `config/` 直属的 `*.php`，排序后逐个执行 `require`，以文件名作为配置键保存返回值。

```php
// loom.php（节选）
$builder->addModule(\App\AppModule::class);
$app = $builder->build();
```

启动模块在组合根里显式登记，不再通过 `config/` 下的 PHP 文件返回模块类名数组。

PHP 配置缓存在 `Config` 实例中，重复 `load()` 不会重新执行这些文件。所有配置（包括 HTTP、数据库和协议配置）都走同一条 PHP 加载路径；环境变量在各配置文件中读取，一并缓存。`get()` 返回全部文件命名空间，`get('http.address')` 按点号读取。`Config` 不保存或执行容器注册回调；服务与模块登记写在 `loom.php` 里。配置文件应只返回配置，不要依赖加载过程中读取其他配置。

### 3.6 模块扫描会主动触发业务类加载

模块声明如：

```php
#[\Loom\Attributes\Module(['App' => 'app'])]
final class AppModule {}
```

其执行链是：

```text
loom.php 中 addModule() 登记模块类名
  → ModuleDiscovery 对模块做 ReflectionClass
  → 读取 Module 注解中的扫描范围
  → ClassDiscovery 递归枚举 PHP 路径，推导完整类名
  → 对每个类做 ReflectionClass，触发必要的自动加载
  → 读取并校验 Controller、Route、Service、Job 等注解
  → ApplicationBuilder::build() 把声明登记到服务、路由、命令、任务和定时器
```

[ClassDiscovery](../framework/src/Module/ClassDiscovery.php) 根据路径推导类名，不通过解析文件内容寻找任意类名；它排序路径、跳过符号链接，并递归处理目录。因此文件路径、命名空间和类名必须一致，扫描目录不适合存放不声明对应类的普通执行脚本。

**自动加载器支持按需加载，但模块发现会提前加载扫描范围内的类来读取元数据。** 不能理解为只有某个路由被访问时，对应控制器文件才第一次加载。发现阶段不创建业务服务对象，但会实例化注解对象、校验模型等声明；文件顶层语句也会随加载执行，所以业务文件顶层应避免数据库写入、输出内容或启动后台任务。

模块声明的扫描映射本身不会增加类路径。`App` 和 `Generated\Grpc` 之外的命名空间不会由这个宿主自动映射。

### 3.7 应用在启动时装配一次

`ApplicationBuilder::build()` 把 `addModule()` 收集的名单交给 `ModuleDiscovery::configured($config, $modules)`：扫描并校验声明、预构建注解路由表，然后冻结。带 `Singleton` / `Scoped` / `Component` 的类用 `tryAdd` 补登记，`Cli\Annotation\Command` 类进入命令表，`Timer` 类交给 `TimerRunner`，`Job` 交给 `JobDispatcher`。`ModuleDiscovery`、`Router`、`HttpKernel`、`Console` 和 `Grpc\Dispatcher` 作为实例登记在根容器上。

`build()` 返回后，`loom.php` 再用 `use()`、`mapGet()` 等、`mapCommand()`、`mapGrpc()` 往同一张路由表、命令表和 gRPC 方法表里追加。路由表属于应用，不按请求重新装配，也不加载 `routes/*.php`。每个请求、消息、调用或命令由宿主开一个作用域，控制器、中间件、`RequestContext` 和 `Database` 从这个作用域解析。修改模块声明或 `loom.php` 后需要重启应用。

### 3.8 模板也通过 require 执行

[View::render()](../framework/src/View/View.php) 在闭包内 `require $template`，向模板提供 `$data`，用输出缓冲收集 HTML。每次渲染都需要重新执行模板，因此这里也不使用 `require_once`。

模板中的 HTML 和 PHP 表达式共同产生响应正文。模板路径由应用选择，展示用户输入时使用 `Loom\escape()` 转义。

### 3.9 文件加载与请求生命周期的区别

服务启动时加载框架声明，执行 `loom.php`，装好应用的路由表。每次请求创建新作用域，只在这次作用域里解析控制器和执行期服务，不再重新执行 `loom.php`。重新创建对象不意味着重新启动虚拟机，也不意味着所有类文件都重新从磁盘执行一次。

修改框架或业务类、注解后，应重启长期运行的服务；不要把重复装配当作源码热重载。队列作业使用独立宿主进程，执行 `loom.php _job`，重新走一遍入口的初始化链路。

## 4. 一次请求怎样执行

```text
Go 侧 HTTP 服务接收请求（可与其它请求并发）
  → HttpServer::handle() 用 withScope() 开这次请求的 ServiceScope，并绑定作用域的 Database
  → RequestFactory 转换 Request
  → 普通业务请求交给启动时装好的 HttpKernel（/assets/ 由 StaticFiles 处理）
  → HttpKernel 在 RequestContext 上绑定当前请求，从本次作用域解析控制器和中间件
  → 全局中间件
  → Router 匹配方法和路径
  → 绑定路径参数，执行路由中间件
  → ActionResolver 从容器解析控制器
  → 执行业务，返回 Response
  → HttpTransport 写出响应
  → 释放这次请求的作用域；连接回池，不关进程级连接池
```

[Router](../framework/src/Routing/Router.php) 只匹配路由，返回定义和参数，不创建控制器。[HttpKernel](../framework/src/Http/HttpKernel.php) 组织中间件与处理器执行。路由中间件可以提前返回响应，此时后续控制器无需实例化。WebSocket 也在路由中间件放行后才升级连接。

全局中间件包裹 404、405 等路由响应；未捕获异常由 `HttpServer` 返回通用 500，细节写入标准错误。

## 5. 作用域与依赖注入

Go 宿主和 Origami 虚拟机常驻，HTTP 回调可以并发。这不是 php-fpm 的一进程一次请求。作用域只有两层：根 `ServiceProvider` 持有单例，每个工作单元（HTTP 请求、TCP 消息、gRPC 调用、命令、队列或调度节拍、定时器节拍）一个 `ServiceScope`。`RequestContext` 和 `Database` 登记为 scoped。路由表、命令表和 gRPC 方法表是根容器上的实例。`withScope()` 保证异常路径也会释放这次的作用域。

[ServiceCollection](../framework/src/DependencyInjection/ServiceCollection.php) 登记，[ServiceProvider](../framework/src/DependencyInjection/ServiceProvider.php) 缓存单例并检测循环依赖，[ServiceScope](../framework/src/DependencyInjection/ServiceScope.php) 缓存本作用域的 scoped；`ConfigInjector` 负责构造参数和配置属性注入。

- `addSingleton`：根容器上一份，所有作用域共用；构造时不能依赖 scoped 服务。
- `addScoped`：每个作用域一份；未登记具体类的默认生命周期，避免并发请求共用一份。
- `addTransient`：每次解析创建对象。
- `addInstance`：登记已构造的对象。
- `configure`：把配置节绑定为 singleton 选项对象。绑定规则见 [宿主、应用与选项](hosting.md)。
- `addHostedService`：登记由 `Host` 启停的后台服务。`serve` 的启动顺序见 [宿主、应用与选项](hosting.md)。

同一标识登记多次时取最后一条，应用登记覆盖框架默认值。控制器通过类型化构造参数表达依赖，容器递归解析；非类名标识可用 `Container\Annotation\Inject` / `Named`，配置使用 `InjectConfig` 或工厂。`loom.php` 的显式登记优先于模块服务生命周期注解。

`ServiceScope::run()` 在回调期间把本作用域的 `Database` 绑定到执行帧，模型静态查询因此用这一份。事务只属于调用它的那一次 `transaction()` 回调，作用域不拥有连接。

## 6. 数据访问与真实接口示例

模型查询的关系是：

```text
Task::whereUserId(...)->whereId(...)->first()
  → ModelQuery 保存该 Database，并在查询对象上累积条件和绑定参数
  → 向进程级连接池借连接；若查询创建于事务回调内，则使用该事务
  → MySQL
```

`$database` 提供 DSN 和查询入口。条件和绑定参数留在查询上。模型和同一次 `transaction()` 回调里的原始 SQL 使用同一个事务。模型的 `Table`、`Column`、`Id`、`GeneratedValue` 注解描述映射；表结构由迁移维护。具体边界见 [模型文档](models.md)。

[CreateTaskController](../app/Http/Controllers/Projects/CreateTaskController.php) 是完整而简短的阅读示例：

1. 注解注册 `POST /api/studio/tasks`。
2. `RequireWorkspaceAccess` 检查工作台访问条件，`ParseJsonInput` 解析 JSON。
3. 控制器取出当前用户，校验标题和可选项目 ID。
4. 项目查询同时约束项目 ID 与用户 ID。
5. `Task::create()` 用当前作用域的数据库持久化任务，返回 201 JSON 响应。

简单接口可以直接使用 Model；复杂业务再用 Service 组织流程，Repository 封装关联查询。框架不会自动给所有查询加当前用户条件，资源归属仍需显式约束。

## 7. Timer、Queue 与 Schedule

- **Timer**：作为 `HostedService` 跟随 HTTP 服务进程运行，由 `Host` 在监听前启动、退出时停止。按固定周期触发，每次触发在新作用域里解析定时器；同一定时器不重叠执行，忙碌期间的触发跳过。只有 `serve` 启动 Host，多 HTTP 进程会各自执行自己的定时器。与队列、调度的进程边界见 [宿主、应用与选项](hosting.md)。
- **Queue**：`dispatch()` 写入 Redis Stream（事务提交后入队）；`loom_jobs` 仅归档。独立 `queue:work` 消费，每进程唯一 consumer，子进程执行与 PEL 回收、死信与延迟迁移见 [framework.md](framework.md)。应用事件可 `queued: true` 异步监听。
- **Schedule**：独立 `schedule:work` 计算到期计划，在数据库事务中生成任务并推进下次执行时间，实际任务交给队列处理。

队列是至少一次交付，业务处理必须考虑幂等。启动 HTTP 不会自动启动队列和调度进程。

## 8. 建议阅读顺序

先阅读一条完整链路，再深入扩展机制：

1. [loom.php](../loom.php) 与 [宿主、应用与选项](hosting.md)：理解入口和应用组合。
2. [HttpServer](../framework/src/Http/HttpServer.php) 与 [ServiceScope](../framework/src/DependencyInjection/ServiceScope.php)：理解进程和请求生命周期，规则见 [依赖注入与作用域](core-services.md)。
3. [ApplicationBuilder](../framework/src/Hosting/ApplicationBuilder.php) 与 [HttpKernel](../framework/src/Http/HttpKernel.php)：理解装配与执行的区别。
4. [CreateTaskController](../app/Http/Controllers/Projects/CreateTaskController.php) 与 [Task](../app/Models/Projects/Task.php)：理解真实业务如何落库。
5. [ModuleDiscovery](../framework/src/Module/ModuleDiscovery.php)：理解扫描、注解和注册。
6. [Queue](../framework/src/Queue/Queue.php) 与 [Schedule](../framework/src/Schedule/Schedule.php)：理解跨进程任务处理。
