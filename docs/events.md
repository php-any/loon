# 应用事件总线

进程内事件总线：同步监听器当场执行；`queued: true` 的监听器通过 Redis Stream 异步执行。**不**替代领域表 `AuditEvent`、`OrderEvent` 等持久化事件模块。

## 登记监听器

在 `build()` 之前：

```php
$builder->events->listen(OrderPaid::class, SendReceipt::class);
$builder->events->listen(OrderPaid::class, SendAnalytics::class, queued: true);
$builder->events->listen(OrderPaid::class, static function (OrderPaid $event): void {
    // 闭包仅支持同步
});
```

模块类上使用 `#[Listens(OrderPaid::class)]` 或 `#[Listens(OrderPaid::class, queued: true)]`（`method` 默认为 `handle`）。发现阶段校验方法存在；队列监听要求事件类实现 `QueueableEvent`，否则 `build()` 失败。闭包不能 `queued: true`。

监听器从当前 `ServiceScope` 解析。登记顺序即派发顺序。同一事件与同一监听器类只能登记一次。

## QueueableEvent

队列异步监听要求事件实现 `Loom\Events\QueueableEvent`：

- `toQueue(): array` — 仅标量、数组、`null`
- `static fromQueue(array $data): static` — worker 中重建事件
- 可选 `queueOwner(): int`，默认任务 `owner` 为 `0`

不得把 Model 实例放进队列数据。

## 派发

作用域内注入 `Loom\Events\EventDispatcher`：

```php
$events->dispatch(new OrderPaid($orderId));
```

同步监听器按登记顺序执行；`queued` 监听器在同一轮派发中调用 `Queue::dispatch('loom.event.listen', …)` 写入 Stream，执行顺序不做保证。

任一同步监听器抛错会中止后续监听器（含尚未入队的队列监听）。同一事件类不可重入派发。

## 监控

活动会话且 `watchers.events` 开启时：

- `app.event`：`name`、`listeners`（含同步与队列登记数）
- `app.event.queued`：事件名、监听器类、`job_id`、`owner`（不含载荷）
- worker 执行队列监听后 `app.event.listener`：事件名、监听器类、`job_id`

队列生命周期见 `queue.job.*` 事件（`watchers.queue`）。
