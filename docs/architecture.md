# 当前架构

Loom 的产品目标是通用型后台，为不同业务提供统一管理工作台与可扩展模块。首页 `/` 是介绍后台的产品官网，`/guide` 提供开发指南；`/admin` 提供统一管理后台，`/demo` 发布准备室作为业务场景示例。用户管理、角色权限、菜单管理、系统配置、操作审计和账户安全已实现，使用说明见 README。

`framework/` 是随项目开发的内部基础设施，不是单独发布的框架产品。业务使用 `App` 命名空间，框架保留 `Loom` 公共接口，生成基类使用 `Generated\Grpc`。

## 启动与依赖

项目自有 Go 源码统一放在 `runtime/`，包括宿主入口、标准库装配、原生扩展和 Go 测试；Go 模块文件也放在该目录。`app/` 与 `framework/` 保持脚本职责。自定义宿主由 `runtime/cmd/loom` 构建到 `build/loom.exe`，负责初始化 Origami、装配标准库和项目扩展；目录结构与依赖管理见 [运行时目录说明](../runtime/README.md)。

`loom.php` 调用 `framework/bootstrap/autoload.php` 返回的函数并传入项目根目录。加载函数返回显式 `ServiceScope`，装配 Config 与容器。它不改工作目录，不加载 Composer，也不注册命名空间。宿主按脚本所属项目把 `App` 和 `Generated\Grpc` 写入 Origami 类路径。CLI 在 finally 中关闭作用域。框架自身资源相对 framework 定位，业务文件相对项目根目录定位。

框架模块按 Application、Http、Routing、Container、View、Database、Auth、Queue、Schedule、Console、Config、Process、Network 划分。目录拆分没有改变已有公共类名。`framework/bootstrap.php` 显式加载模块，以兼容 Origami。

HTTP 核心按职责组合：`ApplicationFactory` 创建应用作用域，`Application` 暴露装配入口，`Router` 保存路由并实现 `RouteMatcher`，`HttpKernel` 处理请求，`Pipeline` 执行中间件，`ActionResolver` 延迟解析控制器。路由结果只包含定义与参数，不持有容器或原生连接。模块发现由 `ModuleDiscovery` 完成，`ModuleRegistry` 负责消费声明和运行时装配。

`Server` 负责启动宿主服务器。定时器和其他 `HostedService` 由 `Host` 在监听前按登记顺序启动，退出时按相反顺序停止。HTTP 监听地址和入口来自 `HttpServerOptions`。`HttpRuntime` 衔接原生请求与应用工厂，`RequestFactory` 转换请求，`HttpTransport` 发送响应及升级 WebSocket，`StaticFiles` 处理公开资源。`RequestScope` 在 finally 中结束请求和传输上下文。数据库连接池属于进程；事务只存在于 `transaction()` 回调。HTTP 请求、TCP 消息、gRPC 调用和作业分别创建作用域并在 finally 中关闭。原生 IO 仍由 Origami 适配。

容器的生命周期注册与对象创建策略分离：singleton 跨应用子作用域共享，scoped 按执行作用域缓存，transient 每次创建，alias 委托目标生命周期；对象缓存委托原生容器，配置注入通过 `ObjectFactory` 的默认适配器接入。Config、Database、Auth 已改为实例服务，控制器、中间件、任务、命令和业务服务使用构造器注入。Config 属于应用配置，Database 与 RequestContext 属于执行作用域；Auth 注入这两个服务，不读取全局请求。模型继承 Loom\Model，使用 public 强类型属性和原生数据库注解。ModelContext 只在进程里仅有一个活动作用域时提供它的 Database。ModelQuery 保存调用方传入的 Database，条件和绑定参数留在查询对象上。普通读写使用进程池；在事务回调里创建的查询使用该事务。关联查询和归属限制保留在应用仓储。旧实体管理器和手写查询器已移除。

通用 HTTP、CLI、数据库、任务、TCP、gRPC 装配在 `framework/bootstrap/`。`config/modules.php` 指定带 `Module` 注解的启动模块，`ModuleDiscovery` 通过 `ClassDiscovery` 统一发现并校验服务、模型、路由、队列、定时器和命令声明。业务默认用类/方法注解注册，`config/services.php` 提供显式工厂覆盖，`routes/` 提供手动扩展。TCP 处理器、迁移命名空间及网络设置分别在 `config/tcp.php`、`config/migrations.php` 和 `config/network.php`，gRPC 协议清单保持由 proto 生成。通用 JSON 中间件属于框架；工作台权限中间件 RequireWorkspaceAccess 属于 app。

