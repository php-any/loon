<?php
declare(strict_types=1);

namespace Loom;

final class HttpError extends \RuntimeException
{
    public int $status;
    public array $details;

    public function __construct(int $status, string $message, array $details = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->details = $details;
    }
}

final class Request
{
    private array $envelope;
    private array $parameters = [];

    public function __construct(array $envelope) { $this->envelope = $envelope; }
    public static function capture(): self { return new self(json_decode(request_envelope(), true, 512, JSON_THROW_ON_ERROR)); }
    public function method(): string { return $this->envelope['method']; }
    public function path(): string { return $this->envelope['path']; }
    public function body(): string { return $this->envelope['body']; }
    public function header(string $name, string $default = ''): string
    {
        return $this->envelope['headers'][strtolower($name)][0] ?? $default;
    }
    public function query(string $name): array { return $this->envelope['query'][$name] ?? []; }
    public function parameter(string $name): string
    {
        if (!array_key_exists($name, $this->parameters)) { throw new \LogicException('Unknown route parameter: ' . $name); }
        return $this->parameters[$name];
    }
    public function withParameters(array $parameters): self
    {
        $copy = clone $this;
        $copy->parameters = $parameters;
        return $copy;
    }
    public function json(): Input
    {
        $type = strtolower(trim(explode(';', $this->header('content-type'))[0]));
        if ($type !== 'application/json') { throw new HttpError(415, 'Expected application/json'); }
        $body = trim($this->body());
        if ($body === '' || $body[0] !== '{') { throw new HttpError(400, 'Expected a JSON object'); }
        try { $values = json_decode($body, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new HttpError(400, 'Malformed JSON'); }
        return new Input($values);
    }
}

// Explicit input contracts preserve missing/null/type distinctions.
final class Input
{
    private array $values;
    public function __construct(array $values) { $this->values = $values; }
    public function has(string $key): bool { return array_key_exists($key, $this->values); }
    public function only(array $fields): self
    {
        foreach ($this->values as $key => $value) {
            if (!in_array($key, $fields, true)) { $this->invalid((string) $key, 'unknown'); }
        }
        return $this;
    }
    private function required(string $key): mixed
    {
        if (!array_key_exists($key, $this->values)) { $this->invalid($key, 'missing'); }
        if ($this->values[$key] === null) { $this->invalid($key, 'null'); }
        return $this->values[$key];
    }
    private function invalid(string $key, string $reason): void
    {
        throw new HttpError(422, 'Invalid input', ['field' => $key, 'reason' => $reason]);
    }
    public function string(string $key, int $minBytes = 0, int $maxBytes = 4096): string
    {
        if ($minBytes < 0 || $maxBytes < $minBytes) { throw new \LogicException('Invalid string limits'); }
        $value = $this->required($key);
        if (!is_string($value)) { $this->invalid($key, 'type'); }
        if (strlen($value) < $minBytes || strlen($value) > $maxBytes) { $this->invalid($key, 'length'); }
        return $value;
    }
    public function integer(string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        if ($max < $min) { throw new \LogicException('Invalid integer limits'); }
        $value = $this->required($key);
        if (!is_int($value)) { $this->invalid($key, 'type'); }
        if ($value < $min || $value > $max) { $this->invalid($key, 'range'); }
        return $value;
    }
    public function boolean(string $key): bool
    {
        $value = $this->required($key);
        if (!is_bool($value)) { $this->invalid($key, 'type'); }
        return $value;
    }
}

final class Response
{
    private int $status;
    private array $headers;
    private string $body;
    public function __construct(string $body, int $status = 200, array $headers = ['Content-Type' => 'text/plain; charset=utf-8'])
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = [];
        foreach ($headers as $name => $value) { $this->headers[strtolower($name)] = $value; }
    }
    public static function json(mixed $value, int $status = 200): self
    {
        return new self(json_encode($value, JSON_THROW_ON_ERROR), $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }
    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
    public static function problem(int $status, string $title, array $details = []): self
    {
        $body = ['type' => 'about:blank', 'title' => $title, 'status' => $status];
        if ($details !== []) { $body['errors'] = $details; }
        return new self(json_encode($body, JSON_THROW_ON_ERROR), $status, ['Content-Type' => 'application/problem+json; charset=utf-8']);
    }
    public function withHeader(string $name, string $value): self
    {
        $copy = clone $this;
        $copy->headers[strtolower($name)] = $value;
        return $copy;
    }
    public function toArray(): array { return ['status' => $this->status, 'headers' => (object) $this->headers, 'body' => $this->body]; }
}

// A container belongs to one application execution. Factories are explicit;
// resolving cycles and rebinding a constructed service are errors.
final class Container
{
    private array $factories = [];
    private array $instances = [];
    private array $resolving = [];
    public function bind(string $id, callable $factory): void
    {
        if (array_key_exists($id, $this->factories) || array_key_exists($id, $this->instances)) {
            throw new \LogicException('Duplicate service: ' . $id);
        }
        $this->factories[$id] = $factory;
    }
    public function get(string $id): object
    {
        if (array_key_exists($id, $this->instances)) { return $this->instances[$id]; }
        if (isset($this->resolving[$id])) { throw new \LogicException('Dependency cycle: ' . $id); }
        $this->resolving[$id] = true;
        try {
            if (array_key_exists($id, $this->factories)) {
                $factory = $this->factories[$id];
                $instance = $factory($this);
            } else { $instance = new $id(); }
            if (!is_object($instance)) { throw new \LogicException('Service factory must return an object: ' . $id); }
            $this->instances[$id] = $instance;
            return $instance;
        } finally { unset($this->resolving[$id]); }
    }
}

interface Action
{
    public function __invoke(Request $request): Response;
}

// Templates are application-owned PHP files. Values remain in $data so input
// cannot overwrite local variables; escape text explicitly with Loom\escape.
final class View
{
    public static function render(string $template, array $data = []): string
    {
        if (!is_file($template)) { throw new \LogicException('Template does not exist: ' . $template); }
        $level = ob_get_level();
        ob_start();
        try {
            $render = static function (string $template, array $data): void { require $template; };
            $render($template, $data);
            if (ob_get_level() !== $level + 1) { throw new \LogicException('Template changed output buffer ownership'); }
            return ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                if (!ob_end_clean()) { break; }
            }
        }
    }
}

