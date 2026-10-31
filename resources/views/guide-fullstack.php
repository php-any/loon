<section id="routing"><div class="eyebrow">06 / ROUTES THAT READ WELL</div><h2>从一条路由，到一组业务。</h2><p>类 Action 适合独立业务入口；闭包适合简短操作。快捷方法和底层 route 使用同一匹配规则，嵌套分组组合路径前缀，命名路由负责生成 URL。</p><pre class="guide-code"><code>$app-&gt;route('GET', '/hello/{name}', Hello::class);
$app-&gt;get('/studio', StudioPage::class, 'studio');
$app-&gt;group('/api', function ($routes) {
    $routes-&gt;group('/studio', function ($routes) {
        $routes-&gt;get('/tasks/{id}', function ($request) {
            $user = Auth::guard();
            return Response::json(Database::query(
                'SELECT * FROM studio_tasks WHERE id=? AND user_id=?',
                [$request-&gt;parameter('id'), $user['id']]
            ));
        }, 'task.show');
    });
});
$app-&gt;url('task.show', ['id' =&gt; 42]); // /api/studio/tasks/42
// 同样提供 post / patch / delete；PUT、OPTIONS 使用 route。</code></pre><p>同方法的重叠路径、重复名称、缺失 URL 参数会明确报错。类 Action 仍由容器构造；闭包必须返回 Response。分组只负责路径组织，不隐式授予权限。</p></section>
<section id="fullstack"><div class="eyebrow">07 / STATE THAT STAYS</div><h2>让业务拥有身份，也拥有记忆。</h2><p>Studio 将账号、会话、任务和摘要存入 SQLite。Auth::guard() 返回当前身份，并在写操作时校验 CSRF 与 Origin。每一次数据读写都包含 user_id 条件。</p><pre class="guide-code"><code>$user = Auth::guard();
$input = $request-&gt;json()-&gt;only(['title']);
$task = Database::execute(
    'INSERT INTO studio_tasks(user_id,title) VALUES(?,?)',
    [$user['id'], $input-&gt;string('title', 1, 240)]
);
return Response::json($task, 201);

// 多条语句作为同一个事务提交；任何一条失败即回滚。
Database::batch([
    ['sql' =&gt; 'UPDATE studio_tasks SET completed=? WHERE id=? AND user_id=?',
     'args' =&gt; [1, $taskId, $user['id']]],
]);</code></pre><p>密码使用 bcrypt。随机会话令牌只以摘要存储；Cookie 设置 HttpOnly、SameSite=Lax，并在 HTTPS 下设置 Secure。会话有效期 24 小时，退出立即撤销；重新登录会轮换当前浏览器的会话。登录与注册使用按账户持久化的尝试限额。</p><p>数据库默认位于 storage/studio.sqlite，可通过 --database 指定。首次启动执行版本化迁移，后续启动校验已应用版本并保留数据。项目与任务通过 ORM 关联，见后文。</p><a class="text-link" href="/studio">打开你的 Studio →</a></section>
<section id="background"><div class="eyebrow">08 / LET TIME DO ITS PART</div><h2>把等待，交给后台。</h2><p>Queue::dispatch 在写入数据库后返回 Job ID。网页立即收到 202；宿主 worker 在独立 RequestVM 中执行 jobs.php，前端轮询状态。延迟任务和周期调度共用这条执行链。</p><pre class="guide-code"><code>// 立即执行或延迟 10 秒：都不阻塞当前 HTTP 请求。
$id = Queue::dispatch('studio.digest', [], $user['id']);
$id = Queue::dispatch('studio.digest', [], $user['id'], 10);

// 相同名称重复注册不会重置下一次执行时间。
Schedule::every('studio.digest.' . $user['id'], 60,
    'studio.digest', [], $user['id']);

// jobs.php：按 Job ID 去重，安全处理重复交付。
$job = json_decode(Request::capture()-&gt;body(), true);
Database::execute(
    'INSERT INTO studio_reports(user_id,job_id,total,completed)
     SELECT ?,?,COUNT(*),COALESCE(SUM(completed),0)
     FROM studio_tasks WHERE user_id=?
     ON CONFLICT(job_id) DO NOTHING',
    [$job['owner'], $job['id'], $job['owner']]
);</code></pre><p>每秒扫描到期任务，原子领取、租约恢复、最多三次自动尝试；失败按 2 秒、8 秒间隔退避，最终失败可手动重试。周期最短 10 秒，示例为每分钟；停机错过的周期只补一次，不集中回放所有历史周期。</p><p>这是持久化后台异步执行。它不把当前请求中的 PHP 对象带到后台，也不提供进程内闭包定时器。单 worker 串行处理任务，HTTP 请求可继续并发；任务超时仍是协作式取消，外部副作用需要业务幂等键。</p></section>
