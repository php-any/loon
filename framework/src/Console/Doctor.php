<?php

declare(strict_types=1);

namespace Loom;

/**
 * 检查当前 Origami 运行时是否具备网络、协程、不可变时区和 MySQL 读写。任一项不满足就抛出 RuntimeException，后面的检查不再做。
 *
 * 构造参数：
 * - $network（\Loom\Network）：检查 HTTP 限制时要读取的网络服务。
 * - $database（\Loom\Database）：临时表插入和查询使用的数据库服务。
 *
 * 返回值：只保存这两项依赖的 Doctor，检查要到 check 才执行。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $doctor = new \Loom\Doctor($network, $database);
 */
final class Doctor
{
    private \Loom\Network $network;
    private \Loom\Database $database;

    /**
     * 保存网络服务和数据库服务。不检查类是否存在，也不连接数据库。
     *
     * @param \Loom\Network $network 要保存的网络服务。
     * @param \Loom\Database $database 要保存的数据库服务。
     * @return void 无返回值，两个依赖已保存。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $doctor = new \Loom\Doctor($network, $database);
     */
    public function __construct(\Loom\Network $network, \Loom\Database $database)
    {
        $this->network = $network;
        $this->database = $database;
    }

    /**
     * 依次确认所需标准库类存在、临时 HTTP 服务有 configure 并用已保存网络服务的 HTTP 限制调用它、协程经 Channel 送回 42、公元起点转到 Asia/Shanghai 后不是原对象。原对象格式须为 1970-01-01T00:00:00+00:00，转换结果须为 1970-01-01T08:00:00+08:00。再用随机表名插入一行并查回。任一步不符合就抛出 RuntimeException。建表成功后，插入和查询放在 try 里，finally 删除该表。需要一份能执行这段 MySQL 的连接；检查通过时不写控制台文字。
     *
     * 参数：无。
     *
     * @return void 无返回值；失败时抛出 RuntimeException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $doctor->check();
     */
    public function check(): void
    {
        foreach (['Net\\Http\\Server', 'Net\\Tcp\\Server', 'Net\\Grpc\\Schema', 'Net\\Grpc\\Server', 'Container\\Container', 'Database\\Annotation\\Table', 'PDO', 'DateTimeImmutable', 'DateTimeZone', 'Channel'] as $class) {
            if (!class_exists($class)) {
                throw new \RuntimeException('Missing Origami std class: ' . $class);
            }
        }
        $http = new \Net\Http\Server('127.0.0.1', 0);
        if (!method_exists($http, 'configure')) {
            throw new \RuntimeException('Missing HTTP network limits: update Origami');
        }
        $http->configure($this->network->limits('http'));
        $channel = new \Channel(1);
        \spawn(function () use ($channel): void {
            $value = 42;
            $channel->send($value);
        });
        if ($channel->receive() !== 42) {
            throw new \RuntimeException('Origami spawn closure regression: update the runtime');
        }
        $channel->close();
        $utc = new \DateTimeImmutable('@0');
        $local = $utc->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        if ($local === $utc || $utc->format('c') !== '1970-01-01T00:00:00+00:00' || $local->format('c') !== '1970-01-01T08:00:00+08:00') {
            throw new \RuntimeException('Origami DateTimeImmutable timezone regression: update the runtime');
        }
        $table = 'loom_doctor_' . bin2hex(random_bytes(8));
        $this->database->execute('CREATE TABLE ' . $table . '(id BIGINT PRIMARY KEY AUTO_INCREMENT,value VARCHAR(32))');
        try {
            $result = $this->database->execute('INSERT INTO ' . $table . '(value) VALUES(?)', ['std']);
            $rows = $this->database->query('SELECT id,value FROM ' . $table);
            if ($result['id'] !== 1 || count($rows) !== 1 || $rows[0]['value'] !== 'std') {
                throw new \RuntimeException('MySQL insert/query check failed');
            }
        } finally {
            $this->database->execute('DROP TABLE ' . $table);
        }
    }
}
