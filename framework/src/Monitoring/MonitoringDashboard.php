<?php

declare(strict_types=1);

namespace Loom\Monitoring;

use Loom\Config;
use Loom\Database;
use Loom\Request;
use Loom\Response;
use Loom\Router;
use Loom\ServiceResolver;
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
        $router->route('GET', $prefix . '/cache', [self::class, 'cacheList']);
        $router->route('GET', $prefix . '/schedule', [self::class, 'scheduleList']);
        $router->route('GET', $prefix . '/tcp', [self::class, 'tcpList']);
        $router->route('GET', $prefix . '/grpc', [self::class, 'grpcList']);
        $router->route('GET', $prefix . '/events', [self::class, 'eventList']);
        $router->route('GET', $prefix . '/filesystem', [self::class, 'filesystemList']);
        $router->route('GET', $prefix . '/sessions/{id}', [self::class, 'sessionDetail']);
        $router->route('GET', $prefix . '/correlation/{correlationId}', [self::class, 'correlation']);
        $router->route('GET', $prefix . '/api/overview', [self::class, 'apiOverview']);
        $router->route('GET', $prefix . '/api/entries', [self::class, 'apiEntries']);
        $router->route('GET', $prefix . '/api/sessions/{id}', [self::class, 'apiSession']);
        $router->route('GET', $prefix . '/api/correlation/{correlationId}', [self::class, 'apiCorrelation']);
    }

    public function overview(
        Request $request,
        ServiceResolver $resolver,
        MonitoringRegistration $registration,
        MonitoringRepository $repository,
        Database $database,
        Config $config,
        MonitoringOptions $options,
    ): Response {
        $gate = $this->gate($request, $resolver, $registration, $config, $options, false);
        if ($gate !== null) {
            return $gate;
        }
        $filters = $this->activeFilters($request);
        $page = $this->page($request);
        $perPage = 40;
        $sessions = $repository->recentSessions($database, $page, $perPage, $filters);
        $total = $repository->countSessions($database, $filters);
        $counts = $repository->entryTypeCounts($database);
        $html = View::render($config->path('framework/resources/views/monitoring/overview.php'), [
            'sessions' => $sessions,
            'base' => $this->basePath($options),
            'filters' => $this->filters($request),
            'counts' => $counts,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
        ]);
        return Response::html($this->layout($config, $options, '概览', $html));
    }

    public function httpList(Request $request, ServiceResolver $resolver, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $resolver, $registration, $repository, $database, $config, $options, 'http.', 'HTTP', $this->basePath($options) . '/http');
    }

    public function consoleList(Request $request, ServiceResolver $resolver, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $resolver, $registration, $repository, $database, $config, $options, 'console.result', 'Console', $this->basePath($options) . '/console');
    }

    public function sqlList(Request $request, ServiceResolver $resolver, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $resolver, $registration, $repository, $database, $config, $options, 'database.sql', 'SQL', $this->basePath($options) . '/sql');
    }

    public function queueList(Request $request, ServiceResolver $resolver, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $resolver, $registration, $repository, $database, $config, $options, 'queue.', 'Queue / Job', $this->basePath($options) . '/queue');
    }

    public function cacheList(Request $request, ServiceResolver $resolver, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $resolver, $registration, $repository, $database, $config, $options, 'cache.', 'Cache', $this->basePath($options) . '/cache');
    }

    public function scheduleList(Request $request, ServiceResolver $resolver, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $resolver, $registration, $repository, $database, $config, $options, 'schedule.', 'Schedule / Timer', $this->basePath($options) . '/schedule');
    }

    public function tcpList(Request $request, ServiceResolver $resolver, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $resolver, $registration, $repository, $database, $config, $options, 'tcp.', 'TCP', $this->basePath($options) . '/tcp');
    }

    public function grpcList(Request $request, ServiceResolver $resolver, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $resolver, $registration, $repository, $database, $config, $options, 'grpc.', 'gRPC', $this->basePath($options) . '/grpc');
    }

    public function eventList(Request $request, ServiceResolver $resolver, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $resolver, $registration, $repository, $database, $config, $options, 'app.event', 'Events', $this->basePath($options) . '/events');
    }

    public function filesystemList(Request $request, ServiceResolver $resolver, MonitoringRegistration $registration, MonitoringRepository $repository, Database $database, Config $config, MonitoringOptions $options): Response
    {
        return $this->entryPage($request, $resolver, $registration, $repository, $database, $config, $options, 'filesystem.', 'Filesystem', $this->basePath($options) . '/filesystem');
    }

    public function sessionDetail(
        Request $request,
        ServiceResolver $resolver,
        MonitoringRegistration $registration,
        MonitoringRepository $repository,
        Database $database,
        Config $config,
        MonitoringOptions $options,
    ): Response {
        $gate = $this->gate($request, $resolver, $registration, $config, $options, false);
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
        ServiceResolver $resolver,
        MonitoringRegistration $registration,
        MonitoringRepository $repository,
        Database $database,
        Config $config,
        MonitoringOptions $options,
    ): Response {
        $gate = $this->gate($request, $resolver, $registration, $config, $options, false);
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

    public function apiOverview(
        Request $request,
        ServiceResolver $resolver,
        MonitoringRegistration $registration,
        MonitoringRepository $repository,
        Database $database,
        Config $config,
        MonitoringOptions $options,
    ): Response {
        $gate = $this->gate($request, $resolver, $registration, $config, $options, true);
        if ($gate !== null) {
            return $gate;
        }
        $filters = $this->activeFilters($request);
        $page = $this->page($request);
        $perPage = $this->perPage($request, 40);
        return Response::json([
            'sessions' => $repository->recentSessions($database, $page, $perPage, $filters),
            'counts' => $repository->entryTypeCounts($database),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $repository->countSessions($database, $filters),
            'filters' => $filters,
        ])->withHeader('Cache-Control', 'no-store');
    }

    public function apiEntries(
        Request $request,
        ServiceResolver $resolver,
        MonitoringRegistration $registration,
        MonitoringRepository $repository,
        Database $database,
        Config $config,
        MonitoringOptions $options,
    ): Response {
        $gate = $this->gate($request, $resolver, $registration, $config, $options, true);
        if ($gate !== null) {
            return $gate;
        }
        $type = strtolower(substr($request->query('type')[0] ?? 'http', 0, 32));
        $prefix = MonitoringRepository::ENTRY_PREFIXES[$type] ?? null;
        if ($prefix === null) {
            return Response::json(['error' => '未知类型'], 422);
        }
        $filters = $this->activeFilters($request);
        $page = $this->page($request);
        $perPage = $this->perPage($request, 40);
        return Response::json([
            'type' => $type,
            'items' => $repository->listEntries($database, $prefix, $page, $perPage, $filters),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $repository->countEntries($database, $prefix, $filters),
            'filters' => $filters,
        ])->withHeader('Cache-Control', 'no-store');
    }

    public function apiSession(
        Request $request,
        ServiceResolver $resolver,
        MonitoringRegistration $registration,
        MonitoringRepository $repository,
        Database $database,
        Config $config,
        MonitoringOptions $options,
    ): Response {
        $gate = $this->gate($request, $resolver, $registration, $config, $options, true);
        if ($gate !== null) {
            return $gate;
        }
        $id = (int)$request->parameter('id');
        $session = $repository->session($database, $id);
        if ($session === null) {
            return Response::json(['error' => 'Not found'], 404);
        }
        return Response::json($session)->withHeader('Cache-Control', 'no-store');
    }

    public function apiCorrelation(
        Request $request,
        ServiceResolver $resolver,
        MonitoringRegistration $registration,
        MonitoringRepository $repository,
        Database $database,
        Config $config,
        MonitoringOptions $options,
    ): Response {
        $gate = $this->gate($request, $resolver, $registration, $config, $options, true);
        if ($gate !== null) {
            return $gate;
        }
        $correlationId = (string)$request->parameter('correlationId');
        return Response::json([
            'correlation_id' => $correlationId,
            'sessions' => $repository->sessionsByCorrelation($database, $correlationId),
        ])->withHeader('Cache-Control', 'no-store');
    }

    private function entryPage(
        Request $request,
        ServiceResolver $resolver,
        MonitoringRegistration $registration,
        MonitoringRepository $repository,
        Database $database,
        Config $config,
        MonitoringOptions $options,
        string $prefix,
        string $title,
        string $listPath,
    ): Response {
        $gate = $this->gate($request, $resolver, $registration, $config, $options, false);
        if ($gate !== null) {
            return $gate;
        }
        $filters = $this->activeFilters($request);
        $page = $this->page($request);
        $perPage = 40;
        $rows = $repository->listEntries($database, $prefix, $page, $perPage, $filters);
        $total = $repository->countEntries($database, $prefix, $filters);
        $html = View::render($config->path('framework/resources/views/monitoring/entries.php'), [
            'title' => $title,
            'rows' => $rows,
            'base' => $this->basePath($options),
            'filters' => $this->filters($request),
            'list_path' => $listPath,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
        ]);
        return Response::html($this->layout($config, $options, $title, $html));
    }

    private function gate(
        Request $request,
        ServiceResolver $resolver,
        MonitoringRegistration $registration,
        Config $config,
        MonitoringOptions $options,
        bool $json,
    ): ?Response {
        if (!$options->dashboard->enabled) {
            return $json ? Response::json(['error' => 'Not found'], 404) : Response::html('Not found', 404);
        }
        if (!$options->writesDatabase()) {
            if ($json) {
                return Response::json(['error' => '需要 monitoring driver=database'], 503);
            }
            $html = View::render($config->path('framework/resources/views/monitoring/needs_database.php'), []);
            return Response::html($this->layout($config, $options, '需要 database 驱动', $html), 503);
        }
        if (!$registration->dashboardAuthorized($resolver)) {
            return $json ? Response::json(['error' => 'Forbidden'], 403) : Response::html('Forbidden', 403);
        }
        return null;
    }

    private function basePath(MonitoringOptions $options): string
    {
        return '/' . trim($options->dashboard->path !== '' ? $options->dashboard->path : 'monitoring', '/');
    }

    private function page(Request $request): int
    {
        $raw = $request->query('page')[0] ?? '1';
        return max(1, (int)$raw);
    }

    private function perPage(Request $request, int $default): int
    {
        $raw = $request->query('per_page')[0] ?? (string)$default;
        return max(1, min(100, (int)$raw));
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
