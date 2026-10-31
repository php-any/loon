(() => {
  'use strict';
  if (!document.querySelector('#studio-app')) return;
  const $ = id => document.getElementById(id);
  let csrf = '', mode = 'register', state = null, timer = null, refreshing = false, project = '', cronLoaded = false, projectsKey = '';
  const message = (text, error = false) => { $('studio-message').textContent = text; $('studio-message').classList.toggle('is-error', error); };
  async function api(path, method = 'GET', body) {
    const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 12000);
    try {
      const response = await fetch('/api/studio' + path, { method, credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: {'Content-Type':'application/json', 'X-CSRF-Token':csrf}, body: body === undefined ? undefined : JSON.stringify(body) });
      const result = await response.json();
      if (!response.ok) { const error = new Error(result.title || '请求失败，请重试'); error.status = response.status; throw error; }
      return result;
    } finally { clearTimeout(timeout); }
  }
  function node(tag, text, className) { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; if (className) el.className = className; return el; }
  function signedOut() { clearTimeout(timer); state = null; csrf = ''; project = ''; cronLoaded = false; projectsKey = ''; $('studio-auth').hidden = false; $('studio-workspace').hidden = true; }
  const when = seconds => new Date(seconds * 1000).toLocaleTimeString('zh-CN', {hour:'2-digit', minute:'2-digit',second:'2-digit'});
  function render() {
    $('studio-auth').hidden = true; $('studio-workspace').hidden = false;
    $('studio-greeting').textContent = state.user.name + '，今天继续创造。';
    const key = JSON.stringify(state.projects);
    if (key !== projectsKey) {
      projectsKey = key;
      for (const [id, placeholder] of [['studio-project-filter','全部任务'],['studio-task-project','暂不归类']]) {
        const select = $(id), selected = select.value; select.replaceChildren(); const empty = node('option', placeholder); empty.value = ''; select.append(empty);
        for (const p of state.projects) { const option = node('option', p.name); option.value = p.id; select.append(option); } select.value = selected;
      }
    }
    $('studio-project-filter').value = project;
    const chosen = state.projects.find(p => String(p.id) === project);
    $('studio-relation-note').textContent = chosen ? chosen.name + ' · ' + state.tasks.length + ' 个任务 · ORM 按项目和当前用户共同约束查询' : state.projects.length + ' 个项目 · 当前显示全部任务（最多 1000 条）';
    $('studio-query-code').textContent = chosen ? "StudioProject::query()->where('id', '=', " + project + ")->where('user_id', '=', $userId)\n    ->firstOrFail()->tasks()->orderBy('id', 'DESC')->rows();" : "StudioTask::query()->where('user_id', '=', $userId)\n    ->orderBy('id', 'DESC')->rows();";
    const completed = state.tasks.filter(t => t.completed === 1).length;
    $('studio-total').textContent = state.tasks.length; $('studio-completed').textContent = completed; $('studio-open').textContent = state.tasks.length - completed;
    const tasks = $('studio-tasks'); tasks.replaceChildren();
    if (!state.tasks.length) tasks.append(node('li', '清单还是空的。写下第一件值得做的事。', 'studio-empty'));
    for (const task of state.tasks) {
      const li = node('li', undefined, task.completed ? 'is-done' : ''); const label = node('label'); const check = node('input'); check.type = 'checkbox'; check.checked = task.completed === 1;
      check.addEventListener('change', () => mutate(check, () => api('/tasks/' + task.id, 'PATCH', {completed:check.checked})));
      const text = node('span', task.title); const parent = state.projects.find(p => p.id === task.project_id); if (parent) text.append(node('small', parent.name, 'studio-project-tag'));
      label.append(check, text); const remove = node('button', '×', 'studio-remove'); remove.type = 'button'; remove.setAttribute('aria-label', '删除任务：' + task.title);
      remove.addEventListener('click', () => mutate(remove, () => api('/tasks/' + task.id, 'DELETE'))); li.append(label, remove); tasks.append(li);
    }
    $('studio-schedule').checked = state.schedules.length > 0;
    const schedule = state.schedules[0];
    const formatNext = schedule ? new Date(schedule.next_run * 1000).toLocaleString('zh-CN', {timeZone:schedule.timezone || 'Asia/Shanghai'}) : '';
    $('studio-schedule-note').textContent = schedule ? '下次运行 ' + formatNext + ' · ' + (schedule.timezone || 'Asia/Shanghai') : '自动摘要已暂停。保存规则即可重新启用。';
    $('studio-cron-current').textContent = schedule ? '当前生效：' + (schedule.expression || '固定间隔 ' + schedule.interval_seconds + ' 秒') + ' · ' + (schedule.timezone || '间隔调度') : '当前未启用自动摘要。';
    if (!cronLoaded) { if (schedule?.expression) { $('studio-cron-expression').value = schedule.expression; $('studio-cron-zone').value = schedule.timezone; } cronLoaded = true; }
    const history = $('studio-migration-history'); history.replaceChildren();
    for (const m of state.migrations) { const row = node('div', undefined, 'studio-migration-row'); row.append(node('code',m.version),node('span','批次 ' + m.batch),node('span',new Date(m.applied_at*1000).toLocaleString('zh-CN')),node('span','已应用','job-badge job-completed')); history.append(row); }
    const reports = $('studio-reports'); reports.replaceChildren();
    if (!state.reports.length) reports.append(node('p', '第一份摘要，等你来生成。', 'studio-empty'));
    for (const report of state.reports) { const card = node('div', undefined, 'studio-report'); card.append(node('span', when(report.created), 'mono'), node('strong', report.completed + ' / ' + report.total + ' 已完成'), node('p', report.total ? '每一步，都值得被看见。' : '先记录任务，再慢慢把它们实现。')); reports.append(card); }
    const jobs = $('studio-jobs'); jobs.replaceChildren();
    const names = {pending:'等待执行', running:'执行中', completed:'已完成', failed:'失败'};
    if (!state.jobs.length) jobs.append(node('p', '提交一份摘要，看看后台如何接力。', 'studio-empty'));
    for (const job of state.jobs) { const row = node('div', undefined, 'studio-job'); row.append(node('span', '#' + job.id, 'mono'), node('strong', '进展摘要'), node('span', names[job.state], 'job-badge job-' + job.state), node('span', '执行 ' + job.attempts + ' 次 · ' + when(job.available), 'studio-hint')); if (job.state === 'failed') { const retry = node('button', '重试', 'studio-quiet'); retry.addEventListener('click', () => mutate(retry, () => api('/jobs/' + job.id + '/retry', 'POST', {}))); row.append(retry); } jobs.append(row); }
    $('studio-sync').textContent = '已同步 · ' + new Date().toLocaleTimeString('zh-CN');
  }
  async function refresh() {
    if (refreshing) return; refreshing = true; clearTimeout(timer);
    try { state = await api('/state' + (project ? '?project=' + encodeURIComponent(project) : '')); csrf = state.user.csrf; render(); }
    catch (error) { if (error.status === 401) signedOut(); else { message(error.name === 'AbortError' ? '连接超时，请稍后重试。' : error.message, true); if (!state) $('studio-auth').hidden = false; } }
    finally { refreshing = false; if (state) timer = setTimeout(refresh, 3000); }
  }
  async function mutate(button, action, success = '') {
    button.disabled = true; clearTimeout(timer);
    try { await action(); message(success); await refresh(); }
    catch (error) { if (error.status === 401) signedOut(); message(error.name === 'AbortError' ? '连接超时，请检查结果后重试。' : error.message, true); if (state) await refresh(); }
    finally { button.disabled = false; }
  }
  document.querySelectorAll('[data-auth-mode]').forEach(button => button.addEventListener('click', () => {
    mode = button.dataset.authMode; document.querySelectorAll('[data-auth-mode]').forEach(b => b.setAttribute('aria-pressed', String(b === button)));
    $('studio-name-label').hidden = mode === 'login'; $('studio-auth-form').elements.name.required = mode === 'register'; $('studio-auth-form').elements.password.autocomplete = mode === 'register' ? 'new-password' : 'current-password'; $('studio-auth-submit').textContent = mode === 'register' ? '创建我的工作室 ↗' : '回到工作室 ↗'; message('');
  }));
  $('studio-auth-form').addEventListener('submit', e => { e.preventDefault(); const form = e.currentTarget; const body = {email:form.elements.email.value, password:form.elements.password.value}; if (mode === 'register') body.name = form.elements.name.value; mutate($('studio-auth-submit'), async () => { await api('/' + mode, 'POST', body); form.elements.password.value = ''; }); });
  $('studio-task-form').addEventListener('submit', e => { e.preventDefault(); mutate(e.currentTarget.querySelector('button'), async () => { const body = {title:$('studio-title').value}; if ($('studio-task-project').value) body.project_id = Number($('studio-task-project').value); await api('/tasks', 'POST', body); $('studio-title').value = ''; }); });
  $('studio-logout').addEventListener('click', () => mutate($('studio-logout'), async () => { await api('/logout', 'POST', {}); signedOut(); }));
  $('studio-report').addEventListener('click', () => mutate($('studio-report'), () => api('/reports', 'POST', {delay:0}), '已交给后台处理，你可以继续编辑清单。'));
  $('studio-delayed').addEventListener('click', () => mutate($('studio-delayed'), () => api('/reports', 'POST', {delay:10}), '已排入队列，将在约 10 秒后执行。'));
  const cronBody = () => ({expression:$('studio-cron-expression').value.trim(),timezone:$('studio-cron-zone').value});
  $('studio-schedule').addEventListener('change', () => mutate($('studio-schedule'), () => $('studio-schedule').checked ? api('/cron','POST',cronBody()) : api('/schedule', 'POST', {enabled:false})));
  $('studio-project-form').addEventListener('submit', e => { e.preventDefault(); mutate(e.currentTarget.querySelector('button'), async () => { await api('/projects','POST',{name:$('studio-project-name').value}); $('studio-project-name').value = ''; },'项目已创建，现在可以把任务放进来。'); });
  $('studio-project-filter').addEventListener('change', () => { project = $('studio-project-filter').value; $('studio-task-project').value = project; refresh(); });
  document.querySelectorAll('[data-cron]').forEach(button => button.addEventListener('click', () => { $('studio-cron-expression').value = button.dataset.cron; $('studio-cron-preview').replaceChildren(); }));
  $('studio-cron-form').addEventListener('submit', e => { e.preventDefault(); mutate(e.currentTarget.querySelector('button'), async () => { const result = await api('/cron/preview','POST',cronBody()); const list = $('studio-cron-preview'); list.replaceChildren(); result.times.forEach(time => list.append(node('li',time.replace('T',' ')))); }); });
  $('studio-cron-save').addEventListener('click', () => mutate($('studio-cron-save'),() => api('/cron','POST',cronBody()),'Cron 规则已保存，后台会按预览的节奏执行。'));
  $('studio-migration-demo').addEventListener('click', () => mutate($('studio-migration-demo'),async () => { const result = await api('/migrations/demo','POST',{}); const list = $('studio-migration-events'); list.replaceChildren(); result.events.forEach(event => list.append(node('li',event))); list.append(node('li','最终记录：' + result.record.title + ' · color=' + result.record.color)); },'迁移实验完成，工作室数据库未被修改。'));
  document.addEventListener('visibilitychange', () => { if (document.hidden) clearTimeout(timer); else refresh(); });
  refresh();
})();
