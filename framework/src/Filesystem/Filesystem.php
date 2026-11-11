<?php

declare(strict_types=1);

namespace Loom\Filesystem;

use Loom\Config;

/**
 * 按 config/filesystems.php 解析命名磁盘。
 */
final class Filesystem
{
    /** @var array<string, FilesystemDisk> */
    private array $resolved = [];

    public function __construct(
        private readonly Config $config,
        private readonly FilesystemOptions $options,
        private readonly FilesystemRegistration $registration,
    ) {
    }

    public function disk(?string $name = null): FilesystemDisk
    {
        $name = $name ?? $this->options->default;
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }
        if (!isset($this->options->disks[$name]) || !is_array($this->options->disks[$name])) {
            throw new \LogicException('Unknown filesystem disk: ' . $name);
        }
        $diskConfig = $this->options->disks[$name];
        $driver = (string)($diskConfig['driver'] ?? 'local');
        if ($driver === 'local') {
            $disk = new LocalFilesystemDisk($this->config, $name, $diskConfig);
        } elseif (isset($this->registration->drivers()[$driver])) {
            $disk = $this->registration->drivers()[$driver]($this->config, $diskConfig);
        } else {
            throw new \LogicException('Unknown filesystem driver: ' . $driver);
        }
        $this->resolved[$name] = $disk;
        return $disk;
    }

    /**
     * @return list<array{prefix: string, disk: FilesystemDisk}>
     */
    public function servedDisks(): array
    {
        $out = [];
        foreach ($this->options->disks as $diskName => $diskConfig) {
            if (!is_array($diskConfig)) {
                continue;
            }
            $serve = $diskConfig['serve'] ?? false;
            $url = $diskConfig['url'] ?? '';
            if ($serve !== true || !is_string($url) || $url === '') {
                continue;
            }
            $out[] = ['prefix' => rtrim($url, '/'), 'disk' => $this->disk($diskName)];
        }
        return $out;
    }
}
