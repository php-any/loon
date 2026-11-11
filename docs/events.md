# 应用事件总线

进程内同步事件总线，用于解耦应用逻辑（例如订单已支付 → 发送收据）。**不**替代领域表 `AuditEvent`、`OrderEvent`、`ReleaseEvent`、`FailureEvent` 等持久化事件模块。

## 登记监听器

在 `build()` 之前：

```php
$builder->events->listen(OrderPaid::class, SendReceipt::class);
$builder->events->listen(OrderPaid::class, static function (OrderPaid $event): void {
    // ...
});
```

或在模块类上使用 `#[Listens(OrderPaid::class)]`（`method` 默认为 `handle`），并实现 `handle(Event $event): void`（或属性指定的方法名）。发现阶段会校验方法存在；缺失则启动失败。

监听器从当前 `ServiceScope` 解析。登记顺序：先 `ApplicationBuilder` 上的登记，再按模块发现顺序。同一事件与同一监听器类只能登记一次。

## 派发

作用域内注入 `Loom\Events\EventDispatcher`：

```php
$events->dispatch(new OrderPaid($orderId));
```

同步执行；无监听器时为 no-op。任一听听器抛错会中止后续监听器并向外传播。同一事件类不可重入派发（嵌套派发**不同**事件类允许）。

事件为普通对象，无强制基类。**负载宜为标量或 id，不要放 Model 实例。**

## 监控

活动监控会话且 `watchers.events` 开启时，会调用 `Monitoring::recordEvent('app.event', ['name' => 事件类名, 'listeners' => N])`。**不记录**事件负载或监听器内的业务字段；条目归入通用 Events 观测桶（`/monitoring/events` 与 `watchers.events`），不要与 `queue.*`、`filesystem.*` 等前缀桶混淆。

队列异步监听、模型生命周期与 dispatch-after-commit 不在 v1 范围内。
