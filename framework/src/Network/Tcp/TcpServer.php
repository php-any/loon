<?php

declare(strict_types=1);

namespace Loom;

/**
 * 运行逐行 JSON TCP 服务，对每个连接设置资源限制，并通过入口回调处理消息和写回响应。
 *
 * 构造参数：
 * - $scope（\Loom\ServiceScope）：当前执行单元拥有的服务作用域。
 * - $network（\Loom\Network）：网络限制配置服务，读取并校验协议参数。
 * - $config（\Loom\Config）：项目配置实例，提供配置读取及项目路径解析。
 * - $database（\Loom\Database）：当前作用域的数据库服务，负责连接、查询及事务。
 *
 * 返回值：使用 new 构造时得到 TcpServer 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $services->get(TcpServer::class)->run();
 */
final class TcpServer
{
    private \Loom\ServiceScope $scope;

    private \Loom\Network $network;
    private \Loom\Config $config;
    private \Loom\Database $database;

    /**
     * 初始化 TcpServer 实例及其依赖。运行逐行 JSON TCP 服务，对每个连接设置资源限制，并通过入口回调处理消息和写回响应。
     *
     * @param \Loom\ServiceScope $scope 当前执行单元拥有的服务作用域。
     * @param \Loom\Network $network 网络限制配置服务，读取并校验协议参数。
     * @param \Loom\Config $config 项目配置实例，提供配置读取及项目路径解析。
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services->get(TcpServer::class)->run();
     */
    public function __construct(\Loom\ServiceScope $scope, \Loom\Network $network, \Loom\Config $config, \Loom\Database $database)
    {
        $this->scope = $scope;

        $this->network = $network;
        $this->config = $config;
        $this->database = $database;
    }

    /**
     * 读取 TCP 地址和限制，安装每连接独立作用域的处理回调并阻塞监听。
     *
     * 参数：无。
     *
     * @return void 无返回值，直到服务结束或抛出异常。
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
                $scope->services()->get(\Loom\TcpServer::class)->handle($connection);
            } finally {
                $scope->close();
            }
        });
    }
    /**
     * 循环读取 JSON 对象行并调用配置入口，限制消息数量及响应大小，结束时关闭数据库和连接。
     *
     * @param mixed $connection 提供 read()、write() 和 close() 的原生 TCP 连接。
     * @return void 无返回值，响应以 JSON 加换行写入连接。
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
     * 从可复用缓冲区读取一条换行终止帧，拒绝超长帧及连接结束时的残缺帧。
     *
     * @param mixed $connection 提供 read()、write() 和 close() 的原生 TCP 连接。
     * @param string $buffer 按引用传入的未消费字节缓冲区，方法会保留下一帧的剩余数据。
     * @param int $maximum 帧最大字节数，不含 LF，但包含可选的 CR。
     * @return ?string 不带 CR/LF 的行；连接正常结束且无残留数据时为 null。
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
