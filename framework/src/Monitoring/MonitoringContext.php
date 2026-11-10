<?php

declare(strict_types=1);

namespace Loom\Monitoring;

/**
 * 描述一次 ServiceScope 工作单元的通道与静态标签，在 begin() 时写入 Monitoring 会话。
 */
final class MonitoringContext
{
    /**
     * @param array<string, scalar|null> $tags 只读元数据，例如 method、path、command、timer、job_kind。
     */
    public function __construct(
        public readonly string $channel,
        public readonly array $tags = [],
        public readonly ?string $incomingCorrelationId = null,
    ) {
    }

    /** 宿主在 begin 之前解析出的入站关联 id（可能为 null，表示由作用域生成）。 */
    public function incomingCorrelationId(): ?string
    {
        return $this->incomingCorrelationId;
    }

    public function withIncomingCorrelation(?string $id): self
    {
        return new self($this->channel, $this->tags, $id);
    }

    public function withCorrelationId(string $id): self
    {
        return new self($this->channel, array_merge($this->tags, ['correlation_id' => $id]), $this->incomingCorrelationId);
    }

    public static function http(string $method, string $path): self
    {
        return new self('http', ['method' => $method, 'path' => $path]);
    }

    public static function console(string $command): self
    {
        return new self('console', ['command' => $command]);
    }

    public static function timer(string $class): self
    {
        return new self('timer', ['timer' => $class]);
    }

    public static function queue(): self
    {
        return new self('queue', []);
    }

    public static function schedule(): self
    {
        return new self('schedule', []);
    }

    public static function job(string $kind, int $id): self
    {
        return new self('job', ['job_kind' => $kind, 'job_id' => $id]);
    }

    public static function tcp(): self
    {
        return new self('tcp', []);
    }

    public static function grpc(string $method): self
    {
        return new self('grpc', ['grpc_method' => $method]);
    }

    public function withTags(array $tags): self
    {
        return new self($this->channel, array_merge($this->tags, $tags), $this->incomingCorrelationId);
    }
}
