<?php

declare(strict_types=1);

namespace Loom\Mail;

/**
 * 框架级可替换邮件传输。默认 smtp 使用 LOOM_SMTP_* 与运行时 sendMail。
 */
final class Mailer
{
    public function __construct(
        private readonly MailOptions $options,
        private readonly MailRegistration $registration,
    ) {
    }

    public function defaultTransport(): string
    {
        return $this->options->default;
    }

    public function send(?string $transport, string $to, string $subject, string $body): bool
    {
        $transport = $transport ?? '';
        if ($transport === '') {
            $transport = $this->options->default;
        }
        $sender = $this->resolve($transport);
        return $sender($to, $subject, $body);
    }

    /**
     * @return callable(string, string, string): bool
     */
    private function resolve(string $transport): callable
    {
        $factory = $this->registration->factory($transport);
        if ($factory !== null) {
            $callable = $factory($this->options);
            if (!is_callable($callable)) {
                throw new \LogicException('Mail transport factory must return callable');
            }
            return $callable;
        }
        return match ($transport) {
            'smtp' => static function (string $to, string $subject, string $body): bool {
                $result = json_decode(\Loom\Native\sendMail($to, $subject, $body), true);
                return is_array($result) && (bool)($result['ok'] ?? false);
            },
            default => throw new \LogicException('Unsupported mail transport: ' . $transport),
        };
    }
}
