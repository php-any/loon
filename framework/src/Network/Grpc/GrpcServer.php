<?php

declare(strict_types=1);

namespace Loom;

/**
 * 按 grpc 配置启动原生服务。每次调用 fork 一个作用域，把 RPC 收成脚本信封交给入口，再把结果写回调用。
 *
 * 构造参数：
 * - $scope（ServiceScope）：run 时每个调用 fork 的父作用域，handle 时用来 load 入口。
 * - $config（Config）：读取 grpc 段，schema 时还读取生成清单。
 * - $database（Database）：handle 结束时关闭。
 * - $network（Network）：run 时读取 grpc 限制。
 *
 * 返回值：使用 new 构造时得到尚未监听的 GrpcServer。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $server = new GrpcServer($scope, $config, $database, $network);
 */
final class GrpcServer
{
    private \Loom\ServiceScope $scope;

    private \Loom\Config $config;
    private \Loom\Database $database;
    private \Loom\Network $network;

    /**
     * 保存作用域、配置、数据库和网络限制。构造时不读取 grpc 配置，也不启动服务。
     *
     * @param \Loom\ServiceScope $scope 每个 RPC fork 以及加载入口时使用的父作用域。
     * @param \Loom\Config $config 提供 grpc 地址、协议文件和入口的配置。
     * @param \Loom\Database $database 单次 handle 结束时关闭的数据库。
     * @param \Loom\Network $network run 时提供 grpc 限制。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $server = new GrpcServer($scope, $config, $database, $network);
     */
    public function __construct(\Loom\ServiceScope $scope, \Loom\Config $config, \Loom\Database $database, \Loom\Network $network)
    {
        $this->scope = $scope;

        $this->config = $config;
        $this->database = $database;
        $this->network = $network;
    }

    /**
     * 从 grpc 配置取出 proto_files 和 import_paths。项目根下的 generated/grpc/routes.json 存在时，把清单里每个文件的 proto 和导入目录并进去。
     * 导入目录经配置转成绝对路径。协议文件和导入目录各自去重后交给 Net\Grpc\Schema。清单 JSON 不合法时，异常来自 json_decode。
     *
     * 参数：无。
     *
     * @return \Net\Grpc\Schema 合并后的协议描述。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $schema = $server->schema();
     */
    public function schema(): \Net\Grpc\Schema
    {
        $config = $this->config->get('grpc');
        $files = $config['proto_files'];
        $paths = $config['import_paths'];
        $manifest = $this->config->root() . '/generated/grpc/routes.json';
        if (is_file($manifest)) {
            foreach (json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR)['files'] as $file) {
                $files[] = $file['proto_file'];
                foreach ($file['import_paths'] as $path) {
                    $paths[] = $path;
                }
            }
        }
        $absolute = [];
        foreach (array_unique($paths) as $path) {
            $absolute[] = $this->config->path($path);
        }
        return new \Net\Grpc\Schema(array_values(array_unique($files)), $absolute);
    }
    /**
     * 用 grpc 地址和 schema 创建原生服务，套上 grpc 限制和 reflection 开关。先 echo 地址，再阻塞 serve。
     * 每个调用 fork 父作用域，在子容器里解析 GrpcServer 并 handle，最后关闭子作用域。
     *
     * 参数：无。
     *
     * @return void 无返回值。配置缺失或限制不合法时在开始监听前抛出。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $server->run();
     */
    public function run(): void
    {
        $config = $this->config->get('grpc');
        $server = new \Net\Grpc\Server($config['address'], $this->schema());
        $server->configure($this->network->limits('grpc'));
        $server->reflection($config['reflection'] ?? false);
        echo 'Loom gRPC: ' . $config['address'] . "\n";
        $parentScope = $this->scope;
        $server->serve(static function ($call) use ($parentScope): void {
            $scope = $parentScope->fork();
            try {
                \Loom\ModelContext::during($scope, static function () use ($scope, $call): void {
                    $scope->container()->get(\Loom\GrpcServer::class)->handle($call);
                });
            } finally {
                $scope->close();
            }
        });
    }
    /**
     * load grpc 入口，把 method 以及 requestJson 解码后的请求交给它。
     * 结果含 error 时按命名状态 fail，未知状态码按 2；否则用 respondJson 写出 data。
     * 任何 Throwable，包括请求 JSON 不合法，都把消息写入标准错误并 fail(13)。finally 关闭数据库。异常不抛回监听循环。
     *
     * @param mixed $call 提供 method、requestJson、respondJson 和 fail 的原生调用。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $server->handle($call);
     */
    public function handle(mixed $call): void
    {
        try {
            $handler = $this->scope->load($this->config->get('grpc')['entry']);
            $result = $handler(['method' => $call->method(), 'request' => json_decode($call->requestJson(), true, 64, JSON_THROW_ON_ERROR)]);
            if (isset($result['error'])) {
                $codes = ['cancelled' => 1, 'unknown' => 2, 'invalid_argument' => 3, 'deadline_exceeded' => 4, 'not_found' => 5, 'already_exists' => 6, 'permission_denied' => 7, 'resource_exhausted' => 8, 'failed_precondition' => 9, 'aborted' => 10, 'out_of_range' => 11, 'unimplemented' => 12, 'internal' => 13, 'unavailable' => 14, 'data_loss' => 15, 'unauthenticated' => 16];
                $call->fail($codes[$result['error']['code']] ?? 2, $result['error']['message']);
            } else {
                $call->respondJson(json_encode($result['data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable $error) {
            fwrite(fopen('php://stderr', 'w'), $error->getMessage() . "\n");
            $call->fail(13, 'Internal Server Error');
        } finally {
            $this->database->close();
        }
    }
}
