<?php
declare(strict_types=1);
/** Scope gate only. An untrusted browser cannot prove the origin of pasted code. */
final class CodeLabIntegrityPolicy
{
    public const AI_MESSAGE='Jarred assistance is disabled inside Code Lab. Read the instructions, write your own code, and use the compiler feedback.';
    public static function isCodeLab(array $context, string $referer=''): bool
    {
        $surface=strtolower(trim((string)($context['surface']??'')));
        if(preg_match('/^(?:code[_-]?lab)(?:[_-](?:task|assignment|practice|playground))?$/D',$surface))return true;
        $path=parse_url($referer,PHP_URL_PATH);
        return is_string($path)&&(bool)preg_match('~/(?:modules|API)/code-lab(?:/|$)~',$path);
    }
}
