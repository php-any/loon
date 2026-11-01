<?php

declare(strict_types=1);

/**
 * 后台任务执行入口配置。
 * queue:work 消费任务后，由独立子进程通过内部 _job 命令加载该入口。
 * 默认入口读取当前请求中的任务数据，按 Job 注解的 kind 找到处理器并执行。
 * 本文件只配置执行入口；队列使用 Redis，连接信息由 LOOM_REDIS_URL 等环境变量提供。
 * 任务重试次数、执行超时、轮询间隔等目前不是此文件支持的配置项。
 * 修改后应重启队列工作进程，确保父子进程使用一致的配置。
 */
return [
    /**
     * 任务 PHP 脚本路径，类型为字符串；相对路径以项目根目录为基准，也支持绝对路径。
     * 自定义脚本可访问 $scope、$services、$config，通过 RequestContext 取得任务请求。
     * 返回值必须兼容 Response::toArray()，其中 status 为状态码，body 为响应正文；
     * CLI 将 status >= 300 视为失败，成功时将 body 写入标准输出。
     * 示例：'entry' => 'bootstrap/jobs.php'（需实现同样的任务分发和返回约定）。
     */
    'entry' => 'framework/bootstrap/jobs.php',
];