## 后台管理边界

`App\Services\Access\Admin` 实现角色权限、管理事务和审计；`AdminController` 使用现有 Auth 会话和 CSRF 校验。每次请求从数据库读取权限与启用状态，权限更新无需重新登录。管理员初始化通过 `admin:bootstrap` 控制台命令完成。内置角色受保护，最后一名启用管理员不可移除。业务数据继续以 user_id 隔离。

`005_admin` 添加独立的角色、账号状态、菜单、设置、审计和账户邮件表，不改变历史迁移。`Account` 负责验证码与邮件队列；SMTP 原生传输在 `runtime/internal/extensions/mail.go`，默认验证 TLS 证书并限制传输时间。邮件凭据由环境变量注入，Web API 不提供读取邮件正文或验证码的入口。`admin:mail` 独立进程负责重试，HTTP 请求只入队。

后台前端源码位于 `frontend/admin/`，使用 Vue 3、TypeScript、Vite、Vue Router、Pinia 与 Element Plus，独立构建到 `public/admin/`。后端 `/admin` 只提供构建入口，业务通过 JSON API 通信；开发代理与生产同域网关保持 Cookie、Origin 和 CSRF 校验。详见 [后台前端说明](../frontend/admin/README.md)。官网不加载管理页面资源，后台不会把仅对管理员开放的数据嵌入初始 HTML。

## 协议与生成文件

`proto/` 是协议源文件；`generated/grpc/` 存基类和 `routes.json` 注册清单；`app/Grpc/Services/` 存手写实现。生成文件提交到版本库并随应用部署，不能只复制 app 而遗漏 generated。

`grpc.package_prefix` 明确指定转换代码命名空间时去掉的 proto 包前缀。当前为 `app`，例如新建 `app.billing.Invoice` 契约时，对应 `App\Grpc\Services\Billing\Invoice` 和 `Generated\Grpc\Billing\InvoiceBase`。应用现有服务是 `proto/release.proto` 中的发布验收规则，手写路由位于 `App\Grpc\ApplicationRoutes::routes()`，通过 `loom.php` 绑定 `Loom\Grpc\RouteProvider` 接口；生成清单初始为空。线上 RPC 路径不受代码命名空间映射影响。改变映射属于源码迁移，需要同步业务类、生成基类和清单；生成器不会替开发者搬动既有手写文件。

## 数据与迁移

业务存储统一为 MySQL，Redis 保存业务任务队列与登录会话，配置见 [MySQL 与 Redis](mysql-redis.md)。测试通过独立 MySQL 数据库隔离。日志放 logs，进程状态放 run，测试产物放 testing，旧备份放 archive，上述运行数据不提交。

框架初始化 SQL 位于 `framework/resources/migrations/base/`，记录在 `loom_base` 命名空间。原有 Cron 迁移位于 `framework/resources/migrations/`，保留 `loom` 命名空间、版本号与原始 SQL 字节，避免已有校验和漂移。初始化迁移使用 IF NOT EXISTS，允许已有数据库登记基线。业务迁移继续位于 `database/migrations/`；应用 migrate:down 只回退业务批次。

## 页面、工具与验证

模板分为 `official/` 产品官网与指南、`demo/` 发布验收场景，由应用 Presentation\SitePages 映射页面名称。两者使用独立布局及 `public/official/`、`public/demo/` 资源；public 是可直接公开的 CSS、JS、图片，没有重复维护一套构建源码。

`framework/tests/` 验证框架机制，`tests/integration/` 验证业务装配和生成器，`tests/e2e/` 验证真实网络。请求隔离探针只在 `tests/fixtures/http/` 应用注册；正常应用的 `/api/isolation` 返回 404。`tests/run.php` 是脚本测试入口，`tests/run-all.ps1` 包含全部端到端验证。

应用不注册问候、回声或返回固定假用户的协议接口。TCP 的 `ReleaseSnapshotHandler` 读取发布任务快照，gRPC 的 `proto/release.proto` 校验验收规则；WebSocket 升级、传输和同源校验放在独立 HTTP 测试应用中验证。模型覆盖测试检查 MySQL 的全部持久化表、字段映射及复合主键操作。

