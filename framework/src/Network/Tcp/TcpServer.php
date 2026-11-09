<?php

declare(strict_types=1);

namespace Loom;

/**
 * 按 tcp 配置监听逐行 JSON。每条消息在新的 ServiceScope 里解析 tcp.handler 指定的类并调用 handle(array $message): array。
 *
 * @example $app->services->get(TcpServer::class)->run();
 */
final class TcpServer
{
    public function __construct(
        private readonly ServiceProvider $services,
        private readonly Network $network,
        private readonly Config $config,
    ) {
    }

    /**
     * 读取 tcp 配置和限制，去掉 maxFrameBytes、maxResponseBytes、maxMessages 后 configure 原生服务，然后阻塞监听。
     */
    public function run(): void
    {
        $config = $this->config->get('tcp');
        if (!isset($config['address'], $config['handler']) || !is_string($config['handler']) || !class_exists($config['handler'])) {
            throw new \LogicException('TCP config requires address and an existing handler class');
        }
        $limits = $this->network->limits('tcp');
        $server = new \Net\Tcp\Server($config['address']);
        foreach (['maxFrameBytes', 'maxResponseBytes', 'maxMessages'] as $name) {
            unset($limits[$name]);
        }
        $server->configure($limits);
        echo 'Loom TCP: ' . $config['address'] . "\n";
        $listener = $this;
        $server->serve(static function ($connection) use ($listener): void {
            $listener->handle($connection);
        });
    }

    /**
     * 按行读取连接。每行必须是 JSON 对象，否则回 ok=false、error=invalid_message。合法消息各开一个作用域交给处理类。
     * 响应超过 maxResponseBytes 时抛出 RuntimeException。达到 maxMessages 后停止。结束时关闭连接。
     *
     * @param mixed $connection 提供 read、write 和 close 的连接。
     */
    public function handle(mixed $connection): void
    {
        try {
            $limits = $this->network->limits('tcp');
            $handler = $this->config->get('tcp')['handler'];
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
                } catch (\JsonException|\InvalidArgumentException) {
                    $message = null;
                }
                $reply = $message === null
                    ? ['ok' => false, 'error' => 'invalid_message']
                    : $this->services->withMonitoredScope(
                        \Loom\Monitoring\MonitoringContext::tcp(),
                        static fn (ServiceScope $scope): array => $scope->get($handler)->handle($message),
                    );
                $bytes = json_encode($reply, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
                if (strlen($bytes) > $limits['maxResponseBytes']) {
                    throw new \RuntimeException('TCP response too large');
                }
                $connection->write($bytes);
            }
        } finally {
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
