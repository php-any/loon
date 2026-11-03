<?php

declare(strict_types=1);

/**
 * 数据库连接配置。Loom 把它转换成进程级 database/sql 连接池使用的 DSN。
 * 当前只接受 mysql: 开头的 DSN。
 * 配置先读取 LOOM_DATABASE；未设置或为空时使用下方默认 DSN。
 * loom.php 先加载根目录 .env（或 LOOM_ENV_FILE 指定的文件），其中的值覆盖进程环境变量。
 * 配置加载后会缓存，修改文件或环境变量后应重启相关服务和工作进程。
 *
 * 本地环境示例（写入 .env，凭据使用实际值）：
 * LOOM_DATABASE="mysql:host=127.0.0.1;port=13306;dbname=loom_test;charset=utf8mb4"
 * LOOM_MYSQL_USER=loom
 * LOOM_MYSQL_PASSWORD=your-local-password
 *
 * 用户名和密码由数据库服务直接读取上述环境变量，不是此数组中的配置键。
 * 添加 username、password、driver、pool 等键不会改变当前连接行为。
 */
return [
    /**
     * PDO 风格的 MySQL DSN，类型为非空字符串。运行时会把它转成 database/sql DSN。
     * host：数据库主机，例如 127.0.0.1、mysql 或数据库服务器域名。
     * port：MySQL 端口，例如默认 3306，或本地容器映射端口 13306。
     * dbname：已创建的数据库名称，例如 loom、loom_test；此配置不会自动创建数据库。
     * charset：连接字符集，当前使用 utf8mb4；其他字符集需由运行时和数据库共同支持。
     * 示例：'dsn' => 'mysql:host=mysql;port=3306;dbname=loom;charset=utf8mb4'。
     * 不要将账号密码拼入 DSN；更换数据库时也应检查 Redis 前缀等环境配置。
     */
    'dsn' => (getenv('LOOM_DATABASE') ?: 'mysql:host=127.0.0.1;port=3306;dbname=loom;charset=utf8mb4'),
];
