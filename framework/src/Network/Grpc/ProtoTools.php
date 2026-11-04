<?php

declare(strict_types=1);

namespace Loom;

/**
 * 按模板新建 proto 文件，或检查项目中的 proto 能否生成并合成 Schema。本类不写 PHP 产物。
 *
 * 构造参数：
 * - $config（Config）：把导入目录解析成绝对路径，并定位 proto 目录和清单。
 * - $grpcGenerator（GrpcGenerator）：校验标识符、定位输出文件，并为每个 proto 做 plan。
 *
 * 返回值：使用 new 构造时得到 ProtoTools。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $tools = new ProtoTools($config, $generator);
 */
final class ProtoTools
{
    private \Loom\Config $config;
    private \Loom\GrpcGenerator $grpcGenerator;

    /**
     * 保存配置和生成器。构造时不读取 proto 目录，也不创建文件。
     *
     * @param \Loom\Config $config 解析 proto 路径和导入目录的配置。
     * @param \Loom\GrpcGenerator $grpcGenerator 校验名字并生成 plan 的生成器。
     * @return void 无直接返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $tools = new ProtoTools($config, $generator);
     */
    public function __construct(\Loom\Config $config, \Loom\GrpcGenerator $grpcGenerator)
    {
        $this->config = $config;
        $this->grpcGenerator = $grpcGenerator;
    }

    /**
     * 服务名必须是单个 PascalCase，最长 80 个字符；包名必须是小写点分标识。两处都再经 GrpcGenerator::identifier。不合法时抛出 InvalidArgumentException。
     * 目标文件在 proto 目录下，名字由服务名转成小写蛇形。文件已存在则抛出 RuntimeException，拒绝覆盖。
     * 从框架 stub 替换 package 和 name，用独占方式创建文件。短写或无法创建目录时抛出 RuntimeException。成功时向标准输出打印路径和下一步提示。
     *
     * @param string $name PascalCase 服务名，例如 Invoice。
     * @param string $package 小写点分包名，例如 app.billing。
     * @return void 无返回值。
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
     * input 为 null 时用 projectFiles 收集全部 proto，否则只检查给出的这一个路径。一个文件都没有时抛出 RuntimeException。
     * 每个文件 plan 一次。服务类名被另一份 proto 占用，或 plan 失败时，抛出带该文件名的 RuntimeException。
     * 最后用收集到的 proto 和导入目录创建 Schema。成功时向标准输出打印文件数。不写生成产物。
     *
     * @param ?string $input 单个 proto 路径。null 表示检查收集到的全部文件。默认 null。
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $tools->check('proto/release.proto');
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
     * 递归收集项目 proto 目录，再按 grpc 的 proto_files 到各个 import_paths 下查找，最后并入清单里的文件。
     * 某个配置项在所有导入目录都找不到，或 realpath 失败时，抛出 RuntimeException。结果按绝对路径去重并排序。
     *
     * 参数：无。
     *
     * @return array 协议文件的绝对路径。
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
     * 目录不存在时直接返回。目录本身是符号链接时抛出 RuntimeException。
     * 子目录递归收集，扩展名为 proto 的文件追加到 files。这里不去重。
     *
     * @param string $directory 要遍历的目录。不存在时忽略。
     * @param array $files 收集到的路径。按引用追加。
     * @return void 无返回值。
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
