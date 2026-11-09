<?php
#[\Loom\Timer(1)]
final class TimerProbe {
    public int $calls = 0;
    public function __invoke(): void { $this->calls++; }
}
#[\Loom\Timer(0)]
final class InvalidTimerInterval { public function __invoke(): void {} }
#[\Loom\Timer(1)]
final class InvalidTimerHandler { public function __invoke(string $value): void {} }
#[\Loom\Timer(1)]
#[\Loom\Timer(2)]
final class DuplicateTimer { public function __invoke(): void {} }
final class NotATimer {}

#[\Loom\Timer(1)]
final class SlowTimerProbe {
    public array $starts = [];
    public int $active = 0;
    public int $maxActive = 0;
    public function __invoke(): void {
        $this->starts[] = microtime(true);
        $this->active++;
        $this->maxActive = max($this->maxActive, $this->active);
        usleep(1400000);
        $this->active--;
    }
}

#[\Loom\Timer(1)]
final class ShortTimerProbe {
    public array $starts = [];
    public function __invoke(): void {
        $this->starts[] = microtime(true);
        usleep(400000);
    }
}

#[\Loom\Timer(cron: '*/5 * * * *', timezone: 'UTC')]
final class CronTimerProbe {
    public int $calls = 0;
    public function __invoke(): void { $this->calls++; }
}

#[\Loom\Timer(5, '*/5 * * * *')]
final class InvalidTimerBoth { public function __invoke(): void {} }

#[\Loom\Timer(cron: 'not-a-cron')]
final class InvalidTimerCron { public function __invoke(): void {} }


