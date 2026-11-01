<?php

declare(strict_types=1);

/**
 * 应用数据库迁移配置，用于 migrate:up、migrate:status、migrate:down。
 * 默认 HTTP 服务、队列和调度工作进程在数据库启动阶段也会执行待应用的迁移。
 * 框架自身的 loom_base、loom 迁移由框架单独管理，不受这里的 path 控制。
 * 修改配置后需重启相关进程；不要通过更换 namespace 绕过既有迁移记录。
 */
return [
    /**
     * 应用迁移记录的命名空间，类型为非空字符串，用于区分 loom_migrations 中的历史。
     * 当前为 studio；新项目可使用自己的稳定名称，例如 'namespace' => 'billing'。
     * 名称没有固定枚举，但应避免与框架的 loom、loom_base 冲突，并适配记录列的 120 字符长度。
     * 对已有数据库修改此值会使原历史不再被本配置识别，可能重新执行 SQL。
     */
    'namespace' => 'studio',
    /**
     * 迁移目录，类型为字符串；相对于项目根目录，也支持绝对路径。
     * 示例：'path' => 'database/billing-migrations'。
     * 仅扫描目录直接包含的 *.up.sql，不递归；每个文件必须配套同名 *.down.sql。
     * 例如 012_orders.up.sql 与 012_orders.down.sql；按文件名字符串顺序执行，建议补齐数字位数。
     * up/down 都必须有内容；回滚撤销当前命名空间最后一个批次。
     * 已执行文件的两份 SQL（含注释及换行）参与校验和，不应原地修改，应新增迁移版本。
     */
    'path' => 'database/migrations',
];
