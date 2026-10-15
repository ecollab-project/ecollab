/* Uses actual PHP page templates with isolated presentation fixtures, not production data. */
const {chromium}=require('playwright'),fs=require('fs'),path=require('path'),http=require('http'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),pages=['landing','policy','login','signup','forgot','admin','student','facilitator','chat','settings','library','onboarding','whiteboard','myspace','codelab','codelabfac'];
const sizes=[[240,640],[280,640],[320,568],[390,844],[568,320],[768,1024],[1024,768],[1440,900],[1920,1080],[2560,1440],[3840,2160]];
const html=new Map();for(const name of pages){const output=execFileSync(process.env.PHP_BIN||'php',[...JSON.parse(process.env.PHP_FIXTURE_ARGS||'[]'),'-d','session.save_path=/tmp','tests/responsive-render-fixture.php',name],{cwd:root,encoding:'utf8'});assert(output.includes('device-layout.css'),name+' failed PHP rendering');html.set(name,output.replace(/<script\b[\s\S]*?<\/script>/gi,'').replace(/(href|src)="https?:\/\/[^/"\s]+(\/assets\/[^"\s]+)"/g,'$1="$2"').replace('</head>','<script defer src="/assets/js/device-layout.js"></script><script defer src="/assets/js/mobile-viewport.js"></script>'+(name==='chat'?'<script defer src="/assets/js/chat/workspace-taskbar.js"></script><script defer src="/assets/js/calls/call-dock.js"></script><script defer src="/assets/js/chat/media-picker.js"></script>':'')+(name.startsWith('codelab')?'<script defer src="/assets/js/code-lab.js"></script><script defer src="/assets/js/code-lab-integrity.js"></script>':'')+'</head>'));}
const server=http.createServer((req,res)=>{const url=new URL(req.url,'http://fixture');if(url.pathname.startsWith('/fixture/')){res.setHeader('Content-Type','text/html');res.end(html.get(url.pathname.split('/').at(-1))||'');return;}const file=path.resolve(root,'.'+url.pathname);if(!file.startsWith(root+path.sep)||!fs.existsSync(file)||!fs.statSync(file).isFile()){res.statusCode=404;res.end();return;}res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'application/octet-stream');res.end(fs.readFileSync(file));});
(async()=>{await new Promise(r=>server.listen(0,'127.0.0.1',r));const base='http://127.0.0.1:'+server.address().port;const browser=await chromium.launch();const results=[];
try{for(const name of pages){for(const [width,height] of sizes){const page=await browser.newPage({viewport:{width,height},hasTouch:width<=1024,isMobile:width<=768});await page.route('**/*',route=>{
 if(route.request().url().includes('/API/code-lab/index.php')){
  const action=route.request().method()==='POST'?JSON.parse(route.request().postData()).action:new URL(route.request().url()).searchParams.get('action');
  const task={id:1,title:'Programming practice with a deliberately long title for mobile layouts',instructions:'Write a program to sum the numbers.',language:'python',kind:'assignment',duration_minutes:null,due_at:null,opens_at:null,state:'available',attempt_id:null,score:null};
  const data=action==='start'?{task,attempt:{id:1,code:'# Private code',version:1,state:'in_progress',ends_at:null,submitted_at:null,score:null},server_now:Math.floor(Date.now()/1000)}:{server:{id:1,name:'Programming course'},tasks:[task]};
  return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data})});
 }
 return route.request().url().startsWith(base)?route.continue():route.abort();
});await page.goto(base+'/fixture/'+name,{waitUntil:'networkidle'});
await page.evaluate(()=>{const composer=document.getElementById('chatInputWrapper');if(composer)composer.style.display='block';const host=document.getElementById('lw-view');if(host)host.innerHTML='<div class="lw-card-grid"><article class="lw-space-card"><h3>A learning community with a deliberately long name for a narrow screen</h3><p>Resources and collaboration</p><footer><span>24 members</span><button>Manage this space</button></footer></article></div><div class="lw-panel"><div class="lw-panel-body"><div class="lw-row"><div><strong>Long learner name that wraps</strong><p>https://example.invalid/'+('long-resource-path-'.repeat(8))+'</p></div><div class="lw-row-actions"><button>Review</button><button>Resolve</button></div></div><table><caption>Fixture records</caption><tr><th>Name</th><th>Details</th><th>Action</th></tr><tr><td>Member</td><td>'+('Evidence '.repeat(20))+'</td><td><button>Open</button></td></tr></table></div></div>';});await page.waitForTimeout(60);if(name==='codelabfac'){await page.locator('#clCreate').click();await page.locator('#clGradingMode').selectOption('automatic');}if(name==='codelab'){await page.locator('[data-start]').click();await page.locator('#clWorkspace').waitFor({state:'visible'});}
if(name==='chat'){
 await page.evaluate(()=>window.EcollabMedia.open('emoji',document.getElementById('chatInputField'),document.getElementById('emojiBtn')));
 await page.locator('#ecMediaPicker input').fill('rocket');
 await page.locator('.ec-media-grid button').first().click();
 assert((await page.locator('#chatInputField').inputValue()).includes('🚀'),'Emoji selection inserts Unicode');
 await page.evaluate(()=>window.EcollabMedia.open('sticker',document.getElementById('chatInputField'),document.getElementById('gifBtn')));
 const picker=await page.locator('#ecMediaPicker').boundingBox();assert(picker&&picker.x>=0&&picker.x+picker.width<=width+2&&picker.y>=0&&picker.y+picker.height<=height+2,'Media picker stays inside viewport');
}
const measured=await page.evaluate(()=>({viewport:innerWidth,document:document.documentElement.scrollWidth,body:document.body.scrollWidth}));const failures=[];if(measured.document>width+2)failures.push('document width '+measured.document);if(name==='chat'&&width<=860){const composer=await page.locator('.chat-input-wrapper').boundingBox();if(composer&&composer.x+composer.width>width+2)failures.push('composer overflow');}
if(name==='chat'&&width>=768){const main=await page.locator('.chat-input-main').boundingBox(),actions=await page.locator('.chat-input-actions').boundingBox();if(!main||!actions||Math.abs((main.y+main.height/2)-(actions.y+actions.height/2))>2)failures.push('composer actions not on input row');}
const controlsOverflow=await page.evaluate(()=>{
 const issues=[];
 for(const el of document.querySelectorAll('button,input,select,textarea,.lw-space-card,.workspace-stat,.chat-input-wrapper')){
  const r=el.getBoundingClientRect();if(!r.width||!r.height||r.right<=0||r.bottom<=0)continue;
  if(el.closest('.lw-sidebar:not(.open),.workspace-sidebar:not(.open)')&&innerWidth<=900)continue;
  let allowed=false;for(let p=el.parentElement;p&&p!==document.body;p=p.parentElement){const style=getComputedStyle(p);if(['auto','scroll'].includes(style.overflowX)){allowed=true;break;}}
  if(!allowed&&(r.right>innerWidth+2||r.left<-2))issues.push((el.id||el.className||el.tagName)+' outside viewport');
 }
 return [...new Set(issues)].slice(0,6);
});failures.push(...controlsOverflow);
results.push({page:name,width,height,failures});if(failures.length||(name==='codelab'&&width===390)||(name==='codelabfac'&&width===1440)){await page.screenshot({path:'responsive-'+name+'-'+width+'.png',fullPage:true});}
await page.close();}}
fs.writeFileSync('responsive-results.json',JSON.stringify(results,null,2));const failed=results.filter(r=>r.failures.length);console.log(JSON.stringify(failed.length?failed:{checks:results.length,pages:pages.length,widths:sizes.length},null,2));assert.equal(failed.length,0,'Responsive overflow failures');
}finally{await browser.close();await new Promise(r=>server.close(r));}})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
