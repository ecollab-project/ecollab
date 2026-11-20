<?php
declare(strict_types=1);
require_once __DIR__.'/CodeLabIntegrityPolicy.php';
require_once __DIR__.'/JarredCapabilities.php';
require_once __DIR__.'/JarredGroundingPolicy.php';
/** Deterministic capability/help replies run before inference or action execution. */
final class JarredAssistancePolicy
{
    public const REFUSAL="I'm sorry, the task that you want from me is not part of my capacity";
    public static function capabilities(): string
    {
        return JarredCapabilities::description();
    }
    public static function unsupported(string $prompt): bool
    {
        return (bool)preg_match('/^(?:(?:please|can you|could you|would you)\s+)*(?:(?:delete|remove|ban|unban|suspend|promote|demote)\s+(?:this\s+|my\s+|the\s+)?(?:server|account|user|student)|(?:change|set|edit)\s+(?:my\s+|the\s+)?(?:grade|password|permissions)|(?:send|write and send)\s+(?:an?\s+|the\s+)?email|(?:submit|solve|complete)\s+(?:my\s+|this\s+|the\s+)?(?:task|assignment|exam)|(?:deploy|restart|reboot)\s+(?:the\s+|my\s+)?(?:server|website|vps)|create\s+(?:an?\s+)?(?:account|server|task|coworkspace))\b/i',trim($prompt));
    }
    public static function reply(PDO $db,int $uid,string $prompt): ?string
    {
        if(preg_match('/what (?:can|do) you (?:do|help)|your (?:capabilities|capacity)|what are you (?:able|capable)/i',$prompt))return self::capabilities();
        $task=CodeLabIntegrityPolicy::activeAttempt($db,$uid);
        if($task)return CodeLabIntegrityPolicy::workflow($task,$prompt);
        if(preg_match('/^(?:hi|hello|hey|good (?:morning|afternoon|evening)|kumusta|kamusta)(?:[!.?\s]*)$/iu',trim($prompt)))return "Hi! I'm Jarred. What would you like help with? You can ask what I can do, find resources, or get study guidance.";
        return self::unsupported($prompt)?self::REFUSAL:null;
    }
    public static function instructions(): string
    {
        return "\nAPPLICATION CAPABILITIES: ".self::capabilities()." If a requested operation is outside these enabled capabilities, say exactly: ".self::REFUSAL." Never invent tools or successful actions. Normal conversation and academic explanations remain available.".JarredGroundingPolicy::instructions();
    }
}
