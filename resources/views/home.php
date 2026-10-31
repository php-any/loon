<section class="hero wrap">
    <div class="hero-copy">
        <div class="eyebrow"><span class="status-dot"></span> A NEW CHAPTER FOR PHP <span class="small-tag">开发预览</span></div>
        <h1>让想法，<br><span class="accent">优雅成形。</span></h1>
        <p class="hero-intro">熟悉的 PHP，全新的可能。<br>从一个清晰的 Action，到一次从容的响应，<br>把精力留给你真正想创造的东西。</p>
        <div class="hero-actions"><a class="button primary" href="/playground">亲手试一试 <span aria-hidden="true">↗</span></a><a class="text-link" href="/guide">认识 Loom <span aria-hidden="true">→</span></a></div>
        <p class="hero-note mono">PHP 的表达力 <span>×</span> Go 的运行底座</p>
    </div>
    <div class="hero-studio">
        <div class="studio-top"><span class="mono">01 / FROM IDEA TO RESPONSE</span><span class="studio-label">在这里，代码开始生长</span></div>
        <div class="code-sheet">
            <div class="file-bar"><span><i class="file-dot"></i> Hello.php</span><span class="mono">PHP</span></div>
            <pre class="code"><code><span class="dim">&lt;?php</span>

<span class="kw">final class</span> <span class="type">Hello</span> <span class="kw">implements</span> Action
{
    <span class="kw">public function</span> <span class="fn">__invoke</span>(
        Request <span class="var">$request</span>
    ): Response {
        <span class="kw">return</span> Response::<span class="fn">json</span>([
            <span class="str">'message'</span> =&gt; <span class="str">'Hello, '</span> .
                <span class="var">$request</span>-&gt;<span class="fn">parameter</span>(<span class="str">'name'</span>)
        ]);
    }
}</code></pre>
        </div>
        <div class="live-card">
            <div class="live-heading"><span class="live-dot"></span><span>从代码，到真实响应</span><span class="mono">LIVE</span></div>
            <form id="hero-form" class="inline-form" action="/welcome" method="get">
                <label class="sr-only" for="hero-name">你的名字</label>
                <span class="method">GET</span><span class="route-prefix">/hello/</span><input id="hero-name" name="name" value="世界" maxlength="80" required autocomplete="off"><button type="submit" aria-label="运行问候请求">↗</button>
            </form>
            <div class="live-result" aria-live="polite"><code id="hero-result">输入名字，发送你的第一个请求。</code><span id="hero-status" class="mono"></span></div>
        </div>
        <div class="studio-caption"><span>一段业务逻辑 · 一个明确入口</span><span aria-hidden="true">↳</span></div>
    </div>
</section>
<section class="principles wrap" aria-label="设计原则">
    <span class="mono principles-label">SMALL CORE.<br>THOUGHTFUL DESIGN.</span>
    <div><span class="principle-index">01</span><strong>表达清晰</strong><span>让代码说出业务本身</span></div>
    <div><span class="principle-index">02</span><strong>边界明确</strong><span>每个请求都有自己的空间</span></div>
    <div><span class="principle-index">03</span><strong>体验完整</strong><span>从服务端，走到浏览器</span></div>