// The transport and connection methods belong to Origami's standard library.
final class WebSocket
{
    public static function accept(): \Net\Websocket\Conn
    {
        $request = Request::capture();
        if ($request->method() !== 'GET' || strtolower($request->header('upgrade')) !== 'websocket'
            || !preg_match('/(^|,)\s*upgrade\s*(,|$)/i', $request->header('connection'))) {
            throw new HttpError(426, 'WebSocket upgrade required');
        }
        $connection = \Net\Websocket\upgrade(websocket_request(), websocket_response(), true);
        websocket_configure($connection);
        return $connection;
    }
}

interface WebSocketAction
{
    public function __invoke(Request $request, \Net\Websocket\Conn $connection): void;
}

final class Application
{
    private Container $container;
    private array $routes = [];
    private string $prefix = '';
    private array $named = [];
    public function __construct() { $this->container = new Container(); }
    public function services(): Container { return $this->container; }
    private function validateAction(mixed $action): void
    {
        if (is_array($action)) {
            if (count($action) !== 2 || !array_key_exists(0, $action) || !array_key_exists(1, $action)
                || (!is_string($action[0]) && !is_object($action[0]))
                || !is_string($action[1]) || $action[1] === '') {
                throw new \LogicException('Expected [controller, method]');
            }
            return;
        }
        if (!is_string($action) && !is_callable($action)) { throw new \LogicException('Invalid route handler'); }
    }
    private function resolveAction(mixed $action): callable
    {
        if (is_array($action)) {
            $controller = $action[0];
            if (is_string($controller)) { $controller = $this->container->get($controller); }
            $action = [$controller, $action[1]];
        } elseif (is_string($action)) {
            $action = $this->container->get($action);
        }
        if (!is_callable($action)) { throw new \LogicException('Route must resolve to a callable public controller method'); }
        return $action;
    }
    public function websocket(string $path, mixed $action, string $name = ''): self
    {
        $this->validateAction($action);
        return $this->get($path, function (Request $request) use ($action): Response {
            $action = $this->resolveAction($action);
            $connection = WebSocket::accept();
            try { $action($request, $connection); }
            finally { $connection->close(); }
            return new Response('', 204);
        }, $name);
    }
    public function get(string $path, mixed $action, string $name = ''): self { return $this->route('GET', $path, $action, $name); }
    public function post(string $path, mixed $action, string $name = ''): self { return $this->route('POST', $path, $action, $name); }
    public function patch(string $path, mixed $action, string $name = ''): self { return $this->route('PATCH', $path, $action, $name); }
    public function delete(string $path, mixed $action, string $name = ''): self { return $this->route('DELETE', $path, $action, $name); }
    public function group(string $prefix, callable $register): self
    {
        $old = $this->prefix;
        $this->prefix .= rtrim($prefix, '/');
        try { $register($this); } finally { $this->prefix = $old; }
        return $this;
    }
    public function url(string $name, array $parameters = []): string
    {
        if (!isset($this->named[$name])) { throw new \LogicException('Unknown route name: ' . $name); }
        $parts = explode('/', $this->named[$name]);
        foreach ($parts as $index => $part) {
            if ($part !== '' && $part[0] === '{') {
                $key = substr($part, 1, -1);
                if (!isset($parameters[$key]) || (string) $parameters[$key] === '' || strpos((string) $parameters[$key], '/') !== false) { throw new \LogicException('Invalid URL parameter: ' . $key); }
                $parts[$index] = rawurlencode((string) $parameters[$key]);
                unset($parameters[$key]);
            }
        }
        if ($parameters !== []) { throw new \LogicException('Unknown URL parameters'); }
        return implode('/', $parts);
    }
    public function route(string $method, string $path, mixed $action, string $name = ''): self
    {
        $path = $this->prefix . $path;
        if ($name !== '' && isset($this->named[$name])) { throw new \LogicException('Duplicate route name: ' . $name); }
        $this->validateAction($action);
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
            throw new \LogicException('Unsupported HTTP method');
        }
        if ($path === '' || $path[0] !== '/' || strpos($path, '?') !== false || strpos($path, '#') !== false) {
            throw new \LogicException('Route must be an absolute path');
        }
        $parts = explode('/', $path);
        $shape = [];
        $names = [];
        foreach ($parts as $part) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/D', $part, $match)) {
                if (in_array($match[1], $names, true)) { throw new \LogicException('Duplicate route parameter'); }
                $names[] = $match[1];
                $shape[] = '{}';
            } else {
                if (strpos($part, '{') !== false || strpos($part, '}') !== false) { throw new \LogicException('Invalid route parameter'); }
                $shape[] = $part;
            }
        }
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method || count($route['shape']) !== count($shape)) { continue; }
            $overlap = true;
            foreach ($shape as $index => $part) {
                $other = $route['shape'][$index];
                if ($part !== '{}' && $other !== '{}' && $part !== $other) { $overlap = false; }
            }
            if ($overlap) { throw new \LogicException('Ambiguous route: ' . $method . ' ' . $path); }
        }
        $this->routes[] = ['method' => $method, 'parts' => $parts, 'shape' => $shape, 'action' => $action];
        if ($name !== '') { $this->named[$name] = $path; }
        return $this;
    }
    public function handle(Request $request): Response
    {
        try {
            $allowed = [];
            $matches = [];
            $parts = explode('/', $request->path());
            foreach ($this->routes as $route) {
                if (count($parts) !== count($route['parts'])) { continue; }
                $parameters = [];
                $matched = true;
                foreach ($route['parts'] as $index => $part) {
                    if ($route['shape'][$index] === '{}') {
                        if ($parts[$index] === '') { $matched = false; break; }
                        $parameters[substr($part, 1, -1)] = $parts[$index];
                    } elseif ($part !== $parts[$index]) { $matched = false; break; }
                }
                if (!$matched) { continue; }
                $allowed[] = $route['method'];
                if ($route['method'] === 'GET') { $allowed[] = 'HEAD'; }
                $matches[$route['method']] = ['action' => $route['action'], 'parameters' => $parameters];
            }
            $method = $request->method();
            if ($method === 'HEAD' && !isset($matches['HEAD'])) { $method = 'GET'; }
            if (isset($matches[$method])) {
                $match = $matches[$method];
                $action = $this->resolveAction($match['action']);
                return $action($request->withParameters($match['parameters']));
            }
            if ($allowed !== []) {
                return Response::problem(405, 'Method Not Allowed')->withHeader('Allow', implode(', ', array_unique($allowed)));
            }
            return Response::problem(404, 'Not Found');
        } catch (HttpError $error) {
            return Response::problem($error->status, $error->getMessage(), $error->details);
        }
    }
}

// Only for HTML text and quoted ordinary attributes, not URL/script/style contexts.
function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
