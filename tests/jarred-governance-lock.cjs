'use strict';
const http=require('http'),assert=require('node:assert/strict'),path=require('path'),{execFile}=require('child_process');
let received,reply,calls=0;const arrived=new Promise(r=>received=r);
const server=http.createServer((req,res)=>{++calls;req.resume();req.on('end',()=>{reply=()=>res.end(JSON.stringify({message:{content:'Synthetic answer'}}));received();});});
(async()=>{await new Promise(r=>server.listen(0,'127.0.0.1',r));const url='http://127.0.0.1:'+server.address().port;
 const run=()=>new Promise((resolve,reject)=>execFile(process.env.PHP_BIN||'php',[...JSON.parse(process.env.PHP_FIXTURE_ARGS||'[]'),'-r',`require 'services/OllamaService.php';try{echo json_encode((new OllamaService('${url}'))->generate([['role'=>'user','content'=>'Synthetic concept']], '', 10));}catch(RuntimeException $e){echo json_encode(['status'=>$e->getCode()]);}`],{cwd:path.resolve(__dirname,'..'),timeout:10000},(e,out,err)=>e?reject(new Error(err||String(e))):resolve(JSON.parse(out))));
 try{const first=run();await arrived;assert.equal((await run()).status,503,'Second process bypassed inference lock');assert.equal(calls,1,'Rejected request reached backend');reply();assert.equal((await first).text,'Synthetic answer');console.log('PASS: two PHP processes sharing one temp namespace admit one local HTTP request and reject the concurrent request with503; no model inference or cross-namespace claim');}finally{if(reply)reply();await new Promise(r=>server.close(r));}
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
