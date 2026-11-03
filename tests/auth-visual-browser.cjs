'use strict';
const {chromium}=require('playwright');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
let html=execFileSync(process.env.PHP_BIN||'php',['tests/responsive-render-fixture.php','login'],{cwd:root,encoding:'utf8'})
 .replace(/<script\b[\s\S]*?<\/script>/gi,'')
 .replace(/(href|src)="https?:\/\/[^/"\s]+(\/assets\/[^"\s]+)"/g,'$1="$2"');
const server=http.createServer((req,res)=>{
 const url=new URL(req.url,'http://fixture');if(url.pathname==='/login'){res.setHeader('Content-Type','text/html');return res.end(html);}
 const file=path.resolve(root,'.'+url.pathname);if(!file.startsWith(root+path.sep)||!fs.existsSync(file)){res.statusCode=404;return res.end();}
 let text=fs.readFileSync(file);if(file.endsWith('.css'))text=text.toString().replace(/:-webkit-autofill|:autofill/g,'.fixture-autofill');
 res.setHeader('Content-Type',file.endsWith('.css')?'text/css':'application/octet-stream');res.end(text);
});
(async()=>{
 await new Promise(r=>server.listen(0,'127.0.0.1',r));const browser=await chromium.launch();let checks=0;
 try {for(const width of [320,457,1440]){
  const page=await browser.newPage({viewport:{width,height:900}});await page.route('**/*',route=>route.request().url().startsWith('http://127.0.0.1:')?route.continue():route.abort());
  await page.goto('http://127.0.0.1:'+server.address().port+'/login');await page.addStyleTag({content:'*,*::before,*::after{animation:none!important;transition:none!important}'});
  for(const palette of ['forest-mint','purple-pink'])for(const theme of ['dark','light']){
   await page.evaluate(({palette,theme})=>{document.documentElement.dataset.colorPalette=palette;document.documentElement.dataset.theme=theme;document.querySelector('#email').value='learner@example.invalid';document.querySelector('#email').classList.add('fixture-autofill');},{palette,theme});
   const actual=await page.evaluate(()=>{
    const input=document.querySelector('#email'),row=input.closest('.input-row');const ref=document.createElement('span');ref.style.color='var(--ec-text)';ref.style.backgroundColor='var(--ec-input)';document.body.append(ref);const expected={text:getComputedStyle(ref).color,input:getComputedStyle(ref).backgroundColor};ref.remove();
    return {expected,row:getComputedStyle(row).backgroundColor,fill:getComputedStyle(input).webkitTextFillColor,shadow:getComputedStyle(input).boxShadow,heading:getComputedStyle(document.querySelector('.hero-text .white')).color,social:[...document.querySelectorAll('.social-btn')].map(el=>getComputedStyle(el).textDecorationLine),overflow:document.documentElement.scrollWidth>innerWidth+1,chip:document.querySelector('.brain-chip').getBoundingClientRect().right<=innerWidth};
   });
   assert(actual.social.every(v=>v==='none'),'SSO links must not be underlined');
   assert.equal(actual.row,actual.expected.input,'input container follows palette');
   assert.equal(actual.fill,actual.expected.text,'autofill text follows light/dark palette');
   assert(actual.shadow.startsWith(actual.expected.input),'autofill has no mismatched inset rectangle');
   assert.equal(actual.heading,actual.expected.text,'heading readable in light and dark modes');
   assert(!actual.overflow&&actual.chip,'auth card/chip must fit viewport');checks++;
  }
  if(width===457&&process.env.AUTH_SCREENSHOTS){await page.screenshot({path:path.join(process.env.AUTH_SCREENSHOTS,'login-fixed.png'),fullPage:true});}
  await page.close();
 }
 console.log('PASS: '+checks+' auth viewport/palette/mode cases, including autofill, SSO links and heading contrast');
 }finally{await browser.close();await new Promise(r=>server.close(r));}
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
