<?php

declare(strict_types=1);

namespace Loom\Monitoring;

/**
 * 跨 HTTP、队列、TCP、gRPC 与子进程 _job 的关联标识：命名约定与校验。
 */
final class Correlation
{
    /** 入站 HTTP 请求头（小写头名，与 Request::header 一致）。 */
    public const HEADER = 'x-loom-correlation-id';

    /** 写入队列任务 payload 与出站 TCP 单行 JSON 的字段名。 */
    public const PAYLOAD_KEY = '_loom_correlation_id';

    private const MAX_LENGTH = 128;

    /**
     * 规范化入站 id；不合法时返回 null（由调用方生成新 id）。
     */
    public static function accepts(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
            return null;
        }
        return $value;
    }

    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function resolve(?string $incoming): string
    {
        return self::accepts($incoming) ?? self::generate();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function stampPayload(array $payload, ?string $id): array
    {
        $accepted = self::accepts($id);
        if ($accepted === null) {
            return $payload;
        }
        if (!isset($payload[self::PAYLOAD_KEY]) || self::accepts((string)$payload[self::PAYLOAD_KEY]) === null) {
            $payload[self::PAYLOAD_KEY] = $accepted;
        }
        return $payload;
    }

    /**
     * @param array<string, mixed> $job Redis/MySQL 认领后的任务信封或 _job 输入。
     */
    public static function fromJobEnvelope(array $job): ?string
    {
        if (isset($job['correlation_id'])) {
            $top = self::accepts((string)$job['correlation_id']);
            if ($top !== null) {
                return $top;
            }
        }
        $payload = $job['payload'] ?? null;
        if (is_array($payload) && isset($payload[self::PAYLOAD_KEY])) {
            return self::accepts((string)$payload[self::PAYLOAD_KEY]);
        }
        return null;
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public static function enrichJobEnvelope(array $job): array
    {
        $fromPayload = null;
        if (is_array($job['payload'] ?? null)) {
            $fromPayload = self::fromJobEnvelope($job);
        }
        if ($fromPayload !== null) {
            $job['correlation_id'] = $fromPayload;
        }
        return $job;
    }

    /**
     * @param array<string, mixed> $message 入站 TCP JSON 对象。
     */
    public static function fromTcpMessage(array $message): ?string
    {
        foreach ([self::PAYLOAD_KEY, 'correlation_id'] as $key) {
            if (isset($message[$key])) {
                $accepted = self::accepts((string)$message[$key]);
                if ($accepted !== null) {
                    return $accepted;
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $request gRPC 解码后的请求体。
     */
    public static function fromGrpcRequest(array $request): ?string
    {
        return self::fromTcpMessage($request);
    }
}
