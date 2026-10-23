'use strict';
// Actual copied PHP route/provider; synthetic auth/DB and loopback HTTP model only.
const fs=require('fs'),os=require('os'),path=require('path'),net=require('net'),http=require('http'),assert=require('node:assert/strict'),{spawn,execFile,execFileSync}=require('child_process');
const ROOT=path.resolve(__dirname,'..'),baseline=process.argv.includes('--baseline');
const dir=fs.mkdtempSync(path.join(process.env.RUNNER_TEMP||ROOT,'.shared-ai-fixture-'));fs.chmodSync(dir,0o700);
const namespace=process.env.JARRED_TEST_MOUNT_NS==='1';let app,provider,active=null,calls=0,behavior='normal',waiter;
async function stopApp(){if(app&&app.exitCode===null){const ended=new Promise(r=>app.once('exit',r));if(namespace)execFileSync('sudo',['-n','kill','-TERM','--','-'+app.pid]);else app.kill();await ended;}}
let profiles=Array.from({length:4},(_,i)=>({id:i+1,username:'student'+(i+1),full_name:'Synthetic '+(i+1),role:'student',bio:'Studies databases'}));
function file(p,s){const f=path.join(dir,p);fs.mkdirSync(path.dirname(f),{recursive:true});fs.writeFileSync(f,s);}
function copy(p){let s=baseline?execFileSync('git',['show','2e65196fca7448155c19519fda61fdda82c6c80d:'+p],{cwd:ROOT}):fs.readFileSync(path.join(ROOT,p));file(p,s);}
function groups(rows=profiles){return {groups:rows.reduce((a,p,i)=>{if(i%2===0)a.push({reason:'Synthetic compatible interests',members:[]});a.at(-1).members.push({id:p.id,fit:'Database practice'});return a;},[])};}
function response(res,obj){res.setHeader('Content-Type','application/json');res.end(JSON.stringify({model:'fixture',message:{content:JSON.stringify(obj)},done:true,done_reason:'stop'}));}
function saveProfiles(){file('profiles.json',JSON.stringify(profiles));}
function command(view,args){const php=process.env.PHP_BIN||'php',extra=JSON.parse(process.env.PHP_FIXTURE_ARGS||'[]');if(!namespace)return [php,[...extra,...args]];
 const shared=['OLLAMA_URL','OLLAMA_MODEL','OLLAMA_INFERENCE_LOCK_FILE'].map(k=>k+'='+env[k]);return ['sudo',['-n','env',...shared,'TMPDIR=/tmp','unshare','--mount','--fork','--propagation','private','bash',path.join(dir,'namespace.sh'),path.join(dir,view),php,...extra,...args]];}
