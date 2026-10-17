<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/database/config/db.php';
require_once dirname(__DIR__).'/services/PeerMatchingService.php';

/**
 * Deterministic facilitator-only grouping recommendations.
 * Current evidence: existing peer-matching profiles. Assessment evidence can
 * be added later without allowing the language model to choose scores.
 */
final class JarredGroupRecommendationService
{
    private PDO $db;
    public function __construct(){ $this->db=Database::getInstance(); }

    public function recommend(int $facilitatorId,int $serverId,int $groupSize=4): array
    {
        $groupSize=max(2,min(8,$groupSize));
        if(!$this->canManage($facilitatorId,$serverId)) throw new RuntimeException('Facilitator access required.');

        $q=$this->db->prepare("SELECT u.id,COALESCE(NULLIF(u.full_name,''),u.username) name,u.username
            FROM users u JOIN server_members sm ON sm.user_id=u.id AND sm.server_id=:sid
            WHERE u.deleted_at IS NULL AND COALESCE(u.is_system,0)=0
              AND u.status NOT IN ('banned','suspended','deactivated')
              AND u.role='student' ORDER BY u.id");
        $q->execute([':sid'=>$serverId]);
        $students=$q->fetchAll(PDO::FETCH_ASSOC);
        if(!$students) return ['basis'=>'profile_compatibility','groups'=>[],'unassigned'=>[]];

        $engine=new PeerMatchingService();
        $profiles=[];
        foreach($students as $s) $profiles[(int)$s['id']]=$engine->loadProfile($this->db,(int)$s['id']);

        // Seed groups round-robin, then place each remaining student into the
        // group with the strongest average compatibility while respecting size.
        $groupCount=max(1,(int)ceil(count($students)/$groupSize));
        $groups=array_fill(0,$groupCount,[]);
        $ordered=$students;
        foreach($ordered as $i=>$student){
            if($i<$groupCount){$groups[$i][]=$student;continue;}
            $best=null;$bestScore=-1.0;
            foreach($groups as $gi=>$members){
                if(count($members)>=$groupSize)continue;
                $scores=[];
                foreach($members as $m){
                    $scores[]=(float)$engine->scoreProfiles($profiles[(int)$student['id']],$profiles[(int)$m['id']])['total'];
                }
                $avg=$scores?array_sum($scores)/count($scores):0.0;
                if($avg>$bestScore){$bestScore=$avg;$best=$gi;}
            }
            if($best===null)$best=array_key_first($groups);
            $groups[$best][]=$student;
        }

        $out=[];
        foreach($groups as $i=>$members){
            $pairs=[];
            for($a=0;$a<count($members);$a++)for($b=$a+1;$b<count($members);$b++){
                $score=$engine->scoreProfiles($profiles[(int)$members[$a]['id']],$profiles[(int)$members[$b]['id']]);
                $pairs[]=['a'=>(int)$members[$a]['id'],'b'=>(int)$members[$b]['id'],'score'=>$score['total'],'reasons'=>$score['tags']];
            }
            $out[]=[
                'group'=>$i+1,
                'members'=>array_map(static fn($s)=>['id'=>(int)$s['id'],'name'=>$s['name'],'username'=>$s['username']],$members),
                'average_pair_compatibility'=>$pairs?round(array_sum(array_column($pairs,'score'))/count($pairs),2):null,
                'pair_evidence'=>$pairs,
            ];
        }
        return [
            'basis'=>'existing deterministic peer profile compatibility only',
            'assessment_evidence_included'=>false,
            'groups'=>$out,
            'note'=>'Assessment-derived skill evidence is not included until real assessment telemetry exists.',
        ];
    }

    private function canManage(int $uid,int $sid): bool
    {
        $q=$this->db->prepare("SELECT 1 FROM server_members sm JOIN users u ON u.id=sm.user_id
            WHERE sm.server_id=? AND sm.user_id=? AND
            (sm.server_role IN ('owner','admin','moderator','facilitator') OR u.role IN ('facilitator','admin','super_admin')) LIMIT 1");
        $q->execute([$sid,$uid]);
        return(bool)$q->fetchColumn();
    }
}
