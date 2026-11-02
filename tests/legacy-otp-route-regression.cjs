'use strict';
const {execFileSync}=require('node:child_process');
const assert=require('node:assert/strict');
for(const method of ['GET','POST']){
 const script=`require 'config.php';require 'security/middleware/AuthMiddleware.php';ini_set('session.save_path',sys_get_temp_dir());AuthMiddleware::startSession();$_SESSION['csrf_token']='token';$_SERVER['HTTP_X_CSRF_TOKEN']='token';$_SERVER['REQUEST_METHOD']='${method}';ob_start();register_shutdown_function(function(){$body=ob_get_clean();echo json_encode(['status'=>http_response_code(),'body'=>json_decode($body,true),'pending'=>isset($_SESSION['pending_otp'])]);});require 'API/auth/send-otp.php';`;
 const r=JSON.parse(execFileSync(process.env.PHP_BIN||'php',['-r',script],{encoding:'utf8'}));
 assert.equal(r.status,410,'Unconsumed legacy OTP route must be explicitly retired');
 assert.equal(r.body.success,false);assert.equal(r.pending,false);
}
console.log('PASS: legacy OTP route returns 410 without generating or delivering codes');
