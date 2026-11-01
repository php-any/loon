# 后台核心模块

`/admin` 提供用户、订单、队列、定时器、通知、角色权限、菜单和运行监控。新增数据通过增量迁移安装，保留旧角色、账号和自定义菜单；普通成员不会自动获得全局管理权限。个人通知入口位于右上角，不需要管理权限。

## 启动和部署

1. 运行 `./scripts/build.ps1` 构建包含 SMTP、Webhook 原生传输的宿主。
2. 在 `frontend/admin` 执行 `npm ci`、`npm run build`，交付 `public/admin` 构建产物。
3. 按 [MySQL 与 Redis 配置](mysql-redis.md) 准备新数据库和 Redis。启动 HTTP 服务时执行数据库增量迁移；部署前停止旧工作进程，部署匹配的脚本、资源及宿主后再启动。
4. 空库初始化自动创建超级管理员 `admin@example.com`，初始密码 `1234567890`，登录后修改密码。已有账号的数据库不会创建默认账号；可运行 `./build/loom.exe loom.php admin:bootstrap <管理员邮箱>` 授予指定已有账号超级管理员权限。
5. 独立运行 `queue:work`、`schedule:work` 和 `admin:notify`。账户验证和密码恢复邮件仍由 `admin:mail` 发送。

开发环境可用 `./scripts/dev-stack.ps1` 启动完整进程组，已包含通知消费者。只运行 `serve` 不会启动队列、周期调度或通知消费者，监控会明确显示未采集或心跳过期。

## 通知渠道配置

站内通知始终保存。邮件和 Webhook 默认关闭，由具有 `channels.manage` 权限的管理员在“通知管理 → 渠道配置”启用。配置变量需要传递给 HTTP 和通知工作进程；修改环境变量后重启这些进程。以下是 PowerShell 配置示例，不会自动读取 `.env`：

```powershell
$env:LOOM_SMTP_ADDRESS = 'smtp.example.com:587'
$env:LOOM_SMTP_FROM = 'noreply@example.com'
$env:LOOM_SMTP_TLS = 'starttls'
$env:LOOM_SMTP_USER = 'smtp-user'
# 从部署环境的密钥管理器注入 LOOM_SMTP_PASSWORD。
# 同样注入 LOOM_WEBHOOK_SECRET_MAIN，值至少 32 字节。
./build/loom.exe admin:notify
```

Webhook 配置填写 `https://接收端域名/路径` 和环境变量名称 `LOOM_WEBHOOK_SECRET_MAIN`，不填写密钥值。只支持公网 HTTPS、默认 443 端口，不接受账号信息、查询参数、片段或重定向。解析与连接均检查 IP，连接使用校验后的地址，保留 TLS 主机名验证。HTTP 2xx 表示接收成功；超时为 10 秒。

请求正文包含 `event_id`、`user_id`、`title`、`body`。请求头为：

- `X-Loom-Event-ID`：稳定的 `notification-<通知ID>`，接收端以此去重。
- `X-Loom-Timestamp`：发送时的 Unix 秒数，接收端应校验时间窗口。
- `X-Loom-Signature`：`sha256=` 加十六进制 HMAC-SHA256，签名输入为 `时间戳 + "." + 原始请求正文`，密钥为配置的环境变量值。

外部通知至少一次投递，每轮最多尝试三次（首次及两次自动重试），失败后支持人工重试。消费者崩溃后通过 60 秒租约回收；租约令牌防止旧消费者覆盖新状态。关闭渠道保留待投递记录，重新开启后继续处理。尚未开启的渠道不会为历史通知补建投递记录。密钥、账户验证码和原始任务错误不出现在后台或审计记录中。

订单状态变化与通知在同一事务提交。通知消费者每轮扫描最多 100 个尚未通知的最终失败任务，通知当前启用且具备 `monitor.view` 权限的用户；同一任务只生成一次失败事件。`admin:notify --once` 扫描一次并投递一条外部通知。

## 数据与接口约定

