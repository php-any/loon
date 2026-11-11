# 同步缓存（Loom\Cache）

`Loom\Cache` 是应用级单例门面，提供带 TTL 的同步 `get` / `put` / `remember` 等操作。值仅支持标量、`array` 与 `null`，以 JSON 信封写入 Redis 或进程内数组，从而用 `has()` 区分「未命中」与「缓存的 null」。

配置位于可选的 [config/cache.php](../config/cache.php)；节缺失时 `CacheOptions` 使用内置默认：`default = redis`，`stores` 含 `redis` 与 `array` 驱动。

```php
$builder = \Loom\Application::createBuilder($root, $envFile);
$builder->cache->extend('memcached', static fn (string $name, array $config): \Loom\CacheStore => new MemcachedCacheStore($name, $config));
$app = $builder->build();

$cache = $scope->get(\Loom\Cache::class);
$cache->remember('report:daily', 300, static fn (): array => loadReport());
```

## 与 Loom\Redis 的分工

**不要**把认证会话、`RedisQueue`、通知 Stream 等现有用法迁到 `Cache`，也不要用 `Cache` 替代 `Redis::command` / `using` / `pipeline` / `XADD`。`Redis` 仍是短连接命令包装；`Cache` 只在业务键上附加 `cache:{store}:` 前缀（再经 `Redis::key()` 加 `LOOM_REDIS_PREFIX`），与会话键、队列流等隔离。

## API 摘要

| 方法 | 行为 |
|------|------|
| `get($key)` | 命中返回值，缺失为 `null` |
| `has($key)` | 键是否存在（含缓存的 null） |
| `put($key, $value, $ttlSeconds)` | TTL 必填 |
| `forever($key, $value)` | 无过期 |
| `forget($key)` | 删除 |
| `remember($key, $ttl, $callback)` | 未命中执行回调并写入；回调抛错不写入 |
| `rememberForever($key, $callback)` | 同上，永久缓存 |
| `increment` / `decrement` | 仅整型计数键；与 JSON 信封键混用抛 `LogicException` |
| `store($name)` | 切换命名存储（测试可用 `array`） |
| `flush()` | 仅删除本存储的 `cache:{store}:` 键（Redis 驱动在单条 `Redis::using()` 连接上用 `SCAN` + 分批 `DEL`），**从不** `FLUSHDB` |

`array` 存储进程内有效，适合单测与无 Redis 环境；`redis` 存储连接失败仍映射为 HTTP 503，与 `Redis` 一致。`remember` 在并发下可能重复计算，本版本不加锁。

## 监控

开启监控会话且 `config/monitoring.php` 中 `watchers.cache.enabled` 为真时，缓存操作经 `ConfigurableMonitoring::recordEvent` 写入当前会话，事件名如 `cache.hit`、`cache.miss`、`cache.write`、`cache.forget`、`cache.flush`、`cache.increment`。载荷包含存储名、逻辑键、TTL（如适用）与 hit/miss 标记，**不含**缓存值或回调结果。

仪表盘在 `driver=database` 且启用时提供 `/monitoring/cache` 列表（与会话详情中的其它 `recordEvent` 项一致），见 [统一监控](monitoring.md)。

## 测试

`framework/tests/loom_cache_test.php` 覆盖数组存储、Redis 前缀隔离、`flush` 不删除 `session:` 类键，以及监控开关行为。
