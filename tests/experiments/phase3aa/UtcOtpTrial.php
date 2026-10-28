<?php
declare(strict_types=1);
namespace Tests\Experiments\Phase3aa;

/** NON-PRODUCTION TRIAL. Explicit CLI-test include only; no application autoload or route. */
if(PHP_SAPI!=='cli'||!defined('APP_ENV')||APP_ENV!=='testing'||APP_DEBUG||!getenv('PHASE3Y_PRIVATE')||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D',(string)getenv('CLASSROOM_DSN')))throw new \RuntimeException('Owned CLI test runtime required');
final class UtcOtpTrial extends \OtpService
{
 private \PDO $clockDb;
 public function __construct(){parent::__construct();$this->clockDb=\Database::getInstance();}
 public function generate(int $userId,string $action='reset_password'):string
 {
  $this->clockDb->prepare('UPDATE otp_codes SET used_at=UTC_TIMESTAMP() WHERE user_id=:uid AND action=:action AND used_at IS NULL')->execute([':uid'=>$userId,':action'=>$action]);
  $otp=str_pad((string)random_int(0,(int)(10 ** OTP_LENGTH)-1),OTP_LENGTH,'0',STR_PAD_LEFT);
  $hash=password_hash($otp,PASSWORD_BCRYPT,['cost'=>10]);
  $s=$this->clockDb->prepare('INSERT INTO otp_codes(user_id,action,code_hash,expires_at,created_at) VALUES(:uid,:action,:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL :ttl SECOND),UTC_TIMESTAMP())');
  $s->bindValue(':uid',$userId,\PDO::PARAM_INT);$s->bindValue(':action',$action);$s->bindValue(':hash',$hash);$s->bindValue(':ttl',OTP_EXPIRY,\PDO::PARAM_INT);$s->execute();return $otp;
 }
 public function verify(int $userId,string $submittedOtp,string $action='reset_password'):array
 {
  $s=$this->clockDb->prepare('SELECT id,code_hash FROM otp_codes WHERE user_id=:uid AND action=:action AND expires_at>UTC_TIMESTAMP() AND used_at IS NULL ORDER BY created_at DESC LIMIT 1');$s->execute([':uid'=>$userId,':action'=>$action]);$row=$s->fetch();
  if(!$row)return ['success'=>false,'error'=>'Code expired or not found. Please request a new one.'];
  if(!password_verify(trim($submittedOtp),$row['code_hash']))return ['success'=>false,'error'=>'Incorrect code. Please try again.'];
  // Deliberately preserve existing SELECT-then-UPDATE semantics; no atomic-consumption redesign.
  $this->clockDb->prepare('UPDATE otp_codes SET used_at=UTC_TIMESTAMP() WHERE id=:id')->execute([':id'=>$row['id']]);return ['success'=>true];
 }
 public function countValid(int $userId,string $action='reset_password'):int
 {
  $s=$this->clockDb->prepare('SELECT COUNT(*) FROM otp_codes WHERE user_id=:uid AND action=:action AND expires_at>UTC_TIMESTAMP() AND used_at IS NULL');$s->execute([':uid'=>$userId,':action'=>$action]);return (int)$s->fetchColumn();
 }
 public function purgeExpired():int
 {
  $s=$this->clockDb->prepare('DELETE FROM otp_codes WHERE expires_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR) OR used_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)');$s->execute();return $s->rowCount();
 }
}
