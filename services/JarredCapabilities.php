<?php
declare(strict_types=1);
/** Enabled executors only. Inference never grants additional authority. */
final class JarredCapabilities
{
    public static function actions(): array
    {
        return ['read_and_navigate'=>['level'=>0], 'temporary_voice'=>['level'=>1]];
    }
    public static function tools(): array
    {
        return [
            ['type'=>'function','function'=>['name'=>'get_active_members','description'=>'Get live active/idle/voice members for the current accessible eCollab server.','parameters'=>['type'=>'object','properties'=>[]]]],
            ['type'=>'function','function'=>['name'=>'get_peer_matches','description'=>'Get deterministic eCollab peer matches for the requester, optionally scoped to the current server.','parameters'=>['type'=>'object','properties'=>['limit'=>['type'=>'integer','minimum'=>1,'maximum'=>12]]]]],
            ['type'=>'function','function'=>['name'=>'get_servers_and_channels','description'=>'List eCollab servers and channels the requester is authorized to access.','parameters'=>['type'=>'object','properties'=>[]]]],
            ['type'=>'function','function'=>['name'=>'search_messages','description'=>'Search authorized eCollab channel messages. Use only when the user asks to find or recall messages/discussions.','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string'],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>25]],'required'=>['query']]]],
            ['type'=>'function','function'=>['name'=>'get_collaboration_context','description'=>'Get authorized Coworkspace/document/whiteboard metadata and presence for the current eCollab surface.','parameters'=>['type'=>'object','properties'=>[]]]],
            ['type'=>'function','function'=>['name'=>'get_library_recommendations','description'=>'Get academic book/library recommendations personalized for the requester or current server.','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string']]]]],
        ];
    }
    public static function description(): string
    {
        return "I'm Jarred, eCollab's assistant. I can explain concepts, help with programming outside active Code Lab attempts, organize study plans, and suggest improvements to authorized documents and whiteboards. I can find accessible channels, search authorized discussions, show active members, explain peer matches and recommend academic books. In Jarred chat I can open an authorized channel by its exact name or #slug in your current server, open your current authorized document or whiteboard, and guide temporary voice-room creation through confirmation. During an active Code Lab attempt I provide planning, debugging-method, test-design and review workflows, without code or ready-to-submit answers. I cannot submit work, change grades, send email, manage accounts/permissions, publish tasks, create coworkspaces or run server commands. Navigation opens the existing interface; it does not edit a resource. I report an action as completed only after its backend succeeds.";
    }
}
