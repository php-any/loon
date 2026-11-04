<?php

declare(strict_types=1);

namespace Loom;

/**
 * 按 tcp 配置监听，每个连接 fork 一次作用域，再把逐行 JSON 交给入口脚本。handle 结束时关闭数据库和连接。
 *
 * 构造参数：
 * - $scope（ServiceScope）：run 时作为每条连接 fork 的父作用域，handle 时用来 load 入口。
 * - $network（Network）：读取 tcp 限制。
 * - $config（Config）：读取 tcp 的地址和入口脚本。
 * - $database（Database）：handle 结束时关闭。
 *
 * 返回值：使用 new 构造时得到尚未监听的 TcpServer。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $server = new TcpServer($scope, $network, $config, $database);
 */
final class TcpServer
{
    private \Loom\ServiceScope $scope;

    private \Loom\Network $network;
    private \Loom\Config $config;
    private \Loom\Database $database;

    /**
     * 保存作用域、网络限制、配置和数据库。构造时不读取 tcp 配置，也不开始监听。
     *
     * @param \Loom\ServiceScope $scope 每条连接 fork 以及加载入口时使用的父作用域。
     * @param \Loom\Network $network 提供 tcp 限制的对象。
     * @param \Loom\Config $config 提供 tcp 地址和入口脚本的配置。
     * @param \Loom\Database $database handle 结束时要关闭的数据库。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $server = new TcpServer($scope, $network, $config, $database);
     */
    public function __construct(\Loom\ServiceScope $scope, \Loom\Network $network, \Loom\Config $config, \Loom\Database $database)
    {
        $this->scope = $scope;

        $this->network = $network;
        $this->config = $config;
        $this->database = $database;
    }

    /**
     * 读取 tcp 配置和 tcp 限制，去掉 maxFrameBytes、maxResponseBytes、maxMessages 后再 configure 原生服务。
     * 先把监听地址 echo 到标准输出，然后阻塞 serve。每个连接 fork 父作用域，在子容器里解析 TcpServer 并 handle；无论成败都关闭子作用域。
     *
     * 参数：无。
     *
     * @return void 无返回值。配置或限制不合法时在开始监听前抛出。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $server->run();
     */
    public function run(): void
    {
        $config = $this->config->get('tcp');
        $limits = $this->network->limits('tcp');
        $server = new \Net\Tcp\Server($config['address']);
        foreach (['maxFrameBytes', 'maxResponseBytes', 'maxMessages'] as $name) {
            unset($limits[$name]);
        }
        $server->configure($limits);
        echo 'Loom TCP: ' . $config['address'] . "\n";
        $parentScope = $this->scope;
        $server->serve(static function ($connection) use ($parentScope): void {
            $scope = $parentScope->fork();
            try {
                \Loom\ModelContext::during($scope, static function () use ($scope, $connection): void {
                    $scope->container()->get(\Loom\TcpServer::class)->handle($connection);
                });
            } finally {
                $scope->close();
            }
        });
    }
    /**
     * load tcp 入口得到处理回调，再用 readLine 按行读取。每一行必须是 JSON 对象，解码失败或不是对象时写回 ok 为 false、error 为 invalid_message。
     * 成功时把回调的返回值编码成一行 JSON。响应字节数超过 maxResponseBytes 时抛出 RuntimeException。达到 maxMessages 后停止读取。
     * finally 里关闭数据库和连接，包括中途抛错。
     *
     * @param mixed $connection 提供 read、write 和 close 的连接。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $server->handle($connection);
     */
    public function handle(mixed $connection): void
    {
        try {
            $limits = $this->network->limits('tcp');
            $handler = $this->scope->load($this->config->get('tcp')['entry']);
            $buffer = '';
            $count = 0;
            while ($count < $limits['maxMessages']) {
                $line = self::readLine($connection, $buffer, $limits['maxFrameBytes']);
                if ($line === null) {
                    break;
                }
                $count++;
                try {
                    $message = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                    if (!is_array($message) || !str_starts_with(ltrim($line), '{')) {
                        throw new \InvalidArgumentException('Expected a JSON object');
                    }
                    $reply = $handler($message);
                } catch (\JsonException|\InvalidArgumentException $error) {
                    $reply = ['ok' => false, 'error' => 'invalid_message'];
                }
                $bytes = json_encode($reply, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
                if (strlen($bytes) > $limits['maxResponseBytes']) {
                    throw new \RuntimeException('TCP response too large');
                }
                $connection->write($bytes);
            }
        } finally {
            $this->database->close();
            $connection->close();
        }
    }
    /**
     * 在 buffer 里寻找换行。换行前的长度超过 maximum，或缓冲区在还没有换行时已经超过 maximum，都抛出 RuntimeException。
     * 没有完整一行时继续 read。read 得到空字符串时，缓冲区还有残字节则抛出 RuntimeException，否则返回 null。
     * 返回的行去掉末尾的 CR，buffer 留下换行之后的内容。
     *
     * @param mixed $connection 提供 read 的连接。每次最多读 8192 字节，并且不超过这一帧剩余的额度。
     * @param string $buffer 尚未消费的字节。按引用保留下一帧的剩余部分。
     * @param int $maximum 一帧允许的最大字节数，按换行前的长度计算。
     * @return ?string 去掉 CR 和 LF 的一行；对端结束且没有残字节时为 null。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $buffer = ''; $line = TcpServer::readLine($connection, $buffer, 1048576);
     */
    public static function readLine(mixed $connection, string &$buffer, int $maximum): ?string
    {
        while (true) {
            $end = strpos($buffer, "\n");
            if ($end !== false) {
                if ($end > $maximum) {
                    throw new \RuntimeException('TCP frame too large');
                }
                $line = substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + 1);
                return rtrim($line, "\r");
            }
            if (strlen($buffer) > $maximum) {
                throw new \RuntimeException('TCP frame too large');
            }
            $chunk = $connection->read(min(8192, $maximum + 1 - strlen($buffer)));
            if ($chunk === '') {
                if ($buffer !== '') {
                    throw new \RuntimeException('Incomplete TCP frame');
                }
                return null;
            }
            $buffer .= $chunk;
        }
    }
}
