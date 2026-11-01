<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

/**
 * 读取任务信封文件，在任务请求上下文执行入口并恢复原请求。由控制台容器解析依赖并调用。
 *
 * 构造参数：
 * - $scope（\Loom\ServiceScope）：命令运行期间的服务作用域，提供配置、数据库和业务服务。
 *
 * 返回值：使用 new 构造时得到 JobCommand 实例；注册到容器时由容器管理实例的创建与复用。
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
     * 初始化 JobCommand 实例及其依赖。读取任务信封文件，在任务请求上下文执行入口并恢复原请求。由控制台容器解析依赖并调用。
     *
     * @param \Loom\ServiceScope $scope 命令运行期间的服务作用域，提供配置、数据库和业务服务。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $console->run(['_job', '/tmp/job.json']);
     */
    public function __construct(private \Loom\ServiceScope $scope)
    {
    }

    /**
     * 读取任务信封文件，在任务请求上下文执行入口并恢复原请求。
     *
     * 参数：无。
     *
     * @return int 成功返回 0；异常交给控制台转换为非零退出码。
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
        $services = $this->scope->services();
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
