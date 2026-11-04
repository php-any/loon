<?php

declare(strict_types=1);

namespace Loom\Models;

/**
 * loom_migrations 的一行。主键是命名空间加版本，另外保存校验和、批次和应用时间。new 出来的对象不会自己写库。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $record = new MigrationRecord();
 */
#[\Database\Annotation\Table('loom_migrations')]
final class MigrationRecord extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('namespace', false)]
    public string $namespace;

    #[\Database\Annotation\Id]
    #[\Database\Annotation\Column('version', false)]
    public string $version;

    #[\Database\Annotation\Column('checksum', false)]
    public string $checksum;

    #[\Database\Annotation\Column('batch', false)]
    public int $batch;

    #[\Database\Annotation\Column('applied_at', false)]
    public int $applied_at;
}
