const assert=require('node:assert/strict'),fs=require('node:fs');
const {chromium}=require('playwright');
(async()=>{
 const origin=process.env.CLASSROOM_BROWSER_URL;assert(/^http:\/\/127\.0\.0\.1:\d+$/.test(origin));
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});const results=[];
 try{
  for(const mode of ['student','facilitator'])for(const viewport of [{width:1440,height:900},{width:390,height:844}]){
   console.log('CLASSROOM_BROWSER_CASE '+JSON.stringify({mode,viewport}));
   const context=await browser.newContext({viewport});let excluded=0;const errors=[];
   await context.route('**/*',async route=>{const u=new URL(route.request().url());if(u.origin!==origin){excluded++;return route.abort();}if(u.pathname.startsWith('/API/')&&!['/API/student/workspace.php','/API/profile/settings.php'].includes(u.pathname)){excluded++;return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,user:{},settings:{},notifications:[],preferences:{}})});}return route.continue();});
   await context.addInitScript(()=>{window.__long=[];window.__shifts=[];new PerformanceObserver(l=>window.__long.push(...l.getEntries().map(e=>e.duration))).observe({type:'longtask',buffered:true});new PerformanceObserver(l=>window.__shifts.push(...l.getEntries().filter(e=>!e.hadRecentInput).map(e=>e.value))).observe({type:'layout-shift',buffered:true});});
   const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));const cdp=await context.newCDPSession(page);await cdp.send('Performance.enable');
   await page.goto(origin+'/fixture/seed?uid='+(mode==='student'?1:900));const start=Date.now();
   await page.goto(origin+'/modules/'+mode+'/dashboard.php'+(mode==='facilitator'?'?server_id=1':''),{waitUntil:'load'});
   await page.waitForFunction(()=>document.querySelector('#lw-view').getAttribute('aria-busy')==='false');assert.equal(await page.locator('#lw-status').textContent(),'');
   const initial=await page.evaluate(()=>({navigation:performance.getEntriesByType('navigation')[0].toJSON(),nodes:document.querySelectorAll('*').length,heap:performance.memory?.usedJSHeapSize??null,longTasks:window.__long,layoutShift:window.__shifts.reduce((a,b)=>a+b,0)}));const ready_ms=Date.now()-start;
   const section=mode==='student'?'notes':'members';await page.evaluate(section=>document.querySelector('nav [data-page="'+section+'"]').click(),section);
   await page.evaluate(()=>{window.__mutations=0;window.__observer=new MutationObserver(r=>window.__mutations+=r.length);window.__observer.observe(document.querySelector('#lw-view'),{childList:true,subtree:true});});
   const before=(await cdp.send('Performance.getMetrics')).metrics,samples=[];
   for(let i=0;i<10;i++)samples.push(await page.evaluate(async i=>{const t=performance.now();const button=[...document.querySelectorAll('.lw-pagination button')].find(n=>n.textContent===(i%2?'Previous':'Next'));if(!button||button.disabled)throw new Error('Pagination fixture missing');button.click();document.querySelector('#lw-view').getBoundingClientRect();await new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)));return performance.now()-t;},i));
   await page.locator('#lw-search').fill(mode==='student'?'Note 199':'Student 1');assert((await page.locator('#lw-view').textContent()).includes(mode==='student'?'Note 199':'Student 1'));if(mode==='student')assert.equal(await page.locator('#lw-view .lw-row').count(),1,'Search narrows the retained note');await page.locator('#lw-search').fill('');
   await page.locator('#lw-refresh').click();await page.waitForFunction(()=>document.querySelector('#lw-view').getAttribute('aria-busy')==='false');assert.equal(await page.locator('#lw-status').textContent(),'');
   await page.keyboard.press('/');assert(await page.locator('#lw-search').evaluate(n=>n===document.activeElement));
   if(viewport.width<600){await page.locator('#lw-menu').click();assert.equal(await page.locator('#lw-menu').getAttribute('aria-expanded'),'true');await page.locator('nav [data-page="'+section+'"]').click();assert.equal(await page.locator('#lw-menu').getAttribute('aria-expanded'),'false');}
   const after=(await cdp.send('Performance.getMetrics')).metrics,delta={};for(const name of ['TaskDuration','ScriptDuration','LayoutDuration','RecalcStyleDuration'])delta[name]=(after.find(x=>x.name===name)?.value??0)-(before.find(x=>x.name===name)?.value??0);
   const final=await page.evaluate(()=>{window.__observer.disconnect();return{nodes:document.querySelectorAll('*').length,mutations:window.__mutations,overflow:document.documentElement.scrollWidth>innerWidth+1,heap:performance.memory?.usedJSHeapSize??null,longTasks:window.__long};});assert(!final.overflow,'No horizontal document overflow');assert.deepEqual(errors,[]);
   results.push({mode,viewport,ready_ms,initial,delta,pagination_samples_ms:samples,final,excludedRequests:excluded,errors,browser:browser.version()});await context.close();
  }
  const evidence={scope:'Actual PHP-rendered student/facilitator pages, local CSS/JS and owned FPM/MariaDB workspace API; unrelated APIs/CDN blocked/stubbed, not mobile hardware or a full chat shell',results};fs.writeFileSync(process.env.CLASSROOM_BROWSER_EVIDENCE,JSON.stringify(evidence,null,2)+'\n');console.log('CLASSROOM_BROWSER_EVIDENCE '+JSON.stringify(evidence));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
