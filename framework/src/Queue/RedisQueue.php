<?php

declare(strict_types=1);

namespace Loom;

// MySQL 提供事务性发件箱与归档；Redis Streams 负责投递和待处理消息。
/**
 * 用 MySQL 发件箱补 Redis Streams。认领时 Lua 和数据库 CAS 一起核对租约，不同数据库的队列键互相分开。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $redisQueue = new RedisQueue($database, $config);
 */
final class RedisQueue
{
    private \Loom\Database $database;
    private string $cursor = '0-0';

    /**
     * 保存数据库和配置，并把消费游标设为 0-0。这里不连接 Redis。
     *
     * @param \Loom\Database $database 认领时用来读发件箱、做租约 CAS。
     * @param \Loom\Config $config 用来解析 default 连接，把队列键隔开。
     * @return void 不返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $redisQueue = new RedisQueue($database, $config);
     */
    public function __construct(\Loom\Database $database, private \Loom\Config $config)
    {
        $this->database = $database;
    }

    /**
     * 根据数据库 DSN 散列构造同一 Redis 哈希槽中的流、发布记录和租约键。
     *
     * 参数：无。
     *
     * @return array 固定顺序的三个完整键：[stream, published, leases]。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $keys = $redisQueue->keys();
     */
    public function keys(): array
    {
        // 即使共享 Redis 及其前缀，任务 ID 仍仅在各数据库内有效。
        $base = '{queue:' . hash('sha256', (new Database($this->config))->pdoDsn()) . '}';
        return [Redis::key($base . ':stream'), Redis::key($base . ':published'), Redis::key($base . ':leases')];
    }
    /**
     * 创建 Redis 操作连接并执行发布、恢复和任务认领。
     *
     * @param int $now 当前 Unix 时间戳，单位秒。
     * @return ?array 完整任务信封；没有任务或数据库拒绝租约时返回 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $job = $redisQueue->claim(time());
     */
    public function claim(int $now): ?array
    {
        return Redis::using(fn ($client) => $this->claimUsing($client, $now));
    }
    /**
     * 从框架队列资源目录读取指定 Lua 脚本。
     *
     * @param string $name 框架队列 Lua 脚本名，不带 .lua，例如 initialize、publish、claim、ack。
     * @return string Lua 源码字符串。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $lua = self::script('claim');
     */
    private static function script(string $name): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/resources/queue/' . $name . '.lua');
    }
    /**
     * 组合 EVAL、Lua 源码、三个固定队列键和参数列表。
     *
     * @param string $name 框架队列 Lua 脚本名，不带 .lua，例如 initialize、publish、claim、ack。
     * @param array $args 按对应 Lua 脚本 ARGV 顺序传入的字符串参数列表。
     * @return array 可交给 Redis::execute 的命令数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $command = $this->command('initialize', []);
     */
    private function command(string $name, array $args): array
    {
        return array_merge(['EVAL', self::script($name), '3'], $this->keys(), $args);
    }
    /**
     * 清理耗尽重试的任务，发布到期记录并回收过期投递，最后用 MySQL CAS 接受租约。
     *
     * @param \Redis $client 当前操作已连接的原生 Redis 客户端。
     * @param int $now 当前 Unix 时间戳，单位秒。
     * @return ?array 带租约和 message_id 的任务；未取得有效租约返回 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $job = $this->claimUsing($client, time());
     */
    private function claimUsing(\Redis $client, int $now): ?array
    {
        Models\Job::whereState('running')->where('lease_until', '<=', $now)->where('attempts', '>=', 3)->update(['state' => 'failed', 'error' => 'Worker lease expired after final attempt', 'lease' => null]);
        // 恢复操作有数量限制。Redis 数据丢失或重启后，也可通过持久化发件箱重建。
        Redis::execute($client, $this->command('initialize', []));
        $rows = Models\Job::readyForRedis($this->database, $now);
        $commands = [];
        foreach ($rows as $row) {
            $payload = json_encode(['id' => (int)$row['id'], 'kind' => $row['kind'], 'payload' => json_decode($row['payload'], true), 'owner' => (int)$row['owner'], 'attempt' => (int)$row['attempts'] + 1], JSON_THROW_ON_ERROR);
            $commands[] = $this->command('publish', [(string)$row['id'], (string)((int)$row['attempts'] + 1), $payload]);
        }
        if ($commands !== []) {
            Redis::pipeline($client, $commands);
        }
        $lease = bin2hex(random_bytes(32));
        $claimed = Redis::execute($client, $this->command('claim', [$lease, $this->cursor]));
        $this->cursor = $claimed[0];
        if (count($claimed) === 1) {
            return null;
        }
        $job = json_decode($claimed[2], true, 64, JSON_THROW_ON_ERROR);
        $job['lease'] = $lease;
        $job['message_id'] = $claimed[1];
        if (!Models\Job::acceptRedisLease($this->database, $job, $now)) {
            Redis::execute($client, $this->command('ack', [(string)$job['id'], $job['lease'], $job['message_id'], (string)$job['attempt']]));
            return null;
        }
        return $job;
    }
    /**
     * 按任务编号、租约和尝试次数确认投递，避免旧工作进程确认新租约。
     *
     * @param array $job 含 id、lease、message_id、attempt 的认领信封。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $redisQueue->ack($job);
     */
    public function ack(array $job): void
    {
        Redis::command($this->command('ack', [(string)$job['id'], $job['lease'], $job['message_id'], (string)$job['attempt']]));
    }
}
