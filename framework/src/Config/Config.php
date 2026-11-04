<?php

declare(strict_types=1);

namespace Loom;

/**
 * 按文件名加载项目 config 目录下的 php 文件，再用点分键读取。每个实例单独缓存；更换根目录会丢掉缓存。
 *
 * 构造参数：
 * - $root（?string）：已存在的项目根目录。null 表示构造时先不设置。默认 null。
 *
 * 返回值：使用 new 构造时得到尚未读取配置文件的 Config。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $config = new Config($projectRoot);
 */
final class Config
{
    /**
     * 根目录不是 null 时立刻交给 setRoot 校验并保存。null 时不设置根目录，也不读取配置。
     *
     * @param ?string $root 已存在的项目根目录。null 表示构造时不设置。默认 null。
     * @return void 无直接返回值。目录不存在时异常来自 setRoot。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $config = new Config($projectRoot);
     */
    public function __construct(?string $root = null)
    {
        if ($root !== null) {
            $this->setRoot($root);
        }
    }
    private ?string $root = null;
    private ?array $values = null;
    private bool $loading = false;
    /**
     * 用 realpath 确认这是一个已存在的目录，保存解析后的路径，并丢掉已经加载的配置缓存。
     *
     * @param string $root 要作为项目根的目录路径。
     * @return void 无返回值。路径不存在或不是目录时抛出 InvalidArgumentException。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $config->setRoot($projectRoot);
     */
    public function setRoot(string $root): void
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException('Application root must be an existing directory');
        }
        $this->root = $resolved;
        $this->values = null;
    }
    /**
     * 返回 setRoot 保存的绝对路径。还没有设置根目录时抛出 LogicException。
     *
     * 参数：无。
     *
     * @return string realpath 之后的根目录。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $root = $config->root();
     */
    public function root(): string
    {
        if ($this->root === null) {
            throw new \LogicException('Application root has not been configured');
        }
        return $this->root;
    }
    /**
     * 以 /、反斜杠，或以盘符加斜杠、反斜杠开头的路径原样返回。其余路径接在根目录后面，中间加一个斜杠。
     * 不检查目标是否存在。根目录尚未设置时，相对路径会在 root 上抛出 LogicException。
     *
     * @param string $path 绝对路径，或相对项目根的路径。
     * @return string 拼好的路径。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $file = $config->path('config/http.php');
     */
    public function path(string $path): string
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            return $path;
        }
        return $this->root() . '/' . $path;
    }
    /**
     * 缓存已经存在时直接返回。正在加载时再进入会抛出 LogicException。
     * 否则按文件名排序 require config 目录中的 php 文件，不进入子目录，用去掉后缀的文件名做键。
     * 任一文件抛错时，加载中标记会在 finally 里清掉，缓存保持未设置，原异常继续抛出。
     *
     * 参数：无。
     *
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $config->load();
     */
    public function load(): void
    {
        if ($this->values !== null) {
            return;
        }
        if ($this->loading) {
            throw new \LogicException('Config files must not read other configuration during loading');
        }
        $files = glob($this->path('config/*.php'));
        sort($files, SORT_STRING);
        $values = [];
        $this->loading = true;
        try {
            foreach ($files as $file) {
                $name = basename($file, '.php');
                $values[$name] = $this->readFile($file);
            }
            $this->values = $values;
        } finally {
            $this->loading = false;
        }
    }
    /**
     * require 这个 php 文件并返回它的返回值。不写入缓存，也不另外包装异常。
     *
     * @param string $file 要执行的 php 文件路径。
     * @return mixed 该文件 return 的值。
     *
     * 内部调用示例（仅类内使用）：
     * @example $value = $this->readFile($file);
     */
    private function readFile(string $file): mixed
    {
        return require $file;
    }
    /**
     * 先 load。key 为 null 时返回文件名到文件返回值的整份缓存。
     * 否则按点号逐段向下取。任一段不是数组或键不存在时返回 default。键存在且值是 null 时返回 null。
     *
     * @param ?string $key 点分键，例如 http.address。null 表示整份缓存。默认 null。
     * @param mixed $default 沿途缺键时返回的值。默认 null。
     * @return mixed 找到的配置值，或 default。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $timeout = $config->get('network.http.requestTimeoutMs', 10000);
     */
    public function get(?string $key = null, mixed $default = null): mixed
    {
        $this->load();
        if ($key === null) {
            return $this->values;
        }
        $value = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
