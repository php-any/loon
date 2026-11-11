<?php

declare(strict_types=1);

namespace Loom;

/**
 * 保存 Cron 或固定间隔计划。到期时在一个事务里写成队列任务和运行记录，并推进下次时间。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $schedule = new Schedule($database);
 */
final class Schedule
{
    private \Loom\Database $database;

    /**
     * 只保存数据库。这里不写入计划，也不推进到期任务。
     *
     * @param \Loom\Database $database 以后读写 loom_schedules、任务和运行记录。
     * @return void 不返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $schedule = new Schedule($database);
     */
    public function __construct(\Loom\Database $database, private readonly \Loom\Queue $queue)
    {
        $this->database = $database;
    }

    /**
     * 使用与调度器一致的 Cron 解析器预览未来五次执行时间。
     *
     * @param string $expression 用来向后推五次的五字段表达式。
     * @param string $timezone 计算这五次时间所用的 IANA 时区。
     * @return array 五个带 UTC 偏移的 ISO 8601 本地时间字符串。时区无效、表达式不合法，或八年内没有下一次时抛 HTTP 422 或 503。
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
     * @param string $name 要写入或覆盖的 Cron 计划名。空串抛 HTTP 422。
     * @param string $expression 要保存的五字段表达式。非法时构造 Cron 会抛 HTTP 422。
     * @param string $timezone 和表达式一起保存的 IANA 时区。
     * @param string $kind 到期后投递的任务种类。空串抛 HTTP 422。
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
     * @param string $name 要写入或覆盖的间隔计划名。空串抛 HTTP 422。
     * @param int $seconds 固定间隔秒数。小于 10 或大于 2592000 抛 HTTP 422。
     * @param string $kind 到期后投递的任务种类。空串抛 HTTP 422。
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
                $this->dispatchSchedule($schedule, $now);
            }
        });
    }

    /**
     * 读取当前已到期的计划行，不锁定也不投递任务。供 CLI 预演与测试使用。
     *
     * @param ?int $now 比较的 Unix 秒；null 使用当前时间。
     * @return array 到期计划行，字段同 loom_schedules 查询结果。
     */
    public function dueSnapshot(?int $now = null): array
    {
        $now = $now ?? time();

        return $this->database->query(
            'SELECT name, kind, next_run, interval_seconds, expression, timezone, enabled FROM loom_schedules WHERE enabled=1 AND next_run<=? ORDER BY name LIMIT 100',
            [$now],
        );
    }

    /**
     * 按名称立即投递一次计划任务并推进下次运行时间，不要求 next_run 已到期。
     *
     * @param string $name loom_schedules.name。
     * @param ?int $now 用作投递时间与 Cron 推进基准；null 使用当前时间。
     */
    public function runNow(string $name, ?int $now = null): void
    {
        if ($name === '') {
            throw new HttpError(422, 'Invalid schedule');
        }
        $now = $now ?? time();
        $this->database->transaction(function () use ($name, $now): void {
            $schedule = $this->database->query('SELECT * FROM loom_schedules WHERE name=? FOR UPDATE', [$name])[0] ?? null;
            if ($schedule === null) {
                throw new HttpError(404, 'Schedule not found');
            }
            if (!(int)$schedule['enabled']) {
                throw new HttpError(422, 'Schedule disabled');
            }
            $this->dispatchSchedule($schedule, $now);
        });
    }

    /**
     * @param array<string, mixed> $schedule loom_schedules 一行
     */
    private function dispatchSchedule(array $schedule, int $now): void
    {
        $next = $schedule['expression'] !== null ? (new Cron($schedule['expression'], $schedule['timezone']))->next($now) : $now + (int)$schedule['interval_seconds'];
        $payload = json_decode((string)$schedule['payload'], true, 64, JSON_THROW_ON_ERROR);
        $jobId = $this->queue->dispatch($schedule['kind'], is_array($payload) ? $payload : [], (int)$schedule['owner']);
        Models\ScheduleRun::create(['schedule_name' => $schedule['name'], 'job_id' => $jobId, 'created' => $now]);
        Models\ScheduledJob::whereName($schedule['name'])->update(['next_run' => $next]);
    }
}
