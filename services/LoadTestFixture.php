<?php
declare(strict_types=1);
final class LoadTestFixture
{
    private array $columnCache=[];
    public function __construct(private PDO $db){}
    private function columns(string $table):array{return $this->columnCache[$table]??=$this->loadColumns($table);}
    private function loadColumns(string $table):array{return array_column($this->db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC),'Field');}
    private function insert(string $table,array $values):int{
        $available=$this->columns($table);$values=array_intersect_key($values,array_flip($available));
        $names=array_keys($values);$sql='INSERT INTO `'.$table.'` (`'.implode('`,`',$names).'`) VALUES ('.implode(',',array_fill(0,count($names),'?')).')';
        $this->db->prepare($sql)->execute(array_values($values));return (int)$this->db->lastInsertId();
    }
    public function create(int $count,string $base,string $ws,string $directory):string{
        if($count<1||$count>1000)throw new InvalidArgumentException('Choose 1 to 1000 synthetic students');
        if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Cannot create private fixture directory');
        $id='k6-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));$path=$directory.'/'.$id.'.json';
        $password=bin2hex(random_bytes(24));$hash=password_hash($password,PASSWORD_BCRYPT,['cost'=>defined('BCRYPT_COST')?BCRYPT_COST:12]);
        $users=[];$this->db->beginTransaction();
        try{
            for($i=1;$i<=$count;$i++){
                $username='k6_'.substr($id,-8).'_'.$i;$email=$username.'@example.invalid';
                $uid=$this->insert('users',['username'=>$username,'email'=>$email,'full_name'=>'Load Test Student '.$i,'password_hash'=>$hash,'role'=>'student','status'=>'offline','email_verified'=>1,'is_online'=>0]);
                $users[]=['id'=>$uid,'email'=>$email,'password'=>$password];
            }
            $owner=$users[0]['id'];
            $server=$this->insert('servers',['owner_id'=>$owner,'name'=>'Private capacity test '.$id,'slug'=>$id,'description'=>'Synthetic capacity-test data; remove with load-test-fixture.php cleanup.','type'=>'private','visibility'=>'private','status'=>'active','member_count'=>$count]);
            $channel=$this->insert('channels',['server_id'=>$server,'name'=>'general','slug'=>'general','type'=>'text','is_private'=>1,'is_locked'=>0,'created_by'=>$owner,'owner_id'=>$owner,'visibility'=>'private']);
            foreach($users as $user){$this->insert('server_members',['server_id'=>$server,'user_id'=>$user['id'],'server_role'=>$user['id']===$owner?'owner':'member']);$this->insert('channel_members',['channel_id'=>$channel,'user_id'=>$user['id']]);}
            for($i=0;$i<50;$i++)$this->insert('messages',['channel_id'=>$channel,'sender_id'=>$users[$i%$count]['id'],'content'=>'[Capacity test '.$id.'] Sample academic discussion '.$i,'content_type'=>'text']);
            $data=['version'=>1,'fixture_id'=>$id,'base_url'=>rtrim($base,'/'),'ws_url'=>$ws,'server_id'=>$server,'channel_id'=>$channel,'users'=>$users];
            if(file_put_contents($path,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===false||!chmod($path,0600))throw new RuntimeException('Cannot save private credentials');
            $this->db->commit();file_put_contents($directory.'/LATEST',$path."\n");chmod($directory.'/LATEST',0600);return $path;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if(is_file($path))unlink($path);throw $e;}
    }
    public function cleanup(array $data):void{
        if(($data['version']??null)!==1||!preg_match('/^k6-\d{8}-\d{6}-[a-f0-9]{8}$/',$data['fixture_id']??''))throw new RuntimeException('Not a load-test fixture');
        $users=$data['users']??[];$ids=array_map(fn($u)=>(int)($u['id']??0),$users);
        if(!$ids||min($ids)<1||count(array_unique($ids))!==count($ids))throw new RuntimeException('Invalid test account IDs');
        $this->db->beginTransaction();
        try{
            $server=$this->db->prepare('SELECT id,slug,owner_id FROM servers WHERE id=? FOR UPDATE');$server->execute([(int)$data['server_id']]);$row=$server->fetch(PDO::FETCH_ASSOC);
            if(!$row||$row['slug']!==$data['fixture_id']||!in_array((int)$row['owner_id'],$ids,true))throw new RuntimeException('Fixture server ownership differs; no cleanup performed');
            $in=implode(',',array_fill(0,count($ids),'?'));$check=$this->db->prepare("SELECT id,username,email,role FROM users WHERE id IN ($in) FOR UPDATE");$check->execute($ids);$rows=$check->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)!==count($ids))throw new RuntimeException('Fixture accounts differ');
            $prefix='k6_'.substr($data['fixture_id'],-8).'_';foreach($rows as $user)if(!str_starts_with($user['username'],$prefix)||$user['email']!==$user['username'].'@example.invalid'||$user['role']!=='student')throw new RuntimeException('Non-test account detected');
            $outsiders=$this->db->prepare("SELECT COUNT(*) FROM server_members WHERE server_id=? AND user_id NOT IN ($in)");$outsiders->execute([(int)$data['server_id'],...$ids]);if((int)$outsiders->fetchColumn()>0)throw new RuntimeException('A real member joined the fixture; review before cleanup');
            $channels=$this->db->prepare('SELECT id,created_by FROM channels WHERE server_id=?');$channels->execute([(int)$data['server_id']]);$channelRows=$channels->fetchAll(PDO::FETCH_ASSOC);
            if(count($channelRows)!==1||(int)$channelRows[0]['id']!==(int)$data['channel_id']||!in_array((int)$channelRows[0]['created_by'],$ids,true))throw new RuntimeException('Fixture channels changed; review before cleanup');
            // Code Lab tables have no cascading server foreign keys. Remove auxiliary
            // capacity fixtures first, so cleanup cannot leave jobs/tasks orphaned.
            foreach(['code_lab_tasks','code_lab_jobs'] as $table){
                $exists=$this->db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$exists->execute([$table]);
                if((int)$exists->fetchColumn()){
                    $lab=$this->db->prepare('SELECT COUNT(*) FROM '.$table.' WHERE server_id=?');$lab->execute([(int)$data['server_id']]);
                    if((int)$lab->fetchColumn())throw new RuntimeException('Code Lab data remains; clean feature-capacity targets before the base fixture.');
                }
            }
            $channelIds=array_map(fn($c)=>(int)$c['id'],$channelRows);
            foreach($channelIds as $cid){$foreign=$this->db->prepare("SELECT COUNT(*) FROM messages WHERE channel_id=? AND sender_id NOT IN ($in)");$foreign->execute([$cid,...$ids]);if((int)$foreign->fetchColumn()>0)throw new RuntimeException('A real user posted in the fixture; review before cleanup');}
            foreach($channelIds as $cid){
                $this->db->prepare('DELETE FROM realtime_outbox WHERE kind=? AND target_id=?')->execute(['channel',$cid]);
                $this->db->prepare('DELETE FROM messages WHERE channel_id=?')->execute([$cid]);
            }
            $this->db->prepare('DELETE FROM servers WHERE id=?')->execute([(int)$data['server_id']]);
            $this->db->prepare("DELETE FROM ws_tokens WHERE user_id IN ($in)")->execute($ids);
            // Preserve authentication audit history; these accounts can no longer log in or obtain inbox tokens.
            $this->db->prepare("UPDATE users SET status='deactivated',deleted_at=NOW(),is_online=0,remember_token=NULL,password_hash=NULL WHERE id IN ($in)")->execute($ids);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
