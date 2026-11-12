# MySQL 与 Redis 存储

框架数据库统一使用 MySQL，只接受 `mysql:` DSN。Redis 保存登录会话、任务队列的 Stream 消息、消费组待确认记录和执行租约。用户、订单、权限、菜单、通知、调度配置、任务历史和事务待投递记录保存在 MySQL。

MySQL 需要表达式默认值、CHECK 约束、InnoDB 和 `SKIP LOCKED`，部署目标为 MySQL 8.4 或更高版本。本次实际集成验证使用 MySQL 9.4.0 与 Redis 8.2.1。测试也使用 MySQL 隔离库，不提供其他数据库兼容路径。

## 配置与启动

连接已有 MySQL/Redis 时，预先创建空数据库并授权应用账号。也可将 `.env.storage.example` 的配置填入根目录 `.env`，替换示例密码，启动仓库提供的 MySQL 8.4 / Redis 8.2 开发配置：

```powershell
docker compose --env-file .env -f compose.storage.yaml up -d
```

Compose 使用本机端口 13306、16379 和持久化卷。根目录 `loom.php` 在启动时由 PHP 加载 `.env`，文件中的值优先于进程环境变量；HTTP、队列、调度和通知命令均使用此入口。例如 `.env`：

```dotenv
LOOM_DATABASE="mysql:host=127.0.0.1;port=13306;dbname=loom;charset=utf8mb4"
LOOM_MYSQL_USER=loom
LOOM_MYSQL_PASSWORD=替换为实际密码
LOOM_REDIS_URL=redis://:URL编码后的密码@127.0.0.1:16379/0
LOOM_REDIS_PREFIX=loom:
```

随后运行：

```powershell
./scripts/build.ps1
./build/loom.exe migrate:up
./scripts/dev-stack.ps1
```

`config/database.php` 与 Laravel 相同，使用 `default`、`connections` 和 `env()`。默认连接是 `mysql`，可用 `DB_CONNECTION` 或 `LOOM_DB_CONNECTION` 改选。连接项使用 `url`、`host`、`port`、`database`、`username`、`password`、`unix_socket`、`charset`。`url` 写成 `mysql://user:secret@127.0.0.1:3306/loom?charset=utf8mb4` 时覆盖同项里的主机、库名和账号。`DB_HOST`、`DB_PORT`、`DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD`、`DB_SOCKET`、`DB_CHARSET` 未设置时，回退到对应的 `LOOM_DB_*` 和 `LOOM_MYSQL_USER`、`LOOM_MYSQL_PASSWORD`。`LOOM_DATABASE` 仍是 `mysql:host=127.0.0.1;port=3306;dbname=loom;charset=utf8mb4` 这种 DSN，放在默认 `mysql` 连接的 `dsn`。连接建立时直接使用这条 DSN，并优先于 `url` 和同项里的主机、库名。不要把密码写进这条 DSN。未声明连接的模型使用 `default`。其他库按同样的键追加到 `connections`。Redis 队列键使用默认连接的 mysql DSN 隔开不同的库。Redis 默认地址为 `redis://127.0.0.1:6379/0`；`LOOM_REDIS_URL` 可设置密码、ACL 用户、端口和数据库，TLS 使用 `rediss://`。不同环境或 MySQL 数据库须使用不同的 `LOOM_REDIS_PREFIX`。

MySQL 连接在驱动 DSN 里设置 `sql_mode` 和 `transaction_isolation`，物理连接建立时生效。当前配置只带 host、port、dbname 和 charset，不带 TLS；远程部署应使用可信私有网络或本地 TLS 隧道。

Redis 传输直接复用 Origami 可选原生模块 `std/php/redis`，宿主在初始化时显式注册，部署需要重新构建匹配的 `loom` 程序。Loom 不再维护自有 RESP 客户端。连接在显式作用域内使用并关闭，原生模块处理超时、请求取消、认证和 TLS；不自动重试可能已被 Redis 执行的写操作。不宣称兼容完整 phpredis 或 Laravel Redis，也不启用原生模块尚未支持的持久连接池、Cluster、Sentinel 或自动 backoff。

命名连接见 `config/redis.php`：`default` 连接名与历史 `LOOM_REDIS_URL` / `LOOM_REDIS_PREFIX` 一致。缓存 `redis` 存储可在 `stores.*.connection` 中引用连接名；队列见 `config/queue.php` 的 `connection`（Redis 连接）与可选 `name`（Stream 键后缀）。`Application::build()` 会把 `RedisOptions` 绑定到 `Redis::bindOptions()`，脚本侧 `Redis::command()` / `using()` 的 `$connection` 参数可显式选择命名连接。

## 队列与会话语义

