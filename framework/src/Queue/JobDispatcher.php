<?php

declare(strict_types=1);

namespace Loom;

/**
 * 按 kind 找到 Job 处理方法执行；ApplicationBuilder 登记的内置 kind 优先于模块发现。
 */
final class JobDispatcher
{
  /** @var array<string, array{0: string, 1: string}> */
    private array $jobs;

  /**
   * @param array<string, array{0: string, 1: string}> $builtin
   */
    public function __construct(ModuleDiscovery $modules, array $builtin = [])
    {
        $this->jobs = array_merge($modules->jobs(), $builtin);
    }

  /**
   * @return list<string>
   */
    public function kinds(): array
    {
        return array_keys($this->jobs);
    }

    public function dispatch(string $kind, Request $request, ServiceResolver $services): Response
    {
        if (!isset($this->jobs[$kind])) {
            throw new \LogicException('Unknown job kind: ' . $kind);
        }
        [$class, $method] = $this->jobs[$kind];
        $context = $services->get(RequestContext::class);
        $previous = $context->replace($request);
        try {
            $response = $services->get($class)->$method($request);
            if (!($response instanceof Response)) {
                throw new \LogicException('Job handler must return Response: ' . $class . '::' . $method);
            }
            return $response;
        } finally {
            $context->bind($previous);
        }
    }
}
