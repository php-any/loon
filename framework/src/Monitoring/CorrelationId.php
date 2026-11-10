<?php

declare(strict_types=1);

namespace Loom\Monitoring;

/**
 * 当前 ServiceScope 内的关联 id；在宿主 begin 前 establish，应用代码通过构造注入读取。
 */
final class CorrelationId
{
    private ?string $value = null;

    /**
     * 采用入站 id，或在没有合法入站值时生成。已 establish 后忽略后续入参。
     */
    public function establish(?string $incoming = null): string
    {
        if ($this->value !== null) {
            return $this->value;
        }
        $this->value = Correlation::resolve($incoming);
        return $this->value;
    }

    /**
     * 子进程 _job 等场景：在已有自动生成 id 的作用域里强制采用信封中的父级 id。
     */
    public function adopt(?string $incoming): string
    {
        $accepted = Correlation::accepts($incoming);
        if ($accepted !== null) {
            $this->value = $accepted;
            return $accepted;
        }
        return $this->establish(null);
    }

    public function current(): string
    {
        if ($this->value === null) {
            throw new \LogicException('Correlation id is not established in this scope');
        }
        return $this->value;
    }

    public function tryCurrent(): ?string
    {
        return $this->value;
    }
}
