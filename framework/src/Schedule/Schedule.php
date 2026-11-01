<?php

declare(strict_types=1);

namespace Loom;

/**
 * 保存 Cron 或固定间隔计划，并在事务内把到期计划推进为持久化队列任务及运行记录。
 *
 * 构造参数：
 * - $database（\Loom\Database）：当前作用域的数据库服务，负责连接、查询及事务。
 *
 * 返回值：使用 new 构造时得到 Schedule 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $schedule->every('report', 60, 'report.build', ['project' => 1], 1);
 */
final class Schedule
{
    private \Loom\Database $database;

    /**
     * 初始化 Schedule 实例及其依赖。保存 Cron 或固定间隔计划，并在事务内把到期计划推进为持久化队列任务及运行记录。
     *
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $schedule->every('report', 60, 'report.build', ['project' => 1], 1);
     */
    public function __construct(\Loom\Database $database)
    {
        $this->database = $database;
    }

    /**
     * 使用与调度器一致的 Cron 解析器预览未来五次执行时间。
     *
     * @param string $expression 五字段 Cron 表达式，例如 0 9 * * 1-5。
     * @param string $timezone 明确 IANA 时区，例如 Asia/Shanghai。
     * @return array 五个带 UTC 偏移的 ISO 8601 本地时间字符串。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $times = Schedule::preview('0 9 * * 1-5', 'Asia/Shanghai');
     */
    public static function preview(string $expression, string $timezone): array
    {
        $cron = new Cron($expression, $timezone);
        $time = time();
        $out = [];
        for ($i = 0; $i < 5; $i++) {
            $time = $cron->next($time);
            $out[] = $cron->local($time)->format('c');
        }
        return $out;
    }
    /**
     * 校验名称、任务种类、Cron 与时区后保存计划，相同规则保留下次运行时间。
     *
     * @param string $name 非空且稳定的计划名称，用于唯一识别和更新。
     * @param string $expression 五字段 Cron 表达式，例如 0 9 * * 1-5。
     * @param string $timezone 明确 IANA 时区，例如 Asia/Shanghai。
     * @param string $kind 非空任务种类，需有对应 Job 处理器。
     * @param array $payload 可 JSON 编码的任务参数数组。
     * @param int $owner 生成任务所属的用户编号。
     * @return void 无返回值，写入或更新计划。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $schedule->cron('report', '0 9 * * *', 'Asia/Shanghai', 'report.build', [], 1);
     */
    public function cron(string $name, string $expression, string $timezone, string $kind, array $payload, int $owner): void
    {
        if ($name === '' || $kind === '') {
            throw new HttpError(422, 'Invalid schedule');
        }
        $cron = new Cron($expression, $timezone);
        $next = $cron->next(time());
        Models\ScheduledJob::storeCron($this->database, $name, $kind, json_encode($payload, JSON_THROW_ON_ERROR), $owner, $next, $expression, $timezone);
    }
    /**
     * 保存固定秒数间隔的计划，允许 10 秒至 30 天。
     *
     * @param string $name 非空且稳定的计划名称，用于唯一识别和更新。
     * @param int $seconds 固定间隔秒数，允许 10 至 2592000。
     * @param string $kind 非空任务种类，需有对应 Job 处理器。
     * @param array $payload 可 JSON 编码的任务参数数组。
     * @param int $owner 生成任务所属的用户编号。
     * @return void 无返回值，写入或更新计划。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $schedule->every('report', 60, 'report.build', [], 1);
     */
    public function every(string $name, int $seconds, string $kind, array $payload, int $owner): void
    {
        if ($name === '' || $kind === '' || $seconds < 10 || $seconds > 2592000) {
            throw new HttpError(422, 'Invalid schedule');
        }
        Models\ScheduledJob::storeInterval($this->database, $name, $kind, json_encode($payload, JSON_THROW_ON_ERROR), $owner, $seconds, time() + $seconds);
    }
    /**
     * 在事务内锁定到期计划并生成任务及运行记录，然后推进下次时间；跳过其他进程持有的锁。
     *
     * @param ?int $now 本轮调度的 Unix 时间戳秒数；null 使用当前时间。默认值为 null。
     * @return void 无返回值，每次最多处理 100 条到期计划。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $schedule->tick();
     */
    public function tick(?int $now = null): void
    {
        $now = $now ?? time();
        $this->database->transaction(function () use ($now): void {
            foreach (Models\ScheduledJob::due($this->database, $now) as $schedule) {
                $next = $schedule['expression'] !== null ? (new Cron($schedule['expression'], $schedule['timezone']))->next($now) : $now + (int)$schedule['interval_seconds'];
                $job = Models\Job::using($this->database)->create(['kind' => $schedule['kind'], 'payload' => $schedule['payload'], 'owner' => (int)$schedule['owner'], 'available' => $now, 'created' => $now]);
                Models\ScheduleRun::using($this->database)->create(['schedule_name' => $schedule['name'], 'job_id' => $job->id, 'created' => $now]);
                Models\ScheduledJob::using($this->database)->whereName($schedule['name'])->update(['next_run' => $next]);
            }
        });
    }
}
