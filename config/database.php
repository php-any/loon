<?php

declare(strict_types=1);

/**
 * 数据库连接，写法与 Laravel 的 config/database.php 相同，环境变量用 env() 读取。
 * default 是连接名，connections 里每一项是一个库。未声明连接的模型使用 default。
 * 当前驱动使用 MySQL。mysql 与 mariadb 都走 MySQL 协议；sqlite、pgsql、sqlsrv 会拒绝。
 *
 * 连接读取 url、host、port、database、username、password、unix_socket、charset、dsn。
 * url 例如 mysql://user:secret@127.0.0.1:3306/loom?charset=utf8mb4，没有 dsn 时覆盖同名的主机、库名和账号。
 * dsn 为 env('LOOM_DATABASE')。它是非空的 mysql:host=...;dbname=... 时，连接直接使用这条 DSN，并优先于 url。
 * collation、prefix、prefix_indexes、strict、engine、options 可以按 Laravel 原样保留，连接建立不使用表前缀。
 * DB_* 未设置时回退到 LOOM_DB_*、LOOM_MYSQL_USER、LOOM_MYSQL_PASSWORD。不要把密码写进 url 以外的 DSN。
 * 配置加载后会缓存，修改后重启相关服务和工作进程。
 *
 * 再加一个库时按同样的键追加，例如 connections.reporting，模型声明 protected static ?string $connection = 'reporting'。
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    */
    'default' => env('DB_CONNECTION', env('LOOM_DB_CONNECTION', 'mysql')),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    */
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', env('LOOM_DB_HOST', '127.0.0.1')),
            'port' => env('DB_PORT', env('LOOM_DB_PORT', '3306')),
            'database' => env('DB_DATABASE', env('LOOM_DB_DATABASE', 'loom')),
            'username' => env('DB_USERNAME', env('LOOM_MYSQL_USER', '')),
            'password' => env('DB_PASSWORD', env('LOOM_MYSQL_PASSWORD', '')),
            'unix_socket' => env('DB_SOCKET', env('LOOM_DB_SOCKET', '')),
            'charset' => env('DB_CHARSET', env('LOOM_DB_CHARSET', 'utf8mb4')),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [],
            'dsn' => env('LOOM_DATABASE'),
        ],
    ],
];
