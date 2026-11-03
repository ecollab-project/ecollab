'use strict';
const {JSDOM}=require('jsdom'),fs=require('fs'),{execFileSync}=require('node:child_process'),assert=require('node:assert/strict');
const html=execFileSync(process.env.PHP_BIN||'php',['-d','session.save_path=/tmp','tests/responsive-render-fixture.php','codelabfac'],{encoding:'utf8'});
const dom=new JSDOM(html,{runScripts:'outside-only',url:'https://ecollab.test/modules/code-lab/index.php?server_id=1'}),w=dom.window,d=w.document;
w.setInterval=()=>0;w.confirm=()=>true;w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};
let job=null,held=null,holdStatus=false,published=0,posts=[],overview=0;
const ready={id:91,state:'ready',file_name:'lesson <img>.txt',difficulty:'hard',language:'python',source_chars:12000,used_chars:8000,draft:{title:'<img src=x onerror=alert(1)> Safe title',instructions:'Difficulty: Hard\n\nRead values and print their sum.',language:'python',source_excerpt:'Python loops repeat a block of statements.'}};
w.fetch=async(url,options={})=>{
 const module=url.includes('/module-tasks.php'),params=options.body instanceof w.FormData?Object.fromEntries(options.body):options.body?JSON.parse(options.body):Object.fromEntries(new URL(url,w.location).searchParams);let data={};
 if(module){posts.push({params,options});switch(params.action){case'status':if(holdStatus){data=await new Promise(r=>{held=r;});}else data={job};break;case'generate':assert.equal(params.server_id,'1');assert.equal(params.difficulty,'hard');assert.equal(params.language,'python');assert.equal(params.module.name,'loops.txt');assert.equal(options.headers['X-CSRF-Token'],'test-csrf');job={...ready,state:'queued',draft:null};data={job};holdStatus=true;break;case'publish':published++;assert.equal(params.job_id,91);assert.equal(params.title,'Edited facilitator task');assert.equal(params.grading_mode,'manual');assert.deepEqual(params.test_cases,[]);data={id:5};job=null;break;}}
 else if(params.action==='overview'){overview++;data={tasks:[],runnable_languages:['python']};}
 return {ok:true,json:async()=>({success:true,data})};
};
const tick=()=>new Promise(r=>setTimeout(r,15));
(async()=>{try{
 w.eval(fs.readFileSync('assets/js/code-lab-module.js','utf8'));w.eval(fs.readFileSync('assets/js/code-lab.js','utf8'));await tick();
 const code=d.getElementById('clCode'),input=d.getElementById('clStdin'),run=d.getElementById('clRun'),output=d.getElementById('clOutput');
 assert(code.compareDocumentPosition(run)&w.Node.DOCUMENT_POSITION_FOLLOWING,'Program precedes Run');assert(d.getElementById('clConsole').contains(input)&&d.getElementById('clConsole').contains(output),'Input and output share one console');assert.equal(d.querySelector('.cl-stdin'),null,'No standalone stdin section');assert.equal(d.querySelector('#clWorkspace #clWorkflowHelp'),null,'No inline Jarred guidance');
 d.getElementById('clCreate').click();await tick();assert(!d.getElementById('clModulePane').hidden);assert(d.getElementById('clTaskForm').hidden,'Upload is the simple default');
 const file=new w.File(['Python loops '.repeat(20)],'loops.txt',{type:'text/plain'});Object.defineProperty(d.getElementById('clModuleFile'),'files',{configurable:true,value:[file]});d.getElementById('clModuleForm').elements.difficulty.value='hard';d.getElementById('clModuleForm').dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));await tick();assert(d.getElementById('clModuleState').textContent.includes('queued'));assert.equal(published,0,'Generate never publishes');
 d.getElementById('clCancel').click();job=ready;holdStatus=false;held({job:ready});await tick();assert(d.getElementById('clTaskForm').hidden,'Completion after close cannot overwrite the current view');
 d.getElementById('clCreate').click();await tick();const form=d.getElementById('clTaskForm');assert(!form.hidden,'Reopen resumes the private generated draft');assert.equal(form.elements.title.value,ready.draft.title);assert.equal(d.querySelector('#clModuleState img'),null);assert.equal(d.querySelector('#clModuleQuote img'),null);assert(d.getElementById('clModuleQuote').textContent.includes('Python loops'));assert(!d.getElementById('clTaskAdvanced').open,'Advanced manual work is collapsed');
 form.elements.title.value='Edited facilitator task';form.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));form.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));await tick();assert.equal(published,1,'Double submit posts once');assert(!d.getElementById('clTaskDialog').open);assert(overview>=2,'Published task refreshes the list');
 d.getElementById('clCreate').click();await tick();d.getElementById('clManualMode').click();assert(!form.hidden&&d.getElementById('clModulePane').hidden,'Manual fallback remains available');
 console.log('PASS: unified input/output console, upload/difficulty payload, queued private draft, close/reopen recovery, escaped review, editable manual grading and single publish');
}finally{dom.window.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
