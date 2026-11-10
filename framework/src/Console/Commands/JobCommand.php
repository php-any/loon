<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * 队列子进程入口：读任务信封文件，在本命令的作用域里交给 JobDispatcher。响应状态 >= 300 时以异常结束，正文写到标准输出。
 */
final class JobCommand extends \Loom\Console\Command
{
    protected string $signature = '_job {file : 任务输入文件}';
    protected string $description = '执行队列子进程任务';
    protected bool $hidden = true;

    public function __construct(private \Loom\JobDispatcher $jobs, private \Loom\ServiceScope $scope)
    {
    }

    public function handle(): int
    {
        $file = $this->argument('file');
        if (!is_file($file)) {
            throw new \Loom\Console\InputError('Job input file required');
        }
        $payload = file_get_contents($file);
        $job = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        if (!isset($job['id'], $job['kind'], $job['owner'], $job['attempt'])) {
            throw new \InvalidArgumentException('Invalid job envelope');
        }
        $incoming = \Loom\Monitoring\Correlation::fromJobEnvelope($job);
        $correlation = $this->scope->get(\Loom\Monitoring\CorrelationId::class)->adopt($incoming);
        $execute = function () use ($job, $correlation, $payload): int {
            $request = new \Loom\Request(['method' => 'POST', 'path' => '/', 'headers' => ['content-type' => ['application/json']], 'query' => [], 'body' => $payload]);
            $jobContext = \Loom\Monitoring\MonitoringContext::job((string)$job['kind'], (int)$job['id'])->withCorrelationId($correlation);
            \Loom\Monitoring\MonitoringGate::begin($this->scope, $jobContext);
            $monitoring = $this->scope->tryGet(\Loom\Monitoring\Monitoring::class);
            $monitoring?->recordEvent('queue.job.dispatch', ['id' => $job['id'], 'kind' => $job['kind'], 'attempt' => $job['attempt']]);
            $result = $this->jobs->dispatch($job['kind'], $request, $this->scope)->toArray();
            if ($result['status'] >= 300) {
                throw new \RuntimeException('Job failed: ' . $result['body']);
            }
            echo $result['body'];
            return 0;
        };
        if (\function_exists('Loom\\Native\\withCorrelation')) {
            return \Loom\Native\withCorrelation($correlation, $execute);
        }
        return $execute();
    }
}
