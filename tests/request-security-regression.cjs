'use strict';
const {execFileSync}=require('node:child_process'),assert=require('node:assert/strict');
const php=process.env.PHP_BIN||'php',args=JSON.parse(process.env.PHP_FIXTURE_ARGS||'[]');
const run=code=>execFileSync(php,[...args,'-r',code],{cwd:require('path').resolve(__dirname,'..'),encoding:'utf8'}).trim();
const base=`ini_set('session.save_path',sys_get_temp_dir());define('SESSION_LIFETIME',3600);define('SESSION_SECURE',false);define('SESSION_SAMESITE','Lax');require 'security/middleware/AuthMiddleware.php';AuthMiddleware::startSession();`;
for(const setup of ["", "$_SESSION['csrf_token']='valid';", "$_SERVER['HTTP_X_CSRF_TOKEN']='valid';", "$_SESSION['csrf_token']='valid';$_SERVER['HTTP_X_CSRF_TOKEN']='wrong';", "$_SESSION['csrf_token']='valid';$_POST['csrf_token']=['valid'];", "$_SESSION['csrf_token']='valid';$_POST['csrf_token']=1;"]){const data=JSON.parse(run(base+setup+`AuthMiddleware::verifyCsrf();echo 'unexpected';`));assert.equal(data.success,false);assert.match(data.error,/CSRF/);}
assert.equal(run(base+`$_SESSION['csrf_token']='valid';$_SERVER['HTTP_X_CSRF_TOKEN']='valid';AuthMiddleware::verifyCsrf();echo 'ok';`),'ok');
assert.equal(run(base+`echo ini_get('session.use_strict_mode').ini_get('session.use_only_cookies').ini_get('session.use_trans_sid');`),'110');
assert.equal(run(base+`$_SESSION['csrf_token']='valid';$_POST['_csrf_token']='valid';AuthMiddleware::verifyCsrf();echo 'ok';`),'ok');
for (const malformed of ["$_POST['csrf_token']=['bad'];","$_POST['csrf_token']=false;"]) {const data=JSON.parse(run(base+`require 'security/csrf/csrf.php';$_SESSION['csrf_token']='valid';`+malformed+`CSRF::verify();echo 'unexpected';`));assert.equal(data.success,false);}
for (const method of ['GET','POST']) {
 const result=JSON.parse(run(`require 'config.php';require 'security/middleware/AuthMiddleware.php';ini_set('session.save_path',sys_get_temp_dir());AuthMiddleware::startSession();$_SESSION['user_id']=1;$_GET['action']='leave_voice';$_SERVER['REQUEST_METHOD']='${method}';ob_start();register_shutdown_function(function(){$body=ob_get_clean();echo json_encode(['status'=>http_response_code(),'body'=>json_decode($body,true)]);});require 'API/chat/active-now.php';`));
 assert.equal(result.status,method==='GET'?405:403);assert.equal(result.body.success,false);
}
console.log('PASS: absent/empty/malformed/mismatched CSRF rejected, header/form tokens accepted, strict cookie-only sessions');
