<?php

declare(strict_types=1);

/**
 * 框架级邮件传输。默认 smtp 传输读取 LOOM_SMTP_* 环境变量（由运行时 sendMail 实现）。
 */
return [
    'default' => env('LOOM_MAIL_TRANSPORT', 'smtp'),
    'transports' => [
        'smtp' => [
            'driver' => 'smtp',
        ],
    ],
];
