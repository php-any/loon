<?php

declare(strict_types=1);

namespace Loom;

/**
 * 合并配置与生成清单中的协议，启动 gRPC 服务并把调用转换为脚本信封，逐调用隔离作用域。
 *
 * 构造参数：
 * - $scope（\Loom\ServiceScope）：当前执行单元拥有的服务作用域。
 * - $config（\Loom\Config）：项目配置实例，提供配置读取及项目路径解析。
 * - $database（\Loom\Database）：当前作用域的数据库服务，负责连接、查询及事务。
 * - $network（\Loom\Network）：网络限制配置服务，读取并校验协议参数。
 *
 * 返回值：使用 new 构造时得到 GrpcServer 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $services->get(GrpcServer::class)->run();
 */
final class GrpcServer
{
    private \Loom\ServiceScope $scope;

    private \Loom\Config $config;
    private \Loom\Database $database;
    private \Loom\Network $network;

    /**
     * 初始化 GrpcServer 实例及其依赖。合并配置与生成清单中的协议，启动 gRPC 服务并把调用转换为脚本信封，逐调用隔离作用域。
     *
     * @param \Loom\ServiceScope $scope 当前执行单元拥有的服务作用域。
     * @param \Loom\Config $config 项目配置实例，提供配置读取及项目路径解析。
     * @param \Loom\Database $database 当前作用域的数据库服务，负责连接、查询及事务。
     * @param \Loom\Network $network 网络限制配置服务，读取并校验协议参数。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $services->get(GrpcServer::class)->run();
     */
    public function __construct(\Loom\ServiceScope $scope, \Loom\Config $config, \Loom\Database $database, \Loom\Network $network)
    {
        $this->scope = $scope;

        $this->config = $config;
        $this->database = $database;
        $this->network = $network;
    }

    /**
     * 合并并去重 proto 文件和导入目录，将目录解析为项目绝对路径。
     *
     * 参数：无。
     *
     * @return \Net\Grpc\Schema 可供原生服务注册使用的 Net\Grpc\Schema。
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
     * 读取地址、Schema、反射和网络限制，安装每次 RPC 的作用域回调并阻塞监听。
     *
     * 参数：无。
     *
     * @return void 无返回值，直到服务结束或异常。
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
                $scope->services()->get(\Loom\GrpcServer::class)->handle($call);
            } finally {
                $scope->close();
            }
        });
    }
    /**
     * 将原生 RPC 请求 JSON 解码为信封并调用入口，将 data 或命名错误转换回原生响应。
     *
     * @param mixed $call 原生 RPC 调用对象，提供 method、requestJson、respondJson 和 fail。
     * @return void 无返回值；未知异常写日志并返回 gRPC internal，最后关闭数据库。
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
