<?php

declare(strict_types=1);

namespace ModuleFixtures;

use Loom\Attributes\Service;
use Loom\Attributes\Inject;
use Loom\Attributes\Controller;
use Loom\Attributes\Route;
use Loom\Attributes\Job;
use Loom\Attributes\Module;
use Database\Annotation\Table;

/**
 * 提供固定文本并注册模块服务别名，验证模块服务注入。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 Value 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(Value::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
#[Service('module.value')]
final class Value
{
    /**
     * 返回用于模块服务注入验证的固定文本。
     *
     * 参数：无。
     *
     * @return string 字符串 injected。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $text = $value->text();
     */
    public function text(): string
    {
        return 'injected';
    }
}

/**
 * 通过注入服务及两个中间件生成响应，验证模块路由注册顺序。
 *
 * 构造参数：
 * - $value（Value）：夹具保存或使用的测试值；具体注入来源由参数属性声明。
 *
 * 返回值：使用 new 构造时得到 Endpoint 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(Endpoint::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
#[Controller('/module', [\ModuleFixtures\FirstMiddleware::class])]
final class Endpoint
{
    private Value $value;
    /**
     * 初始化 Endpoint 实例及其依赖。通过注入服务及两个中间件生成响应，验证模块路由注册顺序。
     *
     * @param Value $value 夹具保存或使用的测试值；具体注入来源由参数属性声明。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = $services->get(Endpoint::class); // 在对应测试环境中解析；无效夹具用于断言异常
     */
    public function __construct(#[Inject('module.value')] Value $value)
    {
        $this->value = $value;
    }
    /**
     * 拼接服务文本和中间件顺序标记。
     *
     * @param \Loom\Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return \Loom\Response 正文可用于核对注入与中间件顺序的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $endpoint->show($request->withAttribute('order', '12'));
     */
    #[Route('GET', '/value', 'module.value', [\ModuleFixtures\SecondMiddleware::class])]
    public function show(\Loom\Request $request): \Loom\Response
    {
        return new \Loom\Response($this->value->text() . $request->attribute('order'));
    }
}
/**
 * 写入顺序标记 1，验证控制器级中间件先执行。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 FirstMiddleware 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(FirstMiddleware::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
final class FirstMiddleware implements \Loom\Middleware
{
    /**
     * 设置请求的中间件顺序标记为 1，再继续执行。
     *
     * @param \Loom\Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @param callable $next 下游处理器，接收 Request 并返回 Response。
     * @return \Loom\Response 下游响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $middleware->handle($request, $next);
     */
    public function handle(\Loom\Request $request, callable $next): \Loom\Response
    {
        return $next($request->withAttribute('order', '1'));
    }
}
/**
 * 追加顺序标记 2，验证路由级中间件后执行。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 SecondMiddleware 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(SecondMiddleware::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
final class SecondMiddleware implements \Loom\Middleware
{
    /**
     * 在已有顺序标记后追加 2，再继续执行。
     *
     * @param \Loom\Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @param callable $next 下游处理器，接收 Request 并返回 Response。
     * @return \Loom\Response 下游响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $middleware->handle($request, $next);
     */
    public function handle(\Loom\Request $request, callable $next): \Loom\Response
    {
        return $next($request->withAttribute('order', $request->attribute('order') . '2'));
    }
}
/**
 * 调用模块服务生成任务响应，验证任务入口依赖注入。
 *
 * 构造参数：
 * - $value（Value）：夹具保存或使用的测试值；具体注入来源由参数属性声明。
 *
 * 返回值：使用 new 构造时得到 Work 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(Work::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
#[Job('module.job')]
final class Work
{
    private Value $value;
    /**
     * 初始化 Work 实例及其依赖。调用模块服务生成任务响应，验证任务入口依赖注入。
     *
     * @param Value $value 夹具保存或使用的测试值；具体注入来源由参数属性声明。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = $services->get(Work::class); // 在对应测试环境中解析；无效夹具用于断言异常
     */
    public function __construct(#[Inject('module.value')] Value $value)
    {
        $this->value = $value;
    }
    /**
     * 使用注入的模块服务生成任务结果。
     *
     * @param \Loom\Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return \Loom\Response 正文 injected 的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $work->handle($request);
     */
    public function handle(\Loom\Request $request): \Loom\Response
    {
        return new \Loom\Response($this->value->text());
    }
}
/**
 * 故意复用模块服务别名，验证重复服务注册被拒绝。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 DuplicateService 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(DuplicateService::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
#[Service('module.value')]
final class DuplicateService
{
}
/**
 * 故意复用任务种类，验证重复任务注册被拒绝。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 DuplicateJob 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(DuplicateJob::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
#[Job('module.job')]
final class DuplicateJob
{
    /**
     * 提供重复任务注册测试所需的合法处理方法。
     *
     * @param \Loom\Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return \Loom\Response 正文为空的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $job->handle($request);
     */
    public function handle(\Loom\Request $request): \Loom\Response
    {
        return new \Loom\Response('');
    }
}
/**
 * 把私有方法声明为路由，验证控制器公开方法约束。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 InvalidController 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(InvalidController::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
#[Controller]
#[Route('GET', '/hidden', '', [], 'hidden')]
final class InvalidController
{
    /**
     * 提供被路由属性标记的私有方法，验证发现过程拒绝非公开处理器。
     *
     * 参数：无。
     *
     * @return void 无返回值；注册此控制器应报错。
     *
     * 内部调用示例（仅类内使用）：
     * @example $this->hidden();
     */
    private function hidden(): void
    {
    }
}
/**
 * 导入 CycleB，与其构成循环，验证模块循环依赖检测。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 CycleA 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(CycleA::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
#[Module([], [\ModuleFixtures\CycleB::class])]
final class CycleA
{
}
/**
 * 导入 CycleA，与其构成循环，验证模块循环依赖检测。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 CycleB 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(CycleB::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
#[Module([], [\ModuleFixtures\CycleA::class])]
final class CycleB
{
}
/**
 * 声明表映射却不继承 Model，验证模型类型约束。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 InvalidModel 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(InvalidModel::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
#[Table('invalid')]
final class InvalidModel
{
    protected static array $casts = ['missing' => 'int'];
    protected static array $columns = ['id'];
}

/**
 * 注入模块服务并累计执行次数，验证定时任务注册与执行。
 *
 * 构造参数：
 * - $value（Value）：夹具保存或使用的测试值；具体注入来源由参数属性声明。
 *
 * 返回值：使用 new 构造时得到 TimerTask 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(TimerTask::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
#[\Loom\Timer(1)]
final class TimerTask
{
    public int $calls = 0;
    private Value $value;
    /**
     * 初始化 TimerTask 实例及其依赖。注入模块服务并累计执行次数，验证定时任务注册与执行。
     *
     * @param Value $value 夹具保存或使用的测试值；具体注入来源由参数属性声明。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = $services->get(TimerTask::class); // 在对应测试环境中解析；无效夹具用于断言异常
     */
    public function __construct(#[Inject('module.value')] Value $value)
    {
        $this->value = $value;
    }
    /**
     * 校验注入服务文本并增加任务调用次数。
     *
     * 参数：无。
     *
     * @return void 无返回值，通过 calls 观察执行次数。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $task();
     */
    public function __invoke(): void
    {
        if ($this->value->text() === 'injected') {
            $this->calls++;
        }
    }
}