新增资源使用 `GET/POST /api/admin/operations/{resource}`，资源为 `orders`、`queue`、`schedules`、`notifications`、`channels`、`monitor`、`inbox`、`users`。用户创建继续使用 `POST /api/admin/manage/users`，`action=save,id=0`，并提供姓名、邮箱、初始密码、启用状态和角色。现有用户编辑不接受密码字段。

列表默认每页 25 条，使用 `page`、`q`；订单和队列另支持 `status`、`owner`。`id` 查询订单详情、队列安全摘要。`users?id=` 返回用户详情。通知管理展示全局外部投递记录，`inbox` 只返回当前账号的站内通知；超级管理员也不能读取其他人的通知正文。

- 订单创建：`action=create,user_id,request_key,items`；明细含 `name,quantity,unit_price`。`unit_price` 为整数分，数量为正整数，总额由服务器计算，币种固定 CNY。请求标识由客户端生成并保留至提交成功，重复提交返回 409。
- 订单流转：`action=transition,id,expected,status`。待处理可转处理中或取消，处理中可转完成或取消；终态不可更改，不提供删除。
- 队列重试：`action=retry,id`，只允许失败任务。队列详情只公开允许的结构化数字/布尔结果，不显示任意任务载荷和自由文本输出。
- 定时器：`GET /api/admin/operations/schedules` 只列出代码中的 `#[Timer(...)]` 声明，支持按任务类搜索及分页，字段为 `class`、`seconds`、`state`。后台不支持新增、编辑、删除或启停；POST 返回 405。旧的 `/api/studio/cron`、`/api/studio/schedule` 写入路由已移除。周期和任务逻辑通过代码维护，未采集的运行状态明确显示为未采集。
- 通知发送：`action=send,user_id,title,body,request_key`；相同发送者及请求标识幂等。投递重试为 `action=retry,id`。个人已读为向 `inbox` 提交 `action=read,id`。

状态冲突返回 409，输入错误返回 422，缺少权限返回 403。所有 POST 沿用会话、Origin 与 CSRF 校验。新增查看、管理、重试、发送及渠道配置权限在角色编辑中分别分配；使用页面还需拥有相应查看权限。

监控每 10 秒刷新，离开页面停止；展示心跳、当前队列和外部投递状态计数，以及最近调度触发。不采集历史指标或主机资源。代码 Timer 展示声明周期，未单独上报的任务明确标记未采集；现有 Heartbeat 运行情况在监控页面查看。

## 验证

```powershell
./build/loom.exe tests/run.php
./tests/e2e/admin.ps1
./tests/e2e/account.ps1
./tests/e2e/background.ps1
./tests/e2e/release-demo.ps1
# runtime 目录
go test ./internal/extensions -count=1 -timeout=60s
# frontend/admin 目录
npm run build
```

测试使用独立数据库和本地 SMTP/HTTP 替身，不向真实收件人或 Webhook 服务投递。升级测试检查旧用户、角色及自定义菜单保留；重复迁移不重复插入模块入口。

### 官网用户、管理员与模拟支付

首页 `/` 展示产品和套餐，`/account` 提供官网用户注册、登录、退出与个人订单。官网身份保存在 `shop_customers`，会话使用 `shop_sessions` 与 `loom_customer_session` Cookie。后台管理员继续使用独立的 `loom_users`、后台会话和权限体系。同邮箱可分别注册不同体系，登录和退出互不覆盖。旧后台账号、旧订单保留，不自动迁移成官网用户。

`/api/studio/register` 已关闭公开注册。首次初始化空库时由 `011_default_admin` 迁移自动创建 `admin@example.com` / `1234567890` 超级管理员，密码以 bcrypt 哈希保存。迁移仅在没有任何后台账号时创建，已有账号不会被提权，重复启动不会重置密码或重新启用账号。回滚该迁移保留账号及后续修改。也可通过 `admin:create <email> <name>` 创建其他账号，密码读取环境变量 `LOOM_ADMIN_INITIAL_PASSWORD`，随后通过 `admin:bootstrap <email>` 授予超级管理员。后续由有权限的管理员在“系统管理 / 管理员管理”中创建后台账号。注册开关仅控制官网用户注册。

