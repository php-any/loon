<?php

declare(strict_types=1);

namespace Loom;

// 标准库 spawn 与 Channel 使管道读取独立于监督进程的截止时间。
/**
 * 以参数数组启动子进程，并发读取两路输出，限制总耗时及输出量，结束后清理进程与管道。
 *
 * 构造参数：
 * - $config（\Loom\Config）：项目配置实例，提供配置读取及项目路径解析。
 *
 * 返回值：使用 new 构造时得到 Process 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $result = $process->run([PHP_BINARY, $config->path('loom.php'), 'list'], 10);
 */
final class Process
{
    private \Loom\Config $config;

    /**
     * 初始化 Process 实例及其依赖。以参数数组启动子进程，并发读取两路输出，限制总耗时及输出量，结束后清理进程与管道。
     *
     * @param \Loom\Config $config 项目配置实例，提供配置读取及项目路径解析。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $result = $process->run([PHP_BINARY, $config->path('loom.php'), 'list'], 10);
     */
    public function __construct(\Loom\Config $config)
    {
        $this->config = $config;
    }

    /**
     * 在项目根目录运行子进程，合并监督 stdout/stderr；超时或总输出超过 4 MiB 时抛出异常并终止进程。
     *
     * @param array $arguments 可执行文件和各参数组成的数组，不拼接为 shell 命令字符串。
     * @param int $timeout 总执行时间上限，单位秒。默认值为 60。
     * @return array 包含 code 退出码、stdout 标准输出、stderr 标准错误的数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $result = $process->run([PHP_BINARY, $config->path('loom.php'), 'list'], 10);
     */
    public function run(array $arguments, int $timeout = 60): array
    {
        $pipes = [];
        $process = proc_open($arguments, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->config->root());
        if (!is_resource($process) || !isset($pipes[1],$pipes[2])) {
            throw new \RuntimeException('Cannot start process with output streams');
        }
        $events = new \Channel(2);
        $received = 0;
        $output = '';
        $errors = '';
        $deadline = microtime(true) + $timeout;
        foreach ($pipes as $fd => $stream) {
            \spawn(function () use ($events, $fd, $stream): void {
                try {
                    $text = stream_get_contents($stream, 4194305);
                    if ($text === false) {
                        throw new \RuntimeException('Cannot read process output');
                    }
                    $events->send(['fd' => $fd, 'text' => $text, 'error' => null]);
                } catch (\Throwable $error) {
                    $events->send(['fd' => $fd, 'text' => '', 'error' => $error->getMessage()]);
                }
            });
        }
        try {
            while ($received < 2) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('Process timed out');
                }
                if ($events->len() === 0) {
                    usleep(10000);
                    continue;
                }
                $event = $events->receive();
                $received++;
                if ($event['error'] !== null) {
                    throw new \RuntimeException($event['error']);
                }
                if ($event['fd'] === 1) {
                    $output = $event['text'];
                } else {
                    $errors = $event['text'];
                }
                if (strlen($output) + strlen($errors) > 4194304) {
                    throw new \RuntimeException('Process output exceeded 4 MiB');
                }
            }
            while (proc_get_status($process)['running']) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('Process timed out');
                }
                usleep(10000);
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $pipes = [];
            $code = proc_close($process);
            $process = null;
            return ['code' => $code, 'stdout' => $output, 'stderr' => $errors];
        } finally {
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                // 等待事件完成之前关闭读取器，以解除挂起的读取。
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                while ($received < 2) {
                    $events->receive();
                    $received++;
                }
                proc_close($process);
            }
            $events->close();
        }
    }
}
