'use strict';
const {chromium}=require('playwright'),fs=require('fs'),path=require('path'),http=require('http'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),names=['settings','chat','student','facilitator','admin','codelab','login','landing'],html=new Map(),palettes=JSON.parse(fs.readFileSync('assets/data/color-palettes.json','utf8'));
for(const name of names){let text=execFileSync(process.env.PHP_BIN||'php',[...JSON.parse(process.env.PHP_FIXTURE_ARGS||'[]'),'-d','session.save_path=/tmp','tests/responsive-render-fixture.php',name],{cwd:root,encoding:'utf8'});assert(text.includes('ecColorPalettes'),name+' includes shared palette assets');text=text.replace(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi,(all,attrs,body)=>attrs.includes('application/json')||name==='settings'&&body.includes('const BASE=')?all:'').replace(/(href|src)="https?:\/\/[^/"\s]+(\/assets\/[^"\s]+)"/g,'$1="$2"').replace('</head>','<script defer src="/assets/js/color-theme.js"></script><script defer src="/assets/js/workspace-widgets.js"></script><script defer src="/assets/js/accessibility-apply.js"></script></head>');html.set(name,text);}
const server=http.createServer((req,res)=>{const url=new URL(req.url,'http://fixture');if(url.pathname.startsWith('/fixture/')){res.setHeader('Content-Type','text/html');return res.end(html.get(url.pathname.split('/').at(-1))||'');}const file=path.resolve(root,'.'+url.pathname);if(!file.startsWith(root+path.sep)||!fs.existsSync(file)||!fs.statSync(file).isFile()){res.statusCode=404;return res.end();}res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'application/octet-stream');res.end(fs.readFileSync(file));});
(async()=>{await new Promise(r=>server.listen(0,'127.0.0.1',r));const base='http://127.0.0.1:'+server.address().port,browser=await chromium.launch(process.env.CHROMIUM_PATH?{executablePath:process.env.CHROMIUM_PATH}:{});let saved={color_palette:'ecollab-original',theme:'dark'},reject=false,checks=0;
try{for(const width of [320,768,1440]){const page=await browser.newPage({viewport:{width,height:1000}});await page.route('**/*',route=>{const url=route.request().url();if(url.includes('/API/profile/settings.php')){if(route.request().method()==='POST'){if(reject)return route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({error:'Please retry'})});saved={...saved,...JSON.parse(route.request().postData())};}return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,settings:saved})});}if(url.includes('/API/'))return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,profile:{}})});return url.startsWith(base)?route.continue():route.abort();});
 for(const name of names){saved={color_palette:'ecollab-original',theme:'dark'};await page.goto(base+'/fixture/'+name+(name==='settings'?'?section=appearance':''),{waitUntil:'networkidle'});await page.waitForFunction(()=>!!window.EcollabColors);const initial=await page.evaluate(()=>getComputedStyle(document.documentElement).getPropertyValue('--ec-primary').trim());assert.equal(initial,palettes.find(p=>p.id==='ecollab-original').dark.primary,name+' original default');
 if(process.env.PALETTE_SCREENSHOTS&&['settings','chat','student'].includes(name)&&width===1440)await page.screenshot({path:path.join(process.env.PALETTE_SCREENSHOTS,name+'.png'),fullPage:true});
 for(const palette of palettes){for(const theme of ['dark','light']){await page.evaluate(({id,theme})=>{document.documentElement.dataset.theme=theme;window.EcollabColors.apply({color_palette:id});},{id:palette.id,theme});const actual=await page.evaluate(()=>({page:getComputedStyle(document.body).getPropertyValue('--ec-page').trim(),accent:getComputedStyle(document.body).getPropertyValue('--accent').trim(),overflow:document.documentElement.scrollWidth>innerWidth+2}));assert.equal(actual.page,palette[theme].page,name+' '+palette.name+' '+theme);assert.equal(actual.accent,palette[theme].accent);assert(!actual.overflow,name+' palette causes overflow at '+width);checks++;}}
 if(name==='settings'){await page.locator('[data-color-choice="fcms-burgundy-coral"]').click();await page.locator('#ecPaletteStatus').filter({hasText:'FCMS Burgundy & Coral saved'}).waitFor();await page.reload({waitUntil:'networkidle'});await page.waitForFunction(()=>document.documentElement.dataset.colorPalette==='fcms-burgundy-coral');await page.locator('[data-color-choice="ecollab-original"]').click();await page.locator('#ecPaletteStatus').filter({hasText:'saved to your account'}).waitFor();await page.locator('[data-color-choice="purple-pink"]').click();await page.locator('#ecPaletteStatus').filter({hasText:'Purple & Pink saved'}).waitFor();await page.reload({waitUntil:'networkidle'});await page.waitForFunction(()=>document.documentElement.dataset.colorPalette==='purple-pink');assert.equal(await page.locator('[aria-pressed="true"]').count(),1);reject=true;await page.locator('[data-color-choice="ecollab-original"]').click();await page.locator('#ecPaletteStatus').filter({hasText:'Please retry'}).waitFor();assert.equal(await page.evaluate(()=>document.documentElement.dataset.colorPalette),'purple-pink');reject=false;}
 }
 await page.goto(base+'/fixture/settings?section=appearance',{waitUntil:'networkidle'});
 const preview=page.locator('.ec-workspace-preview');
 assert(await preview.isVisible());
 for(const area of ['.ec-preview-search','.ec-preview-tools','.ec-preview-thread','.ec-preview-composer','.ec-preview-account','.ec-preview-switcher']){
   assert(await preview.locator(area).isVisible(),area+' renders at '+width);
 }
 assert.equal(await preview.locator('[data-editor-widget] .ec-preview-samples').count(),9);
 assert.equal(await preview.locator('.ec-preview-left [data-editor-widget="shortcuts"]').getByText('Library',{exact:true}).count(),1);
 assert.equal(await preview.locator('.ec-preview-right [data-editor-widget="members"]').getByText('Professor',{exact:true}).count(),1);
 assert(await preview.evaluate(e=>[e,...e.querySelectorAll('[data-editor-widget],.ec-preview-bar,.ec-preview-dock')].every(part=>part.scrollWidth<=part.clientWidth+2)),'Preview and its contents fit at '+width);
 if(width===1440){
   const desktopButton=preview.getByRole('button',{name:'Move left for Suggested matches'});
   assert((await desktopButton.boundingBox()).width<=28,'Compact desktop controls leave room for the widget name');
 }
 if(width===320){
   const touchButton=preview.getByRole('button',{name:'Move left for Active now'});
   assert((await touchButton.boundingBox()).height>=44,'Move controls are touch-sized');
 }
 if(width===320 || width===1440){
  const colors=['#111827','#202c44','#f4bc63','#4fd5e2','#f8fafc'];
  const editor=page.locator('#ecCustomPalette');
  assert.equal(await editor.locator('input[type="color"]').count(),5);
  for(const [index,role] of ['page','surface','primary','accent','text'].entries()){
    await editor.locator(`input[name="${role}Hex"]`).fill(colors[index]);
  }
  assert.equal(await page.locator('#ecPalettePreview').evaluate(e=>e.style.getPropertyValue('--ec-custom-accent')),'#4fd5e2');
  await editor.getByRole('button',{name:'Save custom palette'}).click();
  await page.locator('#ecPaletteStatus').filter({hasText:'Custom palette saved'}).waitFor();
  const form=page.locator('#ecWidgetForm');
  const activity=form.locator('[data-editor-widget="activity"]');
  if(width===1440){
    const target=form.locator('#ecWidgetList-left [data-editor-widget="shortcuts"]');
    await activity.locator('.ec-widget-grip').dragTo(target,{targetPosition:{x:20,y:5}});
    await form.locator('#ecWidgetList-left [data-editor-widget="activity"]').waitFor();
    assert.equal(await form.locator('#ecWidgetList-left [data-editor-widget]').first().getAttribute('data-editor-widget'),'activity');
    assert(await form.locator('#ecWidgetList-left [data-editor-widget="activity"] .ec-preview-samples').isVisible());
    assert.equal(saved.workspace_layout,undefined,'Dragging updates the preview without saving');
    await form.getByRole('button',{name:'Discard edits'}).click();
    assert.equal(await form.locator('#ecWidgetList-right [data-editor-widget="activity"]').count(),1);
  }
  await activity.getByRole('button',{name:'Move left for Active now'}).click();
  await activity.getByRole('button',{name:'Move Active now up'}).click();
  await form.locator('[data-editor-widget="matches"]').getByRole('checkbox').uncheck();
  const hiddenMatches=form.locator('#ecWidgetList-hidden [data-editor-widget="matches"]');
  assert.equal(await hiddenMatches.count(),1);
  assert.equal(await hiddenMatches.locator('.ec-preview-samples').count(),0);
  await form.getByRole('button',{name:'Save workspace layout'}).click();
  await page.locator('#ecWidgetStatus').filter({hasText:'Workspace layout saved'}).waitFor();
  await page.goto(base+'/fixture/chat',{waitUntil:'networkidle'});
  assert.equal(await page.evaluate(()=>document.documentElement.dataset.colorPalette),'custom');
  assert.equal(await page.evaluate(()=>getComputedStyle(document.body).getPropertyValue('--ec-page').trim()),'#111827');
  assert.equal(await page.locator('#ecWorkspaceLeft [data-workspace-widget="activity"]').count(),1);
  assert.deepEqual(await page.locator('#ecWorkspaceLeft [data-workspace-widget]').evaluateAll(rows=>rows.slice(-3).map(row=>row.dataset.workspaceWidget)),['direct','activity','groups']);
  assert(await page.locator('[data-workspace-widget="matches"]').isHidden());
  if(width===320){
    await page.locator('#mobileTopbar [data-context-toggle]').click();
    await page.locator('#sidebarRight').waitFor({state:'visible'});
    await page.keyboard.press('Escape');
    assert(await page.locator('#sidebarRight').isHidden());
  }
  await page.goto(base+'/fixture/settings?section=appearance',{waitUntil:'networkidle'});
  await page.locator('#ecWidgetReset').click();
  await page.locator('#ecWidgetStatus').filter({hasText:'Default workspace layout restored'}).waitFor();
  assert.equal(saved.workspace_layout,'');
  await page.locator('#ecRestoreOriginal').click();
  await page.locator('#ecPaletteStatus').filter({hasText:'eCollab Original saved'}).waitFor();
 }
 await page.close();}console.log('PASS: '+checks+' palette/mode/page/viewport checks plus settings persistence and failed-save rollback');}finally{await browser.close();await new Promise(r=>server.close(r));}})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