“业务管理 / 用户管理”通过 `customers.manage` 权限管理官网账号，可以新增、搜索、编辑姓名与启停账号，不分配后台角色；停用会撤销用户会话。“用户订单”使用 `orders.view` 权限显示独立的官网订单；原有通用订单保留在原模块中。

登录官网用户后确认 ¥99.00 体验套餐，调用 `POST /api/checkout/simulate`，仅接受 `request_key`，金额由服务端固定，不连接真实支付渠道。订单保存在 `shop_orders`，订单号以 `SIM` 开头。同用户同请求标识重试返回原订单，不重复通知。`GET /api/customer/orders` 仅显示当前用户最近 25 笔订单。

订单、审计和后台通知在同一事务中保存。启用且具有 `orders.view` 权限的管理员收到支付通知；用户在个人订单中查看支付结果。后台通过 WebSocket 接收新消息；关闭后台期间的通知仍会留存。


### 队列与 WebSocket 通知

`Notifications::emit()` 在业务事务中同时写入站内通知和 `notification.push` 队列任务，回滚时两者一起撤销。事件键与收件人唯一约束保证重复支付不重复创建通知、推送任务。`queue:work` 执行 `PushNotification`，把收件人唤醒事件写入 Redis Stream，再由 HTTP 服务的 WebSocket 连接推送个人收件箱数据。

- 队列使用 MySQL 存档与事务待投递记录、Redis Stream 消费组。实时推送也使用 Redis Stream。
- 推送地址：`/api/admin/notifications/socket`。使用管理员 HttpOnly Cookie，升级前验证登录、启用状态和同源 Origin；连接期间持续检查会话撤销。官网用户不能连接管理员通知通道。
- Redis Stream 按数据库和管理员隔离，只保存通知编号，约保留最近 1000 条唤醒事件并设置 24 小时过期。通知正文与已读状态以数据库为准。
- Redis 故障时任务保留并按现有队列规则重试，最多自动尝试三次，最终失败可在队列管理中重试。失败的推送任务不会再生成推送失败通知，避免故障时形成递归任务。
- 前端取消定时 HTTP 轮询。首次连接和重连同步最近 25 条通知及总未读数，按通知编号避免重复弹窗；离线期间更早的记录可在消息中心分页查看。异常断线以 1–30 秒指数退避重连，正常连接不定时断开或重新握手。页面卸载会释放连接与定时器。
- Loom 宿主区分 HTTP 请求与升级后的 WebSocket 生命周期。普通 HTTP 保留 10 秒请求超时；只有成功升级后才停止该请求计时并释放 HTTP 请求并发名额，WebSocket 仍受总连接数、消息大小、写超时和登录会话约束。连接保持期间通过 Redis `XREAD BLOCK` 等待事件并主动推送，不再周期性发送 `rotate` 或重新握手。对端关闭、会话撤销或真实网络故障时才释放连接；网络故障后前端退避重连。
- 邮件和 Webhook 保留独立的 `admin:notify` 投递流程。

开发环境先启动 Redis，或配置 `LOOM_REDIS_URL`。`scripts/dev-stack.ps1` 会检查 Redis；本机无运行中的 Redis 时可用 `scripts/dev-redis.ps1 -Executable <redis-server.exe>` 启动仅监听回环地址的开发实例。该实例不保存 Stream 到磁盘，重启后通过数据库收件箱恢复状态；脚本不会停止非自身启动的 Redis。生产环境应单独管理 Redis 的访问控制、持久化与运行状态。

真实集成测试（独立临时数据库、Redis 和 HTTP 服务）：

```powershell
./.venv/Scripts/python.exe tests/e2e/notifications.py --redis '<redis-server.exe 的绝对路径>'
```

覆盖队列未消费时不推送、真实消费后推送、Redis 中断重试、重复支付、断线恢复、同一连接超过 35 秒并接收后续支付、不同管理员隔离、拒绝官网用户与跨站连接、退出后撤销连接。`loom_operations_test.php` 另验证通知与队列事务回滚以及失败任务不递归入队。

