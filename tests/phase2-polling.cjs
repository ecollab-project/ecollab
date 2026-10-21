const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('assets/js/code-lab.js','utf8');
const helpers=source.slice(source.indexOf(' let runnable='),source.indexOf(' const names='));
const poll=source.slice(source.indexOf(' async function pollJob('),source.indexOf(" $('clRun').onclick="));
async function simulate(count,after){
 let now=0,calls=0,position=0,timers=[],id=0;const contexts=[];
 for(let i=0;i<count;i++){
  const listeners={};const node={value:'python'};
  const c={window:{addEventListener:(n,f)=>listeners[n]=f},document:{hidden:false,addEventListener:(n,f)=>listeners[n]=f},$:()=>node,Math:Object.assign(Object.create(Math),{random:()=>0.5}),
   api:async(_a,data)=>{calls++;if(data.queue_position!=='0')position++;return {state:'queued',queue_position:data.queue_position==='0'?null:5};},
   setTimeout:(fn,ms)=>{const n=++id;timers.push({id:n,at:now+ms,fn});return n;},clearTimeout:n=>{timers=timers.filter(t=>t.id!==n);}};
  vm.createContext(c);vm.runInContext(helpers+poll,c);contexts.push({c,listeners});
  if(after)c.pollJob(i+1,'run');else{const request=()=>{calls++;position++;c.setTimeout(request,3000);};request();}
 }
 const flush=async()=>{for(let i=0;i<12;i++)await Promise.resolve();};await flush();
 while(true){timers.sort((a,b)=>a.at-b.at);if(!timers.length||timers[0].at>=60000)break;const t=timers.shift();now=t.at;t.fn();await flush();}
 const measured={calls,position};
 if(after){const before=calls;for(const {c}of contexts)c.document.hidden=true;timers.sort((a,b)=>a.at-b.at);while(timers.length&&timers[0].at<70000){const t=timers.shift();now=t.at;t.fn();await flush();timers.sort((a,b)=>a.at-b.at);}assert.equal(calls,before,'Hidden pages make no status requests');
  contexts[0].c.document.hidden=false;contexts[0].listeners['ecollab:delivery-sync']({detail:{kind:'code_lab'}});await flush();assert.equal(calls,before+1,'Realtime event wakes status immediately');}
 return measured;
}
(async()=>{for(const n of [50,100,200,300,350,400,450]){const before=await simulate(n,false),after=await simulate(n,true);assert(after.calls<before.calls);console.log(JSON.stringify({clients:n,window_seconds:60,before,after}));}})().catch(e=>{console.error(e);process.exitCode=1;});
