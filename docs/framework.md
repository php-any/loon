# Loom 框架能力

Loom 是面向 Origami 的新 PHP 框架。框架提供 HTTP Kernel、隔离执行、PHP 应用核心，以及 SQLite、认证会话、持久化队列与周期调度。内置 Studio 将这些能力串成真实的个人任务与后台摘要应用，完整生产框架仍在建设。

## 启动应用

在仓库根目录运行：

```powershell
go -C runtime run ./cmd/loom --root .. --addr=127.0.0.1:18087
```

浏览器打开 `http://127.0.0.1:18087/`。首页展示框架设计与实时问候；`/playground` 提供 Action、严格输入契约、六请求并发隔离三个真实交互实验；`/guide` 提供启动、视图、容器与生命周期指南。普通 GET 表单仍可在无 JavaScript 时提交到 `/welcome`，由 PHP Action 渲染转义后的 HTML。`/hello/Ada` 返回 JSON；POST `/greetings` 接受 `application/json` 请求体 `{"name":"Ada","times":2}`，返回 201。

更新服务时先确认并关闭旧 Loom 进程，再在同一个 `18087` 端口启动。PHP 页面在请求时加载；`public/` 的 CSS、JavaScript 和图标从 public 目录读取，修改后无需重新编译宿主。

```powershell
curl.exe --max-time 10 http://127.0.0.1:18087/hello/Ada
```

自定义入口使用 `--entry=<文件路径>`，相对路径以工作目录为基准；命令与后台入口由 config/loom.json 配置，默认后台入口为 `bootstrap/jobs.php`。应用使用内嵌 SQLite 驱动，不需要独立数据库服务，不加载 Laravel/Symfony 框架桥接。`-mod=readonly` 在独立项目中无需指定；本地运行时依赖通过 runtime/go.work 连接。

## Studio：认证 → 数据 → 后台工作

打开 `/studio`，创建本地账号后可添加、完成和删除自己的任务；生成摘要立即返回 202，worker 在独立 RequestVM 执行 `bootstrap/jobs.php`，页面每三秒读取状态。支持延迟 10 秒执行和每分钟自动生成摘要，可关闭周期任务。数据库默认为 `storage/studio.sqlite`，使用 `--database=<路径>` 指定其他文件。此目录被 Git 忽略，不包含预置密码或用户数据。

- `Database::query/execute` 使用参数绑定；`Database::batch` 将多条语句放在同一事务中，失败回滚。SQL 是可信应用代码，参数不能代替表名或列名。当前仅 SQLite；Studio 使用版本化迁移和显式模型 ORM，见下文。
- `Auth::register/login/user/guard/logout` 提供 bcrypt 密码认证、24 小时数据库会话与会话撤销。随机令牌只存摘要，Cookie 为 HttpOnly、SameSite=Lax，HTTPS 下启用 Secure。重新登录轮换当前会话。注册、登录每个邮箱每分钟最多 10 次尝试；此限制不能替代部署层的来源限流。
- `Auth::guard()` 对写操作检查会话 CSRF 与精确 Origin；注册和登录也检查 Origin。CLI 调用必须提供与服务一致的 Origin，不能信任任意代理头。应用通过 `user_id` 条件实现资源归属，不提供隐式全局授权。尚无邮箱验证、账户恢复和通用 Policy。
- `Queue::dispatch($kind, $payload, $owner, $delay)` 返回持久化 Job ID，最大延迟 30 天。宿主 `Services.Work` 每秒扫描，原子领取任务、使用 60 秒租约、最多三次自动尝试，退避为 2 秒和 8 秒；worker 任务超时 30 秒，应用 PHP Handler 为 10 秒。取消仍是协作式，不能强行中断不响应取消的原生操作。
- `Schedule::every($name, $seconds, $kind, $payload, $owner)` 持久化周期任务（10 秒至 30 天）。同名重复注册不重置下一次执行时间；停机错过的周期合并为一次到期任务。周期任务写入队列和推进时间在同一事务内。固定秒数间隔与 Cron 日历调度共用队列。
- 队列是**至少一次交付**。Studio 使用唯一 `job_id` 和单条 INSERT SELECT，使摘要副作用幂等；其他应用必须为自己的外部副作用设计幂等键。原生并发领取测试验证同一可用任务不会同时被多 worker 领取，租约过期后仍可能重投。
- `runtime/framework/services.go`、`runtime/framework/worker.go` 与 `runtime/framework/php/services.php` 属于框架，`runtime/internal/host/storage.go`、`routes/studio.php`、`app/Jobs/Digest.php` 属于应用业务。Go Services 只持有连接等进程资源，不保留 PHP 请求对象。应用一个 worker 串行执行后台任务，HTTP 请求可继续并发。

