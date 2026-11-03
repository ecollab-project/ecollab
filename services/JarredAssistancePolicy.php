<?php
declare(strict_types=1);
require_once __DIR__.'/CodeLabIntegrityPolicy.php';
/** Deterministic capability/help replies run before inference or action execution. */
final class JarredAssistancePolicy
{
    public const REFUSAL="I'm sorry, the task that you want from me is not part of my capacity";
    public static function capabilities(): string
    {
        return "I'm Jarred, eCollab's assistant. I can explain academic concepts, help with programming and study plans, suggest improvements to authorized documents and whiteboards, summarize supplied authorized class activity, explain peer recommendations, and suggest academic books. I can open the current authorized channel/document/whiteboard and guide creation of a temporary public or private voice room through confirmation. During an active Code Lab task I provide a learning workflow instead of solutions. I cannot submit your work, change grades, manage accounts or permissions, send email, run server commands, access unauthorized resources, or claim an action happened without a successful backend result.";
    }
    public static function unsupported(string $prompt): bool
    {
        return (bool)preg_match('/^(?:(?:please|can you|could you|would you)\s+)*(?:(?:delete|remove|ban|unban|suspend|promote|demote)\s+(?:this\s+|my\s+|the\s+)?(?:server|account|user|student)|(?:change|set|edit)\s+(?:my\s+|the\s+)?(?:grade|password|permissions)|(?:send|write and send)\s+(?:an?\s+|the\s+)?email|(?:submit|solve|complete)\s+(?:my\s+|this\s+|the\s+)?(?:task|assignment|exam)|(?:deploy|restart|reboot)\s+(?:the\s+|my\s+)?(?:server|website|vps)|create\s+(?:an?\s+)?(?:account|server|task|coworkspace))\b/i',trim($prompt));
    }
    public static function reply(PDO $db,int $uid,string $prompt): ?string
    {
        $task=CodeLabIntegrityPolicy::activeAttempt($db,$uid);
        if($task)return CodeLabIntegrityPolicy::workflow($task);
        if(preg_match('/what (?:can|do) you (?:do|help)|your (?:capabilities|capacity)|what are you (?:able|capable)/i',$prompt))return self::capabilities();
        return self::unsupported($prompt)?self::REFUSAL:null;
    }
    public static function instructions(): string
    {
        return "\nAPPLICATION CAPABILITIES: ".self::capabilities()." If a requested operation is outside these enabled capabilities, say exactly: ".self::REFUSAL." Never invent tools or successful actions. Normal conversation and academic explanations remain available.";
    }
}
