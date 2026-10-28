<?php
declare(strict_types=1);
// Rehearsal only: the bootstrap rejects every target except its disposable loopback DB.
require_once __DIR__.'/phase3y-bootstrap.php';
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$uid=(int)$in['user_id'];
$s=$db->prepare('SELECT email FROM users WHERE id=?');$s->execute([$uid]);if(!preg_match('/^phase3y[a-z0-9_-]+@example\.test$/D',(string)$s->fetchColumn()))throw new RuntimeException('Owned actor');
$db->prepare('DELETE FROM otp_codes WHERE user_id=?')->execute([$uid]);
$code='owned-legacy-code';$hash=password_hash($code,PASSWORD_BCRYPT,['cost'=>10]);
foreach(['reset_password','verify_email','2fa']as$action){$db->prepare('INSERT INTO otp_codes(user_id,action,code_hash,expires_at,created_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR))')->execute([$uid,$action,$hash]);}
// A retained used row with intentionally unknown, inconsistent historical clocks.
$db->prepare("INSERT INTO otp_codes(user_id,action,code_hash,expires_at,created_at,used_at) VALUES(?,'legacy_used',?,'2001-02-03 04:05:06','2002-03-04 05:06:07','2000-01-02 03:04:05')")->execute([$uid,$hash]);
$used=$db->prepare('SELECT * FROM otp_codes WHERE user_id=? AND used_at IS NOT NULL ORDER BY id');$used->execute([$uid]);$before=$used->fetchAll();
// Model the stopped/drained cutover; do not filter by ambiguous expiry/creation.
$invalidate=$db->prepare('UPDATE otp_codes SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL');$invalidate->execute([$uid]);$changed=$invalidate->rowCount();
$used->execute([$uid]);$after=$used->fetchAll();$old=array_values(array_filter($after,fn($r)=>$r['action']==='legacy_used'));
if($old!==$before||$changed!==3)throw new RuntimeException('History changed during transition');
$otp=new OtpService();foreach(['reset_password','verify_email','2fa']as$action){if($otp->countValid($uid,$action)!==0||$otp->verify($uid,$code,$action)['success'])throw new RuntimeException('Legacy code remained usable');}
$fresh=$otp->generate($uid);if(!$otp->verify($uid,$fresh)['success'])throw new RuntimeException('Fresh request cannot complete');
// Rollback policy is forward-only for invalidated codes: no used_at clearing.
$used->execute([$uid]);$history=array_values(array_filter($used->fetchAll(),fn($r)=>$r['action']==='legacy_used'));if($history!==$before)throw new RuntimeException('Historical timestamp changed');
echo json_encode(['status'=>'PASS','scope'=>'owned actor only; no production transition script','legacy_unused_invalidated'=>$changed,'legacy_used_rows_preserved_byte_for_byte'=>true,'fresh_request_success'=>true,'rollback_resurrection_allowed'=>false,'purge_invoked'=>false,'retention_gate'=>'No production purge until unknown-provenance history is retained outside active UTC cleanup under an approved retention plan'],JSON_THROW_ON_ERROR);
