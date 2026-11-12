<p>以下示例中的 $auth、$database、$queue、$schedule 来自当前作用域；业务类通过构造器注入这些服务。</p>
<section id="routing"><div class="eyebrow">06 / ROUTES THAT READ WELL</div><h2>从一条路由，到一组业务。</h2><p>控制器方法适合独立业务入口；闭包适合简短操作。快捷方法和底层 route 使用同一匹配规则，嵌套分组组合路径前缀，命名路由负责生成 URL。</p><pre class="guide-code"><code>// 示例：为后台项目任务扩展一个只读详情入口。
$app-&gt;get('/api/projects/tasks/{id}', function ($request) use ($auth) {
    $user = $auth-&gt;guard();
    $task = Task::whereUserId($user['id'])
        -&gt;whereId((int)$request-&gt;parameter('id'))-&gt;firstOrFail();
    return Response::json($task-&gt;toArray());
}, 'task.show');
$app-&gt;url('task.show', ['id' =&gt; 42]); // /api/projects/tasks/42
// 现有应用入口使用控制器路由注解，避免重复注册。</code></pre><p>同方法的重叠路径、重复名称、缺失 URL 参数会明确报错。控制器仍由容器构造；闭包必须返回 Response。分组只负责路径组织，不隐式授予权限。</p></section>
<section id="fullstack"><div class="eyebrow">07 / STATE THAT STAYS</div><h2>让业务拥有身份，也拥有记忆。</h2><p>Loom 使用 MySQL 保存业务数据、Redis 保存会话和 Stream 队列。$auth-&gt;guard() 返回当前身份，并在写操作时校验 CSRF 与 Origin。每一次数据读写都包含 user_id 条件。</p><pre class="guide-code"><code>$user = $auth-&gt;guard();
$input = $request-&gt;json()-&gt;only(['title']);
$task = Task::create([
    'user_id' =&gt; $user['id'],
    'title' =&gt; $input-&gt;string('title', 1, 240),
]);
return Response::json($task-&gt;toArray(), 201);

// 写操作保留用户归属限制。
Task::whereUserId($user['id'])-&gt;whereId($taskId)
    -&gt;update(['completed' =&gt; 1]);</code></pre><p>密码使用 bcrypt。随机会话令牌只以摘要存储；Cookie 设置 HttpOnly、SameSite=Lax，并在 HTTPS 下设置 Secure。会话有效期 24 小时，退出立即撤销；重新登录会轮换当前浏览器的会话。登录与注册使用按账户持久化的尝试限额。</p><p>数据库通过 config/database.php 配置，可由 LOOM_DATABASE 覆盖。首次启动执行版本化迁移，后续启动校验已应用版本并保留数据。项目与任务通过 ORM 关联，见后文。</p><a class="text-link" href="/demo" target="_blank" rel="noopener noreferrer">体验发布验收场景 →</a></section>
<section id="background"><div class="eyebrow">08 / LET TIME DO ITS PART</div><h2>把等待，交给后台。</h2><p>$queue-&gt;dispatch 在写入数据库后返回 Job ID。网页立即收到 202；独立运行的 queue:work 命令在子进程中执行 jobs.php，前端轮询状态。延迟任务和周期调度共用这条执行链。</p><pre class="guide-code"><code>// 立即执行或延迟 10 秒：都不阻塞当前 HTTP 请求。
$id = $queue-&gt;dispatch('studio.digest', [], $user['id']);
$id = $queue-&gt;dispatch('studio.digest', [], $user['id'], 10);

// 相同名称重复注册不会重置下一次执行时间。
$schedule-&gt;every('studio.digest.' . $user['id'], 60,
    'studio.digest', [], $user['id']);

// 摘要作业通过模型统计任务，以 job_id 保证重复交付不重复建报告。
$database-&gt;transaction(function () use ($job) {
    $counts = Task::whereUserId($job['owner'])
        -&gt;aggregate(['total' =&gt; ['COUNT', '*'], 'completed' =&gt; ['SUM', 'completed']]);
    Report::insertOrIgnore([
        'user_id' =&gt; $job['owner'], 'job_id' =&gt; $job['id'],
        'total' =&gt; (int)$counts['total'],
        'completed' =&gt; (int)$counts['completed'],
    ], ['job_id']);
});</code></pre><p>每秒扫描到期任务，原子领取、租约恢复、最多三次自动尝试；失败按 2 秒、8 秒间隔退避，最终失败可手动重试。周期最短 10 秒，示例为每分钟；停机错过的周期只补一次，不集中回放所有历史周期。</p><p>这是持久化后台异步执行。它不把当前请求中的 PHP 对象带到后台，也不提供进程内闭包定时器。单 worker 串行处理任务，HTTP 请求可继续并发；任务超时仍是协作式取消，外部副作用需要业务幂等键。</p></section>
