<?php

declare(strict_types=1);

namespace Loom;

/**
 * 解析五字段 Cron，并按给定 IANA 时区计算下一次运行。日和周都不是通配时，两者满足一个即可。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $cron = new Cron('0 9 * * 1-5', 'Asia/Shanghai');
 */
final class Cron
{
    private array $fields = [];
    private array $wildcards = [];
    private \DateTimeZone $zone;
    /**
     * 校验时区并解析五个字段，把允许的分、时、日、月、周记下来。空时区、Local、非法表达式或超出范围抛 HTTP 422；运行时不能做不可变时区转换时抛 HTTP 503。
     *
     * @param string $expression 按「分 时 日 月 周」排列的五段。支持星号、列表、范围，步长只能用在星号或范围上。
     * @param string $timezone IANA 时区名，例如 Asia/Shanghai。不能是空串或 Local。
     * @return void 不返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $cron = new Cron('0 9 * * 1-5', 'Asia/Shanghai');
     */
    public function __construct(string $expression, string $timezone)
    {
        if ($timezone === '' || $timezone === 'Local') {
            throw new HttpError(422, '请使用明确的 IANA 时区');
        }
        try {
            $this->zone = new \DateTimeZone($timezone);
        } catch (\Throwable $error) {
            throw new HttpError(422, '无效时区');
        }
        // 验证运行时的时区转换，不能静默使用 UTC。
        $probe = new \DateTimeImmutable('@0');
        $converted = $probe->setTimezone($this->zone);
        if ($timezone !== 'UTC' && $converted === $probe) {
            throw new HttpError(503, '当前 Origami std 未提供可用的不可变时区转换，暂不能使用此 Cron 时区');
        }
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) {
            throw new HttpError(422, 'Cron 需要五个字段：分 时 日 月 周');
        }
        $mins = [0, 0, 1, 1, 0];
        $maxs = [59, 23, 31, 12, 7];
        foreach ($parts as $i => $part) {
            $values = [];
            $this->wildcards[] = str_starts_with($part, '*');
            foreach (explode(',', $part) as $item) {
                if (!preg_match('/^(\*|[0-9]+(?:-[0-9]+)?)(?:\/([0-9]+))?$/D', $item, $match)) {
                    throw new HttpError(422, '无效 Cron 字段');
                }
                $step = isset($match[2]) ? (int) $match[2] : 1;
                if ($step < 1 || $step > $maxs[$i] + 1) {
                    throw new HttpError(422, '无效 Cron 步长');
                }
                if ($match[1] === '*') {
                    $lo = $mins[$i];
                    $hi = $maxs[$i];
                } else {
                    $range = explode('-', $match[1]);
                    $lo = (int)$range[0];
                    $hi = (int)($range[1] ?? $range[0]);
                    if (isset($match[2]) && count($range) !== 2) {
                        throw new HttpError(422, '步长须用于 * 或范围');
                    }
                }
                if ($lo < $mins[$i] || $hi > $maxs[$i] || $hi < $lo) {
                    throw new HttpError(422, 'Cron 字段超出范围');
                }
                for ($n = $lo; $n <= $hi; $n += $step) {
                    $values[$i === 4 && $n === 7 ? 0 : $n] = true;
                }
            }
            $this->fields[] = $values;
        }
    }
    /**
     * 把 Unix 时间戳转换为规则声明时区中的不可变时间。
     *
     * @param int $timestamp Unix 时间戳，单位秒。
     * @return \DateTimeImmutable 设置指定时区的 DateTimeImmutable。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $local = $cron->local(time());
     */
    public function local(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->zone);
    }
    /**
     * 从 after 之后的下一分钟寻找匹配时间，最多搜索八年；没有匹配则抛出 HTTP 422。
     *
     * @param int $after 搜索起点的 Unix 时间戳，单位秒，不包含该时刻。
     * @return int 严格晚于 after 的下一次运行 Unix 时间戳，单位秒。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $next = $cron->next(time());
     */
    public function next(int $after): int
    {
        $time = (int)(floor($after / 60) * 60 + 60);
        $end = $after + 366 * 8 * 86400;
        while ($time < $end) {
            $parts = explode(' ', $this->local($time)->format('i G j n w'));
            $minute = (int)$parts[0];
            $hour = (int)$parts[1];
            $dom = (int)$parts[2];
            $month = (int)$parts[3];
            $dow = (int)$parts[4];
            $day = isset($this->fields[2][$dom]) && isset($this->fields[4][$dow]);
            if (!$this->wildcards[2] && !$this->wildcards[4]) {
                $day = isset($this->fields[2][$dom]) || isset($this->fields[4][$dow]);
            }
            if (isset($this->fields[3][$month]) && $day && isset($this->fields[1][$hour]) && isset($this->fields[0][$minute])) {
                return $time;
            }
            $time += (!isset($this->fields[3][$month]) || !$day || !isset($this->fields[1][$hour])) ? (60 - $minute) * 60 : 60;
        }
        throw new HttpError(422, '未来八年内没有匹配的执行时间');
    }
}
