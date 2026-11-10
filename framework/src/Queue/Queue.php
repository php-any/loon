<?php

declare(strict_types=1);

namespace Loom;

/**
 * 把任务写入 MySQL，再用 Redis 租约认领，并放到独立进程里执行。同一任务失败最多三次。模型写入使用当前作用域的数据库。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $queue = new Queue($process, $config, $redisQueue);
 */
final class Queue
{
    private \Loom\Process $process;
    private \Loom\Config $config;
    private \Loom\RedisQueue $redisQueue;

    /**
     * 保存进程、配置和 Redis 队列。这里不写任务，也不启动子进程。任务行写入当前作用域的数据库。
     *
     * @param \Loom\Process $process 以后用来启动 _job 子进程。
     * @param \Loom\Config $config 以后用它的项目根目录拼 loom.php。
     * @param \Loom\RedisQueue $redisQueue 以后认领任务并确认投递。
     * @return void 不返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $queue = new Queue($process, $config, $redisQueue);
     */
    public function __construct(
        \Loom\Process $process,
        \Loom\Config $config,
        \Loom\RedisQueue $redisQueue,
        private readonly ?\Loom\Monitoring\Monitoring $monitoring = null,
        private readonly ?\Loom\Monitoring\CorrelationId $correlationId = null,
    ) {
        $this->process = $process;
        $this->config = $config;
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
        $payload = \Loom\Monitoring\Correlation::stampPayload($payload, $this->correlationId?->tryCurrent());
        return Models\Job::create(['kind' => $kind, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'owner' => $owner, 'available' => time() + $delay, 'created' => time()])->id;
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
     * @param array $job 要结算的认领结果，读取其中的 id、lease 和 attempt。
     * @param ?string $result 成功时写入的输出；失败时忽略，可为 null。
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
            Models\Job::whereId($job['id'])->whereLease($job['lease'])->update(['state' => 'completed', 'result' => $result, 'error' => null, 'lease' => null]);
        } else {
            Models\Job::whereId($job['id'])->whereLease($job['lease'])->update(['state' => $job['attempt'] >= 3 ? 'failed' : 'pending', 'available' => $now + $job['attempt'] * $job['attempt'] * 2, 'error' => $error, 'lease' => null]);
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
        $this->monitoring?->recordEvent('queue.job.claimed', [
            'id' => $job['id'],
            'kind' => $job['kind'],
            'attempt' => $job['attempt'],
        ]);
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
     * @param array $job 整段编码进临时文件、交给 _job 子进程的任务数据。
     * @return string 退出码为 0 时的标准输出。写文件失败、非零退出或超过 30 秒抛 RuntimeException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $result = $this->runIsolated($job);
     */
    private function runIsolated(array $job): string
    {
        $job = \Loom\Monitoring\Correlation::enrichJobEnvelope($job);
        if (!isset($job['correlation_id']) || $job['correlation_id'] === '') {
            $current = $this->correlationId?->tryCurrent();
            if ($current !== null && $current !== '') {
                $job['correlation_id'] = $current;
            }
        }
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
