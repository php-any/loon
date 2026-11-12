<div class="wrap page-top"><div class="eyebrow">THE FIELD GUIDE</div><h1>清晰的代码，<br>是最好的起点。</h1><p>从启动后台，到扩展业务模块。<br>跟着这份指南，了解 Loom 通用型后台的开发基础。</p></div>
<div class="wrap guide-layout"><aside class="guide-nav"><span class="eyebrow">这份指南</span><a href="#start">01 · 本地启动</a><a href="#action">02 · 第一个 Action</a><a href="#views">03 · 页面与视图</a><a href="#services">04 · 组织依赖</a><a href="#lifecycle">05 · 请求生命周期</a><a href="#routing">06 · 路由组织</a><a href="#fullstack">07 · 身份与数据库</a><a href="#background">08 · 队列与调度</a><a href="#orm">09 · ORM 与关系</a><a href="#migrations">10 · 版本化迁移</a><a href="#cron">11 · Cron 日历</a><a href="#roadmap">12 · 当前进展</a><a class="guide-lab-link" href="/admin" target="_blank" rel="noopener noreferrer">体验后台 ↗</a></aside>
<article class="guide-content">
<section id="start"><div class="eyebrow">01 / GET STARTED</div><h2>先让它运行起来。</h2><p>在 Loom 独立项目根目录启动服务。Origami 脚本负责框架与业务，通过标准库启动 HTTP 服务。</p><div class="command-block"><code id="start-command">./build/loom.exe serve</code><button type="button" data-copy-target="start-command">复制命令</button></div><p class="guide-note">先按 README 使用 scripts/build.ps1 构建宿主，再执行 ./build/loom.exe doctor 检查标准库。配置在 config/*.php；部署运行不需要 Go 工具链。</p><div class="file-tree"><pre>loom/
├── runtime/         <span>Go 宿主与原生扩展</span>
├── bin/loom.php     <span>脚本命令入口</span>
├── framework/       <span>内部框架源码、资源与测试</span>
├── config/          <span>应用配置</span>
├── app/             <span>业务模型、Action 与命令</span>
├── routes/          <span>路由与命令注册</span>
├── resources/views/ <span>布局与页面模板</span>
├── database/        <span>版本化迁移</span>
└── public/          <span>样式、脚本与图标</span></pre></div></section>
<section id="action"><div class="eyebrow">02 / ONE INTENTION, ONE ACTION</div><h2>让入口读起来像业务。</h2><p>控制器接收 Request，返回 Response。发布验收入口用路由注解注册，页面与业务服务按职责组织。</p><pre class="guide-code"><code>#[\Net\Annotation\Controller]
final class ReleasePageController
{
    #[\Net\Annotation\GetMapping('/demo')]
    public function release(Request $request): Response
    {
        return SitePages::page('release', '发布准备室 · 端到端业务体验');
    }
}</code></pre><p>GET 路由也能处理 HEAD，响应正文由脚本 Server 抑制。未匹配的路径返回 404，方法不匹配返回带 Allow 头部的 405。</p><a class="text-link" href="/demo">查看发布验收页面 ↗</a></section>
<section id="views"><div class="eyebrow">03 / A PAGE, BEAUTIFULLY COMPOSED</div><h2>把页面组合起来。</h2><p>视图是普通 PHP 文件，数据统一放在 $data 中。先渲染内容，再交给布局；模板使用独立输出缓冲，异常会向上传递。</p><pre class="guide-code"><code>$content = View::render(dirname(__DIR__, 2) . '/resources/views/official/pages/home.php', [
    'name' =&gt; '世界',
]);

return Response::html(View::render(
    dirname(__DIR__, 2) . '/resources/views/official/layout.php',
    ['title' =&gt; '你好，Loom', 'content' =&gt; $content]
));</code></pre><p>用户输入需要显式转义：<code>Loom\escape($data['name'])</code>。不要把请求参数直接拼成模板路径；只将应用生成的可信视图内容作为 HTML 插入布局。</p><p><a href="/demo">在发布验收场景中体验服务端页面与业务操作 →</a></p></section>
<section id="services"><div class="eyebrow">04 / EXPLICIT DEPENDENCIES</div><h2>依赖有来处，状态有归属。</h2><p>构造器声明业务依赖，框架通过容器自动解析。发布快照处理器使用发布服务，无需业务手动注册数据库连接。</p><pre class="guide-code"><code>final class ReleaseSnapshotHandler
{
    private ReleaseProtocols $releaseProtocols;

    public function __construct(ReleaseProtocols $releaseProtocols)
    {
        $this-&gt;releaseProtocols = $releaseProtocols;
    }
}</code></pre><p>支持显式工厂、构造参数自动解析与配置注入。依赖实例按作用域管理，配置可通过服务绑定或 InjectConfig 声明。</p></section>
<section id="lifecycle"><div class="eyebrow">05 / FOLLOW A REQUEST</div><h2>每一步，都有交代。</h2><ol class="guide-steps"><li><strong>接收</strong><p>通过标准库接收请求，脚本检查输入大小。</p></li><li><strong>隔离</strong><p>标准库隔离请求作用域，脚本装配当前请求的应用。</p></li><li><strong>执行</strong><p>匹配路由、获取依赖、校验输入，调用对应 Action。</p></li><li><strong>交付</strong><p>写出响应并释放当前请求的数据库连接和上下文。</p></li></ol><p>标准库在脚本解析前限制请求体为 1 MiB，并执行网络超时、连接和请求配额；脚本限制响应正文为 4 MiB、协作执行时间为 10 秒。</p></section>
<?php require __DIR__ . '/fullstack.php'; ?>
<?php require __DIR__ . '/persistence.php'; ?>
<section id="roadmap"><div class="eyebrow">12 / GROWING IN THE OPEN</div><h2>通用型后台，<br>从清晰的边界开始。</h2><p>统一后台 /admin 已集成用户、角色权限、菜单管理、系统配置、操作审计与业务工作台。以下是当前能力和部署说明。</p><div class="roadmap-list"><div><span class="roadmap-status available">已实现</span><p>Action 与闭包路由、嵌套分组与命名 URL、容器、输入契约、视图、请求隔离、MySQL 参数化查询、事务批处理、ORM、版本化迁移、Cron 时区调度、密码认证、持久化会话与 CSRF、延迟队列、失败重试、周期调度、后台 PHP 执行。</p></div><div><span class="roadmap-status available">管理模块</span><p>统一后台布局、分层菜单、用户停用、角色权限、系统配置与操作审计。管理员通过服务器命令 admin:bootstrap 授予。</p></div><div><span class="roadmap-status available">账户安全</span><p>邮箱验证、密码恢复、一次性验证码与 SMTP 邮件队列。部署时配置 SMTP 环境变量并启动 admin:mail；详细步骤见 README。</p></div></div><p class="guide-note">当前为单机开发预览。队列采用至少一次交付，任务应实现幂等。完整生产部署仍需 HTTPS、备份、资源配额与运维观测。</p><a class="button primary" href="/admin" target="_blank" rel="noopener noreferrer">体验后台 <span>↗</span></a></section>
</article></div>
