<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$options = (new Loom\Options(new Loom\Config(dirname(__DIR__, 2))))->bindOptional(Loom\Mail\MailOptions::class, 'mail');
if (!($options instanceof Loom\Mail\MailOptions) || $options->default !== 'smtp') {
    throw new RuntimeException('config/mail.php must bind MailOptions with smtp default');
}

$registration = new Loom\Mail\MailRegistration();
$registration->extend('memory', static function (): callable {
    $sent = [];
    return static function (string $to, string $subject, string $body) use (&$sent): bool {
        $sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
        return true;
    };
});
$mailer = new Loom\Mail\Mailer($options, $registration);
if (!$mailer->send('memory', 'user@example.test', 'Hello', 'Body')) {
    throw new RuntimeException('Extended mail transport must send');
}

try {
    $mailer->send('unknown', 'user@example.test', 'Hello', 'Body');
    throw new RuntimeException('Unknown mail transport must be rejected');
} catch (LogicException) {
    // expected
}

echo "loom_mail_test: OK\n";
