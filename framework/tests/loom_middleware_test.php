<?php

require_once dirname(__DIR__) . '/bootstrap.php';
/**
 * 断言测试条件成立，失败时立即抛出异常以终止当前测试。
 *
 * @param mixed $ok 待断言的条件；为假时抛出测试失败异常。
 * @return void 无返回值；条件失败时抛出 RuntimeException。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example check_middleware(true);
 */
function check_middleware($ok)
{
    if (!$ok) {
        throw new RuntimeException('middleware assertion');
    }
}
/**
 * 在没有模块的临时项目根上构建应用。$configure 在构建前收到 ServiceCollection。
 *
 * @param ?callable $configure 构建前登记或替换服务的回调。
 * @return Loom\Application 已构建的应用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $app = middleware_app();
 */
function middleware_app(?callable $configure = null): Loom\Application
{
    $root = sys_get_temp_dir() . '/loom-middleware-' . bin2hex(random_bytes(6));
    mkdir($root . '/config', 0700, true);
    try {
        $builder = Loom\Application::createBuilder($root);
        if ($configure !== null) {
            $configure($builder->services);
        }
        return $builder->build();
    } finally {
        rmdir($root . '/config');
        rmdir($root);
    }
}
/**
 * 创建记录进入和退出顺序的中间件，并在响应中写入追踪头。
 *
 * @param mixed $name 当前操作使用的名称；测试中作为响应头名或追踪标识。
 * @return \Closure 接收请求及下游处理器的闭包。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $middleware = trace_middleware('auth');
 */
