<?php
declare(strict_types=1);
/** Scope gate only. An untrusted browser cannot prove the origin of pasted code. */
final class CodeLabIntegrityPolicy
{
    public const AI_MESSAGE='Jarred provides workflow guidance while a Code Lab task is in progress.';
    public static function isCodeLab(array $context, string $referer=''): bool
    {
        $surface=strtolower(trim((string)($context['surface']??'')));
        if(preg_match('/^(?:code[_-]?lab)(?:[_-](?:task|assignment|practice|playground))?$/D',$surface))return true;
        $path=parse_url($referer,PHP_URL_PATH);
        return is_string($path)&&(bool)preg_match('~/(?:modules|API)/code-lab(?:/|$)~',$path);
    }

    /** Browser-supplied context never decides whether solutions may be generated. */
    public static function activeAttempt(PDO $db,int $uid,?int $now=null): ?array
    {
        $now ??= time();
        try {
            $q=$db->prepare("SELECT t.id,t.title,t.due_at,t.duration_minutes,a.started_at FROM code_lab_attempts a JOIN code_lab_tasks t ON t.id=a.task_id JOIN servers s ON s.id=t.server_id JOIN server_members sm ON sm.server_id=s.id AND sm.user_id=a.user_id JOIN users u ON u.id=a.user_id WHERE a.user_id=? AND a.submitted_at IS NULL AND u.role='student' AND u.deleted_at IS NULL AND u.status NOT IN ('banned','suspended','deactivated') AND s.status='active' ORDER BY a.started_at DESC");
            $q->execute([$uid]);
            while($task=$q->fetch(PDO::FETCH_ASSOC)){
                $end=$task['due_at']!==null?strtotime($task['due_at'].' UTC'):null;
                if($task['duration_minutes']!==null){$timer=strtotime($task['started_at'].' UTC')+(int)$task['duration_minutes']*60;$end=$end===null?$timer:min($end,$timer);}
                if($end===null||$now<$end){$task['id']=(int)$task['id'];return $task;}
            }
            return null;
        } catch(PDOException $e){
            // A deployment without Code Lab schema cannot have a started task.
            if($e->getCode()==='42S02'||str_contains($e->getMessage(),'no such table'))return null;
            throw $e;
        }
    }

    public static function workflow(array $task): string
    {
        return "A Code Lab task is in progress. I can help you work through it without supplying a ready-to-submit solution.\n\n"
            ."1. Restate the goal in your own words and identify inputs, outputs and constraints.\n"
            ."2. Break the problem into small steps; sketch your own algorithm on paper.\n"
            ."3. Write a small part yourself, then compile or run it.\n"
            ."4. Build test cases for normal inputs, boundaries and invalid inputs.\n"
            ."5. Read the first compiler error or failing test, trace the relevant values, and fix one cause at a time.\n"
            ."6. Explain why your approach works, review the rubric and submit your own work.\n\n"
            ."Use your lesson notes for concepts and examples that are separate from the task. Normal AI assistance resumes after submission or expiry.";
    }
}
