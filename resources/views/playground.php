<div class="wrap page-top"><div class="eyebrow">THE LOOM PLAYGROUND</div><h1>把好奇心，<br>交给一次请求。</h1><p>改一个参数，试一次错误，或让六个请求同时发生。<br>这里的每个结果，都来自正在运行的 Loom。</p></div>
<section class="wrap lab-layout" aria-label="交互实验室">
    <aside class="lab-sidebar"><span class="eyebrow">选择一个实验</span><div role="tablist" aria-label="实验类型" aria-orientation="vertical">
        <button id="tab-greeting" role="tab" aria-selected="true" aria-controls="pane-greeting" data-scenario="greeting"><span class="mono">01</span><span><strong>一个完整的 Action</strong><small>输入 → 业务 → JSON</small></span><b>↗</b></button>
        <button id="tab-contracts" role="tab" aria-selected="false" aria-controls="pane-contracts" tabindex="-1" data-scenario="contracts"><span class="mono">02</span><span><strong>让边界说话</strong><small>严格输入契约</small></span><b>↗</b></button>
        <button id="tab-isolation" role="tab" aria-selected="false" aria-controls="pane-isolation" tabindex="-1" data-scenario="isolation"><span class="mono">03</span><span><strong>同时发生，各自独立</strong><small>并发请求隔离</small></span><b>↗</b></button>
    </div><div class="lab-sidebar-note"><span class="status-dot"></span><p>真实请求。本地运行。<br>没有预设的成功结果。</p></div><a class="text-link" href="/guide">查看实现指南 →</a></aside>
    <div class="lab-main">
        <section id="pane-greeting" role="tabpanel" aria-labelledby="tab-greeting" class="lab-pane">
            <div class="pane-heading"><span class="mono">EXPERIMENT / 01</span><h2>给世界，一声问候。</h2><p>这个 Action 通过容器获取服务，校验姓名与次数，再返回一个 JSON 响应。</p></div>
            <form id="greeting-form" class="lab-form"><div class="fields-row"><label for="greeting-name">姓名 <span>string</span><input id="greeting-name" name="name" value="Ada" required maxlength="80" autocomplete="off"></label><label for="greeting-times">次数 <span>integer · 1–10</span><input id="greeting-times" name="times" type="number" min="1" max="10" value="2" required></label></div><div class="request-strip"><span class="method">POST</span><code>/greetings</code><span>application/json</span></div><button class="button primary" type="submit">发送请求 <span>↗</span></button></form>
            <div class="response-panel"><div class="response-bar"><span class="mono">RESPONSE</span><span id="greeting-meta" class="response-meta">等待发送</span><button type="button" class="copy-small" data-copy-target="greeting-result" aria-label="复制问候响应">复制</button></div><pre id="greeting-result" aria-live="polite">响应会显示在这里。</pre></div>
            <details class="code-details"><summary>这背后的 PHP 代码 <span>+</span></summary><pre><code>$input = $request-&gt;json()-&gt;only(['name', 'times']);
$name = $input-&gt;string('name', 1, 80);
$times = $input-&gt;integer('times', 1, 10);

return Response::json(
    $this-&gt;service-&gt;greet($name, $times),
    201
);</code></pre></details>
        </section>
        <section id="pane-contracts" role="tabpanel" aria-labelledby="tab-contracts" class="lab-pane" hidden>
            <div class="pane-heading"><span class="mono">EXPERIMENT / 02</span><h2>错误，也值得被好好表达。</h2><p>选择一种输入，或直接编辑 JSON。校验发生在服务端，字符串 “2” 不会被偷偷当成整数。</p></div>
            <div class="preset-list" aria-label="输入示例"><button type="button" data-preset="valid">有效输入</button><button type="button" data-preset="type" class="selected" aria-pressed="true">类型不符</button><button type="button" data-preset="missing">字段缺失</button><button type="button" data-preset="unknown">未知字段</button><button type="button" data-preset="malformed">无效 JSON</button></div>
            <form id="contract-form" class="lab-form"><label class="editor-label" for="contract-body"><span class="mono">REQUEST BODY</span><span>可编辑</span></label><textarea id="contract-body" spellcheck="false" rows="6">{
  "name": "Ada",
  "times": "2"
}</textarea><div class="form-bottom"><span class="mono">POST /greetings</span><button class="button primary" type="submit">验证输入 <span>↗</span></button></div></form>
            <div class="response-panel"><div class="response-bar"><span class="mono">RESPONSE</span><span id="contract-meta" class="response-meta">等待验证</span><button type="button" class="copy-small" data-copy-target="contract-result" aria-label="复制校验响应">复制</button></div><pre id="contract-result" aria-live="polite">试着越过边界，看看框架如何回应。</pre></div>
            <p class="lab-footnote">400 表示 JSON 格式错误；422 表示输入不符合业务契约。错误响应包含字段和原因，前端可以据此给出准确提示。</p>
        </section>
        <section id="pane-isolation" role="tabpanel" aria-labelledby="tab-isolation" class="lab-pane" hidden>
            <div class="pane-heading"><span class="mono">EXPERIMENT / 03</span><h2>六次相遇，六份独立状态。</h2><p>同时发出六个带不同标记的请求。每个请求都增加同一个 PHP 静态属性，并读回自己的全局变量。</p></div>
            <div class="isolation-rule"><span>每次请求中的计数</span><code>0 <span>→</span> 1</code><p>跨请求不累加，标记不串扰。</p></div>
            <div class="probe-grid" id="probe-grid" aria-live="polite">
                <?php foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $label) { ?>
                <div class="probe"><span class="mono">REQUEST <?php echo $label; ?></span><strong>—</strong><small>等待运行</small></div>
                <?php } ?>
            </div>
            <div class="form-bottom"><span id="probe-summary" role="status">六个请求将并发发送</span><button type="button" id="probe-run" class="button primary">运行隔离实验 <span>↗</span></button></div>
            <details class="code-details"><summary>验证的是什么？ <span>+</span></summary><pre><code>private static int $visits = 0;

self::$visits++;
$GLOBALS['loom_probe_token'] = $request-&gt;header('x-loom-probe');

return Response::json([
    'token' =&gt; $GLOBALS['loom_probe_token'],
    'counter' =&gt; self::$visits,
]);</code></pre><p>这是请求隔离的功能演示，不是吞吐基准，也不是对全部 PHP 扩展线程安全性的证明。</p></details>
        </section>
    </div>
</section>
<noscript><p class="wrap">实验室需要 JavaScript 发送并展示请求。你仍可阅读<a href="/guide">开发指南</a>，或使用首页的普通 HTML 表单。</p></noscript>
<section class="wrap lab-next"><p>一次请求，理解一个设计。</p><a class="text-link" href="/guide">把它写进你的应用 <span>→</span></a></section>
