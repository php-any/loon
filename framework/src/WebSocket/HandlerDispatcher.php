<?php

declare(strict_types=1);

namespace Loom\WebSocket;

use Loom\ActionResolver;
use Loom\Attributes\WebSocketMessage;
use Loom\Invoker;
use Loom\Request;
use Loom\ServiceResolver;

/**
 * 升级后分发 WebSocket 处理器：传统阻塞处理器或带消息循环的注解分发器。
 */
#[\Container\Annotation\Scoped]
final class HandlerDispatcher
{
    private ?\Loom\Monitoring\Monitoring $monitoring;

    public function __construct(?\Loom\Monitoring\Monitoring $monitoring = null)
    {
        $this->monitoring = $monitoring;
    }

    public function dispatch(callable $action, Request $request, \Net\Websocket\Conn $connection, ServiceResolver $services): void
    {
        $resolver = new ActionResolver($services);
        $callable = $resolver->resolve($action);
        $class = null;
        $entry = '';
        if (is_array($action) && is_string($action[0])) {
            $class = new \ReflectionClass($action[0]);
            $entry = $action[1];
        } elseif (is_array($callable) && is_object($callable[0])) {
            $class = new \ReflectionClass(get_class($callable[0]));
            $entry = $callable[1];
        }
        $handlers = $class !== null ? $this->messageHandlers($class) : [];
        if ($handlers === []) {
            $callable($request, $connection);
            return;
        }
        $instance = is_array($callable) ? $callable[0] : null;
        $this->monitoring?->recordEvent('websocket.connect', ['handler' => $class?->getName() ?? 'callable']);
        try {
            if ($instance !== null && $class->hasMethod('onOpen')) {
                Invoker::call([$instance, 'onOpen'], $services, [$request, $connection]);
            }
            $poll = $instance !== null && $class->hasMethod('poll') && $class->getMethod('poll')->isPublic();
            while (true) {
                if ($poll) {
                    Invoker::call([$instance, 'poll'], $services, [$connection]);
                }
                try {
                    $connection->configure(['readTimeoutMs' => 1000]);
                    $raw = $connection->readText();
                } catch (\Throwable $closed) {
                    break;
                }
                $this->monitoring?->recordEvent('websocket.message', ['handler' => $class->getName()]);
                $message = json_decode($raw, true);
                if (!is_array($message)) {
                    $connection->writeText(json_encode(['type' => 'error', 'message' => '无效的 JSON 消息'], JSON_THROW_ON_ERROR));
                    continue;
                }
                $type = (string)($message['type'] ?? '');
                $method = $handlers[$type] ?? null;
                if ($method === null || $instance === null) {
                    $connection->writeText(json_encode(['type' => 'error', 'message' => '未知消息类型'], JSON_THROW_ON_ERROR));
                    continue;
                }
                Invoker::call([$instance, $method], $services, [$message, $connection]);
            }
        } finally {
            $this->monitoring?->recordEvent('websocket.close', ['handler' => $class?->getName() ?? 'callable']);
            if ($instance !== null && $class->hasMethod('onClose')) {
                try {
                    Invoker::call([$instance, 'onClose'], $services, [$connection]);
                } catch (\Throwable $ignored) {
                }
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function messageHandlers(\ReflectionClass $class): array
    {
        $map = [];
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }
            foreach ($method->getAttributes(WebSocketMessage::class) as $attribute) {
                $type = $attribute->newInstance()->type;
                if ($type === '' || isset($map[$type])) {
                    throw new \LogicException('Invalid or duplicate WebSocketMessage on ' . $class->getName() . '::' . $method->getName());
                }
                $map[$type] = $method->getName();
            }
        }
        return $map;
    }
}