function trace_middleware($name)
{
    return function ($request, $next) use ($name) {
        $GLOBALS['trace'][] = $name . ':in';
        $response = $next($request);
        $GLOBALS['trace'][] = $name . ':out';
        return $response->withHeader('X-' . $name, 'yes');
    };
}
/**
 * 验证路由参数已绑定，并将构造值写入请求 user 属性。
 *
 * 构造参数：
 * - $value（string）：夹具保存或使用的测试值；具体注入来源由参数属性声明。
 *
 * 返回值：使用 new 构造时得到 TestMiddleware 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(TestMiddleware::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class TestMiddleware implements Loom\Middleware
{
    private string $value;
    /**
     * 初始化 TestMiddleware 实例及其依赖。验证路由参数已绑定，并将构造值写入请求 user 属性。
     *
     * @param string $value 夹具保存或使用的测试值；具体注入来源由参数属性声明。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = $services->get(TestMiddleware::class); // 在对应测试环境中解析；无效夹具用于断言异常
     */
    public function __construct(string $value)
    {
        $this->value = $value;
    }
    /**
     * 断言路由 id 后把构造值附加到 user 属性，再执行下游。
     *
     * @param Loom\Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @param callable $next 下游处理器，接收 Request 并返回 Response。
     * @return Loom\Response 下游处理器返回的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $middleware->handle($request->withParameters(['id' => '7']), $next);
     */
    public function handle(Loom\Request $request, callable $next): Loom\Response
    {
        check_middleware($request->parameter('id') === '7');
        return $next($request->withAttribute('user', $this->value));
    }
}
$GLOBALS['factories'] = 0;
$app = middleware_app(function (Loom\ServiceCollection $services) {
    $services->addScoped(TestMiddleware::class, function () {
        $GLOBALS['factories']++;
        return new TestMiddleware('Ada');
    });
});
$app->mapGroup('/api', trace_middleware('outer'))->group(function (Loom\Router $routes) {
    $routes->group('/v1', function (Loom\Router $routes) {
        $routes->get('/items/{id}', function ($request) {
            $GLOBALS['trace'][] = 'action';
            return new Loom\Response($request->attribute('user'));
        }, 'item', [trace_middleware('route'), TestMiddleware::class]);
    }, [trace_middleware('inner')]);
});
// 路由注册之后添加的全局中间件仍包裹分发过程，包括 404 和 405。
$app->use(trace_middleware('global'));
check_middleware($GLOBALS['factories'] === 0);
foreach (['GET', 'HEAD'] as $method) {
    $GLOBALS['trace'] = [];
    $request = new Loom\Request(['method' => $method, 'path' => '/api/v1/items/7']);
    $result = $app->handle($request)->toArray();
    check_middleware($result['body'] === 'Ada' && $result['headers']->{'x-global'} === 'yes');
    check_middleware($GLOBALS['trace'] === ['global:in', 'outer:in', 'inner:in', 'route:in', 'action', 'route:out', 'inner:out', 'outer:out', 'global:out']);
    check_middleware($request->attribute('user', 'none') === 'none');
}
// 中间件类名在每次请求的作用域里解析一次。
check_middleware($GLOBALS['factories'] === 2);
foreach ([['GET', '/missing', 404], ['POST', '/api/v1/items/7', 405]] as $case) {
    $GLOBALS['trace'] = [];
    $result = $app->handle(new Loom\Request(['method' => $case[0], 'path' => $case[1]]))->toArray();
    check_middleware($result['status'] === $case[2]);
    check_middleware($GLOBALS['trace'] === ['global:in', 'global:out']);
}
try {
    $app->mapGroup('/failed', trace_middleware('leaked'))->group(function ($routes) {
        throw new RuntimeException('stop');
    });
} catch (RuntimeException $error) {
}
$app->mapGet('/root', function ($request) {
    return new Loom\Response('root');
});
$GLOBALS['trace'] = [];
check_middleware($app->handle(new Loom\Request(['method' => 'GET', 'path' => '/root']))->toArray()['body'] === 'root');
check_middleware($GLOBALS['trace'] === ['global:in', 'global:out']);
$blocked = middleware_app();
$blocked->mapGet('/private', 'MustNotResolveController', '', [function ($request, $next) {
    return new Loom\Response('denied', 403);
}, 'MustNotResolveMiddleware']);
check_middleware($blocked->handle(new Loom\Request(['method' => 'GET', 'path' => '/private']))->toArray()['status'] === 403);
$blocked->mapWebSocket('/socket', 'MustNotResolveController', '', [function ($request, $next) {
    throw new Loom\HttpError(401, 'Login required');
}]);
check_middleware($blocked->handle(new Loom\Request(['method' => 'GET', 'path' => '/socket']))->toArray()['status'] === 401);
$blocked->use(function ($request, $next) {
    throw new Loom\HttpError(429, 'Slow down');
});
check_middleware($blocked->handle(new Loom\Request(['method' => 'GET', 'path' => '/missing']))->toArray()['status'] === 429);
foreach ([null, 42, [], new stdClass(), ''] as $bad) {
    $thrown = false;
    try {
        $app->use($bad);
    } catch (LogicException $error) {
        $thrown = true;
    }
    check_middleware($thrown);
}
$badApp = middleware_app();
$badApp->use(function ($request, $next) {
    throw new RuntimeException('unexpected');
});
$thrown = false;
try {
    $badApp->handle(new Loom\Request(['method' => 'GET', 'path' => '/']));
} catch (RuntimeException $error) {
    $thrown = $error->getMessage() === 'unexpected';
}
check_middleware($thrown);
$request = new Loom\Request([]);
check_middleware($request->withAttribute('null', null)->attribute('null', 'fallback') === null);
/**
 * 提供可调用对象及具名中间件方法，验证不同处理器形式。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 CallableMiddleware 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(CallableMiddleware::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class CallableMiddleware
{
    /**
     * 执行下游并添加对象中间件响应头。
     *
     * @param mixed $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @param mixed $next 下游处理器，接收 Request 并返回 Response。
     * @return \Loom\Response 包含 X-Object: yes 的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $middleware($request, $next);
     */
    public function __invoke($request, $next)
    {
        return $next($request)->withHeader('X-Object', 'yes');
    }
    /**
     * 执行下游并添加具名方法中间件响应头。
     *
     * @param mixed $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @param mixed $next 下游处理器，接收 Request 并返回 Response。
     * @return \Loom\Response 包含 X-Method: yes 的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $middleware->process($request, $next);
     */
    public function process($request, $next)
    {
        return $next($request)->withHeader('X-Method', 'yes');
    }
}
$forms = middleware_app();
$forms->use(new CallableMiddleware());
$forms->use([new CallableMiddleware(), 'process']);
$forms->mapGet('/items/{id}', function ($request) {
    return new Loom\Response($request->attribute('user'));
}, '', [new TestMiddleware('object')]);
$forms->map('HEAD', '/items/{id}', function ($request) {
    return new Loom\Response('head');
});
$result = $forms->handle(new Loom\Request(['method' => 'GET', 'path' => '/items/7']))->toArray();
check_middleware($result['body'] === 'object' && $result['headers']->{'x-object'} === 'yes' && $result['headers']->{'x-method'} === 'yes');
check_middleware($forms->handle(new Loom\Request(['method' => 'HEAD', 'path' => '/items/7']))->toArray()['body'] === 'head');
$forms->mapGet('/error', function ($request) {
    throw new Loom\HttpError(422, 'Invalid');
});
$result = $forms->handle(new Loom\Request(['method' => 'GET', 'path' => '/error']))->toArray();
check_middleware($result['status'] === 422 && $result['headers']->{'x-object'} === 'yes');
// 链式选项可组合使用，不修改应用或可复用的构建器。
$fluent = middleware_app();
$base = $fluent->mapGroup('/api')->withMiddleware(trace_middleware('outer'));
$inner = $base->prefix('/v1')->withMiddleware(trace_middleware('inner'), trace_middleware('route'));
$inner->group(function ($routes) {
    $routes->get('/items/{id}', function ($request) {
        return new Loom\Response($request->parameter('id'));
    }, 'fluent.item');
});
$base->get('/base', function ($request) {
    return new Loom\Response('base');
});
$fluent->mapGet('/plain', function ($request) {
    return new Loom\Response('plain');
});
check_middleware($fluent->url('fluent.item', ['id' => '7']) === '/api/v1/items/7');
foreach ([['/api/v1/items/7', ['outer:in', 'inner:in', 'route:in', 'route:out', 'inner:out', 'outer:out']], ['/api/base', ['outer:in', 'outer:out']], ['/plain', []], ['/missing', []]] as $case) {
    $GLOBALS['trace'] = [];
    $fluent->handle(new Loom\Request(['method' => 'GET', 'path' => $case[0]]));
    check_middleware($GLOBALS['trace'] === $case[1]);
}
try {
    $inner->group(function ($routes) {
        throw new RuntimeException('stop');
    });
} catch (RuntimeException $error) {
}
$fluent->mapGet('/after', function ($request) {
    return new Loom\Response('after');
});
$GLOBALS['trace'] = [];
check_middleware($fluent->handle(new Loom\Request(['method' => 'GET', 'path' => '/after']))->toArray()['body'] === 'after');
check_middleware($GLOBALS['trace'] === []);
// 每个 HTTP 辅助方法与 WebSocket 共用同一作用域管道。
foreach (['get', 'post', 'put', 'patch', 'delete', 'head', 'options'] as $verb) {
    $base->$verb('/verbs', function ($request) {
        return new Loom\Response($request->method());
    });
    $GLOBALS['trace'] = [];
    check_middleware($fluent->handle(new Loom\Request(['method' => strtoupper($verb), 'path' => '/api/verbs']))->toArray()['body'] === strtoupper($verb));
    check_middleware($GLOBALS['trace'] === ['outer:in', 'outer:out']);
}
$fluent->mapGroup('', function ($request, $next) {
    return new Loom\Response('blocked', 403);
})
    ->websocket('/socket', 'NeverResolve');
check_middleware($fluent->handle(new Loom\Request(['method' => 'GET', 'path' => '/socket']))->toArray()['status'] === 403);
$thrown = false;
try {
    $base->withMiddleware(42)->get('/invalid', 'NeverResolve');
} catch (LogicException $error) {
    $thrown = true;
}
check_middleware($thrown);
check_middleware($fluent->handle(new Loom\Request(['method' => 'GET', 'path' => '/api/invalid']))->toArray()['status'] === 404);
echo "loom_middleware_test: OK\n";
