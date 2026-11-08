<?php

declare(strict_types=1);

namespace Loom;

/**
 * 按 kind 找到模块发现的 Job 处理方法，在给定作用域里执行。执行期间 RequestContext 当前请求换成任务请求，结束后恢复。
 *
 * @example $response = $jobs->dispatch('digest', $request, $scope);
 */
final class JobDispatcher
{
    public function __construct(private readonly ModuleDiscovery $modules)
    {
    }

    /**
     * @return list<string> 已发现的任务 kind。
     */
    public function kinds(): array
    {
        return array_keys($this->modules->jobs());
    }

    /**
     * 未知 kind 抛出 LogicException。处理方法的异常原样抛出。
     *
     * @param string $kind 任务种类。
     * @param Request $request 正文是任务信封 JSON 的请求。
     * @param ServiceResolver $services 这次任务的作用域。
     * @return Response 处理方法返回的响应。
     */
    public function dispatch(string $kind, Request $request, ServiceResolver $services): Response
    {
        $jobs = $this->modules->jobs();
        if (!isset($jobs[$kind])) {
            throw new \LogicException('Unknown job kind: ' . $kind);
        }
        [$class, $method] = $jobs[$kind];
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
