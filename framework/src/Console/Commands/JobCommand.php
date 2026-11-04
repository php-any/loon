<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * 隐藏命令 _job。读取任务信封文件，换成任务请求后执行 jobs.entry，结束时恢复原来的请求。成功时把入口响应体 echo 到标准输出并返回 0。
 *
 * 构造参数：
 * - $scope（\Loom\ServiceScope）：取请求上下文、配置和加载任务入口的作用域。
 *
 * 返回值：尚未读取任务文件的 JobCommand。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $console->run(['_job', '/tmp/job.json']);
 */
final class JobCommand extends \Loom\Console\Command
{
    protected string $signature = '_job {file : 任务输入文件}';
    protected string $description = '执行队列子进程任务';
    protected bool $hidden = true;

    /**
     * 保存服务作用域。不打开任务文件，也不替换请求。
     *
     * @param \Loom\ServiceScope $scope 要保存的服务作用域。
     * @return void 无返回值，作用域已保存在实例上。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $command = new \Loom\Console\Commands\JobCommand($scope);
     */
    public function __construct(private \Loom\ServiceScope $scope)
    {
    }

    /**
     * 读取位置参数 file。路径不是已有文件时抛出 InputError。文件内容按深度 64 解码 JSON，解码失败时抛出异常。信封缺少 id、kind、owner 或 attempt 时抛出 InvalidArgumentException。然后把请求上下文换成正文为该文件内容的 POST /，加载配置 jobs.entry。入口返回的 status 大于等于 300 时抛出 RuntimeException。成功则 echo 响应体并返回 0，不经过 Output。try 期间无论成功或抛出，都会把请求上下文绑回进入前的值。
     *
     * 参数：无。
     *
     * @return int 入口成功时为 0。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->run(['_job', '/tmp/job.json']);
     */
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
        $services = $this->scope->container();
        $context = $services->get(\Loom\RequestContext::class);
        $previous = $context->replace(new \Loom\Request(['method' => 'POST', 'path' => '/', 'headers' => ['content-type' => ['application/json']], 'query' => [], 'body' => $payload]));
        try {
            $result = $this->scope->load($services->get(\Loom\Config::class)->get('jobs.entry'));
            if ($result['status'] >= 300) {
                throw new \RuntimeException('Job failed: ' . $result['body']);
            }
            echo $result['body'];
            return 0;
        } finally {
            $context->bind($previous);
        }
    }
}
