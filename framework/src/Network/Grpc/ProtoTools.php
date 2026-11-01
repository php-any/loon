<?php

declare(strict_types=1);

namespace Loom;

/**
 * 创建协议模板并检查项目协议集合，复用生成器校验路径、导入及 PHP 命名冲突。
 *
 * 构造参数：
 * - $config（\Loom\Config）：项目配置实例，提供配置读取及项目路径解析。
 * - $grpcGenerator（\Loom\GrpcGenerator）：gRPC 协议代码生成服务。
 *
 * 返回值：使用 new 构造时得到 ProtoTools 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $tools->check('proto/release.proto');
 */
final class ProtoTools
{
    private \Loom\Config $config;
    private \Loom\GrpcGenerator $grpcGenerator;

    /**
     * 初始化 ProtoTools 实例及其依赖。创建协议模板并检查项目协议集合，复用生成器校验路径、导入及 PHP 命名冲突。
     *
     * @param \Loom\Config $config 项目配置实例，提供配置读取及项目路径解析。
     * @param \Loom\GrpcGenerator $grpcGenerator gRPC 协议代码生成服务。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $tools->check('proto/release.proto');
     */
    public function __construct(\Loom\Config $config, \Loom\GrpcGenerator $grpcGenerator)
    {
        $this->config = $config;
        $this->grpcGenerator = $grpcGenerator;
    }

    /**
     * 验证 PascalCase 服务名及小写点分包名，独占创建 proto 模板，绝不覆盖已有文件。
     *
     * @param string $name 单个 PascalCase 服务名，最长 80 字符，例如 Invoice。
     * @param string $package 小写点分包名，例如 app.billing。
     * @return void 无返回值，输出创建路径及后续生成提示。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $tools->create('Invoice', 'app.billing');
     */
    public function create(string $name, string $package): void
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9]{0,79}$/D', $name)) {
            throw new \InvalidArgumentException('name must be a single PascalCase identifier');
        }
        GrpcGenerator::identifier($name);
        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/D', $package)) {
            throw new \InvalidArgumentException('package must be dotted lowercase identifiers, for example app.users');
        }
        foreach (explode('.', $package) as $part) {
            GrpcGenerator::identifier($part);
        }
        $relative = 'proto/' . strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name)) . '.proto';
        $path = $this->grpcGenerator->target($relative);
        if (file_exists($path)) {
            throw new \RuntimeException('Refusing to overwrite existing proto: ' . $relative);
        }
        $template = file_get_contents(dirname(__DIR__, 3) . '/resources/stubs/service.proto.stub');
        $source = str_replace(['{{package}}', '{{name}}'], [$package, $name], $template);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true)) {
            throw new \RuntimeException('Cannot create proto directory');
        }
        $file = fopen($path, 'x');
        if ($file === false) {
            throw new \RuntimeException('Cannot exclusively create proto: ' . $relative);
        }
        try {
            if (fwrite($file, $source) !== strlen($source)) {
                throw new \RuntimeException('Incomplete proto write: ' . $relative);
            }
        } finally {
            fclose($file);
        }
        echo 'Created ' . $relative . "\n";
        echo 'Next: edit the contract, then make:grpc ' . $relative . " --dry-run\n";
    }
    /**
     * 检查指定协议或项目全部协议的生成计划及合并 Schema，不写生成产物。
     *
     * @param ?string $input 指定 .proto 文件路径；null 时检查整个项目。默认值为 null。
     * @return void 无返回值，成功打印数量；失败报告涉及的协议文件。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $tools->check();
     */
    public function check(?string $input = null): void
    {
        $files = $input === null ? $this->projectFiles() : [$input];
        if ($files === []) {
            throw new \RuntimeException('No proto files found');
        }
        $owned = [];
        $entries = [];
        $imports = [];
        foreach ($files as $file) {
            try {
                $plan = $this->grpcGenerator->plan($file);
                foreach ($plan['services'] as $service) {
                    $class = strtolower($service['class']);
                    if (isset($owned[$class]) && $owned[$class] !== $plan['key']) {
                        throw new \LogicException('Generated class collision with ' . $owned[$class] . ': ' . $service['class']);
                    }
                    $owned[$class] = $plan['key'];
                }
                $entries[] = $plan['record']['proto_file'];
                $imports = array_merge($imports, $plan['record']['import_paths']);
            } catch (\Throwable $error) {
                throw new \RuntimeException($file . ': ' . $error->getMessage());
            }
        }
        $absolute = [];
        foreach (array_unique($imports) as $path) {
            $absolute[] = $this->config->path($path);
        }
        new \Net\Grpc\Schema(array_values(array_unique($entries)), $absolute);
        echo 'Proto check: OK (' . count($files) . " files)\n";
    }
    /**
     * 收集 proto 目录、显式配置和生成清单中的文件，校验存在性并按规范路径去重排序。
     *
     * 参数：无。
     *
     * @return array 协议绝对路径列表。
     *
     * 内部调用示例（仅类内使用）：
     * @example $files = $this->projectFiles();
     */
    private function projectFiles(): array
    {
        $files = [];
        self::collect($this->config->path('proto'), $files);
        $config = $this->config->get('grpc');
        foreach ($config['proto_files'] as $entry) {
            $found = false;
            foreach ($config['import_paths'] as $path) {
                $candidate = $this->config->path($path . '/' . $entry);
                if (is_file($candidate)) {
                    $files[] = $candidate;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new \RuntimeException('Configured proto not found: ' . $entry);
            }
        }
        $manifest = $this->config->path('generated/grpc/routes.json');
        if (is_file($manifest)) {
            foreach (json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR)['files'] as $file => $record) {
                $files[] = $this->config->path($file);
            }
        }
        $unique = [];
        foreach ($files as $file) {
            $resolved = realpath($file);
            if ($resolved === false) {
                throw new \RuntimeException('Registered proto not found: ' . $file);
            }
            $unique[$resolved] = str_replace('\\', '/', $resolved);
        }
        $result = array_values($unique);
        sort($result, SORT_STRING);
        return $result;
    }
    /**
     * 递归收集目录中的 .proto 文件，目录不存在时忽略，符号链接目录拒绝。
     *
     * @param string $directory 要递归遍历的协议目录。
     * @param array $files 按引用接收已发现协议文件路径的列表。
     * @return void 无返回值，通过引用追加 files。
     *
     * 内部调用示例（仅类内使用）：
     * @example self::collect($directory, $files);
     */
    private static function collect(string $directory, array &$files): void
    {
        if (!is_dir($directory)) {
            return;
        }
        if (is_link($directory)) {
            throw new \RuntimeException('Refusing symlink proto directory: ' . $directory);
        }
        foreach (glob($directory . '/*') as $path) {
            if (is_dir($path)) {
                self::collect($path, $files);
            } elseif (pathinfo($path, PATHINFO_EXTENSION) === 'proto') {
                $files[] = $path;
            }
        }
    }
}
