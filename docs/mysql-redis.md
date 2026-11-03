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

默认 MySQL DSN 是 `mysql:host=127.0.0.1;port=3306;dbname=loom;charset=utf8mb4`，用户名和密码读取 `LOOM_MYSQL_USER`、`LOOM_MYSQL_PASSWORD`，不要把密码嵌入 DSN。Redis 默认地址为 `redis://127.0.0.1:6379/0`；`LOOM_REDIS_URL` 可设置密码、ACL 用户、端口和数据库，TLS 使用 `rediss://`。不同环境或 MySQL 数据库须使用不同的 `LOOM_REDIS_PREFIX`。

MySQL 连接在驱动 DSN 里设置 `sql_mode` 和 `transaction_isolation`，物理连接建立时生效。当前配置只带 host、port、dbname 和 charset，不带 TLS；远程部署应使用可信私有网络或本地 TLS 隧道。

Redis 传输直接复用 Origami 可选原生模块 `std/php/redis`，宿主在初始化时显式注册，部署需要重新构建匹配的 `loom` 程序。Loom 不再维护自有 RESP 客户端。连接在显式作用域内使用并关闭，原生模块处理超时、请求取消、认证和 TLS；不自动重试可能已被 Redis 执行的写操作。不宣称兼容完整 phpredis 或 Laravel Redis，也不启用原生模块尚未支持的持久连接池、Cluster、Sentinel 或自动 backoff。

## 队列与会话语义

- 业务事务写入 MySQL `loom_jobs`，同时形成任务历史与待投递记录。消费者只把已经提交的任务投递到 Redis，业务回滚不产生可执行任务。
- 延迟任务和失败退避保存在 MySQL，到期后通过 XADD 写入 Redis Stream；XREADGROUP 消费，XACK 确认后 XDEL 清理。XAUTOCLAIM 回收未确认消息，过期的数据库租约生成下一次尝试。Lua 保证发布去重和确认的租约校验。Redis 至少需要 6.2。MySQL 条件更新记录尝试次数，只有当前租约的消费者可完成任务。租约为 60 秒，子进程执行上限为 30 秒，最多自动尝试三次。
- 消费者从 MySQL 恢复 Redis 中丢失的待执行任务；未到期租约不会提前重跑。任务为至少一次执行，业务处理器须幂等。Redis 不可用时停止领取，不静默改为数据库队列。
- 后台读取 MySQL 任务历史和错误统计，人工重试更新待投递记录，再同步到 Redis。
- 登录会话仅保存在 Redis，键使用令牌 SHA-256 摘要，TTL 为 24 小时。密码重置和停用通过 MySQL `auth_version` 在同一事务中撤销会话，Redis 中残留的旧会话立即失效。会话丢失要求重新登录。
- 通知及账户邮件保留各自事务 outbox 与投递审计，由 `admin:notify`、`admin:mail` 投递，不混入业务任务处理器。

升级时先停止旧队列 worker，再启动新版本。队列键使用 `{queue:<数据库 DSN 的 SHA-256>}:stream`、`:published` 和 `:leases`，隔离共享 Redis 前缀但任务 ID 相同的数据库，不读取旧的 `{queue}` 或 ready/running/payload 队列键；未完成任务从 MySQL 恢复，运行中任务等待租约到期。不要混用新旧 worker。应用入口迁移后，也需要重启 `queue:work`、`schedule:work` 和 `admin:notify`，确保它们加载当前配置。

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