let env;
function cli(kind='normal',view='a',timeout=3){const code=`require ${JSON.stringify(path.join(dir,'services/OllamaService.php'))};try{$s=new OllamaService(null,null,${timeout});$r=${kind==='json'?`$s->generateJson([['role'=>'user','content'=>'Synthetic grouping']])`:`$s->generate([['role'=>'user','content'=>'Synthetic concept']], '', 10)`};echo json_encode(['ok'=>true,'result'=>$r]);}catch(Throwable $e){echo json_encode(['status'=>$e->getCode(),'error'=>$e->getMessage()]);}`;const [bin,args]=command(view,['-r',code]);return new Promise((resolve,reject)=>execFile(bin,args,{env:{...env,TMPDIR:path.join(dir,view)},cwd:ROOT,timeout:10000},(e,out,err)=>e?reject(new Error(err||String(e))):resolve(JSON.parse(out))));}
async function port(){const s=net.createServer();await new Promise(r=>s.listen(0,'127.0.0.1',r));const p=s.address().port;await new Promise(r=>s.close(r));return p;}
(async()=>{
 for(const p of ['API/admin/group-recommendations.php','services/OllamaService.php'])copy(p);
 if(!baseline)copy('services/AdminGroupRecommendationService.php');
 for(const view of ['a','b']){fs.mkdirSync(path.join(dir,view));file(view+'/view',view);}
 file('namespace.sh','#!/bin/bash\nset -eu\nmount --bind "$1" /tmp\nview="$1"; shift\ntest "$(cat /tmp/view)" = "$(basename "$view")"\nexec "$@"\n');
 file('config.php',`<?php define('ROOT_PATH',__DIR__);define('APP_DEBUG',true);function env($k,$d=null){return getenv($k)?:$d;}`);
 file('database/config/db.php',`<?php class FixtureRows{function __construct(private array $rows){}function fetchAll(...$a){return $this->rows;}}class Database{static function getInstance(){return new self;}function query($sql){return new FixtureRows(str_starts_with($sql,'SHOW')?[['Field'=>'bio']]:json_decode(file_get_contents(ROOT_PATH.'/profiles.json'),true));}}`);
 file('security/middleware/AuthMiddleware.php',`<?php class AuthMiddleware{static function startSession(){session_start();}static function requireAuth($api){if(($_SERVER['HTTP_X_FIXTURE_USER']??'')!=='1'){http_response_code(401);echo '{}';exit;}return ['id'=>1,'role'=>$_SERVER['HTTP_X_FIXTURE_ROLE']??'student'];}static function verifyCsrf(){if(($_SERVER['HTTP_X_CSRF_TOKEN']??'')!=='fixture'){http_response_code(403);echo '{}';exit;}}}`);
 file('security/middleware/RoleMiddleware.php',`<?php class RoleMiddleware{static function requireRole($allowed,$api){if(!in_array($_SERVER['HTTP_X_FIXTURE_ROLE']??'', $allowed,true)){http_response_code(403);echo '{}';exit;}}}`);
 saveProfiles();file('inference.lock','');fs.chmodSync(path.join(dir,'inference.lock'),0o600);
 provider=http.createServer((req,res)=>{let raw='';req.on('data',c=>raw+=c);req.on('end',()=>{++calls;const payload=JSON.parse(raw);if(!baseline){assert.equal(payload.options.num_ctx,4096);assert(payload.options.num_thread<=2);assert(payload.options.num_predict<=1024);if(payload.format==='json'){assert.equal(payload.truncate,false);assert.equal(payload.shift,false);}}
  if(behavior==='hold'){active={res,payload};if(waiter){waiter();waiter=null;}return;}
  if(behavior==='oversize'){res.end('x'.repeat(1048577));return;}
  if(behavior==='bad-outer'){res.end('PRIVATE_PROFILE_INVALID_JSON');return;}
  if(behavior==='redirect'){res.writeHead(307,{Location:'http://127.0.0.1:1/private'});res.end();return;}
  if(behavior==='http-error'){res.writeHead(500);res.end('PRIVATE_PROFILE_PROVIDER_ERROR');return;}
  if(behavior==='timeout'){setTimeout(()=>{if(!res.destroyed)response(res,groups());},1500);return;}
  if(behavior==='missing-done'){res.end(JSON.stringify({message:{content:JSON.stringify(groups())}}));return;}
  if(behavior==='length'){res.end(JSON.stringify({message:{content:JSON.stringify(groups())},done:true,done_reason:'length'}));return;}
  let output=groups();if(behavior==='invented')output.groups[0].members[0].id=99999;
  if(behavior==='compact')output.groups.forEach(g=>g.members=g.members.map(m=>m.id));
  if(behavior==='too-large')output.groups=[{reason:'Synthetic group',members:output.groups.flatMap(g=>g.members)}];
  if(behavior==='bad-id')output.groups[0].members[0].id='1';
  if(behavior==='empty')output.groups=[];
  if(behavior==='missing')output.groups.pop();if(behavior==='duplicate')output.groups[1].members[0].id=1;
  if(behavior==='bad-json'){res.end(JSON.stringify({done:true,done_reason:'stop',message:{content:'```json\nPRIVATE_PROFILE\n```'}}));return;}
  if(payload.format==='json')response(res,output);else res.end(JSON.stringify({message:{content:'Synthetic concept answer'},done:true,done_reason:'stop'}));
 });});await new Promise(r=>provider.listen(0,'127.0.0.1',r));
 env={...process.env,OLLAMA_URL:'http://127.0.0.1:'+provider.address().port,OLLAMA_MODEL:'fixture',OLLAMA_INFERENCE_LOCK_FILE:path.join(dir,'inference.lock'),http_proxy:'http://127.0.0.1:1',ALL_PROXY:'http://127.0.0.1:1',NO_PROXY:baseline?'127.0.0.1':''};
 const ap=await port();const [bin,args]=command('b',['-d','display_errors=0','-d','log_errors=1','-d','error_log='+path.join(dir,'errors.log'),'-S','127.0.0.1:'+ap,'-t',dir]);app=spawn(bin,args,{cwd:ROOT,env:{...env,TMPDIR:path.join(dir,'b')},stdio:['ignore','ignore','pipe'],detached:namespace});let errors='';app.stderr.on('data',c=>errors+=c);
 const endpoint='http://127.0.0.1:'+ap+'/API/admin/group-recommendations.php';
 for(let i=0;i<100;i++){try{await fetch(endpoint);break;}catch(e){if(i===99)throw Error(errors);await new Promise(r=>setTimeout(r,20));}}
 const request=async(extra={},headers={})=>{const r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-Fixture-User':'1','X-Fixture-Role':'admin','X-CSRF-Token':'fixture',...headers},body:JSON.stringify({topic:'Database study',group_size:2,...extra})});return {status:r.status,body:await r.json()};};
 const held=()=>new Promise(r=>{waiter=r;});
 behavior='hold';let arrival=held();const normal=cli();await arrival;
 if(baseline){const pending=request();await new Promise(r=>setTimeout(r,200));assert.equal(calls,2,'Original admin request did not bypass inference lock');response(active.res,groups());const result=await pending;assert.equal(result.status,200); // finish normal request retained separately below
  console.log('REPRODUCED baseline: admin raw cURL reached backend while ordinary Jarred held its lock');return;
 }
 let before=calls;assert.equal((await request()).status,503);assert.equal(calls,before);active.res.end(JSON.stringify({message:{content:'Synthetic answer'}}));await normal;
 behavior='hold';arrival=held();const admin=request();await arrival;before=calls;assert.equal((await cli()).status,503);assert.equal(calls,before);response(active.res,groups());const ok=await admin;assert.equal(ok.status,200);assert.equal(ok.body.candidate_count,4);assert.equal(ok.body.groups.flatMap(g=>g.members).length,4);assert.equal(ok.body.source,'local_llm');assert.equal(ok.body.groups[0].members[0].username,'student1');
 behavior='normal';assert.equal((await request({}, {'X-Fixture-Role':'student'})).status,403);assert.equal((await request({}, {'X-Fixture-User':'0'})).status,401);assert.equal((await request({}, {'X-CSRF-Token':'bad'})).status,403);
 for(const mode of ['invented','missing','duplicate','too-large','bad-id','empty','bad-json','bad-outer','oversize','missing-done','length','redirect','http-error']){behavior=mode;before=calls;const r=await request();assert([502,503].includes(r.status),mode+': '+JSON.stringify(r));assert(!JSON.stringify(r).includes('PRIVATE_'));assert.equal(calls,before+1,'Unexpected automatic model retry');behavior='normal';assert.equal((await request()).status,200,'Lock not released after '+mode);}
 behavior='compact';const compact=await request();assert.equal(compact.status,200);assert.equal(compact.body.groups[0].members[0].fit,'');assert.equal(compact.body.groups[0].members[0].username,'student1');behavior='normal';
 before=calls;assert.equal((await request({topic:'x'.repeat(33000)})).status,422);assert.equal(calls,before);
 const original=profiles;profiles=Array.from({length:80},(_,i)=>({id:i+1,username:'s'+i,full_name:'S'+i,role:'student',bio:''}));saveProfiles();behavior='normal';const eighty=await request();assert.equal(eighty.status,200);assert.equal(eighty.body.candidate_count,80);assert.equal(new Set(eighty.body.groups.flatMap(g=>g.members.map(m=>m.id))).size,80);
 profiles[0].bio='x'.repeat(9000);saveProfiles();before=calls;assert.equal((await request()).status,422);assert.equal(calls,before,'Oversized profiles reached inference');profiles=original;saveProfiles();
 behavior='timeout';const timed=await cli('json','a',1);assert.equal(timed.status,503);behavior='normal';assert.equal((await request()).status,200,'Timeout leaked lock');
 behavior='hold';arrival=held();const crash=spawn(process.env.PHP_BIN||'php',[...JSON.parse(process.env.PHP_FIXTURE_ARGS||'[]'),'-r',`require ${JSON.stringify(path.join(dir,'services/OllamaService.php'))};(new OllamaService())->generate([['role'=>'user','content'=>'Synthetic interrupted worker']]);`],{cwd:ROOT,env,stdio:'ignore'});await arrival;crash.kill('SIGKILL');await new Promise(r=>crash.once('exit',r));active.res.end('{}');behavior='normal';assert.equal((await request()).status,200,'Killed PHP worker retained lock');
 behavior='oversize';assert.equal((await cli()).status,503,'Ordinary Jarred accepted oversized response');behavior='normal';assert.equal((await cli()).ok,true);
 const saved=env.OLLAMA_INFERENCE_LOCK_FILE;env.OLLAMA_INFERENCE_LOCK_FILE=path.join(dir,'missing.lock');before=calls;assert.equal((await cli()).status,503);assert.equal(calls,before,'Missing shared lock used a fallback');env.OLLAMA_INFERENCE_LOCK_FILE=saved;
 await stopApp();const [again,newArgs]=command('b',['-d','display_errors=0','-S','127.0.0.1:'+ap,'-t',dir]);app=spawn(again,newArgs,{cwd:ROOT,env,stdio:'ignore',detached:namespace});await new Promise(r=>setTimeout(r,200));assert.equal((await request()).status,200,'App worker restart lost shared protection');
 const log=(fs.existsSync(path.join(dir,'errors.log'))?fs.readFileSync(path.join(dir,'errors.log'),'utf8'):'')+errors;assert(!log.includes('PRIVATE_'));
 console.log('PASS: actual admin route/provider, both concurrency orders, all80 candidates, strict complete grouping, auth/CSRF, safe invalid/oversize/timeout errors, no retry, killed-worker lock release and app restart; '+(namespace?'actual separate Linux /tmp mount views':'different PHP TMPDIR views (not mount namespaces)'));
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(async()=>{if(active&&!active.res.writableEnded)active.res.end('{}');await stopApp();if(provider){provider.closeAllConnections();await new Promise(r=>provider.close(r));}fs.rmSync(dir,{recursive:true,force:true});});
