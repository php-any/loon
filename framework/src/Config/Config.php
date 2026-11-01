<?php

declare(strict_types=1);

namespace Loom;

/**
 * 从项目 config/*.php 按文件名加载配置，并以点分键读取；加载结果按实例缓存。
 *
 * 构造参数：
 * - $root（?string）：已存在的项目根目录；构造时可为 null，随后用 setRoot 设置。默认值为 null。
 *
 * 返回值：使用 new 构造时得到 Config 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $config = new Config($projectRoot); $address = $config->get('http.address');
 */
final class Config
{
    /**
     * 初始化 Config 实例及其依赖。从项目 config/*.php 按文件名加载配置，并以点分键读取；加载结果按实例缓存。
     *
     * @param ?string $root 已存在的项目根目录；构造时可为 null，随后用 setRoot 设置。默认值为 null。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $config = new Config($projectRoot); $address = $config->get('http.address');
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
     * 验证并设置现有项目根目录，同时使旧配置缓存失效。
     *
     * @param string $root 已存在的项目根目录；构造时可为 null，随后用 setRoot 设置。
     * @return void 无返回值；目录不存在时抛出 InvalidArgumentException。
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
     * 取得已设置的项目根目录，未配置时抛出 LogicException。
     *
     * 参数：无。
     *
     * @return string 规范化后的根目录绝对路径。
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
     * 将相对路径拼到项目根目录，绝对路径保持不变。
     *
     * @param string $path 相对项目根目录或绝对文件路径。
     * @return string 可用于文件访问的路径；不校验文件是否存在。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $path = $config->path('config/http.php');
     */
    public function path(string $path): string
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            return $path;
        }
        return $this->root() . '/' . $path;
    }
    /**
     * 按文件名排序 require 配置文件并缓存，配置加载过程中禁止递归读取其他配置。
     *
     * 参数：无。
     *
     * @return void 无返回值；已加载时直接返回。
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
     * 执行单个 PHP 配置文件并取得其返回值。
     *
     * @param string $file 需要 require 的 PHP 配置文件路径。
     * @return mixed 配置文件返回值，可以是数组、闭包等。
     *
     * 内部调用示例（仅类内使用）：
     * @example $value = $this->readFile($file);
     */
    private function readFile(string $file): mixed
    {
        return require $file;
    }
    /**
     * 按点分路径读取配置，首次读取时加载；缺失键返回默认值，显式 null 保持不变。
     *
     * @param ?string $key 点分配置键，例如 http.address；null 表示全部配置。默认值为 null。
     * @param mixed $default 键不存在时的回退值。默认值为 null。
     * @return mixed 配置值；key 为 null 时返回全部配置。
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