`scripts/` 只存开发工具。`build/` 只保留本机构建产物，Python 测试依赖放 `.venv/`。历史上直接修改 Origami 源码的临时补丁保存在 `docs/history/upstream-patches/`，不参与安装、启动或测试；正式上游依赖与缺口见 [运行时要求](runtime-requirements.md)。旧迁移设计见 [历史方案](history/script-runtime-migration.md)。

## Origami std 复用边界

以下结论针对 此前使用的 `ef4fe76d3195` 版本，根据固定版本 std 源码和 Loom 宿主实测。原生 Inject 修复已合入上游，默认构建直接使用固定依赖，不再应用本地补丁。

- `std/container` 已由 `std.Load()` 加载。Loom 容器委托 `Container\Container` 的 singleton、bind 工厂和 make 处理缓存、工厂执行、循环依赖及失败后的解析状态清理，根容器缓存应用 singleton，业务作用域分别缓存 scoped；Go 锁保护并发单例构造。配置属性/构造参数注入仍由 Loom 适配，因为 std 容器仅提供构造器注入，且默认自动装配策略不同。
- `std/database/annotation` 提供 Table、Column、Id、GeneratedValue，模型直接使用这组原生注解。表名和列名来自原生元数据，类型来自属性声明；Id 用于模型主键，GeneratedValue 标记数据库生成字段。Loom 不另定义数据库映射注解，也不设置批量写入字段白名单。当前原生注解的 getArguments() 在探针中返回空数组，不能依赖它恢复原始参数。
- `std/container/annotation` 提供 Component、Singleton、Scoped、Bind、Inject、Named。它们带注册/宏执行行为，不能当成普通 PHP 元数据注解直接替换。旧版本中，原生 Inject/Named 因缺少参数所属类信息而在加载时失败。该问题已由上游提交 `ef4fe76d31954059dfa362168cce19fa7ae23679` 修复，支持普通/提升参数及继承构造器，并拒绝非构造器目标。Loom 的 Inject 仍承担自身配置注入适配契约，本次没有改动应用注解 API。
- `std/net/annotation` 提供 Application、Controller、Route、GetMapping/PostMapping 等。原生 Route 表达类级前缀，映射宏与原生 HTTP handler 参数绑定；Loom Route 还承担 Request/Response、中间件顺序、命名路由和 WebSocket 适配，暂保留该契约。原生 CLI Command 使用 execute()，也不等价于现有签名解析和 handle() 命令契约。
- 当前版本未发现可直接提供“固定周期、超时跳过、同任务不并发”契约的标准库定时器；TimerRunner 保留这部分调度策略，继续复用 std spawn 和 PHP 时间函数。队列的租约、重试、隔离进程以及业务写入白名单也属于 Loom 策略。

后续增加基础能力前先核对固定版本 std 的实现和行为测试；能直接使用的能力直接使用，语义有差异的能力通过适配层接入，确认的上游缺口应先记录再考虑补齐。


模型接入的 v1.0.8 行为差异及适配边界见 [基础模型](models.md)：连续 `where()` 会替换而不是追加条件，对象插入会丢掉 0、false 和空字符串。`ModelQuery` 因此自己累积条件，并自己生成保留这些值的 INSERT/UPDATE。查询器原先只保存 `*sql.DB`；`Database\bind` 让它也能在 `Database\Sql` 返回的 `*sql.Tx` 上执行。PDO 不是连接模型。

## 历史标识

Studio 不是独立项目或产品名称。现存 `studio_*` 表名、`studio` 迁移命名空间及 `/api/studio` 接口属于早期项目与任务模块的历史标识；修改迁移命名空间会使已有迁移被重新识别，不能随类名整理直接替换。代码按身份、权限、项目、订单、通知和发布验收等职责组织。应用迁移记录通过 `app:migrations --json` 查看，命令从配置读取命名空间。

演示入口仅保留发布验收这一具体场景，原工作室、协议实验室和 HTTP 实验页面跳转至 `/demo`；相关独立模板、脚本和技术演示控制器已移除。后台项目与任务功能继续由 `/admin` 提供。迁移事务机制在框架测试中验证，不再通过业务接口创建临时笔记本表。
