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

    public static function workflow(array $task,string $focus='workflow'): string
    {
        $focus=self::focus($focus);
        $steps=match($focus){
            'plan'=>"1. Restate the task goal in your own words.\n2. List the required inputs, outputs and constraints.\n3. Split your approach into small milestones, then explain the first milestone before coding.\n\nWhat input and output does the task require?",
            'debug'=>"1. Read the first error and identify whether it happens while compiling or running.\n2. Trace the relevant values on paper using your own example.\n3. Change one cause at a time, then rerun your test cases.\n\nAt which step does your expected result first differ from the actual result? Describe the step without pasting your solution.",
            'test'=>"1. Choose one normal input and predict its result by hand.\n2. Add boundary and empty/minimum-size cases allowed by the instructions.\n3. Consider invalid inputs only if the task requires handling them.\n4. Compare actual and predicted results without copying hidden assessment cases.\n\nWhich boundary condition have you not tested yet?",
            'review'=>"1. Check each instruction and rubric item against your own work.\n2. Explain why your algorithm works and consider time or space limits.\n3. Rerun your test cases and verify input/output formatting.\n4. Save your draft, then submit through Code Lab yourself.\n\nWhich requirement is least certain in your current approach?",
            default=>"1. Restate the goal and constraints.\n2. Break the problem into small steps.\n3. Implement one small part yourself.\n4. Build test cases for normal inputs and boundaries.\n5. Trace the first failing step.\n6. Review the rubric and submit your own work.\n\nChoose planning, debugging, testing or review for focused guidance.",
        };
        return "A Code Lab task is in progress. I can guide your workflow without supplying code or a ready-to-submit solution.\n\n".$steps."\n\nNormal AI assistance resumes after submission or expiry.";
    }

    public static function focus(string $text): string
    {
        $text=mb_strtolower(mb_substr($text,0,4000));
        if(preg_match('/\b(debug|debugging|error|errors|bug|bugs|crash|crashes|failing|failed|stuck|wrong output)\b/',$text))return 'debug';
        if(preg_match('/\b(test|tests|testing|boundary|edge case)\b/',$text))return 'test';
        if(preg_match('/\b(review|rubric|submit|submission|checklist)\b/',$text))return 'review';
        if(preg_match('/\b(plan|planning|start|approach|break down)\b/',$text))return 'plan';
        return 'workflow';
    }
}
