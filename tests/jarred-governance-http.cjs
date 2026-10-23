'use strict';
const fs=require('fs'),os=require('os'),path=require('path'),net=require('net'),assert=require('node:assert/strict'),{spawn}=require('child_process');
const root=path.resolve(__dirname,'..'),dir=fs.mkdtempSync(path.join(os.tmpdir(),'jarred-governance-'));let server;
function file(name,content){const p=path.join(dir,name);fs.mkdirSync(path.dirname(p),{recursive:true});fs.writeFileSync(p,content);}
function copy(name){file(name,fs.readFileSync(path.join(root,name)));}
(async()=>{
 for(const name of ['API/chat/ai-assist.php','services/UserPreferences.php','services/ColorPaletteService.php','services/CodeLabIntegrityPolicy.php'])copy(name);
 file('config.php',`<?php define('APP_DEBUG',true);function env($key,$default=null){return $key==='GEMINI_API_KEY'?'synthetic-provider-key':$default;}`);
 file('database/config/db.php',`<?php class Database{static function getInstance(){ $db=new PDO('sqlite:'.__DIR__.'/fixture.sqlite');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('CREATE TABLE IF NOT EXISTS user_settings(user_id INTEGER PRIMARY KEY,jarred_enabled INTEGER); INSERT OR IGNORE INTO user_settings VALUES(1,0),(2,1),(3,1)');return $db;}}`);
 file('security/middleware/AuthMiddleware.php',`<?php class AuthMiddleware{static function startSession(){session_start();}static function requireAuth($api){$id=(int)($_SERVER['HTTP_X_FIXTURE_USER']??0);if(!$id){http_response_code(401);echo '{}';exit;}$_SESSION['user_id']=$id;return ['id'=>$id];}static function verifyCsrf(){if(($_SERVER['HTTP_X_CSRF_TOKEN']??'')!=='fixture'){http_response_code(403);echo '{}';exit;}}}`);
 file('security/AuditLogger.php','<?php');file('security/SecurityHeaders.php','<?php class SecurityHeaders{static function send($isApi=false){}}');
 file('security/rate-limit/RateLimiter.php',`<?php class RateLimiter{function attempt($action,$uid,$max,$window){$p=__DIR__.'/counts.json';$all=is_file($p)?json_decode(file_get_contents($p),true):[];$key=$action.':'.$uid;$n=$all[$key]??0;$allowed=$n<$max;if($allowed){$all[$key]=$n+1;file_put_contents($p,json_encode($all));}return ['allowed'=>$allowed,'retry_after'=>3600];}}`);
 file('services/GeminiService.php',`<?php class GeminiService{function __construct(...$args){}function generate(...$args){if(session_status()===PHP_SESSION_ACTIVE)throw new LogicException('PHP session held during inference');$p=__DIR__.'/calls';file_put_contents($p,(string)(1+(int)@file_get_contents($p)));if($args[0][0]['content']==='outage')throw new RuntimeException('PRIVATE_PROMPT_PRIVATE_KEY_PRIVATE_DOCUMENT');return ['text'=>'Authorized educational suggestion'];}}`);
 const socket=net.createServer();await new Promise(r=>socket.listen(0,'127.0.0.1',r));const port=socket.address().port;await new Promise(r=>socket.close(r));
 server=spawn(process.env.PHP_BIN||'php',[...JSON.parse(process.env.PHP_FIXTURE_ARGS||'[]'),'-d','display_errors=0','-d','log_errors=1','-d','error_log='+path.join(dir,'error.log'),'-S','127.0.0.1:'+port,'-t',dir],{stdio:['ignore','ignore','pipe']});let stderr='';server.stderr.on('data',x=>stderr+=x);
 const endpoint='http://127.0.0.1:'+port+'/API/chat/ai-assist.php';
 for(let i=0;i<100;i++){try{await fetch(endpoint);break;}catch(e){if(i===99)throw new Error(stderr);await new Promise(r=>setTimeout(r,20));}}
 async function request(user,prompt='Explain recursion',surface='chat',csrf='fixture'){const r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-Fixture-User':String(user),'X-CSRF-Token':csrf},body:JSON.stringify({prompt,surface})});return {status:r.status,body:await r.json()};}
 assert.equal((await request(0)).status,401);assert.equal((await request(2,'Explain recursion','chat','bad')).status,403);
 assert.equal((await request(1)).status,403,'Disabled Jarred reached provider');assert(!fs.existsSync(path.join(dir,'services/calls')));assert(!fs.existsSync(path.join(dir,'security/rate-limit/counts.json')),'Disabled request consumed admission quota');
 assert.equal((await request(2,'Explain recursion','code_lab')).status,403);
 const ok=await request(2);assert.equal(ok.status,200);assert.equal(ok.body.suggestion,'Authorized educational suggestion');
 assert.equal((await request(3)).status,200,'Other authorized identity lost functionality');
 const outage=await request(2,'outage');assert.equal(outage.status,500);assert(!JSON.stringify(outage).includes('PRIVATE_'));
 for(let i=0;i<18;i++)assert.equal((await request(2)).status,200);
 const denied=await request(2);assert.equal(denied.status,429);assert.equal(denied.body.retry_after,3600);
 assert.equal(Number(fs.readFileSync(path.join(dir,'services/calls'))),21,'Rejected request reached provider or admitted failure was refunded');
 const logs=fs.readFileSync(path.join(dir,'error.log'),'utf8')+stderr;assert(!logs.includes('PRIVATE_'),'Exception content entered operational logs');
 console.log('PASS: actual suggestion endpoint honors Jarred opt-out, auth/CSRF, session release, unchanged 20/hour admission accounting, overload and safe provider errors; synthetic DB/auth/provider boundaries');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(async()=>{if(server&&server.exitCode===null){server.kill();await new Promise(r=>server.once('exit',r));}fs.rmSync(dir,{recursive:true,force:true});});
