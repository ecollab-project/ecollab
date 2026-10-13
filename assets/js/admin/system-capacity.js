'use strict';
(() => {
  const page = document.getElementById('page-capacity');
  if (!page) return;
  const endpoint = (window.ECOLLAB_BASE || '') + '/API/admin/system-capacity.php';
  const el = id => document.getElementById(id);
  const number = value => value === null || value === undefined ? '—' : Number(value).toLocaleString(undefined,{maximumFractionDigits:2});
  let data = null, chart = null, busy = false, saving = false;
  const message = (text,error=false) => { el('capacityMessage').textContent=text; el('capacityMessage').dataset.error=String(error); };
  const cells = (body, rows) => {
    body.replaceChildren();
    for (const row of rows) {
      const tr = document.createElement('tr');
      for (const value of row) { const td=document.createElement('td'); td.textContent=String(value); tr.append(td); }
      body.append(tr);
    }
  };
  function forecast() {
    const raw=el('capacityExpected').value;
    if (raw === '' || !data?.baseline) { el('capacityForecast').textContent='Enter an expected user count to compare with the capacity reference.'; return; }
    const expected=Number(raw), tested=Number(data.baseline.tested_users), target=data.settings.operational_target;
    if (!Number.isSafeInteger(expected) || expected < 0 || expected > 1000000) { el('capacityForecast').textContent='Enter a whole number from 0 to 1,000,000.'; return; }
    const remaining=tested-expected;
    el('capacityForecast').textContent=`${number(expected)} users = ${number(expected/tested*100)}% of the tested reference. `+
      (remaining < 0 ? `${number(-remaining)} above tested capacity.` : `${number(remaining)} below tested capacity.`)+
      (target !== null && expected > target ? ' Above your operational target.' : '');
  }
  function render(next) {
    data=next;
    el('capacityActive').textContent=number(data.current.active_users);
    el('capacityTested').textContent=number(data.baseline?.tested_users);
    el('capacityWorkload').textContent=data.baseline ? `${data.baseline.workload} · ${data.baseline.tested_on}` : 'No completed, passed reference';
    el('capacityTarget').textContent=data.settings.operational_target === null ? 'Not set' : number(data.settings.operational_target);
    el('capacityPeak').textContent=number(data.history.peak_users);
    el('capacityPeakAt').textContent=data.history.peak_at ? new Date(data.history.peak_at).toLocaleTimeString('en-PH',{timeZone:'Asia/Manila',hour:'2-digit',minute:'2-digit'})+' · Asia/Manila' : 'No samples yet';
    el('capacityUsageText').textContent=data.capacity_percent === null ? 'No tested reference available' : `${number(data.current.active_users)} / ${number(data.baseline.tested_users)} tested users · ${number(data.capacity_percent)}%`;
    el('capacityUsage').value=Math.min(100,data.capacity_percent ?? 0);
    el('capacityPresence').textContent=`${number(data.current.presence_users)} unique users with presence in the last 10 minutes. This page refreshes every 30 seconds while visible.`;
    if (document.activeElement !== el('capacityTargetInput')) el('capacityTargetInput').value=data.settings.operational_target ?? '';
    const hours=Array.from({length:24},(_,i)=>String(i).padStart(2,'0')+':00');
    const stale=!data.history.last_sample_at || Date.now()-Date.parse(data.history.last_sample_at)>150000;
    el('capacityCoverage').textContent=`${number(data.history.samples_today)} minute samples today. `+
      (stale ? 'Collector has no recent sample; enable or check the minute collector. ' : 'Collector is reporting. ')+
      'Missing samples are gaps, not zero users. Peaks are sampled estimates, not an exact maximum.';
    cells(el('capacityHourly'),hours.map((h,i)=>[h,number(data.history.hourly_peaks[i]),number(data.history.hourly_samples[i])]));
    if (window.Chart) {
      if (!chart) chart=new Chart(el('capacityChart'),{type:'bar',data:{labels:hours,datasets:[{label:'Sampled peak users',data:data.history.hourly_peaks,backgroundColor:'#8b5cf6'}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});
      else { chart.data.datasets[0].data=data.history.hourly_peaks; chart.update('none'); }
    }
    cells(el('capacityRecords'),data.records.map(r=>[r.tested_on,number(r.tested_users),r.result+(r.completed==='1'||r.completed===1?' · completed':' · incomplete/unverified')+(String(r.id)===String(data.baseline?.id)?' · reference':''),r.http_p95_ms===null?'—':number(r.http_p95_ms)+' ms',r.delivery_p95_ms===null?'—':number(r.delivery_p95_ms)+' ms',r.workload+'\n'+r.notes]));
    cells(el('capacityAudit'),data.audit.length ? data.audit.map(r=>[r.created_at,r.actor_id,r.baseline_test_id,r.operational_target ?? 'Not set']) : [['No settings edits recorded','—','—','—']]);
    forecast();
  }
  async function request(body) {
    const options={credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}};
    if (body) { options.method='POST'; options.headers['Content-Type']='application/json'; options.headers['X-CSRF-Token']=document.querySelector('meta[name="csrf-token"]')?.content || ''; options.body=JSON.stringify(body); }
    const controller=new AbortController(), timer=setTimeout(()=>controller.abort(),15000); options.signal=controller.signal;
    try {
      const response=await fetch(endpoint,options); let result;
      try { result=await response.json(); } catch (_) { throw new Error('Capacity response unavailable. Refresh and check your session.'); }
      if (!response.ok || !result.success) throw new Error(result.error || 'Capacity request failed');
      return result.data;
    } finally { clearTimeout(timer); }
  }
  async function refresh() {
    if (busy || saving || document.hidden || !page.classList.contains('active')) return;
    busy=true;
    try { render(await request()); message('Updated '+new Date().toLocaleTimeString()+' · synthetic test accounts excluded'); }
    catch (error) { message(error.message,true); }
    finally { busy=false; }
  }
  async function save(form,body) {
    if (!data || busy || saving) return;
    saving=true; const button=form.querySelector('button[type=submit]'); button.disabled=true;
    try { render(await request({...body,version:data.settings.version})); message('Saved. Capacity references do not impose login limits.'); }
    catch (error) { message(error.message,true); }
    finally { saving=false; button.disabled=false; }
  }
  el('capacitySettings').addEventListener('submit',event=>{event.preventDefault();save(event.currentTarget,{action:'settings',operational_target:el('capacityTargetInput').value});});
  el('capacityRecord').addEventListener('submit',event=>{
    event.preventDefault(); const form=event.currentTarget, body=Object.fromEntries(new FormData(form));
    body.completed=form.elements.completed.checked; body.set_baseline=form.elements.set_baseline.checked;
    if (body.set_baseline && (body.result!=='passed' || !body.completed)) { message('Only completed, passed tests can become the reference.',true); return; }
    save(form,{...body,action:'record'});
  });
  el('capacityExpected').addEventListener('input',forecast);
  el('capacityRefresh').addEventListener('click',refresh);
  const date=el('capacityRecord').elements.tested_on;
  date.value=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Manila',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date()); date.max=date.value;
  new MutationObserver(()=>{if(page.classList.contains('active')){chart?.resize();refresh();}}).observe(page,{attributes:true,attributeFilter:['class']});
  document.addEventListener('visibilitychange',refresh);
  setInterval(refresh,30000); refresh();
})();
