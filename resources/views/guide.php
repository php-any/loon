<div class="wrap page-top"><div class="eyebrow">THE FIELD GUIDE</div><h1>清晰的代码，<br>是最好的起点。</h1><p>从一个 Action 到一个页面。<br>跟着这份指南，理解 Loom 应用如何真正运行。</p></div>
<div class="wrap guide-layout"><aside class="guide-nav"><span class="eyebrow">这份指南</span><a href="#start">01 · 本地启动</a><a href="#action">02 · 第一个 Action</a><a href="#views">03 · 页面与视图</a><a href="#services">04 · 组织依赖</a><a href="#lifecycle">05 · 请求生命周期</a><a href="#routing">06 · 路由组织</a><a href="#fullstack">07 · 身份与数据库</a><a href="#background">08 · 队列与调度</a><a href="#orm">09 · ORM 与关系</a><a href="#migrations">10 · 版本化迁移</a><a href="#cron">11 · Cron 日历</a><a href="#roadmap">12 · 当前进展</a><a class="guide-lab-link" href="/studio">打开 Studio 工作台 ↗</a></aside>
<article class="guide-content">
<section id="start"><div class="eyebrow">01 / GET STARTED</div><h2>先让它运行起来。</h2><p>在 Loom 独立项目根目录启动服务。PHP 负责业务与页面，Go 宿主负责 HTTP 和执行作用域。</p><div class="command-block"><code id="start-command">go run ./cmd/loom --addr=127.0.0.1:18087</code><button type="button" data-copy-target="start-command">复制命令</button></div><p class="guide-note">首次开发先运行 scripts/setup.ps1 连接 Origami 运行时。配置在 config/loom.json，根目录不放业务脚本或 Go 源码。</p><div class="file-tree"><pre>loom/
├── cmd/loom/        <span>命令行入口</span>
├── framework/       <span>框架核心与 PHP API</span>
├── internal/host/   <span>宿主装配</span>
├── app/             <span>业务模型、Action 与命令</span>
├── bootstrap/       <span>HTTP、终端与任务入口</span>
├── routes/          <span>路由与命令注册</span>
├── resources/views/ <span>布局与页面模板</span>
├── database/        <span>版本化迁移</span>
└── public/          <span>样式、脚本与图标</span></pre></div></section>
<section id="action"><div class="eyebrow">02 / ONE INTENTION, ONE ACTION</div><h2>让入口读起来像业务。</h2><p>Action 接收 Request，返回 Response。路径参数由路由绑定，业务代码只关心自己的输入与结果。</p><pre class="guide-code"><code>final class Hello implements Action
{
    public function __invoke(Request $request): Response
    {
        return Response::json([
            'message' =&gt; 'Hello, ' . $request-&gt;parameter('name')
        ]);
    }
}

$app = new Application();
$app-&gt;route('GET', '/hello/{name}', Hello::class);
return $app-&gt;handle(Request::capture())-&gt;toArray();</code></pre><p>GET 路由也能处理 HEAD，响应正文由宿主抑制。未匹配的路径返回 404，方法不匹配返回带 Allow 头部的 405。</p><a class="text-link" href="/hello/Loom">查看这个 Action 的响应 ↗</a></section>
<section id="views"><div class="eyebrow">03 / A PAGE, BEAUTIFULLY COMPOSED</div><h2>把页面组合起来。</h2><p>视图是普通 PHP 文件，数据统一放在 $data 中。先渲染内容，再交给布局；模板使用独立输出缓冲，异常会向上传递。</p><pre class="guide-code"><code>$content = View::render(dirname(__DIR__, 2) . '/resources/views/home.php', [
    'name' =&gt; '世界',
]);

return Response::html(View::render(
    dirname(__DIR__, 2) . '/resources/views/layout.php',
    ['title' =&gt; '你好，Loom', 'content' =&gt; $content]
));</code></pre><p>用户输入需要显式转义：<code>Loom\escape($data['name'])</code>。不要把请求参数直接拼成模板路径；只将应用生成的可信视图内容作为 HTML 插入布局。</p><form class="guide-form" action="/welcome" method="get"><label for="view-name">亲手试试服务端渲染</label><div class="inline-form"><input id="view-name" name="name" value="新朋友" required maxlength="80"><button type="submit">生成页面 ↗</button></div><small>这个表单不依赖 JavaScript。</small></form></section>
<section id="services"><div class="eyebrow">04 / EXPLICIT DEPENDENCIES</div><h2>依赖有来处，状态有归属。</h2><p>通过明确的工厂声明构造过程。同一作用域中的服务只创建一次，下一个请求得到自己的实例。循环依赖和重复绑定会明确报错。</p><pre class="guide-code"><code>$app-&gt;services()-&gt;bind(
    CreateGreeting::class,
    function ($services) {
        return new CreateGreeting(
            $services-&gt;get(GreetingService::class)
        );
    }
);</code></pre><p>当前支持显式工厂与无参构造，自动构造注入和进程级服务尚未实现。</p></section>
<section id="lifecycle"><div class="eyebrow">05 / FOLLOW A REQUEST</div><h2>每一步，都有交代。</h2><ol class="guide-steps"><li><strong>接收</strong><p>限制请求体和并发数量，为请求设置截止时间。</p></li><li><strong>隔离</strong><p>创建并激活 RequestVM，加载当前请求的 PHP 框架与应用。</p></li><li><strong>执行</strong><p>匹配路由、获取依赖、校验输入，调用对应 Action。</p></li><li><strong>交付</strong><p>验证响应，运行 shutdown 清理，再提交状态、头部与正文。</p></li></ol><p>默认请求体限制 1 MiB，响应正文限制 4 MiB，执行超时 10 秒，并发上限 64。超时采用协作式取消；不支持取消的原生调用仍需专门处理。</p></section>
<?php require __DIR__ . '/guide-fullstack.php'; ?>
<?php require __DIR__ . '/guide-persistence.php'; ?>
<section id="roadmap"><div class="eyebrow">12 / GROWING IN THE OPEN</div><h2>美好的框架，<br>从诚实的边界开始。</h2><p>Studio 展示框架当前已经实现的行为。完整框架还在建设中，以下是清晰的能力边界。</p><div class="roadmap-list"><div><span class="roadmap-status available">已实现</span><p>Action 与闭包路由、嵌套分组与命名 URL、容器、输入契约、视图、请求隔离、SQLite 参数化查询、事务批处理、ORM、版本化迁移、Cron 时区调度、密码认证、持久化会话与 CSRF、延迟队列、失败重试、周期调度、后台 PHP 执行。</p></div><div><span class="roadmap-status">下一步</span><p>账户恢复、邮件验证、通用 Policy、契约提取与启动预检。</p></div><div><span class="roadmap-status">规划中</span><p>更多数据库驱动、关系预加载、Outbox API、结构化并发、实时订阅与前端交互组件。</p></div></div><p class="guide-note">当前为单机开发预览。队列采用至少一次交付，任务应实现幂等。完整生产部署仍需 HTTPS、备份、资源配额与运维观测。</p><a class="button primary" href="/studio">进入 Studio 工作台 <span>↗</span></a></section>
</article></div>