## 当前执行契约

### WebSocket

Loom 复用 Origami 标准库的 `Net\Websocket\upgrade()` 和 `Net\Websocket\Conn`，使用同一个 HTTP 监听端口。每个连接拥有独立 RequestVM，连接内可保留 PHP 局部状态，连接之间不共享应用对象。

```php
$app->websocket('/ws/{room}', function (\Loom\Request $request, \Net\Websocket\Conn $connection): void {
    $connection->writeText($request->parameter('room'));
    $connection->writeText($connection->readText());
}, 'room.socket');
```

也可传入 `[Controller::class, 'method']` 或 `[$controller, 'method']`。类名通过现有容器解析，方法接收 `Request` 与 `Net\Websocket\Conn`，无需实现接口。单动作 `__invoke` 控制器继续可用，`Loom\WebSocketAction` 为可选接口。回调返回或抛出异常后宿主关闭连接，客户端断开时标准库 `readText()` 会抛出异常。`writeText()` 发送文本；当前标准库 `readText()` 返回消息载荷，并未严格拒绝二进制帧。此接口尚未提供广播、房间管理或跨进程消息分发。

已提供 `/ws/echo` 示例：接收一条消息、原样回复，然后关闭连接。启动后在站点的浏览器控制台运行：

```javascript
const socket = new WebSocket(`${location.protocol === 'https:' ? 'wss:' : 'ws:'}//${location.host}/ws/echo`);
socket.onopen = () => socket.send('你好 Loom');
socket.onmessage = event => console.log(event.data);
```

普通 HTTP 访问 WebSocket 路由返回 426；握手强制启用标准库同源检查（无 Origin 的非浏览器客户端允许连接）。鉴权必须在升级前完成：需要鉴权的端点可用普通 `$app->get()`，先调用 `Auth::user()` 等校验，再调用 `Loom\WebSocket::accept()` 并使用 `try/finally` 关闭连接，最后返回空的 204 Response。Origin 检查不能替代身份和权限校验。

Go `loom.Config` 提供 `MaxWebSocketConnections`（默认 64）、`MaxWebSocketMessageBytes`（默认 1 MiB，入站消息）、`WebSocketTimeout`（默认 1 小时，包含握手和 PHP 执行的总生命周期）。升级请求使用独立并发额度和超时，不占用普通 HTTP 请求额度；到期或请求上下文取消会关闭底层连接，解除阻塞读取。回调正常结束也会关闭连接。标准库当前 `close()` 直接关闭传输连接，不保证完成 WebSocket close-frame 握手。

宿主将停止信号传入请求上下文，以便关闭已升级的连接。自定义 Go 宿主也应设置 `http.Server.BaseContext` 并在退出时取消它，因为 `http.Server.Shutdown` 本身不会关闭已劫持的连接。反向代理须转发 HTTP/1.1 Upgrade/Connection 头，并设置合适的长连接超时。

### ORM、迁移与 Cron

`php/orm.php` 提供 `Model` 和不可变 `Query`。模型显式声明 `$table/$columns/$fillable/$casts`，支持 create、hydrate、get/set、脏属性 save 和 delete。查询支持 where（含 NULL）、orderBy、limit、get/rows、first/firstOrFail、count，以及受条件保护的 update/delete。值使用绑定参数；列名、操作符、排序方向均校验。默认/最大读取 1000 条，不暗示完整分页。Query 写操作不能带 order/limit。关系通过模型方法返回 Query 或 Model；Studio 的 `StudioProject::tasks()` 和 `StudioTask::project()` 同时限制用户归属。当前没有 eager loading、复杂 JOIN DSL、乐观锁或 ORM 事件系统。

`migrations.go` 的 `Services.Migrate(ctx, namespace, plan, down)` 用 SQLite `BEGIN IMMEDIATE` 串行化迁移检查与执行，记录版本、Up/Down 校验和、批次和时间。版本必须严格递增；已应用版本缺失、内容漂移、非前缀历史均拒绝。所有 pending 版本同批原子提交，失败 DDL 与历史记录一起回滚；down 逆序回滚最后一批。SQL 文件由可信应用提供，不得包含 BEGIN/COMMIT 等事务控制语句。

Studio 的 `database/migrations/*.up.sql` 与 `*.down.sql` 运行时从 database/migrations 加载。001 接纳旧版表，002 新增项目关系；旧任务保留，project_id 初始为 NULL。启动自动升级，不自动回滚。命令：

```powershell
go -C runtime run ./cmd/loom --root .. --migrate=status
go -C runtime run ./cmd/loom --root .. --migrate=up
# 回滚会执行 down SQL；先停止服务、备份并审查最后一批的内容。
go -C runtime run ./cmd/loom --root .. --migrate=down
```

支持 `--database=<路径>` 选择数据库。新安装的一个批次可能包含多个版本，down 不等于只撤销一个文件。交互演示 `/api/studio/migrations/demo` 受登录与 CSRF 保护，使用独立内存库真实执行升级、回滚、失败 DDL 回滚与重复升级；不回滚 Studio 数据。页面另行展示实际 Studio 版本记录。

`Schedule::cron(name, expression, timezone, kind, payload, owner)` 注册五字段 Cron；`Schedule::preview(expression, timezone)` 使用同一解析器返回后续五次带偏移的时间。支持数字、星号、列表、范围和步长，周日 0/7，日与周同时受限时采用 OR。明确使用 IANA 时区（内置 tzdata）；DST 缺失时间跳过，重复时间可分别执行，预览以 UTC offset 区分。此语义参考 [crontab(5)](https://www.man7.org/linux/man-pages/man5/crontab.5.html)。暂不支持英文月份/星期名、秒字段、L/W/#、宏。

下一次时间严格晚于给定时刻，无匹配表达式在八年搜索边界后明确拒绝。同名相同 Cron 不推迟已保存的 next_run；修改表达式、时区或调度类型会重算。停机期间的漏跑合并为一次到期任务。周期推进与入队同事务，单次扫描最多处理 100 个到期规则，再由 worker 继续扫描；交付仍是至少一次。

Studio 可以创建项目、把新任务归入项目、按模型关系筛选；Cron 面板提供规则预设、时区、五次预览、保存启用与暂停。自动摘要统计全部个人任务，项目筛选仅影响清单与顶部统计。

Go 宿主加载 Origami 通用标准库、PHP 标准库和 Loom 原生边界。每个请求创建 `RequestVM`，先 `Activate`，再安装可清理的 deadline，绑定 HTTP 请求。内嵌 PHP 框架源码在请求作用域解析，应用入口也在该作用域执行。框架类、容器、Action 与业务实例均不跨请求共享。

入口必须返回 `$app->handle(Request::capture())->toArray()`，也可直接返回 `Response` 的 `toArray()`。宿主校验响应、执行 shutdown 回调后才提交 HTTP 状态、头部和正文。未捕获异常只在宿主错误回调记录细节，客户端得到通用 500。`OnError` 可并发调用，必须线程安全且不能 panic。

路由支持 `[Controller::class, 'method']`、`[$controller, 'method']`、单动作 `__invoke` 控制器及闭包。控制器公开方法接收 `Request` 并返回 `Response`，不强制实现接口；`Loom\Action` 保留为单动作控制器的可选契约。类名在匹配路由后通过容器延迟解析，复用显式依赖工厂。

```php
$app->get('/posts/{id}', [PostController::class, 'show']);
$app->post('/posts', [PostController::class, 'store']);
$app->websocket('/ws/echo', [\App\WebSocket\Handlers\EchoHandler::class, 'echoMessage']);
```

提供 `route`、`get/post/patch/delete`、嵌套 `group` 与命名 `url`。支持完整路径和单段 `{name}` 参数；URL 生成拒绝缺失、空值、斜杠和多余参数，逐段编码。大小写、尾斜杠均精确匹配；URL 路径采用 Go 解码后的 `URL.Path`。同方法可能匹配同一路径的路由在注册时拒绝。HEAD 优先使用显式 HEAD 路由，否则执行 GET，并在宿主抑制正文。404/405 使用 problem JSON，405 包含 Allow。分组组织路径并可显式配置中间件，不隐式安装认证。

### HTTP 中间件

推荐使用 `withMiddleware(...$middleware)` 与 `prefix($prefix)` 链式声明局部路由配置，再调用 `get/post/put/patch/delete/head/options/route/websocket` 注册单路由，或调用 `group($register)` 注册分组。多个中间件直接作为多个参数传入，无需为路由名称填写空字符串。

```php
$app->withMiddleware(ParseJsonInput::class)->post('/greetings', CreateGreetingController::class);
$app->prefix('/api')->withMiddleware(RequireUser::class)->group(function ($routes) {
    $routes->get('/profile', [ProfileController::class, 'show'], 'profile');
    $routes->withMiddleware(ParseJsonInput::class)->post('/posts', [PostController::class, 'store']);
});
```

每次 `prefix` / `withMiddleware` 返回独立的配置副本，可复用并派生新配置，不改变应用或兄弟路由；同一个配置上的连续路由注册共用该配置。分组回调接收应用对象，内部路由继承当前分组的前缀及中间件；退出分组或回调异常后恢复原有作用域。配置在调用注册方法时应用，构建但未使用的配置不影响请求。

`$app->middleware($middleware)` 仍按注册顺序添加**全局**中间件。原有 `group($prefix, $register, $middleware = [])` 和路由末尾中间件参数保持兼容。

```php
$app->middleware(function (\Loom\Request $request, callable $next): \Loom\Response {
    return $next($request)->withHeader('X-App', 'Loom');
});
$app->prefix('/api')->withMiddleware(AuditRequest::class, RequireUser::class)->group(function ($routes) {
    $routes->get('/profile', [ProfileController::class, 'show'], 'profile');
});

class RequireUser implements \Loom\Middleware
{
    public function handle(\Loom\Request $request, callable $next): \Loom\Response
    {
        $user = \Loom\Auth::user();
        return $next($request->withAttribute('user', $user));
    }
}
```

应用已在 `app/Http/Middleware/` 接入真实中间件：`RequireStudioUser` 统一执行登录、CSRF 与 Origin 校验，将用户写入 `user` 请求属性；`ParseJsonInput` 校验 JSON 媒体类型和对象结构，将 `Loom\Input` 写入 `input` 属性。`routes/studio.php` 对受保护路由分组注册前者，对需要 JSON 的路由注册后者；注册、登录保持公开。`routes/web.php` 的 `/greetings` 也使用 JSON 中间件。控制器通过 `$request->attribute('user')` 和 `$request->attribute('input')` 读取结果，字段级业务校验仍由控制器负责。无需请求体的删除、重试等操作不强制解析 JSON。

执行顺序为全局 → 外层分组 → 内层分组 → 路由 → 控制器，响应沿相反顺序返回。每层可调用 `$next($request)`，也可直接返回 `Response` 短路；后续中间件及控制器仅在实际执行时解析。支持闭包、可调用对象或数组，以及实现 `Loom\Middleware::handle` 的对象；类名通过应用容器延迟解析，可绑定显式依赖工厂。字符串表示容器服务类名，不表示函数名。

全局中间件包围路由匹配，因此也处理 404/405；分组和路由中间件仅在方法及路径匹配后执行，可读取路由参数。HEAD 回退到 GET 时使用 GET 中间件，正文仍由宿主抑制。`Request::withAttribute()` 返回副本，`attribute($name, $default = null)` 读取请求内上下文，显式 null 不会替换成默认值。全局中间件执行前尚无路由参数。

路由中的 `HttpError` 在路由分发边界转换为 problem 响应，全局中间件可继续处理该响应；全局中间件抛出的 `HttpError` 在应用外层转换。其他异常继续交给宿主处理。WebSocket 中间件在握手前运行，可拒绝升级；握手成功后的响应头修改不再影响已发送的握手。此管线仅覆盖 PHP HTTP 应用，不覆盖宿主静态资源、TCP 或 gRPC。

容器使用显式 factory 绑定，未绑定的类尝试无参构造；服务在一个执行作用域内复用。重复绑定、依赖环和非对象结果均报错。暂不提供自动构造注入、进程级服务、属性扫描或启动期路由清单编译。

`Request::json()` 要求 JSON 对象及 `application/json`。`Input::only` 拒绝未知字段，`string`、`integer`、`boolean` 拒绝隐式转换，并区分缺失、null、类型、长度或范围错误；字符串限制单位是字节。输入错误返回 422，JSON 格式错误返回 400，媒体类型错误返回 415。

`Response::json/html/problem` 创建响应；`withHeader` 返回副本并以大小写无关方式覆盖头部。当前头部契约是单值映射，尚不支持多个 Set-Cookie。`Loom\escape()` 仅适用于 HTML 文本和普通带引号属性，不适用于脚本、CSS 或 URL 安全校验。

## 资源与隔离边界

`View::render($template, $data)` 将应用指定的 PHP 模板渲染为字符串。模板通过 `$data` 访问显式数据，可组合内容与布局；独立输出缓冲在成功或异常时清理，异常继续向上传递。模板路径必须由应用控制，用户输入使用 `Loom\escape()` 显式转义。此能力不等于自动转义模板编译器。

- 默认请求体上限 1 MiB、响应正文上限 4 MiB、执行超时 10 秒、同时处理请求上限 64。超出并发上限立即返回 503，没有无界等待队列。
- 首版请求体只支持 UTF-8 文本，非法编码返回 400；二进制、multipart 和流式响应尚未实现。
- 响应大小限制发生在序列化后，不构成 PHP 堆内存配额。超时是协作式取消，不会遗弃仍在运行的 PHP goroutine，也不能强制终止不支持取消的原生调用。
- 外部宿主必须配置 HTTP ReadHeaderTimeout、ReadTimeout、WriteTimeout、IdleTimeout；仅设置执行上下文不能中断所有请求体读取。应用已配置这些超时。
- Action 通过 Response 输出。直接 echo、header、shutdown 输出，以及未关闭的输出缓冲都会导致响应失败。允许应用自行开启并正常关闭输出缓冲来生成字符串。
- 请求隔离复用 RequestVM；当前测试覆盖静态属性、GLOBALS、身份输入和 shutdown 修改。尚未完成全部 PHP 扩展的进程级状态审计，不能据此声称任意 PHP 扩展都已达到请求隔离要求。
- 普通 `spawn` 属于 Origami 现有扩展，Loom 当前没有提供结构化任务接口。应用不得将请求对象、容器、事务或业务对象传入脱离作用域的后台闭包。
- 尚未提供完整多租户策略、关系预加载、Outbox API、通用前端交互组件或完整模板编译器。Studio 已有基本认证、Session、CSRF 与资源归属检查；生产部署仍需 HTTPS、备份、全局限流、队列积压配额和观测。

## PHP 脚本命令（Loom Console）

参照 [Laravel Artisan](https://laravel.com/framework/docs/artisan/commands) 的签名和应用组织方式，命令在 `routes/console.php` 注册。它们由独立的 `bootstrap/console.php` 入口执行，不启动 HTTP 或 worker。

```php
use Loom\Console\Input;
use Loom\Console\Output;

$console->command('hello {name=世界} {--upper}', function (Input $input, Output $output) {
    $name = $input->argument('name');
    $output->info('Hello, ' . ($input->option('upper') ? strtoupper($name) : $name));
    return 0;
}, '向指定的人问好');
```

从仓库根目录运行（宿主选项放在 `command` 前，PHP 命令选项放在命令名后）：

```powershell
go -C runtime run ./cmd/loom --root .. command list
go -C runtime run ./cmd/loom --root .. command hello Loom --upper
go -C runtime run ./cmd/loom --root .. command help studio:tasks
go -C runtime run ./cmd/loom --root .. command studio:tasks 1 --completed --json
go -C runtime run ./cmd/loom --root .. command studio:digest 1 --dry-run
go -C runtime run ./cmd/loom --root .. command make:command SendDigest --command="mail:digest {user} {--force}"
```

生成器创建 `app/Console/Commands/SendDigest.php`，下一次执行自动注册。生成文件不覆盖现有文件，拒绝路径、保留字与重复命令名。命令类继承 `Loom\Console\Command`，声明 `$signature`、`$description`，在 `handle(): int` 中使用 `$this->argument()`、`option()`、`info()`、`error()`、`table()` 和 `json()`。可参照真实命令 `StudioAbout.php`。构造依赖通过 `$console->services()->bind(...)` 显式工厂注册，再调用 `$console->add(ClassName::class)`；不提供隐式构造或 handle 参数注入。

签名支持 `{user}` 必填、`{user?}` 可选、`{name=world}` 默认值、`{users*}` 必填数组、`{users?*}` 可选数组；数组参数必须最后，必填参数不能跟在可选参数后。选项支持 `{--force}`、`{--queue=}`、`{--queue=default}`、`{--Q|queue=}` 和可重复的 `{--id=*}`。字段描述写成 `{user : 用户 ID}`。短选项支持 `-Q value`、`-Qvalue`；`--` 结束选项解析。暂不支持短布尔选项组合、交互式提问、进度条、属性命令声明或 Artisan 的全部兼容接口。

`list`、`help name`、`name --help` 自动生成帮助。命令可通过 `$console->call(['name', 'argument', '--flag'])` 调用另一命令，不经过 shell。成功默认为 0，输入错误为 2，未捕获异常为 1；命令可显式返回 0–255。Go 应用把取消映射为 130、超时映射为 124。`go run` 自身会把子程序非零退出显示为 `exit status N`；自动化需要原始退出码时运行编译后的程序。

命令复用 ORM、Database、Queue 和 Schedule；终端操作没有登录 Session，业务命令显式接收并检查用户 ID。`studio:tasks` 展示按用户隔离的 ORM 查询，`studio:digest` 将真实摘要任务入队，由已有 Studio worker 消费。应用首次调用数据库能力才打开数据库并应用 Studio 迁移；单纯 list/help/生成命令不会创建数据库。业务命令不要求 HTTP 服务运行，队列消费需要 worker 运行。

宿主通过 `loom.NewConsole(Config{Entry: ..., Services: ...})` 和 `Run(ctx, args, stdout, stderr)` 集成，复用 RequestVM 隔离和原生服务边界。默认单次执行超时 60 秒、两条输出流合计上限 4 MiB，可通过 Config 调整；超时仍是协作取消。终端支持直接 echo，但拒绝 HTTP headers 和未关闭的输出缓冲。此轮不提供长期驻留命令的无限执行模式。

## 验证

```powershell
go -C runtime test ./... -count=1 -timeout 120s
go -C runtime test ./framework -run TestPHPContracts -count=1
```

Loom 的 Go 测试包含真实回环 HTTP 并发请求，测试服务器由 httptest 自动创建和关闭。其余测试覆盖页面转义、Action 依赖注入、输入错误、HEAD/404/405、禁止直接输出、shutdown 异常、请求/响应上限、过载恢复、PHP 死循环超时和后续请求恢复。PHP 测试也可在原生 PHP 上运行，框架契约不依赖 Origami 特殊语法。

本切片顺带修复 JSON_THROW_ON_ERROR 的已识别异常路径：注册 JsonException，decode 语法/深度错误和 encode 已检测错误正确抛异常，抛异常模式保留 json_last_error。它不是 JSON 全部 flags 与错误分类的完整兼容性重写；已有 partial output、UTF-8 等其他行为仍需单独验收。

PHP 对照测试还发现并修复了 htmlspecialchars 默认 HTML 4.01 模式下的引号实体差异（`&quot;`、`&#039;`）。该修复不代表其全部 flags、编码和 double_encode 行为已经完整实现。

## 多协议入口

HTTP 控制器位于 `app/Http/Controllers`，WebSocket 处理器位于 `app/WebSocket/Handlers`；TCP 与 gRPC 使用独立 PHP 消息入口。完整配置、可运行客户端和扩展步骤见 [多协议开发指南](protocols.md)。

