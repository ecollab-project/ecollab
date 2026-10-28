<?php
declare(strict_types=1);
namespace Tests\Integration;
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__).'/Support/LocalOtpTransport.php';
/** Independent persisted-expiry, intent-expiry and consumed-row contracts on native MariaDB. */
final class OtpExpiryTest extends TestCase
{
    private \PDO $db;
    private \AuthService $auth;
    private int $uid=0;
    private string $code;
    protected function setUp():void
    {
        $this->db=\Database::getInstance();$_SESSION=[];
        $this->auth=new \AuthService();$mail=new \Tests\Support\LocalOtpTransport();
        (new \ReflectionProperty(\AuthService::class,'otpService'))->setValue($this->auth,$mail);
        $email=uniqid('phase3ynative',true).'@example.test';
        $data=['email'=>$email,'full_name'=>'Owned Synthetic Actor','password'=>'owned-fixture-password-42','course'=>'BS Computer Science','year_level'=>2,'terms_agreed'=>true];
        $this->assertTrue($this->auth->sendPreSignupEmailOtp($email,$data['full_name'])['success']);
        $this->assertTrue($this->auth->verifyPreSignupEmailOtp($email,$mail->codeFor($email,'verify_email'))['success']);
        $registered=$this->auth->register($data);$this->assertTrue($registered['success']);$this->uid=$registered['user_id'];
        $_SESSION=[];$login=$this->auth->login($email,$data['password'],false,true);
        $this->assertTrue($login['success']);$this->assertTrue($login['otp_required']);$this->assertArrayNotHasKey('user_id',$_SESSION);
        $this->code=$mail->codeFor($email,'2fa');
    }
    private function row():array
    {
        $s=$this->db->prepare("SELECT expires_at>NOW() AS valid,used_at FROM otp_codes WHERE user_id=? AND action='2fa' ORDER BY id DESC LIMIT 1");$s->execute([$this->uid]);return $s->fetch();
    }
    protected function tearDown():void
    {
        if($this->uid){foreach(['otp_codes','pm_user_study_prefs','user_interests','user_hobbies','user_profiles','user_encrypted_pii','user_settings']as$t)$this->db->prepare('DELETE FROM '.$t.' WHERE user_id=?')->execute([$this->uid]);$this->db->prepare('DELETE FROM users WHERE id=?')->execute([$this->uid]);}
        $_SESSION=[];
    }
    public function testExpiredDatabaseCodeRejectsWithValidLoginIntent():void
    {
        $this->assertSame(1,(int)$this->row()['valid']);
        $this->db->prepare("UPDATE otp_codes SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE user_id=? AND action='2fa'")->execute([$this->uid]);
        $this->assertGreaterThan(time(),$_SESSION['pending_login_expires']);
        $this->assertFalse($this->auth->verifyOtp($this->uid,$this->code,'2fa')['success']);
        $this->assertArrayNotHasKey('user_id',$_SESSION);$this->assertSame($this->uid,$_SESSION['pending_login_user_id']);$this->assertNull($this->row()['used_at']);
    }
    public function testExpiredLoginIntentRejectsWithValidDatabaseCode():void
    {
        $this->assertSame(1,(int)$this->row()['valid']);$_SESSION['pending_login_expires']=time()-60;
        $this->assertFalse($this->auth->verifyOtp($this->uid,$this->code,'2fa')['success']);
        $this->assertArrayNotHasKey('user_id',$_SESSION);$this->assertArrayNotHasKey('pending_login_user_id',$_SESSION);$this->assertSame(1,(int)$this->row()['valid']);$this->assertNull($this->row()['used_at']);
    }
    public function testConsumedDatabaseCodeCannotBeVerifiedAgain():void
    {
        $this->assertTrue($this->auth->verifyOtp($this->uid,$this->code,'2fa')['success']);$used=$this->row()['used_at'];$this->assertNotNull($used);
        $this->assertFalse((new \OtpService())->verify($this->uid,$this->code,'2fa')['success']);$this->assertSame($used,$this->row()['used_at']);$this->assertSame($this->uid,$_SESSION['user_id']);
    }
}
