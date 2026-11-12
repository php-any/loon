<?php

declare(strict_types=1);

namespace Loom\Mail;

/**
 * ApplicationBuilder::mail 上的链式配置入口。
 */
final class MailBuilder
{
    public function __construct(private readonly MailRegistration $registration)
    {
    }

    /**
     * 在 build() 前登记自定义邮件传输。
     *
     * @param callable(MailOptions): callable(string $to, string $subject, string $body): bool $factory
     */
    public function extend(string $transport, callable $factory): self
    {
        $this->registration->extend($transport, $factory);
        return $this;
    }

    public function registration(): MailRegistration
    {
        return $this->registration;
    }
}
