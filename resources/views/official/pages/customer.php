<section class="section wrap customer-center">
    <div class="section-heading"><div><div class="eyebrow">YOUR LOOM</div><h1>用户中心</h1></div><p>管理你的账号，体验服务，查看订单。</p></div>
    <div class="feature-grid">
        <div class="feature feature-wide" id="customer-auth-card">
            <div class="customer-tabs" role="group" aria-label="账号操作"><button type="button" id="customer-login-tab" class="button" aria-pressed="true">用户登录</button><button type="button" id="customer-register-tab" class="button" aria-pressed="false">注册用户</button></div>
            <form id="official-account" class="checkout-form">
                <label id="customer-name-field" hidden>姓名<input name="name" autocomplete="name" maxlength="80"></label>
                <label>邮箱<input name="email" type="email" autocomplete="email" required maxlength="254"></label>
                <label>密码<input name="password" type="password" autocomplete="current-password" required minlength="10" maxlength="72" placeholder="至少 10 位"></label>
                <button id="customer-submit" class="button primary" type="submit">登录用户中心</button>
            </form>
            <p id="official-user" aria-live="polite"></p>
            <button id="official-logout" class="button" type="button" hidden>退出用户登录</button>
        </div>
        <div class="feature feature-sage" id="checkout">
            <span class="feature-number mono">SIMULATED PAYMENT</span><h3>Loom 体验套餐</h3><p class="plan-price">¥99.00 <span>/ 次</span></p><p>仅用于体验支付流程，不会实际扣款。<br>支付成功后，可在下方查看订单。</p>
            <button id="official-pay" type="button" class="button primary" disabled>确认模拟支付 ¥99.00</button>
            <p id="official-payment" role="status">请先登录或注册用户账号。</p>
        </div>
    </div>
    <section class="customer-orders" id="customer-orders" hidden><div class="section-heading"><h2>我的订单</h2><button id="customer-refresh" class="button" type="button">刷新订单</button></div><p>最近 25 笔模拟支付记录</p><div id="customer-order-list" aria-live="polite"></div></section>
</section>
<script src="/assets/official/checkout.js" defer></script>
