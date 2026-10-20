<?php
declare(strict_types=1);
require_once __DIR__.'/../services/PeerMatchingService.php';
require_once __DIR__.'/../services/PeerSemanticClient.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
class CountStatement extends PDOStatement {
    protected function __construct(private CountPDO $owner) {}
    public function execute(?array $params=null):bool{$this->owner->statements++;return parent::execute($params);}
}
class CountPDO extends PDO {
    public int $statements=0;
    public function __construct(){parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[CountStatement::class,[$this]]);}
}
$db=new CountPDO();
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY); CREATE TABLE pm_user_study_prefs(user_id INTEGER PRIMARY KEY,study_style TEXT); CREATE TABLE pm_subjects(id INTEGER PRIMARY KEY,name TEXT); CREATE TABLE pm_interest_tags(id INTEGER PRIMARY KEY,name TEXT); CREATE TABLE pm_hobby_tags(id INTEGER PRIMARY KEY,name TEXT); CREATE TABLE pm_user_subjects(user_id INTEGER,subject_id INTEGER,role TEXT,proficiency TEXT,PRIMARY KEY(user_id,subject_id)); CREATE TABLE pm_user_interests(user_id INTEGER,interest_id INTEGER,PRIMARY KEY(user_id,interest_id)); CREATE TABLE pm_user_hobbies(user_id INTEGER,hobby_id INTEGER,PRIMARY KEY(user_id,hobby_id)); CREATE TABLE friendships(id INTEGER PRIMARY KEY,requester_id INTEGER,addressee_id INTEGER,status TEXT); CREATE TABLE pm_match_requests(id INTEGER PRIMARY KEY,requester_id INTEGER,addressee_id INTEGER,status TEXT)');
$db->exec("INSERT INTO pm_subjects VALUES(1,'PHP'),(2,'Math'); INSERT INTO pm_interest_tags VALUES(1,'Databases'); INSERT INTO pm_hobby_tags VALUES(1,'Chess')");
for($i=1;$i<=101;$i++){
    $db->exec("INSERT INTO users VALUES($i); INSERT INTO pm_user_study_prefs VALUES($i,'group'); INSERT INTO pm_user_subjects VALUES($i,1,'learner','intermediate'),($i,2,'mentor','advanced'); INSERT INTO pm_user_interests VALUES($i,1); INSERT INTO pm_user_hobbies VALUES($i,1)");
}
$db->exec("INSERT INTO friendships VALUES(1,1,2,'accepted'),(2,3,1,'pending'),(3,2,1,'blocked'); INSERT INTO pm_match_requests VALUES(1,1,2,'accepted'),(2,1,4,'pending')");
function legacy(PDO $db,int $id):array{
    $out=[];
    foreach(['prefs'=>'SELECT * FROM pm_user_study_prefs WHERE user_id=? LIMIT 1','subjects'=>'SELECT p.subject_id,p.role,p.proficiency,t.name FROM pm_user_subjects p JOIN pm_subjects t ON t.id=p.subject_id WHERE p.user_id=?','interests'=>'SELECT p.interest_id,t.name FROM pm_user_interests p JOIN pm_interest_tags t ON t.id=p.interest_id WHERE p.user_id=?','hobbies'=>'SELECT p.hobby_id,t.name FROM pm_user_hobbies p JOIN pm_hobby_tags t ON t.id=p.hobby_id WHERE p.user_id=?'] as$key=>$sql){$q=$db->prepare($sql);$q->execute([$id]);$out[$key]=$key==='prefs'?($q->fetch(PDO::FETCH_ASSOC)?:[]):$q->fetchAll(PDO::FETCH_ASSOC);}
    return $out;
}
$service=new PeerMatchingService();$rows=[];
foreach([0,1,50,100]as$n){
    $ids=$n?array_reverse(range(2,$n+1)):[];
    $db->statements=0;$start=hrtime(true);$old=[];foreach($ids as$id)$old[$id]=legacy($db,$id);$oldMs=(hrtime(true)-$start)/1e6;$oldCount=$db->statements;
    $db->statements=0;$start=hrtime(true);$new=$service->loadProfiles($db,$ids);$newMs=(hrtime(true)-$start)/1e6;$newCount=$db->statements;
    check($old===$new,"Profile parity N=$n");check($newCount===($n?4:0),"Bounded profile queries N=$n");
    foreach($ids as$id){foreach([null,0.,80.,100.]as$semantic)check($service->scoreProfiles($old[$id],$old[$id],$semantic)===$service->scoreProfiles($new[$id],$new[$id],$semantic),'Score parity');check(PeerSemanticClient::profileText($old[$id])===PeerSemanticClient::profileText($new[$id]),'Semantic input parity');}
    $db->statements=0;$relations=$service->loadRelationships($db,1,$ids);check($db->statements===($n?1:0),'Relationship batch count');
    foreach($ids as$id){$q=$db->prepare('SELECT status FROM friendships WHERE (requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?) LIMIT 1');$q->execute([1,$id,$id,1]);check(($q->fetchColumn()?:null)===$relations[$id]['friendship'],'Friendship direction and reciprocal parity');$q=$db->prepare('SELECT status FROM pm_match_requests WHERE requester_id=? AND addressee_id=? LIMIT 1');$q->execute([1,$id]);check(($q->fetchColumn()?:null)===$relations[$id]['request_status'],'Request direction parity');}
    $rows[]=['candidates'=>$n,'legacy_profile_statements'=>$oldCount,'batch_profile_statements'=>$newCount,'legacy_profile_ms'=>round($oldMs,3),'batch_profile_ms'=>round($newMs,3)];
}
check($service->loadProfile($db,999)===['prefs'=>[],'subjects'=>[],'interests'=>[],'hobbies'=>[]],'Missing profile shape');
check(count($service->loadProfiles($db,[1,1,0,-1]))===1,'Deduplicated valid IDs');
echo json_encode(['scope'=>'SQLite synthetic profile-loading microbenchmark; not HTTP/VPS capacity','results'=>$rows,'memory_peak_bytes'=>memory_get_peak_usage(true)],JSON_PRETTY_PRINT)."\n";
echo "PASS peer batch: 0/1/50/100, reversed candidate order, exact profiles/scores/semantic inputs, relationships and empty profiles\n";
