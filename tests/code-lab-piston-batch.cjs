'use strict';
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const assert = require('node:assert/strict');
(async () => {
 const fragment = await fs.readFile(path.join(__dirname,'../deploy/code-lab/piston/grading/job-method.js'),'utf8');
 const job_states={PRIMED:1,EXECUTED:2};
 const FakeJob = new Function('require','path','job_states',`return class {
   async #create_isolate_box(){const dir=await require('fs/promises').mkdtemp(this.root+'/case-');return {dir};}
   ${fragment}
 }`)(require,path,job_states);
 const root=await fs.mkdtemp(path.join(os.tmpdir(),'grading-'));
 async function fixture(compiled=true){
  const dir=await fs.mkdtemp(root+'/job-');await fs.mkdir(dir+'/submission');
  await fs.writeFile(dir+'/submission/main.c','source');
  const job=new FakeJob();Object.assign(job,{root,state:1,runtime:{compiled},files:[{name:'main.c',encoding:'utf8'}],args:[],timeouts:{compile:30000,run:8000},cpu_times:{compile:10000,run:2000},memory_limits:{compile:536870912,run:268435456}});
  return {job,box:{dir}};
 }
 try {
  const {job,box}=await fixture();let compiles=0,runs=0;
  job.safe_call=async (b,phase,args,wall,cpu,memory)=>{
   const cwd=b.dir+'/submission';
   if(phase==='compile'){
    compiles++;assert.equal(job.stdin,'');assert.equal(cpu,10000);
    await fs.mkdir(cwd+'/classes');await fs.writeFile(cwd+'/classes/artifact','pristine',{mode:0o755});return {code:0};
   }
   assert.equal(wall,8000);assert.equal(cpu,2000);assert.equal(memory,268435456);
   assert.equal(await fs.readFile(cwd+'/classes/artifact','utf8'),'pristine');
   assert.equal((await fs.stat(cwd+'/classes/artifact')).mode&0o777,0o755);
   assert.equal(job.stdin,['first\n','second\n'][runs++]);
   await fs.writeFile(cwd+'/classes/artifact','mutated');
   await fs.writeFile(cwd+'/private-case-state','not carried forward');
   return {code:0,stdout:job.stdin};
  };
  const result=await job.ecollab_execute_cases(box,['first','second']);
  assert.equal(compiles,1);assert.equal(runs,2);assert.equal(result.compile_count,1);assert.equal(job.stdin,'');
  for(const kind of ['symlink','hardlink','large','entries']){
   const {job,box}=await fixture(false);
   if(kind==='symlink')await fs.symlink('/etc/passwd',box.dir+'/submission/link');
   if(kind==='hardlink')await fs.link(box.dir+'/submission/main.c',box.dir+'/submission/link');
   if(kind==='large')await fs.writeFile(box.dir+'/submission/large',Buffer.alloc(8388609));
   if(kind==='entries')for(let i=0;i<257;i++)await fs.writeFile(box.dir+'/submission/f'+i,'');
   job.safe_call=()=>{throw Error('unsafe artifact executed');};
   await assert.rejects(job.ecollab_execute_cases(box,['input']),/Artifact/);
  }
  const failed=await fixture();failed.job.safe_call=async()=>({code:1,stderr:'compile error'});
  assert.deepEqual((await failed.job.ecollab_execute_cases(failed.box,['a','b'])).runs,[]);
  console.log('PASS: one compile, pristine per-case snapshots, fixed limits, compiler failure, rejected links and bounded artifacts');
 }finally{await fs.rm(root,{recursive:true,force:true});}
})().catch(error=>{console.error(error);process.exitCode=1;});
