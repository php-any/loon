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
        $request = new \Loom\Request(['method' => 'POST', 'path' => '/', 'headers' => ['content-type' => ['application/json']], 'query' => [], 'body' => $payload]);
        $result = $this->jobs->dispatch($job['kind'], $request, $this->scope)->toArray();
        if ($result['status'] >= 300) {
            throw new \RuntimeException('Job failed: ' . $result['body']);
        }
        echo $result['body'];
        return 0;
    }
}
