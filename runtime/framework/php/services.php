<?php
declare(strict_types=1);
namespace Loom;

// Explicit native capabilities. Storage and workers are configured by the host.
final class Host
{
    public static function call(array $command): mixed
    {
        $result = json_decode(host_call(json_encode($command, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
        if (isset($result['status'])) { throw new HttpError($result['status'], $result['message']); }
        return $result['value'];
    }
}
final class Database
{
    public static function query(string $sql, array $args = []): array { return Host::call(['op' => 'db.query', 'sql' => $sql, 'args' => $args]); }
    public static function execute(string $sql, array $args = []): array { return Host::call(['op' => 'db.execute', 'sql' => $sql, 'args' => $args]); }
    // Statements commit together or roll back together; parameters are always bound.
    public static function batch(array $statements): void { Host::call(['op' => 'db.batch', 'statements' => $statements]); }
}
final class Auth
{
    public static function user(): array { return Host::call(['op' => 'auth.current']); }
    public static function guard(): array { return Host::call(['op' => 'auth.guard']); }
    public static function login(string $email, string $password): array { return Host::call(['op' => 'auth.login', 'email' => $email, 'password' => $password]); }
    public static function register(string $name, string $email, string $password): array { return Host::call(['op' => 'auth.register', 'name' => $name, 'email' => $email, 'password' => $password]); }
    public static function logout(): array { return Host::call(['op' => 'auth.logout']); }
}
final class Queue
{
    public static function dispatch(string $kind, array $payload, int $owner, int $delay = 0): int { return Host::call(['op' => 'queue.dispatch', 'kind' => $kind, 'payload' => $payload, 'owner' => $owner, 'delay' => $delay]); }
}
final class Schedule
{
    public static function cron(string $name, string $expression, string $timezone, string $kind, array $payload, int $owner): void { Host::call(['op' => 'schedule.cron', 'name' => $name, 'expression' => $expression, 'timezone' => $timezone, 'kind' => $kind, 'payload' => $payload, 'owner' => $owner]); }
    public static function preview(string $expression, string $timezone): array { return Host::call(['op' => 'cron.preview', 'expression' => $expression, 'timezone' => $timezone]); }
    public static function every(string $name, int $seconds, string $kind, array $payload, int $owner): void { Host::call(['op' => 'schedule.every', 'name' => $name, 'interval' => $seconds, 'kind' => $kind, 'payload' => $payload, 'owner' => $owner]); }
}
