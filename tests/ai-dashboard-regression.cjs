const fs=require('fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const src=fs.readFileSync('assets/js/ai-session.js','utf8');
async function run(late=false,fail=false){
 const d=new JSDOM('<meta name="csrf-token" content="token"><div id="aiModal" class="mo'+(late?' show':'')+'"><div class="mb"><input id="aiInput"><button onclick="sendAI()">Send</button></div></div>',{url:'https://ecollab.test',runScripts:'outside-only'}),w=d.window;
 w.ECOLLAB_BASE='';w.toast=()=>{};let posts=0;w.fetch=async(path,o={})=>{let data={success:true};if(path.endsWith('quick-prompts.php'))data.prompts=[];else if(path.endsWith('sessions.php')&&o.method==='POST')data.session={id:1,session_title:'New AI Conversation'};else if(path.endsWith('sessions.php'))data.sessions=[];else if(path.endsWith('message.php')){posts++;assert.equal(o.headers['X-CSRF-Token'],'token');assert.equal(JSON.parse(o.body).prompt,'Hello');await new Promise(r=>setTimeout(r,5));data=fail?{success:false,error:'Provider unavailable'}:{success:true,message:{content:'Hello from Jarred'}};}return{ok:!fail||!path.endsWith('message.php'),status:fail?503:200,json:async()=>data};};
 if(late){await new Promise(r=>w.addEventListener('load',r,{once:true}));}
 w.eval(src);if(!late)w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
 w.document.querySelector('#aiModal').classList.add('show');await new Promise(r=>setTimeout(r,20));
 assert(w.document.querySelector('#ecAiInput'),'show modal builds persistent assistant');w.document.querySelector('#ecAiInput').value='Hello';const first=w.sendAI();await w.sendAI();await first;
 assert.equal(posts,1,'duplicate sends do not send duplicate messages');assert.equal(w.document.querySelector('#ecAiInput').disabled,false);assert.equal(w.document.querySelector('#ecAiSend').disabled,false);
 if(fail){assert(w.document.querySelector('[role=alert]').textContent.includes('Provider unavailable'));assert.equal(w.document.querySelector('#ecAiInput').value,'Hello');}else assert(w.document.querySelector('#ecAiLog').textContent.includes('Hello from Jarred'));
 w.close();
}
(async()=>{await run();await run(true);await run(false,true);console.log('PASS AI modal show, late loading, send, duplicate guard and recoverable errors');})().catch(e=>{console.error(e);process.exit(1)});
