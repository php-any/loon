<?php

declare(strict_types=1);

namespace Loom\Mail;

/**
 * 邮件传输选项，对应 config/mail.php。
 */
final class MailOptions
{
    public string $default = 'smtp';

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $transports = [
        'smtp' => ['driver' => 'smtp'],
    ];
}
