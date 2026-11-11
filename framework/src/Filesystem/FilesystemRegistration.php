<?php

declare(strict_types=1);

namespace Loom\Filesystem;

/**
 * build() 前登记自定义磁盘驱动工厂。
 */
final class FilesystemRegistration
{
    /** @var array<string, callable(Config, array): FilesystemDisk> */
    private array $drivers = [];

    /**
     * @param callable(\Loom\Config, array): FilesystemDisk $factory
     */
    public function extend(string $driver, callable $factory): void
    {
        if ($driver === '' || $driver === 'local') {
            throw new \LogicException('Cannot replace built-in local driver via extend');
        }
        $this->drivers[$driver] = $factory;
    }

    /**
     * @return array<string, callable(\Loom\Config, array): FilesystemDisk>
     */
    public function drivers(): array
    {
        return $this->drivers;
    }
}
