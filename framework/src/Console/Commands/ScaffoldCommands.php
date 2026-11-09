<?php

declare(strict_types=1);

namespace Loom\Console\Commands;

use Loom\Config;
use Loom\Console\Console;
use Loom\Console\Definition;
use Loom\Console\Input;
use Loom\Console\InputError;
use Loom\Console\Output;
use Loom\GrpcGenerator;
use Loom\ProtoTools;

/**
 * 代码生成命令：make:command、make:timer、make:proto、proto:check、make:grpc。由 Application 在构建时登记。
 *
 * @example ScaffoldCommands::register($console);
 */
final class ScaffoldCommands
{
    private const RESERVED = 'abstract and array as bool break callable case catch class clone const continue declare default die do echo else elseif empty enddeclare endfor endforeach endif endswitch endwhile enum eval exit extends false final finally float fn for foreach function global goto if implements include include_once instanceof insteadof int interface isset iterable list match mixed namespace never new null object or parent print private protected public readonly require require_once resource return self static string switch throw trait true try unset use var void while xor yield';

    public static function register(Console $console): void
    {
        $console->command('make:command {name : PascalCase 命令类名} {--command= : 命令签名}', static function (Input $input, Output $output, Config $config) use ($console): int {
            $name = $input->argument('name');
            if (!preg_match('/^[A-Z][A-Za-z0-9]{0,79}$/D', $name)) {
                throw new InputError('name 必须是单个 PascalCase 类名，不接受路径或命名空间');
            }
            if (in_array(strtolower($name), explode(' ', self::RESERVED), true)) {
                throw new InputError('name 不能是 PHP 保留字');
            }
            $signature = $input->option('command') ?? ('app:' . strtolower($name));
            try {
                $definition = new Definition($signature, '');
            } catch (\LogicException $error) {
                throw new InputError($error->getMessage());
            }
            if ($console->has($definition->name)) {
                throw new InputError('命令名已存在：' . $definition->name);
            }
            $directory = $config->root() . '/app/Console/Commands';
            if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
                throw new \RuntimeException('无法创建命令目录');
            }
            $path = $directory . '/' . $name . '.php';
            if (file_exists($path)) {
                throw new InputError('命令文件已存在，未覆盖：' . $path);
            }
            $source = "<?php\ndeclare(strict_types=1);\n\nnamespace App\\Console\\Commands;\n\nuse Loom\\Console\\Command;\n\n#[\\Loom\\Attributes\\ConsoleCommand]\nfinal class " . $name . " extends Command\n{\n    protected string \$signature = " . var_export($signature, true) . ";\n    protected string \$description = '请填写命令说明';\n\n    public function handle(): int\n    {\n        \$this->info('命令已就绪，请在 handle 方法中实现业务逻辑。');\n        return 0;\n    }\n}\n";
            // 独占创建，并发执行时不会覆盖已有文件。
            $handle = fopen($path, 'x');
            if ($handle === false) {
                throw new \RuntimeException('无法独占创建命令文件');
            }
            try {
                if (fwrite($handle, $source) !== strlen($source)) {
                    throw new \RuntimeException('命令文件写入不完整');
                }
            } finally {
                fclose($handle);
            }
            $output->info('已创建：' . $path);
            return 0;
        }, '生成自动注册的 PHP 命令类，不覆盖已有文件');

        $console->command('make:timer {name : PascalCase 定时器类名} {--seconds=60 : 执行周期（秒，至少 1）}', static function (Input $input, Output $output, Config $config): int {
            $name = $input->argument('name');
            if (!preg_match('/^[A-Z][A-Za-z0-9]{0,79}$/D', $name)) {
                throw new InputError('name 必须是单个 PascalCase 类名，不接受路径或命名空间');
            }
            if (in_array(strtolower($name), explode(' ', self::RESERVED), true)) {
                throw new InputError('name 不能是 PHP 保留字');
            }
            $seconds = (int)$input->option('seconds');
            if ($seconds < 1) {
                throw new InputError('seconds 必须至少为 1');
            }
            $directory = $config->root() . '/app/Console/Timers';
            if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
                throw new \RuntimeException('无法创建定时器目录');
            }
            $path = $directory . '/' . $name . '.php';
            if (file_exists($path)) {
                throw new InputError('定时器文件已存在，未覆盖：' . $path);
            }
            $source = "<?php\ndeclare(strict_types=1);\n\nnamespace App\\Console\\Timers;\n\nuse Loom\\Timer;\n\n#[Timer(" . $seconds . ")]\nfinal class " . $name . "\n{\n    public function __invoke(): void\n    {\n        // 定时任务逻辑\n    }\n}\n";
            $handle = fopen($path, 'x');
            if ($handle === false) {
                throw new \RuntimeException('无法独占创建定时器文件');
            }
            try {
                if (fwrite($handle, $source) !== strlen($source)) {
                    throw new \RuntimeException('定时器文件写入不完整');
                }
            } finally {
                fclose($handle);
            }
            $output->info('已创建：' . $path);
            return 0;
        }, '生成带 #[Timer] 注解的定时器类，不覆盖已有文件');

        $console->command('make:proto {name : PascalCase 名称，例如 User} {--package=app : Protobuf 包名}', static function (Input $input, ProtoTools $tools): int {
            $tools->create($input->argument('name'), $input->option('package'));
            return 0;
        }, '创建 proto3 协议模板，不覆盖已有文件');

        $console->command('proto:check {file? : 可选的单个 proto 文件}', static function (Input $input, ProtoTools $tools): int {
            $tools->check($input->argument('file'));
            return 0;
        }, '检查协议语法、imports、命名冲突和不支持的 RPC');

        $console->command('make:grpc {file : proto 文件} {--dry-run : 预览文件变更，不写入}', static function (Input $input, GrpcGenerator $generator): int {
            $generator->generate($input->argument('file'), $input->option('dry-run'));
            return 0;
        }, '生成 gRPC 基类与注册清单，保留手写业务文件并提示待实现方法');
    }
}
