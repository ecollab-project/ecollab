// Exercise the actual endpoint and auth middleware; database fixtures isolate storage/HTTP from production data.
const assert=require('node:assert/strict'), fs=require('node:fs'), os=require('node:os'), path=require('node:path');
const {spawn}=require('node:child_process'), net=require('node:net');
const {JSDOM,CookieJar}=require('jsdom');
const root=path.resolve(__dirname,'..');
const temporary=fs.mkdtempSync(path.join(os.tmpdir(),'ecollab-tus-http-'));
const app=path.join(temporary,'app');let server;
const write=(name,text)=>{const p=path.join(app,name);fs.mkdirSync(path.dirname(p),{recursive:true});fs.writeFileSync(p,text);};
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
  const socket=net.createServer();await new Promise(r=>socket.listen(0,'127.0.0.1',r));
  const port=socket.address().port;await new Promise(r=>socket.close(r));const base='http://127.0.0.1:'+port;
  for(const name of ['API/chat/resumable-upload.php','services/ChatTusStorage.php','services/ChatUploadAccess.php','security/middleware/AuthMiddleware.php'])write(name,fs.readFileSync(path.join(root,name)));
  write('config.php',`<?php
    ini_set('session.save_path',${JSON.stringify(temporary)});
    define('ROOT_PATH',__DIR__);define('BASE_URL',${JSON.stringify(base)});define('UPLOAD_DIR',__DIR__.'/uploads/');
    define('SESSION_LIFETIME',3600);define('SESSION_SECURE',false);define('SESSION_SAMESITE','Lax');
    function env($key,$default=null){return $key==='CHAT_RESUMABLE_UPLOADS'?'true':$default;}
  `);
  write('database/config/db.php',String.raw`<?php
    class Database {
      static function getInstance(){return new self;}
      function prepare($sql){return new FixtureStatement($sql);}
    }
    class FixtureStatement {
      private $sql;private $args;
      function __construct($sql){$this->sql=$sql;}
      function execute($args){$this->args=$args;}
      function fetchColumn(){if(str_contains($this->sql,'FROM users'))return 'student';if(str_contains($this->sql,'moderation_actions'))return false;return 1;}
      function fetch($mode){return ($this->args[':id']??0)===20?['type'=>'text','is_private'=>0,'created_by'=>5,'server_id'=>5,'server_role'=>'member','has_access'=>1]:false;}
    }
  `);
  write('login.php',"<?php require 'config.php';require 'security/middleware/AuthMiddleware.php';AuthMiddleware::startSession();$_SESSION['user_id']=18;$_SESSION['csrf_token']='test-token';echo 'ok';");
  write('API/auth/csrf-token.php',"<?php require '../../config.php';require '../../security/middleware/AuthMiddleware.php';AuthMiddleware::requireAuth(true);header('Content-Type: application/json');echo json_encode(['token'=>AuthMiddleware::csrfToken()]);");
  const args=process.env.PHP_TEST_ARGS?JSON.parse(process.env.PHP_TEST_ARGS):[];
  server=spawn(process.env.PHP_BINARY||'php',[...args,'-S','127.0.0.1:'+port,'-t',app],{stdio:['ignore','pipe','pipe']});
  let logs='';server.stderr.on('data',d=>{logs+=d.toString();});server.on('error',e=>{logs+=e.message;});
  let ready=false;for(let i=0;i<50;i++){try{if((await fetch(base+'/login.php')).ok){ready=true;break;}}catch{}await sleep(100);}
  assert(ready,'PHP endpoint did not start: '+logs);
  const login=await fetch(base+'/login.php');const cookie=login.headers.get('set-cookie').split(';')[0];
  const endpoint=base+'/API/chat/resumable-upload.php';
  const headers={'Cookie':cookie,'X-CSRF-Token':'test-token','Tus-Resumable':'1.0.0'};
  const request=(url,method='GET',extra={},body)=>fetch(url,{method,headers:{...headers,...extra},body});
  const unauth=await fetch(endpoint,{method:'HEAD'});
  assert.equal(unauth.status,401, logs+'\n'+await(await fetch(endpoint)).text());
  assert.equal((await request(endpoint,'POST',{'X-CSRF-Token':'wrong'})).status,403);
  assert.equal((await request(endpoint,'OPTIONS')).headers.get('Tus-Extension'),'creation,termination');
  assert.equal((await request(endpoint,'POST',{'Tus-Resumable':'2.0.0'})).status,412);
  const metadata=id=>'name '+Buffer.from('note.php').toString('base64')+',kind '+Buffer.from('channel').toString('base64')+',target '+Buffer.from(String(id)).toString('base64');
  assert.equal((await request(endpoint,'POST',{'Upload-Length':'5','Upload-Metadata':metadata(21)})).status,403);
  const created=await request(endpoint,'POST',{'Upload-Length':'5','Upload-Metadata':metadata(20)});
  assert.equal(created.status,201);const url=created.headers.get('location');assert(url.startsWith(endpoint+'?id='));
  let head=await request(url,'HEAD');assert.equal(head.headers.get('upload-offset'),'0');
  assert.equal((await request(url,'PATCH',{'Upload-Offset':'0','Content-Type':'application/offset+octet-stream'},'he')).status,204);
  assert.equal((await request(url,'PATCH',{'Upload-Offset':'0','Content-Type':'application/offset+octet-stream'},'he')).status,409);
  head=await request(url,'HEAD');assert.equal(head.headers.get('upload-offset'),'2');
  assert.equal((await request(url,'PATCH',{'Upload-Offset':'2','Content-Type':'text/plain'},'llo')).status,415);
  assert.equal((await request(url)).status,409);
  assert.equal((await request(url,'PATCH',{'Upload-Offset':'2','Content-Type':'application/offset+octet-stream'},'llo')).status,204);
  assert.equal((await request(url,'GET',{'X-CSRF-Token':'wrong'})).status,403);
  const complete=await (await request(url)).json();assert.equal(complete.success,true);assert(complete.file_path.endsWith('.txt'));
  assert.equal(fs.readFileSync(path.join(app,complete.file_path),'utf8'),'hello');
  assert.equal((await request(url,'DELETE')).status,204);assert.equal((await request(url,'HEAD')).status,404);

  // A real Uppy+Tus browser instance must interoperate with this PHP endpoint, not just mocks.
  const jar=new CookieJar();jar.setCookieSync(cookie,base);
  const dom=new JSDOM('<body></body>',{url:base+'/chat.php',runScripts:'outside-only',cookieJar:jar,pretendToBeVisual:true});
  const w=dom.window;w.ECOLLAB={userId:18,baseUrl:base,csrfToken:'test-token'};
  w.fetch=(url,options={})=>fetch(url,{...options,headers:{Cookie:cookie,...options.headers}});
  w.eval(fs.readFileSync(path.join(root,'assets/js/chat/resumable-uploads.js'),'utf8'));
  const bytes='A'.repeat(1024*1024+25);
  const file=new w.File([bytes],'real.txt',{type:'text/plain',lastModified:100});
  const upload=w.EcollabUploads.upload(file,{kind:'channel',id:20});
  const result=await Promise.race([upload,sleep(10000).then(()=>{throw new Error('Real Uppy transfer timed out: '+logs);})]);
  assert.equal(result.file_size,bytes.length);assert.equal(fs.readFileSync(path.join(app,result.file_path),'utf8'),bytes);
  const reused=await w.EcollabUploads.upload(file,{kind:'channel',id:20});
  assert.equal(reused.file_path,result.file_path,'Reselection should recover the stored upload URL');
  const dm=await w.EcollabUploads.upload(file,{kind:'dm',id:3});
  assert.notEqual(dm.file_path,result.file_path,'A different chat must use a different resumable upload');
  dom.window.close();console.log('Chat tus HTTP, authentication and real Uppy integration checks passed');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{
  server?.kill();fs.rmSync(temporary,{recursive:true,force:true});
});