</section>
<section class="section wrap" id="possibilities">
    <div class="section-heading"><div><div class="eyebrow">DESIGNED TO FEEL NATURAL</div><h2>好的工具，<br>让复杂自然展开。</h2></div><p>页面、数据和交互，不必各自为政。<br>Loom 从小而清晰的约定出发，<br>让每一次构建都有迹可循。</p></div>
    <div class="feature-grid">
        <a class="feature feature-wide" href="/playground"><span class="feature-number mono">01 — ACTIONS</span><h3>一次意图，<br>一个清晰的入口。</h3><p>显式路由、类型化 Request 与 Response。<br>不用猜测业务逻辑会在哪里发生。</p><div class="route-line"><span class="method">POST</span><code>/greetings</code><span>→</span><code>CreateGreeting</code></div><span class="feature-link">探索 Action <span>↗</span></span></a>
        <a class="feature feature-warm" href="/playground#contracts"><span class="feature-number mono">02 — CONTRACTS</span><h3>严格的边界，<br>友好的反馈。</h3><p>字段缺失、类型不符、未知输入，<br>都得到准确而可读的响应。</p><div class="contract-example"><code>"times": "2"</code><span>422 · 类型不匹配</span></div><span class="feature-link">试试输入校验 <span>↗</span></span></a>
        <a class="feature feature-sage" href="/playground#isolation"><span class="feature-number mono">03 — ISOLATION</span><h3>各自独立，<br>一起发生。</h3><p>独立的请求状态与作用域容器，<br>让并发中的业务保持自己的身份。</p><div class="scope-visual" aria-hidden="true"><span>请求 A<b>1</b></span><span>请求 B<b>1</b></span><span>请求 C<b>1</b></span></div><span class="feature-link">验证并发隔离 <span>↗</span></span></a>
        <a class="feature feature-wide feature-paper" href="/guide#views"><span class="feature-number mono">04 — VIEWS</span><h3>由服务端出发，<br>在浏览器里绽放。</h3><p>可组合的 PHP 视图，恰到好处的浏览器增强。<br>这整个页面，就运行在 Loom 之上。</p><div class="view-visual"><span>PHP View</span><i>→</i><span>HTML</span><i>→</i><span>你的用户</span></div><span class="feature-link">了解页面渲染 <span>↗</span></span></a>
    </div>
</section>
<section class="section wrap"><div class="section-heading"><div><div class="eyebrow">MORE THAN A BEAUTIFUL PAGE</div><h2>从一声问候，<br>走进真实的日常。</h2></div><p>一个完整的个人工作室。<br>登录、记录、完成，再让后台<br>替你整理每一步进展。</p></div><div class="feature-grid"><a class="feature feature-wide feature-sage" href="/studio"><span class="feature-number mono">05 — LOOM STUDIO</span><h3>你的清单，<br>有了自己的家。</h3><p>账号认证、私有任务、SQLite 持久化。<br>刷新和重启之后，一切仍然在这里。</p><span class="feature-link">进入 Studio 工作台 ↗</span></a><a class="feature feature-warm" href="/guide#background"><span class="feature-number mono">06 — BACKGROUND WORK</span><h3>此刻提交，<br>让后台继续。</h3><p>延迟队列、失败重试、周期调度。<br>每一份摘要，来自真实的 PHP 任务。</p><span class="feature-link">了解后台执行 ↗</span></a></div></section>
<section class="journey-section">
    <div class="wrap journey"><div><div class="eyebrow">A PLACE FOR EVERYTHING</div><h2>看得见的流程。<br>心中有数的开发。</h2><p>从入口到响应，每一步都有明确的责任。<br>把基础交给框架，把思考留给业务。</p><a class="text-link" href="/guide#lifecycle">走过一次请求 <span>→</span></a></div>
    <ol class="journey-steps"><li><span class="mono">01</span><div><h3>进入作用域</h3><p>请求状态独立，执行时间有界。</p></div><b>Request</b></li><li><span class="mono">02</span><div><h3>表达业务意图</h3><p>校验输入，解析依赖，执行 Action。</p></div><b>Action</b></li><li><span class="mono">03</span><div><h3>交付明确结果</h3><p>完成清理，再提交 HTML 或 JSON。</p></div><b>Response</b></li></ol></div>
</section>
<section class="closing wrap"><div class="eyebrow">MADE FOR YOUR NEXT IDEA</div><h2>下一次创造，<br>从这里开始。</h2><p>Loom 仍在生长。先体验已经实现的能力，<br>再用清晰、可运行的代码，写下你的下一步。</p><a class="button primary" href="/guide#start">写下第一个 Action <span>↗</span></a><a class="closing-secondary" href="/guide#roadmap">了解当前进展 →</a></section>
