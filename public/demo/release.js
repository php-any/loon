(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  if (!$('release-app')) return;
  let state = null, csrf = '', timer, busy = false, polling = false, taskKey = '', exchangeKey = '', disconnected = false, revision = 0;
  const trace = [];
  const element = (tag, text, className) => { const el=document.createElement(tag); if(text!==undefined) el.textContent=text; if(className) el.className=className; return el; };
  const when = value => new Date(Number(value)*1000).toLocaleString('zh-CN',{hour12:false});
  async function api(path='', method='GET', body) {
    const start=performance.now(), abort=new AbortController(), timeout=setTimeout(()=>abort.abort(),10000);
    try {
      const response=await fetch('/api/demo/release'+path,{method,credentials:'same-origin',cache:'no-store',signal:abort.signal,headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:body===undefined?undefined:JSON.stringify(body)});
      const data=await response.json();
      if(method!=='GET' || !response.ok) { trace.push({at:new Date().toISOString(),method,path:'/api/demo/release'+path,status:response.status,ms:Math.round(performance.now()-start)}); if(trace.length>50) trace.shift(); }
      if(!response.ok) { const error=new Error(data.title || '操作失败'); error.status=response.status; throw error; }
      return data;
    } finally { clearTimeout(timeout); }
  }
  function render() {
    $('release-welcome').hidden=!!state; $('release-workspace').hidden=!state;
    if(!state) return;
    csrf=state.csrf;
    const tasks=state.tasks, done=tasks.filter(t=>Number(t.completed)===1).length, ready=tasks.length>0 && done===tasks.length;
    const latest=state.jobs[0], pending=state.jobs.some(j=>['pending','running'].includes(j.state)), reports=state.reports;
    const exchanges=(state.exchanges||[]).filter(item=>Number(item.job_id)===Number(latest?.id));
    const evidence=$('release-exchanges'), nextExchangeKey=JSON.stringify(exchanges);
    if(exchangeKey!==nextExchangeKey) {
    exchangeKey=nextExchangeKey; evidence.replaceChildren();
    for(const item of [...exchanges].reverse()) {
      const response=JSON.parse(item.response), card=element('details',undefined,'release-exchange');
      const passed=Number(item.ok)===1, decision=item.protocol==='grpc'&&passed?response.ready:null;
      const summary=element('summary',item.protocol.toUpperCase()+' · '+(item.protocol==='tcp'?'读取验收快照':'执行发布规则')+' · '+(!passed?'调用失败':decision===false?'规则未通过':'成功')+' · '+Number(item.elapsed_ms).toFixed(1)+' ms');
      card.append(summary,element('p','任务 #'+item.job_id+' · '+when(item.created)),element('pre',JSON.stringify({request:JSON.parse(item.request),response},null,2)));evidence.append(card);
    }
    }
    const policy=exchanges.find(item=>item.protocol==='grpc'&&Number(item.ok)===1);
    const rejected=policy&&JSON.parse(policy.response).ready===false;
    $('release-chain-status').textContent=!latest?'提交第一份摘要后，在这里观察真实调用。':latest.state==='completed'?exchanges.length?'TCP 快照 → gRPC 规则通过 → 摘要已保存。':'历史摘要没有协议记录，请重新生成体验新流程。':rejected?'gRPC 已拒绝交付：验收未全部完成，当前不生成摘要。恢复验收后，后台重试或重新提交可再次校验。':exchanges.some(item=>!Number(item.ok))?'协议调用失败，尚未交付。后台最多尝试 3 次；恢复服务后可重试。':exchanges.length?'业务服务正在执行，等待交付结果。':'任务 #'+latest.id+' 已入队，等待后台调用 TCP 和 gRPC。';
    const step=!state.project?0:!ready?1:!reports.length?2:3;
    $('release-space').textContent='体验空间 #'+state.workspace.id+' · 有效至 '+when(state.workspace.expires);
    [...$('release-steps').children].forEach((li,i)=>{li.classList.toggle('done',i<step || (i===3 && !!state.schedule));li.classList.toggle('current',i===step && !(i===3 && state.schedule));});
    $('release-project-title').textContent=state.project?.name || '给这次发布起个名字';
    $('release-project-id').textContent=state.project?'PROJECT #'+state.project.id:'尚未创建';
    $('release-project-form').hidden=!!state.project; $('release-plan').hidden=!state.project;
    $('release-task-progress').textContent=done+' / '+tasks.length+' 已验收'; $('release-progress').max=tasks.length||3; $('release-progress').value=done;
    const key=JSON.stringify(tasks);
    if(key!==taskKey) {
      taskKey=key; const list=$('release-tasks');list.replaceChildren();
      for(const task of tasks) {
        const label=element('label',undefined,'release-task'+(Number(task.completed)?' is-done':'')), check=element('input');
        check.type='checkbox';check.checked=Number(task.completed)===1;check.dataset.taskId=task.id;
        check.addEventListener('change',()=>mutate(()=>api('/tasks/'+task.id,'PATCH',{completed:check.checked})));
        label.append(check,element('span',task.title),element('small','#'+task.id));list.append(label);
      }
    }
    $('release-task-evidence').textContent=tasks.length?'已从服务器读取 '+tasks.length+' 条任务，刷新页面后仍可继续。':'';
    $('release-report').disabled=busy||!ready||pending;
    $('release-report').textContent=pending?'摘要正在后台处理…':reports.length?'重新生成发布摘要 ↗':'提交发布摘要 ↗';
    const jobNames={pending:'等待领取',running:'执行中',completed:'已完成',failed:'执行失败'};
    $('release-delivery-status').textContent=latest?jobNames[latest.state]:ready?'可以提交':'等待验收';
    const job=$('release-job');job.replaceChildren();
    if(latest) {
      const top=element('div',undefined,'release-job-state');top.append(element('strong','任务 #'+latest.id),element('span',jobNames[latest.state]));job.append(top);
      job.append(element('p','提交 '+when(latest.created)+' · 可执行 '+when(latest.available)+' · 已尝试 '+latest.attempts+' 次'));
      if(latest.state==='pending') job.append(element('p',Number(latest.available)>state.now?'延迟尚未到期，后台不会提前领取。':'已到执行时间，正在等待队列消费者。'));
      if(latest.error) job.append(element('p','最近错误：'+latest.error));
    } else job.append(element('p',ready?'清单已就绪。提交后会产生一个真实的后台任务。':'完成三项验收，开启下一步。'));
    const reportList=$('release-reports');reportList.replaceChildren();
    for(const report of reports.slice(0,3)) {
      const card=element('article',undefined,'release-report');card.append(element('small','发布准备摘要 #'+report.id+' · 来自任务 #'+report.job_id),element('strong',report.completed+' / '+report.total+' 项验收完成'),element('p','后台生成于 '+when(report.created)));reportList.append(card);
    }
    $('release-schedule').disabled=busy||!reports.length;
    $('release-schedule').textContent=state.schedule?'暂停自动回顾':'启用自动回顾';
    $('release-schedule-status').textContent=state.schedule?'每 15 秒':state.events.some(event=>event.kind==='schedule')?'已暂停':'尚未启用';
    $('release-next').textContent=state.schedule?'下次计划 '+when(state.schedule.next_run):reports.length?'已有摘要，可以启用周期回顾':'先生成第一份摘要';
    const next=[['建立发布计划','给这次发布命名，生成三项验收任务。'],['逐项完成验收','勾选已完成的事项。每次变更都保存到服务器并记录活动。'],['提交并等待摘要','任务延迟 5 秒后由后台领取。只有报告真正生成，流程才会继续。'],['保持团队同步',state.schedule?'自动回顾已启用。下一份摘要将由调度器自动入队，无需再次点击。':'第一份发布摘要已交付。启用周期回顾，观察自动调度完整运行。']][step];
    $('release-next-title').textContent=next[0];$('release-next-description').textContent=next[1];
    const checks=$('release-checks');checks.replaceChildren();
    for(const [label,ok] of [['项目与任务已保存',!!state.project],['发布验收已完成',ready],['后台摘要已交付',reports.length>0],['自动回顾已配置',!!state.schedule]]) {const row=element('div',undefined,'release-check');row.append(element('span',label),element('b',ok?'✓ 已确认':'等待'));checks.append(row);}
    const events=$('release-events');events.replaceChildren();
    for(const event of state.events.slice(0,12)) {const li=element('li',event.detail);li.append(element('time',when(event.created)));events.append(li);}
    $('release-sync').textContent=disconnected?'连接中断':'同步 '+new Date(state.now*1000).toLocaleTimeString('zh-CN');
    const services=$('release-services');services.replaceChildren();
    for(const [name,label] of [['queue','队列消费者'],['scheduler','调度器'],['timer','5 秒定时器']]) {const service=state.services.find(s=>s.name===name);services.append(element('div',label+'：'+(service?.active?'近期有心跳':'未收到近期心跳')));}
    $('release-trace').textContent=trace.length?JSON.stringify(trace,null,2):'本页暂无变更请求';
    document.querySelectorAll('#release-tasks input').forEach(input=>input.disabled=busy);
    $('release-refresh').disabled=busy; $('release-export').disabled=busy;
  }
  function schedulePoll() { clearTimeout(timer);if(state&&!document.hidden) timer=setTimeout(refresh,2000); }
  async function refresh() {
    if(busy||polling) return;
    polling=true;
    const started=revision;
    try {const result=await api();if(started===revision&&!busy){state=result;disconnected=false;render();}}
    catch(error) { if(started!==revision||busy) return; if(error.status===401) {state=null;csrf='';taskKey='';render();} else {disconnected=true;$('release-sync').textContent='连接中断 · 当前为上次同步结果';} }
    finally {polling=false;schedulePoll();}
  }
  async function mutate(action) {
    if(busy) return;
    revision++;
    busy=true;clearTimeout(timer);$('release-message').textContent='';$('release-start').disabled=true;render();
    try {state=await action();disconnected=false;}
    catch(error) {taskKey='';$('release-message').textContent=error.name==='AbortError'?'请求超时，操作结果尚未确认。请同步状态后再试。':error.message;}
    finally {busy=false;$('release-start').disabled=false;render();schedulePoll();}
  }
  $('release-start').addEventListener('click',()=>mutate(()=>api('/start','POST',{})));
  $('release-project-form').addEventListener('submit',event=>{event.preventDefault();mutate(()=>api('/project','POST',{name:$('release-project-name').value}));});
  $('release-report').addEventListener('click',()=>mutate(()=>api('/report','POST',{})));
  $('release-schedule').addEventListener('click',()=>mutate(()=>api('/schedule','POST',{enabled:!state.schedule})));
  $('release-refresh').addEventListener('click',refresh);
  $('release-export').addEventListener('click',()=>{
    const {csrf:ignored,...record}=state;
    const report={exportedAt:new Date().toISOString(),scenario:'发布准备室',...record,requests:trace};
    const url=URL.createObjectURL(new Blob([JSON.stringify(report,null,2)],{type:'application/json'}));
    const link=element('a');link.href=url;link.download='loom-release-'+state.workspace.id+'.json';document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
  });
  document.addEventListener('visibilitychange',()=>{if(document.hidden) clearTimeout(timer);else refresh();});
  refresh();
})();
