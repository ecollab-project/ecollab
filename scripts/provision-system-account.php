<?php
declare(strict_types=1);

// No HTTP entry, no existing-account adoption, and no production target.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('PHASE3AD_OWNED_FRESH_IDENTITY') !== '1') {
    throw new RuntimeException('Owned fresh synthetic identity authorization required');
}
require_once dirname(__DIR__) . '/database/config/db.php';
$db = Database::getInstance();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'classroom_perf_test'
    || !str_starts_with((string)$db->getAttribute(PDO::ATTR_CONNECTION_STATUS), '127.0.0.1 ')
    || $db->inTransaction() || (int)$db->query('SELECT @@autocommit')->fetchColumn() !== 1) {
    throw new RuntimeException('Owned loopback database and independent transaction required');
}
$applied = $db->query("SELECT checksum FROM schema_migrations WHERE filename='069_system_identity.sql' AND success=1")->fetchColumn();
if (!is_string($applied) || !hash_equals(hash_file('sha256', dirname(__DIR__).'/database/migrations/069_system_identity.sql'), $applied)) {
    throw new RuntimeException('Verified identity migration required');
}
$lock = 'ec-provision-'.substr(hash('sha256', 'classroom_perf_test'),0,32);
$s=$db->prepare('SELECT GET_LOCK(?,10)');$s->execute([$lock]);
if ((int)$s->fetchColumn() !== 1) throw new RuntimeException('Provisioning lock unavailable');
try {
    $db->beginTransaction();
    $s=$db->prepare('SELECT id FROM users WHERE username=? OR email=? FOR UPDATE');
    $s->execute(['ecollab_ai','jarred@system.example.invalid']);
    if ($s->fetch()) throw new RuntimeException('Identity collision - refusing adoption');
    $db->exec("INSERT INTO users(username,email,full_name,password_hash,role,status,is_system,email_verified,remember_token,sso_provider,sso_uid) VALUES('ecollab_ai','jarred@system.example.invalid','Jarred',NULL,'student','offline',1,0,NULL,NULL,NULL)");
    $id=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO user_profiles(user_id) VALUES(?)')->execute([$id]);
    $db->prepare('INSERT INTO user_settings(user_id) VALUES(?)')->execute([$id]);
    $db->commit();
    echo json_encode(['success'=>true,'created_user_id'=>$id,'is_system'=>1],JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
} finally {
    $s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->execute([$lock]);
}
