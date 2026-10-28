'use strict';
const {chromium}=require('playwright'),fs=require('fs'),assert=require('assert/strict');
const origin=process.env.PHASE3AD_ORIGIN,actors=JSON.parse(process.env.PHASE3AD_ACTORS),fixture=JSON.parse(process.env.PHASE3AD_FIXTURE),target=process.env.PHASE3AD_BROWSER_OUT;
let browser;const evidence={status:'NOT RUN',scope:'Actual role pages/JS and real FPM APIs,real login;no auth or application API stubs;external hosts blocked',cases:[]};
(async()=>{browser=await chromium.launch({headless:true,args:['--no-sandbox']});evidence.version=browser.version();
for(const i of [0,2])for(const viewport of [{width:1440,height:900},{width:390,height:844}]){
 const actor=actors[i],context=await browser.newContext({viewport}),errors=[];await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());const page=await context.newPage();page.on('pageerror',e=>errors.push(e.name+': '+e.message.slice(0,150)));
 try{
 await page.goto(origin+'/modules/auth/login.php',{waitUntil:'domcontentloaded'});await page.locator('#email').fill(actor.email);await page.locator('#password').fill('owned-fixture-password-42');await page.locator('#loginBtn').click();await page.waitForURL(u=>!u.pathname.endsWith('/auth/login.php'));
 const response=await page.goto(origin+'/modules/'+actor.role+'/dashboard.php'+(actor.role==='facilitator'?'?server_id='+fixture.server:''),{waitUntil:'domcontentloaded'});assert.equal(response.status(),200);await page.waitForFunction(()=>document.querySelector('#lw-view')?.getAttribute('aria-busy')==='false');assert.equal(await page.locator('#lw-status').textContent(),'');
 const data=await page.evaluate(async()=>await(await fetch('/API/dashboard/live.php')).json());assert.equal(data.success,true);assert.equal(data.role,actor.role);
 const section=actor.role==='student'?'notes':'members';if(viewport.width<600)await page.locator('#lw-menu').click();await page.locator('nav [data-page="'+section+'"]').click();assert(await page.locator('#lw-view').isVisible());await page.locator('#lw-refresh').click();await page.waitForFunction(()=>document.querySelector('#lw-view')?.getAttribute('aria-busy')==='false');assert.equal(await page.locator('#lw-status').textContent(),'');
 if(viewport.width<600){await page.locator('#lw-menu').click();assert.equal(await page.locator('#lw-menu').getAttribute('aria-expanded'),'true');await page.locator('nav [data-page="'+section+'"]').click();assert.equal(await page.locator('#lw-menu').getAttribute('aria-expanded'),'false');}
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);assert.deepEqual(errors,[]);evidence.cases.push({role:actor.role,viewport,status:'PASS',actual_auth:true,workspace_refresh:true,role_dashboard_api:true,page_errors:errors});
 }finally{await context.close();}
}
evidence.status='PASS';})().catch(e=>{evidence.status='FAIL';evidence.error={name:e.name,message:String(e.message).replace(/[A-Za-z0-9_.+-]+@[A-Za-z0-9.-]+/g,'<redacted>').slice(0,1000)};process.exitCode=1;}).finally(async()=>{if(browser)await browser.close();fs.writeFileSync(target,JSON.stringify(evidence,null,2));console.log(evidence.status);});
