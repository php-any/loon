<?php

declare(strict_types=1);

namespace Loom\Monitoring;

use Loom\Config;
use Loom\Database;
use Loom\Request;
use Loom\Response;
use Loom\Router;
use Loom\View;

/** 在 ApplicationBuilder::build() 中登记监控仪表盘路由。 */
final class MonitoringDashboard
{
    public static function register(Router $router, string $prefix, MonitoringOptions $options): void
    {
        if (!$options->dashboard->enabled) {
            return;
        }
        $prefix = '/' . trim($prefix !== '' ? $prefix : 'monitoring', '/');
        $router->route('GET', $prefix, [self::class, 'overview']);
        $router->route('GET', $prefix . '/http', [self::class, 'httpList']);
        $router->route('GET', $prefix . '/console', [self::class, 'consoleList']);
        $router->route('GET', $prefix . '/sql', [self::class, 'sqlList']);
        $router->route('GET', $prefix . '/queue', [self::class, 'queueList']);
        $router->route('GET', $prefix . '/schedule', [self::class, 'scheduleList']);
        $router->route('GET', $prefix . '/tcp', [self::class, 'tcpList']);
        $router->route('GET', $prefix . '/grpc', [self::class, 'grpcList']);
        $router->route('GET', $prefix . '/events', [self::class, 'eventList']);
        $router->route('GET', $prefix . '/filesystem', [self::class, 'filesystemList']);
        $router->route('GET', $prefix . '/sessions/{id}', [self::class, 'sessionDetail']);
        $router->route('GET', $prefix . '/correlation/{correlationId}', [self::class, 'correlation']);
    }

    public function overview(
        Request $request,
        MonitoringRegistration $registration,
        MonitoringRepository $repository,
        Database $database,
        Config $config,
        MonitoringOptions $options,
    ): Response {
        $gate = $this->gate($request, $registration, $config, $options);
        if ($gate !== null) {
            return $gate;
        }
        $filters = $this->activeFilters($request);
        $sessions = $repository->recentSessions($database, 40, $filters);
        $html = View::render($config->path('framework/resources/views/monitoring/overview.php'), [
            'sessions' => $sessions,
            'base' => $this->basePath($options),
            'filters' => $filters,
        ]);
        return Response::html($this->layout($config, $options, '概览', $html));
    }

    public function httpList(Request $request, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $registration, $repository, $database, $config, $options, 'http.', 'HTTP');
    }

    public function consoleList(Request $request, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $registration, $repository, $database, $config, $options, 'console.result', 'Console');
    }

    public function sqlList(Request $request, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $registration, $repository, $database, $config, $options, 'database.sql', 'SQL');
    }

    public function queueList(Request $request, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $registration, $repository, $database, $config, $options, 'queue.', 'Queue / Job');
    }

    public function scheduleList(Request $request, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $registration, $repository, $database, $config, $options, 'schedule.', 'Schedule / Timer');
    }

    public function tcpList(Request $request, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $registration, $repository, $database, $config, $options, 'tcp.', 'TCP');
    }

    public function grpcList(Request $request, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $registration, $repository, $database, $config, $options, 'grpc.', 'gRPC');
    }

    public function eventList(Request $request, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $registration, $repository, $database, $config, $options, '', 'Events');
    }

    public function filesystemList(Request $request, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $registration, $repository, $database, $config, $options, 'filesystem.', 'Filesystem');
    }

    public function sessionDetail(
        Request $request,
        MonitoringRegistration $registration,
        MonitoringRepository $repository,
        Database $database,
        Config $config,
        MonitoringOptions $options,
    ): Response {
        $gate = $this->gate($request, $registration, $config, $options);
        if ($gate !== null) {
            return $gate;
        }
        $id = (int)$request->parameter('id');
        $session = $repository->session($database, $id);
        if ($session === null) {
            return Response::html('Not found', 404);
        }
        $html = View::render($config->path('framework/resources/views/monitoring/session.php'), [
            'session' => $session,
            'base' => $this->basePath($options),
        ]);
        return Response::html($this->layout($config, $options, '会话 #' . $id, $html));
    }

    public function correlation(
        Request $request,
        MonitoringRegistration $registration,
        MonitoringRepository $repository,
        Database $database,
        Config $config,
        MonitoringOptions $options,
    ): Response {
        $gate = $this->gate($request, $registration, $config, $options);
        if ($gate !== null) {
            return $gate;
        }
        $correlationId = (string)$request->parameter('correlationId');
        $sessions = $repository->sessionsByCorrelation($database, $correlationId);
        $html = View::render($config->path('framework/resources/views/monitoring/correlation.php'), [
            'correlation_id' => $correlationId,
            'sessions' => $sessions,
            'base' => $this->basePath($options),
        ]);
        return Response::html($this->layout($config, $options, '关联 ' . $correlationId, $html));
    }

    private function entryPage(
        Request $request,
        MonitoringRegistration $registration,
        MonitoringRepository $repository,
        Database $database,
        Config $config,
        MonitoringOptions $options,
        string $prefix,
        string $title,
    ): Response {
        $gate = $this->gate($request, $registration, $config, $options);
        if ($gate !== null) {
            return $gate;
        }
        $filters = $this->activeFilters($request);
        $rows = $repository->listEntries($database, $prefix, 120, $filters);
        $html = View::render($config->path('framework/resources/views/monitoring/entries.php'), [
            'title' => $title,
            'rows' => $rows,
            'base' => $this->basePath($options),
            'filters' => $filters,
        ]);
        return Response::html($this->layout($config, $options, $title, $html));
    }

    private function gate(Request $request, MonitoringRegistration $registration, Config $config, MonitoringOptions $options): ?Response
    {
        if (!$options->dashboard->enabled) {
            return Response::html('Not found', 404);
        }
        if (!$options->writesDatabase()) {
            $html = View::render($config->path('framework/resources/views/monitoring/needs_database.php'), []);
            return Response::html($this->layout($config, $options, '需要 database 驱动', $html), 503);
        }
        if (!$registration->dashboardAuthorized()) {
            return Response::html('Forbidden', 403);
        }
        return null;
    }

    private function basePath(MonitoringOptions $options): string
    {
        return '/' . trim($options->dashboard->path !== '' ? $options->dashboard->path : 'monitoring', '/');
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        return [
            'tag' => substr($request->query('tag')[0] ?? '', 0, 64),
            'correlation_id' => substr($request->query('correlation_id')[0] ?? '', 0, 128),
            'path' => substr($request->query('path')[0] ?? '', 0, 256),
            'status' => substr($request->query('status')[0] ?? '', 0, 8),
        ];
    }

    /**
     * @return array{tag?: string, correlation_id?: string, path?: string, status?: string}
     */
    private function activeFilters(Request $request): array
    {
        $filters = $this->filters($request);
        $active = [];
        foreach ($filters as $key => $value) {
            if ($value !== '') {
                $active[$key] = $value;
            }
        }
        return $active;
    }

    private function layout(Config $config, MonitoringOptions $options, string $title, string $content): string
    {
        return View::render($config->path('framework/resources/views/monitoring/layout.php'), [
            'title' => $title,
            'content' => $content,
            'base' => $this->basePath($options),
        ]);
    }
}
