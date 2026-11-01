(() => {
  const form = document.querySelector('#official-account');
  if (!form) return;
  const userLabel = document.querySelector('#official-user');
  const pay = document.querySelector('#official-pay');
  const status = document.querySelector('#official-payment');
  const logout = document.querySelector('#official-logout');
  const tabs = document.querySelector('.customer-tabs');
  const submit = document.querySelector('#customer-submit');
  const orders = document.querySelector('#customer-orders');
  const orderList = document.querySelector('#customer-order-list');
  let csrf = '';
  let mode = 'login';
  let customerId = 0;
  let requestKey = '';
  async function api(path, body) {
    const response = await fetch(path, {
      method: body ? 'POST' : 'GET', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
    const data = await response.json();
    if (!response.ok) {
      const error = new Error(data.message || data.error || '请求失败，请重试');
      error.status = response.status;
      throw error;
    }
    return data;
  }
  function setMode(next) {
    mode = next;
    document.querySelector('#customer-name-field').hidden = mode !== 'register';
    form.elements.name.required = mode === 'register';
    form.elements.password.autocomplete = mode === 'register' ? 'new-password' : 'current-password';
    document.querySelector('#customer-login-tab').setAttribute('aria-pressed', String(mode === 'login'));
    document.querySelector('#customer-register-tab').setAttribute('aria-pressed', String(mode === 'register'));
    submit.textContent = mode === 'register' ? '注册用户账号' : '登录用户中心';
    userLabel.textContent = mode === 'register' ? '创建你的个人用户账号，注册后自动登录。' : '使用官网用户账号登录。';
  }
  function signedOut() {
    csrf = '';
    customerId = 0;
    form.hidden = tabs.hidden = false;
    logout.hidden = orders.hidden = true;
    pay.disabled = true;
    status.textContent = '请先登录或注册用户账号。';
  }
  async function loadOrders() {
    const data = await api('/api/customer/orders');
    orderList.replaceChildren();
    if (!data.items.length) { orderList.textContent = '还没有订单，试试右侧的体验套餐。'; return; }
    for (const item of data.items) {
      const row = document.createElement('article');
      row.className = 'customer-order';
      const info = document.createElement('div');
      const number = document.createElement('strong');
      number.textContent = item.number;
      const detail = document.createElement('p');
      detail.textContent = `Loom 体验套餐 · ${new Date(Number(item.created) * 1000).toLocaleString()}`;
      info.append(number, detail);
      const amount = document.createElement('span');
      amount.textContent = `¥${(Number(item.total) / 100).toFixed(2)} · 模拟支付成功`;
      row.append(info, amount);
      orderList.append(row);
    }
  }
  async function session() {
    const data = await api('/api/customer/session');
    csrf = data.user.csrf;
    customerId = data.user.id;
    userLabel.textContent = `你好，${data.user.name}（${data.user.email}）`;
    form.hidden = tabs.hidden = true;
    logout.hidden = orders.hidden = false;
    pay.disabled = false;
    status.textContent = '准备就绪，可以确认模拟支付。';
    try { await loadOrders(); } catch (error) { orderList.textContent = error.message; }
  }
  document.querySelector('#customer-login-tab').addEventListener('click', () => setMode('login'));
  document.querySelector('#customer-register-tab').addEventListener('click', () => setMode('register'));
  document.querySelector('#customer-refresh').addEventListener('click', async () => {
    try { await loadOrders(); } catch (error) { orderList.textContent = error.message; }
  });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const action = mode;
    const values = Object.fromEntries(new FormData(form));
    if (action === 'login') delete values.name;
    else if (!values.name.trim()) { userLabel.textContent = '请填写姓名'; return; }
    submit.disabled = true;
    try {
      await api(`/api/customer/${action}`, values);
      form.reset();
      await session();
    } catch (error) { userLabel.textContent = error.message; }
    finally { submit.disabled = false; }
  });
  logout.addEventListener('click', async () => {
    logout.disabled = true;
    try { await api('/api/customer/logout', {}); location.reload(); }
    catch (error) { userLabel.textContent = error.message; logout.disabled = false; }
  });
  pay.addEventListener('click', async () => {
    pay.disabled = true;
    requestKey ||= crypto.randomUUID();
    status.textContent = '正在处理模拟支付…';
    try {
      const data = await api('/api/checkout/simulate', { request_key: requestKey });
      status.textContent = `模拟支付成功！订单 ${data.order.number}，¥99.00，未实际扣款。`;
      pay.textContent = '模拟支付已完成';
      try { await loadOrders(); } catch (error) { orderList.textContent = error.message; }
    } catch (error) {
      if (error.status === 401) { signedOut(); userLabel.textContent = '用户登录已过期，请重新登录。'; }
      else { status.textContent = error.message; pay.disabled = !customerId; }
    }
  });
  setMode(new URLSearchParams(location.search).get('mode') === 'register' ? 'register' : 'login');
  session().catch((error) => { signedOut(); if (error.status !== 401) userLabel.textContent = error.message; });
})();
