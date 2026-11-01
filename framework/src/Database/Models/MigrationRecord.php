<?php

declare(strict_types=1);

namespace Loom\Models;

/**
 * 保存命名空间和版本组成的复合主键迁移历史，映射到 loom_migrations；继承 Model 的查询方法，实例本身不自动持久化。
 *
 * 构造参数：本声明未定义构造参数；继承类型沿用父类的构造约定。
 *
 * 返回值：使用 new 构造时得到 MigrationRecord 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $rows = \Loom\Models\MigrationRecord::using($database)->limit(10)->rows();
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
