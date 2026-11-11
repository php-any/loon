<?php

declare(strict_types=1);

namespace Loom\Filesystem;

/**
 * ApplicationBuilder 上的文件系统配置入口。
 */
final class FilesystemBuilder
{
    public function __construct(private readonly FilesystemRegistration $registration)
    {
    }

    /**
     * 在 build() 前注册自定义驱动，例如 s3。
     *
     * @param callable(\Loom\Config, array): FilesystemDisk $factory
     */
    public function extend(string $driver, callable $factory): self
    {
        $this->registration->extend($driver, $factory);
        return $this;
    }

    public function registration(): FilesystemRegistration
    {
        return $this->registration;
    }
}
