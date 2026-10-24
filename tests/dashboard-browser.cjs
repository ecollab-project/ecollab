const assert=require('node:assert/strict'),fs=require('node:fs'),cp=require('node:child_process');
const {chromium}=require('playwright');
const oldRef='87e82284ced2cee4858b673cc5a8619151c019bb',priorRef='9d5ded1af8ee55364fa87d9c9a05327ffb9193c9';
const old=cp.execFileSync('git',['show',oldRef+':assets/js/learning/workspace.js'],{encoding:'utf8'}),prior=cp.execFileSync('git',['show',priorRef+':assets/js/learning/workspace.js'],{encoding:'utf8'});
assert.equal(old,prior,'#79/#80 current workspace script is byte-identical; their legacy-script change is not loaded here');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});const results=[];
 try{
 for(const viewport of [{width:1440,height:900},{width:390,height:844}])for(const variant of ['baseline','candidate']){
  const url=process.env[variant==='baseline'?'DASHBOARD_BEFORE_URL':'DASHBOARD_AFTER_URL'];assert(/^http:\/\/127\.0\.0\.1:\d+$/.test(url));
  const context=await browser.newContext({viewport});let excludedRequests=0;const errors=[];
  await context.route('**/*',async route=>{const parsed=new URL(route.request().url());if(parsed.origin!==url){excludedRequests++;return route.abort();}if(parsed.pathname.startsWith('/API/')&&parsed.pathname!=='/API/student/workspace.php'){excludedRequests++;return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,settings:{},preferences:{},user:{},profile:{},notifications:[],users:[],conversations:[],unread_count:0})});}return route.continue();});
  await context.addInitScript(()=>{
   window.__listenerCounts={};const add=EventTarget.prototype.addEventListener;EventTarget.prototype.addEventListener=function(type,...args){if(this.id){const k=this.id+':'+type;window.__listenerCounts[k]=(window.__listenerCounts[k]||0)+1;}return add.call(this,type,...args);};
   window.__perf={longTasks:[],shifts:[]};new PerformanceObserver(list=>window.__perf.longTasks.push(...list.getEntries().map(e=>({start:e.startTime,duration:e.duration})))).observe({type:'longtask',buffered:true});new PerformanceObserver(list=>window.__perf.shifts.push(...list.getEntries().filter(e=>!e.hadRecentInput).map(e=>e.value))).observe({type:'layout-shift',buffered:true});
   const Original=Intl.DateTimeFormat;window.__dateConstructs=0;Intl.DateTimeFormat=new Proxy(Original,{construct(target,args){window.__dateConstructs++;return Reflect.construct(target,args);}});
  });
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));const cdp=await context.newCDPSession(page);await cdp.send('Performance.enable');
  await page.goto(url+'/fixture/seed');const start=Date.now();await page.goto(url+'/modules/student/dashboard.php',{waitUntil:'load'});await page.locator('#lw-view').getByText('Welcome back, Fixture.').waitFor();await page.waitForFunction(()=>document.querySelector('#lw-view').getAttribute('aria-busy')==='false');
  const initial=await page.evaluate(()=>({navigation:performance.getEntriesByType('navigation')[0].toJSON(),longTasks:window.__perf.longTasks,layoutShift:window.__perf.shifts.reduce((a,b)=>a+b,0),nodes:document.querySelectorAll('*').length,heap:performance.memory?.usedJSHeapSize??null,chartCanvases:document.querySelectorAll('canvas').length,scriptUrls:[...document.scripts].map(x=>x.src).filter(Boolean)}));
  assert(!initial.scriptUrls.some(s=>s.includes('/student/dashboard.js')),'actual page does not load legacy dashboard script');
  const startupWall=Date.now()-start;
  const before=(await cdp.send('Performance.getMetrics')).metrics;const startConstructs=await page.evaluate(()=>window.__dateConstructs);const timings=[];
  // Existing pagination and DOM actions; each operation includes a forced layout and a paint opportunity.
  await page.evaluate(()=>document.querySelector('nav [data-page="notes"]').click());
  for(let i=0;i<20;i++)timings.push(await page.evaluate(async i=>{const t=performance.now();const button=[...document.querySelectorAll('#lw-view .lw-pagination button')].find(b=>b.textContent===(i%2?'Previous':'Next'));button.click();document.querySelector('#lw-view').getBoundingClientRect();await new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)));return performance.now()-t;},i));
  assert.equal(await page.locator('#lw-view .lw-row').count(),20);await page.locator('#lw-search').fill('Note 24');assert.equal(await page.locator('#lw-view .lw-row').count(),1);await page.locator('#lw-search').fill('');
  await page.locator('#lw-refresh').click();await page.waitForFunction(()=>document.querySelector('#lw-view').getAttribute('aria-busy')==='false');assert.equal(await page.locator('#lw-view .lw-row').count(),20);
  await page.evaluate(()=>document.querySelector('nav [data-page="notifications"]').click());assert.equal(await page.locator('#lw-view .lw-row').count(),20);await page.evaluate(()=>document.querySelector('nav [data-page="activity"]').click());assert.equal(await page.locator('.lw-bars').count(),1);
  const after=(await cdp.send('Performance.getMetrics')).metrics;
  const delta={};for(const name of ['TaskDuration','ScriptDuration','LayoutDuration','RecalcStyleDuration','LayoutCount','RecalcStyleCount'])delta[name]=(after.find(m=>m.name===name)?.value??0)-(before.find(m=>m.name===name)?.value??0);
  const extra=await page.evaluate(()=>({constructs:window.__dateConstructs,longTasks:window.__perf.longTasks,layoutShift:window.__perf.shifts.reduce((a,b)=>a+b,0),nodes:document.querySelectorAll('*').length,listeners:window.__listenerCounts,overflow:document.documentElement.scrollWidth>innerWidth+1,heap:performance.memory?.usedJSHeapSize??null}));assert.equal(extra.listeners['lw-search:input'],1,'search listener registered once across refreshes');assert(!extra.overflow,'current viewport has no horizontal document overflow');
  await page.keyboard.press('/');assert.equal(await page.locator('#lw-search').evaluate(n=>n===document.activeElement),true);if(viewport.width<600){await page.locator('#lw-menu').click();assert.equal(await page.locator('#lw-menu').getAttribute('aria-expanded'),'true');await page.locator('nav [data-page="notes"]').click();assert.equal(await page.locator('#lw-menu').getAttribute('aria-expanded'),'false');}
  results.push({viewport,variant,browser:browser.version(),playwright:require('playwright/package.json').version,startup_wall_ms:startupWall,initial,interaction_metric_delta:delta,interaction_samples_ms:timings,formatter_constructions:extra.constructs-startConstructs,final:extra,excludedRequests,errors});
  await context.close();
 }
 for(const size of [1440,390]){const pair=results.filter(r=>r.viewport.width===size);assert.deepEqual(pair[1].errors,pair[0].errors,'candidate introduces no browser errors');}
 const evidence={scope:'Actual PHP-rendered current dashboard and local CSS/JS in headless Chromium, desktop/mobile viewports; actual isolated workspace API; unrelated APIs/CDN/media requests stubbed/blocked. Sequential synthetic fixtures, not mobile hardware or production network.',prior_script_identical:true,results};fs.writeFileSync(process.env.DASHBOARD_BROWSER_EVIDENCE,JSON.stringify(evidence,null,2)+'\n');console.log('DASHBOARD_BROWSER_EVIDENCE '+JSON.stringify(evidence));console.log(JSON.stringify(results.map(r=>({viewport:r.viewport,variant:r.variant,constructs:r.formatter_constructions,script_seconds:r.interaction_metric_delta.ScriptDuration,layout_seconds:r.interaction_metric_delta.LayoutDuration,errors:r.errors})),null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
