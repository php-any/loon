<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\ConsoleUser;
use Loom\Console\Command;
use Loom\Console\InputError;
use Loom\Queue;

#[\Cli\Annotation\Command]
final class ReportsDispatch extends Command
{
    private Queue $queue;

    public function __construct(Queue $queue)
    {
        $this->queue = $queue;
    }

    protected string $signature = 'reports:dispatch {user : 用户 ID} {--delay=0 : 延迟秒数，0–86400} {--dry-run : 只检查，不入队}';
    protected string $description = '把进展摘要交给持久化队列';

    public function handle(): int
    {
        $id = ConsoleUser::requireId($this->argument('user'));
        $delay = $this->option('delay');
        if (!ctype_digit($delay) || (int) $delay > 86400) {
            throw new InputError('delay 必须是 0–86400 的整数');
        }
        if ($this->option('dry-run')) {
            $this->info('检查通过：将为用户 ' . $id . ' 生成摘要，延迟 ' . $delay . ' 秒。');
            return 0;
        }
        $job = $this->queue->dispatch('studio.digest', [], $id, (int) $delay);
        $this->info('已入队 Job #' . $job . '；由运行中的 队列消费者 执行。');
        return 0;
    }
}
