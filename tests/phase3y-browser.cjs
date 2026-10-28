'use strict';
// Actual application pages/JS/API. No traces, screenshots, credentials or tokens in evidence.
const fs=require('fs'),crypto=require('crypto'),assert=require('assert/strict');
const {chromium}=require('playwright');
const origin=process.env.PHASE3Y_BROWSER_ORIGIN,mail=process.env.PHASE3Y_BROWSER_MAIL;
const target=process.env.PHASE3Y_BROWSER_EVIDENCE;
const evidence={scope:'Actual signup/login HTML, loaded JS and FPM API; external fonts/CDN blocked; logout uses real browser fetch',status:'NOT RUN',steps:[],responses:[],external_hosts:[]};
let stage='availability',browser;
const hash=b=>crypto.createHash('sha256').update(b).digest('hex');
(async()=>{
 const executable=process.env.PHASE3Y_CHROMIUM||chromium.executablePath();
 if(!fs.existsSync(executable)){
  evidence.reason='Chromium executable unavailable';
  if(process.env.PHASE3Y_REQUIRE_BROWSER==='1')throw Error('required browser unavailable');
  return;
 }
 browser=await chromium.launch({headless:true,executablePath:executable,args:['--no-sandbox']});evidence.browser_version=browser.version();
 const context=await browser.newContext({viewport:{width:1280,height:900}});
 await context.route('**/*',route=>{const url=new URL(route.request().url());if(url.origin===origin)return route.continue();if(!evidence.external_hosts.includes(url.host))evidence.external_hosts.push(url.host);return route.abort();});
 const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push({name:e.name,message_sha256:hash(e.message)}));
 evidence.navigations=[];page.on('response',r=>{if(r.request().isNavigationRequest()&&new URL(r.url()).origin===origin)evidence.navigations.push({path:new URL(r.url()).pathname,status:r.status()});});
 const pending=[];page.on('response',r=>{if(new URL(r.url()).pathname.startsWith('/API/auth/'))pending.push((async()=>{const b=await r.body().catch(()=>Buffer.from(''));let j={};try{j=JSON.parse(b)}catch{}evidence.responses.push({path:new URL(r.url()).pathname,status:r.status(),response_sha256:hash(b),keys:Object.keys(j).sort(),success:j.success??null});})());});
 const email=process.env.PHASE3Y_BROWSER_EMAIL,password='owned-fixture-password-42';
 const sid=async()=>(await context.cookies()).find(c=>c.name==='PHPSESSID')?.value;
 stage='signup_page';await page.goto(origin+'/modules/auth/signup.php',{waitUntil:'domcontentloaded'});const before=await sid();
 evidence.signup_scripts=await page.locator('script[src]').evaluateAll(a=>a.map(s=>new URL(s.src).pathname));
 await page.locator('#fullName').fill('Owned Synthetic Actor');await page.locator('#email').fill(email);await page.locator('#password').fill(password);await page.locator('#confirmPass').fill(password);
 stage='request_signup_otp';await page.locator('#panel1 .signup-btn').click();await page.locator('#emailVerificationModal').waitFor({state:'visible'});
 const {code}=await(await fetch(mail+'/_owned_code?email='+encodeURIComponent(email))).json();assert.equal(typeof code,'string');
 stage='incorrect_signup_otp';await page.locator('#signupOtpInput').fill(code==='000000'?'111111':'000000');await page.locator('#signupVerifyBtn').click();await page.waitForFunction(()=>document.getElementById('signupOtpError').textContent.includes('Incorrect'));
 evidence.steps.push({name:'wrong OTP user-facing error',status:'PASS'});
 stage='correct_signup_otp';await page.locator('#signupOtpInput').fill(code);await page.locator('#signupVerifyBtn').click();await page.waitForFunction(()=>document.getElementById('panel2').classList.contains('active'));
 await page.locator('#course').selectOption({label:'BS Computer Science'});await page.locator('#year').selectOption({label:'2nd Year'});await page.locator('#panel2 .btn-next').click();await page.locator('[data-slug="solo-learning"]').click();await page.locator('#panel3 .btn-next').click();await page.locator('#panel4 .btn-next').click();await page.locator('#terms').check({force:true});
 stage='signup_submit';await page.locator('#submitBtn').click();await page.waitForURL('**/modules/onboarding/server-discovery.php',{timeout:20000});assert.notEqual(await sid(),before);
 evidence.steps.push({name:'real signup UI verification and authenticated onboarding transition',status:'PASS',session_rotated:true});
 const session=await page.evaluate(async()=>await(await fetch('/API/auth/validate-session.php')).json());assert.equal(session.authenticated,true);assert.equal(session.role,'student');
 const cookies=await context.cookies();const cookie=cookies.find(c=>c.name==='PHPSESSID');assert.equal(cookie.httpOnly,true);assert.equal(cookie.sameSite,'Lax');evidence.cookie_attributes={httpOnly:cookie.httpOnly,secure:cookie.secure,sameSite:cookie.sameSite};
 stage='logout';const out=await page.evaluate(async()=>{const t=await(await fetch('/API/auth/csrf-token.php')).json();const r=await fetch('/API/auth/logout.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':t.token},body:'{}'});const denied=await fetch('/API/profile/settings.php');return {logout:(await r.json()).success,denied:denied.status};});assert.equal(out.logout,true);assert.equal(out.denied,401);evidence.steps.push({name:'real logout and protected rejection',status:'PASS'});
 stage='login_page';await page.goto(origin+'/modules/auth/login.php',{waitUntil:'domcontentloaded'});const prelogin=await sid();evidence.login_scripts=await page.locator('script[src]').evaluateAll(a=>a.map(s=>new URL(s.src).pathname));await page.locator('#email').fill(email);await page.locator('#password').fill('wrong-owned-password');await page.locator('#loginBtn').click();await page.waitForFunction(()=>document.getElementById('loginAlert')?.textContent.includes('Incorrect'));
 evidence.steps.push({name:'wrong-password UI error against created account',status:'PASS'});
 stage='password_login';await page.locator('#password').fill(password);await page.locator('#loginBtn').click();await page.waitForURL(u=>u.pathname==='/modules/chat/chat.php'||u.pathname==='/modules/onboarding/server-discovery.php',{timeout:20000});assert.notEqual(await sid(),prelogin);
 const final=await page.evaluate(async()=>({session:await(await fetch('/API/auth/validate-session.php')).json(),protectedStatus:(await fetch('/API/profile/settings.php')).status}));assert.equal(final.session.authenticated,true);assert.equal(final.session.role,'student');assert.equal(final.protectedStatus,200);
 evidence.steps.push({name:'real password-login UI and authenticated transition',status:'PASS',session_rotated:true,actual_path:new URL(page.url()).pathname});
 await Promise.allSettled(pending);assert(evidence.navigations.every(n=>n.status<400),'navigation HTTP failure');evidence.page_errors=errors;assert.deepEqual(errors,[]);evidence.status='PASS';
})().catch(()=>{evidence.status='FAIL';evidence.failure_stage=stage;process.exitCode=1;}).finally(async()=>{if(browser)await browser.close();fs.writeFileSync(target,JSON.stringify(evidence,null,2)+'\n');console.log(JSON.stringify({status:evidence.status,stage:evidence.failure_stage??null}));});
