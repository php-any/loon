<?php

declare(strict_types=1);

namespace Loom;

/**
 * 检查当前 Origami 运行时的网络、协程、时区和 MySQL 能力，发现缺失能力时立即报告。
 *
 * 构造参数：
 * - $network（\Loom\Network）：网络限制配置服务，读取并校验协议参数。
 * - $database（\Loom\Database）：当前作用域的数据库服务，负责连接、查询及事务。
 *
 * 返回值：使用 new 构造时得到 Doctor 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $services->get(Doctor::class)->check();
 */
final class Doctor
{
    private \Loom\Network $network;
    private \Loom\Database $database;

    /**
     * 初始化 Doctor 实例及其依赖。检查当前 Origami 运行时的网络、协程、时区和 MySQL 能力，发现缺失能力时立即报告。
     *
     * @param \Loom\Network $network 网络限制配置服务，读取并校验协议参数。
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services->get(Doctor::class)->check();
     */
    public function __construct(\Loom\Network $network, \Loom\Database $database)
    {
        $this->network = $network;
        $this->database = $database;
    }

    /**
     * 验证所需原生类和能力，测试协程传值、不可变时区转换，并通过临时表验证 MySQL 写入及查询。
     *
     * 参数：无。
     *
     * @return void 无返回值；任一能力不满足时抛出 RuntimeException，需要可用 MySQL 连接。
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
        $db = $this->database->connection();
        $table = 'loom_doctor_' . bin2hex(random_bytes(8));
        $db->exec('CREATE TEMPORARY TABLE ' . $table . '(id BIGINT PRIMARY KEY AUTO_INCREMENT,value VARCHAR(32))');
        try {
            $result = $this->database->execute('INSERT INTO ' . $table . '(value) VALUES(?)', ['std']);
            $rows = $this->database->query('SELECT id,value FROM ' . $table);
            if ($result['id'] !== 1 || count($rows) !== 1 || $rows[0]['value'] !== 'std') {
                throw new \RuntimeException('MySQL PDO insert/query check failed');
            }
        } finally {
            $db->exec('DROP TEMPORARY TABLE ' . $table);
        }
    }
}
