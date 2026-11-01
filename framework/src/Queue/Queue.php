<?php

declare(strict_types=1);

namespace Loom;

/**
 * 将任务先写入 MySQL 持久化发件箱，再通过 Redis 租约认领和独立进程执行，失败最多尝试三次。
 *
 * 构造参数：
 * - $process（\Loom\Process）：子进程启动与管理服务。
 * - $config（\Loom\Config）：项目配置实例，提供配置读取及项目路径解析。
 * - $database（\Loom\Database）：当前作用域的数据库服务，负责连接、查询及事务。
 * - $redisQueue（\Loom\RedisQueue）：使用 Redis 存储的队列服务。
 *
 * 返回值：使用 new 构造时得到 Queue 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $id = $queue->dispatch('report.build', ['project' => 1], 1);
 */
final class Queue
{
    private \Loom\Process $process;
    private \Loom\Config $config;
    private \Loom\Database $database;
    private \Loom\RedisQueue $redisQueue;

    /**
     * 初始化 Queue 实例及其依赖。将任务先写入 MySQL 持久化发件箱，再通过 Redis 租约认领和独立进程执行，失败最多尝试三次。
     *
     * @param \Loom\Process $process 子进程启动与管理服务。
     * @param \Loom\Config $config 项目配置实例，提供配置读取及项目路径解析。
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @param \Loom\RedisQueue $redisQueue 使用 Redis 存储的队列服务。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $id = $queue->dispatch('report.build', ['project' => 1], 1);
     */
    public function __construct(\Loom\Process $process, \Loom\Config $config, \Loom\Database $database, \Loom\RedisQueue $redisQueue)
    {
        $this->process = $process;
        $this->config = $config;
        $this->database = $database;
        $this->redisQueue = $redisQueue;
    }

    /**
     * 校验任务种类和延迟后写入待执行任务，不直接运行处理器。
     *
     * @param string $kind 非空任务种类，必须对应已注册的 Job 处理器。
     * @param array $payload 可 JSON 编码的业务参数数组。
     * @param int $owner 任务所属用户编号。
     * @param int $delay 延迟秒数，允许 0 至 2592000。默认值为 0。
     * @return int 新任务主键。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $id = $queue->dispatch('report.build', ['project' => 1], 1, 10);
     */
    public function dispatch(string $kind, array $payload, int $owner, int $delay = 0): int
    {
        if ($kind === '' || $delay < 0 || $delay > 2592000) {
            throw new HttpError(422, 'Invalid job');
        }
        return Models\Job::using($this->database)->create(['kind' => $kind, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'owner' => $owner, 'available' => time() + $delay, 'created' => time()])->id;
    }
    /**
     * 从 Redis 队列认领可运行任务，默认使用当前时间。
     *
     * @param ?int $now Unix 时间戳秒数；null 时使用当前时间。默认值为 null。
     * @return ?array 含 id、kind、payload、owner、attempt、lease、message_id 的任务；无任务时 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $job = $queue->claim();
     */
    public function claim(?int $now = null): ?array
    {
        return $this->redisQueue->claim($now ?? time());
    }
    /**
     * 仅更新仍持有对应租约的任务，成功标记完成，失败重试或终止，再确认 Redis 投递。
     *
     * @param array $job claim 返回的完整任务信封，包含租约、尝试次数和消息编号。
     * @param ?string $result 成功执行的输出；失败时可为 null。
     * @param ?string $error 失败原因；null 表示任务成功。
     * @param ?int $now Unix 时间戳秒数；null 时使用当前时间。默认值为 null。
     * @return void 无返回值；确认失败仍向上传播。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $queue->finish($job, '完成', null);
     */
    public function finish(array $job, ?string $result, ?string $error, ?int $now = null): void
    {
        $now = $now ?? time();
        if ($error === null) {
            Models\Job::using($this->database)->whereId($job['id'])->whereLease($job['lease'])->update(['state' => 'completed', 'result' => $result, 'error' => null, 'lease' => null]);
        } else {
            Models\Job::using($this->database)->whereId($job['id'])->whereLease($job['lease'])->update(['state' => $job['attempt'] >= 3 ? 'failed' : 'pending', 'available' => $now + $job['attempt'] * $job['attempt'] * 2, 'error' => $error, 'lease' => null]);
        }
        $this->redisQueue->ack($job);
    }
    /**
     * 认领并执行至多一个任务，处理器失败记入任务状态，传输确认失败不重复结束任务。
     *
     * 参数：无。
     *
     * @return bool 处理了任务返回 true，无可执行任务返回 false。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $processed = $queue->tick();
     */
    public function tick(): bool
    {
        $job = $this->claim();
        if ($job === null) {
            return false;
        }
        try {
            $result = $this->runIsolated($job);
        } catch (\Throwable $error) {
            $this->finish($job, null, $error->getMessage());
            return true;
        }
        // 传输或确认失败不等于处理器失败：绝不能重复结束同一次执行。
        $this->finish($job, $result, null);
        return true;
    }
    /**
     * 将任务写入临时文件，启动当前运行时的 _job 子进程，30 秒内完成并清理输入文件。
     *
     * @param array $job claim 返回的完整任务信封，包含租约、尝试次数和消息编号。
     * @return string 成功子进程的标准输出；非零退出或超时抛出异常。
     *
     * 内部调用示例（仅类内使用）：
     * @example $result = $this->runIsolated($job);
     */
    private function runIsolated(array $job): string
    {
        $input = tempnam(sys_get_temp_dir(), 'loom-job-');
        try {
            chmod($input, 0600);
            $payload = json_encode($job, JSON_THROW_ON_ERROR);
            if (file_put_contents($input, $payload) !== strlen($payload)) {
                throw new \RuntimeException('Cannot write job input');
            }
            $result = $this->process->run([PHP_BINARY, $this->config->root() . '/loom.php', '_job', $input], 30);
            if ($result['code'] !== 0) {
                throw new \RuntimeException($result['stderr']);
            }
            return $result['stdout'];
        } finally {
            unlink($input);
        }
    }
}
