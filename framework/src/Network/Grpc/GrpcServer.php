<?php

declare(strict_types=1);

namespace Loom;

/**
 * 按 grpc 配置启动原生服务。每次调用在新的 ServiceScope 里交给 Grpc\Dispatcher，再把结果写回调用。
 *
 * @example $app->services->get(GrpcServer::class)->run();
 */
final class GrpcServer
{
    private const STATUS = ['cancelled' => 1, 'unknown' => 2, 'invalid_argument' => 3, 'deadline_exceeded' => 4, 'not_found' => 5, 'already_exists' => 6, 'permission_denied' => 7, 'resource_exhausted' => 8, 'failed_precondition' => 9, 'aborted' => 10, 'out_of_range' => 11, 'unimplemented' => 12, 'internal' => 13, 'unavailable' => 14, 'data_loss' => 15, 'unauthenticated' => 16];

    public function __construct(
        private readonly ServiceProvider $services,
        private readonly Config $config,
        private readonly Network $network,
        private readonly Grpc\Dispatcher $dispatcher,
    ) {
    }

    /**
     * 合并 grpc 配置里的 proto_files、import_paths 和生成清单 generated/grpc/routes.json 里的协议，导入目录转成绝对路径并去重。
     */
    public function schema(): \Net\Grpc\Schema
    {
        $config = $this->config->get('grpc');
        $files = $config['proto_files'];
        $paths = $config['import_paths'];
        $manifest = $this->dispatcher->manifestPath();
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
     * 用 grpc 地址和 schema 创建原生服务，套上 grpc 限制和 reflection 开关，然后阻塞监听。
     */
    public function run(): void
    {
        $config = $this->config->get('grpc');
        $server = new \Net\Grpc\Server($config['address'], $this->schema());
        $server->configure($this->network->limits('grpc'));
        $server->reflection($config['reflection'] ?? false);
        echo 'Loom gRPC: ' . $config['address'] . "\n";
        $listener = $this;
        $server->serve(static function ($call) use ($listener): void {
            $listener->handle($call);
        });
    }

    /**
     * 在新作用域里分派一次调用。结果含 error 时按命名状态 fail，未知状态按 2；否则 respondJson 写出 data。
     * 任何 Throwable，包括请求 JSON 不合法，都写入标准错误并 fail(13)，不抛回监听循环。
     *
     * @param mixed $call 提供 method、requestJson、respondJson 和 fail 的原生调用。
     */
    public function handle(mixed $call): void
    {
        try {
            $request = json_decode($call->requestJson(), true, 64, JSON_THROW_ON_ERROR);
            $method = $call->method();
            $dispatcher = $this->dispatcher;
            $result = $this->services->withScope(static fn (ServiceScope $scope): array => $dispatcher->handle($scope, $method, $request));
            if (isset($result['error'])) {
                $call->fail(self::STATUS[$result['error']['code']] ?? 2, $result['error']['message']);
            } else {
                $call->respondJson(json_encode($result['data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable $error) {
            fwrite(fopen('php://stderr', 'w'), $error->getMessage() . "\n");
            $call->fail(13, 'Internal Server Error');
        }
    }
}
