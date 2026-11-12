<?php

declare(strict_types=1);

namespace Loom\Mail;

/**
 * build() 前通过 MailBuilder::extend() 登记的邮件传输工厂。
 *
 * @internal
 */
final class MailRegistration
{
    /** @var array<string, callable(MailOptions): callable(string, string, string): bool> */
    private array $extensions = [];

    /**
     * @param callable(MailOptions): callable(string, string, string): bool $factory
     */
    public function extend(string $transport, callable $factory): void
    {
        $this->extensions[$transport] = $factory;
    }

    /**
     * @return ?callable(MailOptions): callable(string, string, string): bool
     */
    public function factory(string $transport): ?callable
    {
        return $this->extensions[$transport] ?? null;
    }
}