- `Queue::dispatch()` 以 Redis Stream 为投递源；无事务时立即 `XADD`，事务内在提交后才入队，回滚不入队。可选写入 `loom_jobs` 归档，失败不阻塞 Stream。
- 延迟与失败退避使用 Redis `delayed` 有序集与主 Stream；`XREADGROUP` 消费，`XACK` 后 `XDEL`；`XAUTOCLAIM` 回收超 60 秒 PEL。失败退避 `attempt²×2` 秒，超过 3 次进死信 Stream。Redis 至少 6.2；子进程上限 30 秒。至少一次执行，处理器须幂等。
- 运维面仍可读 `loom_jobs` 历史；不以 MySQL 行状态决定是否可向 Stream 投递。
- 登录会话仅保存在 Redis，键使用令牌 SHA-256 摘要，TTL 为 24 小时。密码重置和停用通过 MySQL `auth_version` 在同一事务中撤销会话，Redis 中残留的旧会话立即失效。会话丢失要求重新登录。
- 通知及账户邮件保留各自事务 outbox 与投递审计，由 `admin:notify`、`admin:mail` 投递，不混入业务任务处理器。

队列键：`{queue:<DSN SHA-256>[:name]}:stream`、`:delayed`、`:dead`，经所选 Redis 连接的前缀加前缀。`config/queue.php` 默认驱动仍为 Redis Stream；自定义驱动在 `build()` 前用 `$builder->queue->extend()` 登记。升级时先停旧 worker 再启新版本，避免混用新旧语义。

## 迁移与验证

Windows 可直接使用本机 MySQL 和 Redis 可执行文件运行独立实库回归，无需 Docker：

```powershell
./scripts/test-storage.ps1 -MySqlBin 'C:/path/to/mysql/bin' -RedisBin 'C:/path/to/redis'
```

两个目录分别需包含 `mysqld.exe` / `mysql.exe` 和 `redis-server.exe` / `redis-cli.exe`。默认使用 `build/loom.exe` 与 `.venv/Scripts/python.exe`，可通过 `-Origami`、`-Python` 指定。脚本使用随机本机端口、临时密码和全新数据目录，为各套件分别创建数据库与 Redis 前缀；完成或失败后停止本次启动的进程并恢复环境变量，不操作已有服务。日志与 `result.json` 保留在 `storage/testing/native-storage-<随机标识>/`。

套件覆盖 MySQL/Redis 集成、ORM、实体映射、全表模型覆盖、列表投影、项目命令、运营业务、后台 HTTP、账户邮件及发布多协议流程。数据库断言覆盖事务回滚、回调内可见未提交写入，以及 Redis 登录会话隔离与独立撤销。

仅验证模型和查询时不需要 Redis，可运行：

```powershell
./scripts/test-storage.ps1 -Suites orm,native_model,model_coverage,read_projection,project_commands
```

这些套件覆盖字段映射、冲突写入、JOIN 投影与分页、项目命令及事务行为；不代表 Redis 会话或队列集成已通过。

MySQL SQL 直接位于各迁移目录；原 `mysql/` 目录的 SQL 内容保持不变，已有 MySQL 迁移校验和不变。新增 `0005_redis_sessions` 迁移移除废弃的 `loom_sessions` 表，会话只保存在 Redis。新表使用自增主键、外键和 InnoDB，调度具有稳定 `id`。迁移通过 MySQL `GET_LOCK` 串行化；DDL 隐式提交，不能整体事务回滚。中途失败需检查部分结构并处理后再重试，不能直接修改迁移校验和。

已执行的迁移按 `up SQL + 空字节 + down SQL` 的原始字节计算 SHA-256，注释、空格和换行也参与校验。不要翻译或格式化历史 SQL；中文说明应写在文档中，结构变更应新增迁移。`loom/0004_model_columns` 表示 MySQL 已具备稳定的调度 id 和用户 auth_version 列；`studio/011_default_admin` 的回滚保留管理员及后续账户修改。这两处历史英文注释必须保留，否则启动会报告 `Migration drift`。两个迁移目录均通过 `.gitattributes` 禁止自动转换换行。

`tests/e2e/admin.ps1`、`tests/e2e/account.ps1` 和 `tests/e2e/release-demo.ps1` 支持 `-DatabaseDsn`。分别使用独立空库，并配置测试 MySQL 凭据、Redis URL 与唯一前缀：

```powershell
./tests/e2e/admin.ps1 -DatabaseDsn 'mysql:host=127.0.0.1;port=13306;dbname=loom_test_admin;charset=utf8mb4'
./tests/e2e/account.ps1 -DatabaseDsn 'mysql:host=127.0.0.1;port=13306;dbname=loom_test_account;charset=utf8mb4'
./tests/e2e/release-demo.ps1 -DatabaseDsn 'mysql:host=127.0.0.1;port=13306;dbname=loom_test_release;charset=utf8mb4'
$env:LOOM_TEST_MYSQL = '1'
# 以下每个测试分别设置 LOOM_DATABASE 指向另一个空库，并配置唯一 Redis 前缀。
./build/loom.exe tests/integration/loom_operations_test.php
./build/loom.exe tests/integration/loom_mysql_redis_test.php
```

集成测试覆盖真实 MySQL 迁移、后台 API、Redis 会话及撤销、延迟队列、租约回收、旧消费者隔离、真实任务子进程、Redis 队列丢失后的恢复和重试上限。完整脚本套件使用 `./scripts/test-storage.ps1 -Suites scripts`：运行器为每个 PHP 测试创建独立 MySQL 库和 Redis 前缀，结束后删除本次创建的数据库。`tests/run.php` 要求 `LOOM_TEST_MYSQL=1`，不会连接默认业务库运行测试。
