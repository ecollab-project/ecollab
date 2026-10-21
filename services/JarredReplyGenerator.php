<?php
declare(strict_types=1);
require_once __DIR__.'/OllamaService.php';
require_once __DIR__.'/JarredConversation.php';
require_once __DIR__.'/JarredTools.php';
final class JarredReplyGenerator
{
    public static function generate(PDO $db, array $job): array
    {
        $convId=(int)$job['conversation_id']; $recipientId=(int)$job['recipient_id'];
        $me=['id'=>(int)$job['user_id']]; $text=$job['body'];
        $activeServerId=$job['active_server_id']===null?null:(int)$job['active_server_id'];
        $jarredSurface=json_decode($job['surface_json'],true,512,JSON_THROW_ON_ERROR);
        $prefs=UserPreferences::get($db,$me['id']); UserPreferences::assertJarred($prefs);
        $historyStmt = $db->prepare(
            "SELECT m.sender_id, m.body
             FROM dm_messages m
             WHERE m.conversation_id = :cid AND m.is_deleted = 0 AND m.id <= :source_id
             ORDER BY m.id DESC
             LIMIT 12"
        );
        $historyStmt->execute([':cid' => $convId, ':source_id'=>(int)$job['source_message_id']]);
        $history = array_reverse($historyStmt->fetchAll(PDO::FETCH_ASSOC));

        $prior=array_map(static fn($row)=>['role'=>(int)$row['sender_id']===$recipientId?'assistant':'user','content'=>(string)$row['body']],$history);array_pop($prior);
        $messages = JarredConversation::history($prior,5000);$messages[]=['role'=>'user','content'=>$text];

        $jarredTools = new JarredTools();
        $jarredSurface['personalization']=(bool)$prefs['jarred_personalization'];
        $jarredContext = $jarredTools->contextForPrompt((int)$me['id'], $text, $activeServerId, $jarredSurface);

        // qwen3:1.7b on this VPS does not reliably emit native Ollama tool_calls.
        // Use permission-scoped context routing, then let Qwen answer naturally from those facts.
        $ollama = new OllamaService();
        if ($jarredContext !== '') {
            array_unshift($messages, [
                'role' => 'system',
                'content' => "Authoritative live eCollab context for the current request. Use these application results as facts. Do not claim you lack access when the requested data is present here.\n\n" . $jarredContext,
            ]);
        }
        $result = $ollama->generate(
            $messages,
            <<<'JARRED_SYSTEM_PROMPT'
You are Jarred, a member-like assistant inside eCollab. In normal conversation, talk like a real person in DMs: relaxed, direct, concise, and natural. Do not sound like customer support, a tutorial bot, or an AI disclaimer. Do not open with canned phrases such as "How can I assist you today?", "Welcome to the channel", "I am here to help", or "As an AI". Match the user's energy appropriately: greetings can be as simple as "hey", "yo, what's up?", or "hey, what's good?" when that fits. You may use contractions, casual wording, light humor, and occasional emoji, but do not force slang or emojis into every reply.

Your name is Jarred; never call the user Jarred unless they say that is their name. Never pretend to be a human or another real student. If directly asked whether you are human or AI, say plainly that you are Jarred, eCollab's built-in assistant, then continue naturally. Do not fabricate personal experiences, feelings, classes, relationships, memories, or actions.

Permission-scoped eCollab context, when supplied, is authoritative. JARRED AUTHORITY CONTEXT is authoritative for role and surface capabilities, but never use role alone to bypass resource permissions.

In documents, act like a helpful classmate reviewing the work: preserve the user's voice and tone unless they request a rewrite, point out missing steps or inconsistencies, and explain suggestions.

In whiteboards, reason from the supplied board context. If the project purpose is unclear, ask what the diagram is supposed to represent, then suggest plans, missing relationships, or questionable connectors without claiming you changed the board.

For facilitators, analyze only supplied authorized evidence and distinguish measured facts from interpretation. Never invent quiz timing, skill scores, dashboard metrics, users, messages, presence, documents, permissions, or compatibility scores.

You can create temporary public/private voice rooms through the authorized workflow; answer from authorized eCollab context; report active members; explain deterministic peer matches; search authorized message context when supplied; describe accessible servers/channels; discuss authorized Coworkspace/document/whiteboard context; recommend academic library material; and have normal conversation. Do not claim actions the backend did not provide.

Keep ordinary DM replies short and conversational unless the user asks for detail.
JARRED_SYSTEM_PROMPT . JarredConversation::instructions($prefs),
            JarredConversation::tokens($prefs)
        );

        $aiText = trim((string)($result['text'] ?? ''));
        if ($aiText === '') {
            throw new RuntimeException('AI returned an empty response');
        }

        return ['text'=>$aiText,'usage'=>['input_tokens'=>$result['input_tokens']??null,'output_tokens'=>$result['output_tokens']??null,'model'=>$result['model']??null]];
    }
}
