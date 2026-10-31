'use strict';

const $ = (selector, root = document) => root.querySelector(selector);
let toastTimer;
function toast(message) {
  const element = $('#toast');
  element.textContent = message;
  element.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { element.hidden = true; }, 3000);
}

async function send(url, options = {}) {
  const start = performance.now();
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 12000);
  try {
    const response = await fetch(url, {...options, cache: 'no-store', signal: controller.signal});
    const text = await response.text();
    let data;
    try { data = JSON.parse(text); } catch { data = null; }
    return {status: response.status, ok: response.ok, data, text, milliseconds: Math.round(performance.now() - start)};
  } finally { clearTimeout(timer); }
}

function networkMessage(error) {
  return error.name === 'AbortError' ? '请求超时，请确认本地服务仍在运行。' : '暂时无法连接 Loom，请确认服务已启动后重试。';
}

function renderResult(prefix, result) {
  const meta = $(`#${prefix}-meta`);
  meta.textContent = `${result.status} · 浏览器往返 ${result.milliseconds} ms`;
  meta.dataset.status = result.ok ? 'success' : 'error';
  $(`#${prefix}-result`).textContent = result.data === null ? result.text : JSON.stringify(result.data, null, 2);
}

async function formRequest(form, prefix, body) {
  const button = $('button[type=submit]', form);
  const previous = button.textContent;
  button.disabled = true;
  button.textContent = '请求中…';
  $(`#${prefix}-meta`).textContent = '正在等待服务端';
  $(`#${prefix}-result`).textContent = '请求已发送…';
  try {
    renderResult(prefix, await send('/greetings', {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body,
    }));
  } catch (error) {
    $(`#${prefix}-meta`).textContent = '连接失败';
    $(`#${prefix}-meta`).dataset.status = 'error';
    $(`#${prefix}-result`).textContent = networkMessage(error);
  } finally { button.disabled = false; button.textContent = previous; }
}

$('#hero-form')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  const button = $('button', form);
  button.disabled = true;
  $('#hero-result').textContent = '正在发送请求…';
  $('#hero-status').textContent = '';
  try {
    const result = await send(`/hello/${encodeURIComponent($('#hero-name').value)}`);
    $('#hero-result').textContent = result.data === null ? result.text : JSON.stringify(result.data);
    $('#hero-status').textContent = `${result.status} · ${result.milliseconds} ms`;
  } catch (error) { $('#hero-result').textContent = networkMessage(error); }
  finally { button.disabled = false; }
});

$('#greeting-form')?.addEventListener('submit', (event) => {
  event.preventDefault();
  formRequest(event.currentTarget, 'greeting', JSON.stringify({
    name: $('#greeting-name').value, times: Number($('#greeting-times').value),
  }));
});

const presets = {
  valid: '{\n  "name": "Ada",\n  "times": 2\n}',
  type: '{\n  "name": "Ada",\n  "times": "2"\n}',
  missing: '{\n  "name": "Ada"\n}',
  unknown: '{\n  "name": "Ada",\n  "times": 2,\n  "admin": true\n}',
  malformed: '{\n  "name": "Ada",\n  "times":\n}',
};

document.querySelectorAll('[data-preset]').forEach(button => {
  button.setAttribute('aria-pressed', String(button.classList.contains('selected')));
  button.addEventListener('click', () => {
    $('#contract-body').value = presets[button.dataset.preset];
    document.querySelectorAll('[data-preset]').forEach(other => {
      other.classList.toggle('selected', other === button);
      other.setAttribute('aria-pressed', String(other === button));
    });
  });
});
$('#contract-body')?.addEventListener('input', () => {
  document.querySelectorAll('[data-preset]').forEach(button => {
    button.classList.remove('selected'); button.setAttribute('aria-pressed', 'false');
  });
});
$('#contract-form')?.addEventListener('submit', event => {
  event.preventDefault();
  // Deliberately send unparsed text so malformed JSON reaches the PHP decoder.
  formRequest(event.currentTarget, 'contract', $('#contract-body').value);
});

const tabs = [...document.querySelectorAll('[data-scenario]')];
function selectScenario(name, focus = false) {
  if (!tabs.some(tab => tab.dataset.scenario === name)) name = 'greeting';
  tabs.forEach(tab => {
    const active = tab.dataset.scenario === name;
    tab.setAttribute('aria-selected', String(active));
    tab.tabIndex = active ? 0 : -1;
    $(`#pane-${tab.dataset.scenario}`).hidden = !active;
    if (active && focus) tab.focus();
  });
}
tabs.forEach((tab, index) => {
  tab.addEventListener('click', () => {
    history.replaceState(null, '', `#${tab.dataset.scenario}`);
    selectScenario(tab.dataset.scenario);
  });
  tab.addEventListener('keydown', event => {
    let target = index;
    if (event.key === 'ArrowDown' || event.key === 'ArrowRight') target = (index + 1) % tabs.length;
    else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') target = (index + tabs.length - 1) % tabs.length;
    else if (event.key === 'Home') target = 0;
    else if (event.key === 'End') target = tabs.length - 1;
    else return;
    event.preventDefault();
    history.replaceState(null, '', `#${tabs[target].dataset.scenario}`);
    selectScenario(tabs[target].dataset.scenario, true);
  });
});
if (tabs.length) {
  selectScenario(location.hash.slice(1));
  window.addEventListener('hashchange', () => selectScenario(location.hash.slice(1)));
}

$('#probe-run')?.addEventListener('click', async event => {
  const button = event.currentTarget;
  const cards = [...document.querySelectorAll('.probe')];
  button.disabled = true;
  button.textContent = '验证中…';
  $('#probe-summary').textContent = '正在并发发送六个独立请求';
  const batch = crypto.randomUUID();
  const results = await Promise.all(cards.map(async (card, index) => {
    const token = `${batch}-${index + 1}`;
    card.dataset.state = 'pending';
    $('strong', card).textContent = '…';
    $('small', card).textContent = '请求已发送';
    try {
      const result = await send('/api/isolation', {headers: {'X-Loom-Probe': token}});
      const valid = result.ok && result.data?.counter === 1 && result.data?.token === token;
      card.dataset.state = valid ? 'success' : 'error';
      $('strong', card).textContent = String(result.data?.counter ?? '!');
      $('small', card).textContent = valid ? `标记一致 · ${result.milliseconds} ms` : '状态与预期不符';
      return valid;
    } catch (error) {
      card.dataset.state = 'error';
      $('strong', card).textContent = '!';
      $('small', card).textContent = error.name === 'AbortError' ? '请求超时，可重试' : '连接失败，可重试';
      return false;
    }
  }));
  const count = results.filter(Boolean).length;
  $('#probe-summary').textContent = count === cards.length ? '6 / 6 验证通过：计数独立，标记一致。' : `${count} / 6 验证通过，请检查未通过的请求。`;
  button.disabled = false;
  button.textContent = '再运行一次 ↗';
});

document.querySelectorAll('[data-copy-target]').forEach(button => {
  button.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(document.getElementById(button.dataset.copyTarget).textContent);
      toast('已复制到剪贴板');
    } catch { toast('浏览器未允许复制，请选中文字后手动复制。'); }
  });
});
