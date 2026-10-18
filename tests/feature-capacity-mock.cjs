'use strict';
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),http=require('node:http'),assert=require('node:assert/strict'),{spawn}=require('node:child_process');
const root=path.resolve(__dirname,'..'),fixture='k6-20261018-000000-1234abcd';
(async()=>{
  for(const [mode,fault]of[['ai',''],['ai','busy'],['ai','wrong'],['code-run',''],['code-run','wrong'],['code-grade',''],['code-grade','wrong'],['code-run','queued'],['code-run','late']]){
    let deleted=0;const seen=new Set(),jobs=new Map();
    const server=http.createServer((req,res)=>{let raw='';req.on('data',chunk=>raw+=chunk);req.on('end',()=>{
      const body=raw?JSON.parse(raw):{},uid=Number((req.headers.cookie||'').match(/capacity=(\d+)/)?.[1]||0);
      const json=(data,status=200)=>{res.writeHead(status,{'Content-Type':'application/json'});res.end(JSON.stringify(data));};
      if(req.url.startsWith('/API/auth/csrf-token'))return json({success:true,token:'synthetic-csrf'});
      if(req.url.startsWith('/API/auth/login')){const id=Number(body.identifier.match(/_(\d+)@/)?.[1]);assert(id>=1&&id<=2);seen.add(id);res.setHeader('Set-Cookie','capacity='+id+'; Path=/');return json({success:true});}
      if(!uid)return json({error:'unauthenticated'},401);
      if(req.url.startsWith('/API/ai/sessions'))return json({success:true,session:{id:uid}},201);
      if(req.url.startsWith('/API/ai/session.php')){deleted++;return json({success:true});}
      if(req.url.startsWith('/API/ai/message')){if(fault==='busy')return json({success:false,error:'busy'},503);return json({success:true,message:{content:fault==='wrong'?'incorrect':'CAPACITY_'+uid}});}
      if(req.url.startsWith('/API/code-lab/')){
        if(body.action==='start')return json({success:true,data:{attempt:{version:1,state:'in_progress'}}});
        if(body.action==='run'||body.action==='submit'){assert.match(body.code,/capacity-/);jobs.set(uid,0);return json({success:true,data:body.action==='run'?{job_id:uid}:{grade_job:{id:uid}}});}
        const polls=(jobs.get(uid)||0)+1;jobs.set(uid,polls);
        const state=fault==='queued'?'queued':polls===1?'queued':polls===2?'running':'done';
        const result=mode==='code-grade'?{score:fault==='wrong'?50:100,total:2,passed:fault==='wrong'?1:2}:{status:'Finished',stdout:fault==='wrong'?'999':'168\n'};
        const answer=()=>json({success:true,data:{id:uid,state,result:state==='done'?result:null}});
        if(fault==='late'&&state==='done')return setTimeout(answer,12000);
        return answer();
      }
      json({error:'not found'},404);
    });});
    await new Promise(r=>server.listen(0,'127.0.0.1',r));
    const dir=fs.mkdtempSync(path.join(os.tmpdir(),'ecollab-feature-test-'));
    try{
      for(const f of['api-capacity.js','programs.json'])fs.copyFileSync(path.join(root,'tools/feature-capacity',f),path.join(dir,f));
      fs.copyFileSync(path.join(root,'tools/load-tests/plan.js'),path.join(dir,'plan.js'));
      fs.writeFileSync(path.join(dir,'accounts.json'),JSON.stringify([1,2].map(id=>({id,email:'k6_1234abcd_'+id+'@example.invalid',password:'x'.repeat(48)}))));
      fs.writeFileSync(path.join(dir,'feature-target.json'),JSON.stringify({run_id:'capacity-20261018-000000-1234abcd',fixture_id:fixture,mode,server_id:19,base_url:'http://127.0.0.1:'+server.address().port,task_ids:{'2:c':1,'2:cpp':2,'2:python':3,'2:java':4}}));
      let output='';const code=await new Promise((resolve,reject)=>{const child=spawn(process.env.K6_BIN||'k6',['run','-e','VUS=2','-e','RAMP_SECONDS=5','-e','POLL_SECONDS=1','-e','DEADLINE_SECONDS=10','-e','REPORT_FILE=result.json','api-capacity.js'],{cwd:dir});child.stdout.on('data',d=>output+=d);child.stderr.on('data',d=>output+=d);child.on('error',reject);child.on('close',resolve);});
      const result=JSON.parse(fs.readFileSync(path.join(dir,'result.json'),'utf8'));
      assert.equal(result.capacity_pass,!fault,output);assert.equal(code===0,!fault,output);assert.equal(seen.size,2);
      if(mode==='ai')assert.equal(deleted,2,'Synthetic sessions cleaned up');
      if(!fault)assert.equal(result.finished,2);else assert(result.failed_thresholds.length>0);
      console.log('PASS: real k6 '+mode+' '+(fault||'success'));
    }finally{server.close();fs.rmSync(dir,{recursive:true,force:true});}
  }
})().catch(e=>{console.error(e);process.exitCode=1;});
