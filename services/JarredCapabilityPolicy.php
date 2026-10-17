<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/database/config/db.php';

/**
 * Role + surface capability policy for Jarred.
 * This class describes authority only. It never performs an action.
 */
final class JarredCapabilityPolicy
{
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    public function context(int $userId, ?int $serverId, array $surface = []): array
    {
        $u=$this->db->prepare("SELECT role FROM users WHERE id=? AND deleted_at IS NULL LIMIT 1");
        $u->execute([$userId]);
        $platformRole=(string)($u->fetchColumn() ?: 'student');

        $serverRole=null;
        if($serverId){
            $s=$this->db->prepare("SELECT server_role FROM server_members WHERE server_id=? AND user_id=? LIMIT 1");
            $s->execute([$serverId,$userId]);
            $serverRole=$s->fetchColumn() ?: null;
        }

        $surfaceName=$this->normalizeSurface($surface);
        $isFacilitator=in_array($platformRole,['facilitator','admin','super_admin'],true)
            || in_array($serverRole,['owner','admin','moderator','facilitator'],true);

        $caps=[
            'conversation'=>['chat','explain','academic_assistance'],
            'navigation'=>['open_authorized_channel','open_authorized_document','open_authorized_whiteboard'],
            'voice'=>['create_temporary_voice_room'],
            'matching'=>['view_own_peer_recommendations'],
            'library'=>['personalized_recommendations'],
        ];

        if($surfaceName==='document'){
            $caps['document_assistant']=[
                'review_authorized_document_context',
                'suggest_completion_steps',
                'suggest_clarity_and_consistency_improvements',
                'preserve_author_voice_and_tone',
                'explain_without_silently_editing',
            ];
        }
        if($surfaceName==='whiteboard'){
            $caps['whiteboard_assistant']=[
                'ask_project_purpose_when_context_is_missing',
                'analyze_authorized_board_structure',
                'suggest_project_plan',
                'suggest_missing_or_incorrect_connectors',
                'explain_connector_reasoning',
                'suggest_without_silently_editing',
            ];
        }
        if($isFacilitator){
            $caps['facilitator']=[
                'summarize_authorized_server_activity',
                'analyze_authorized_student_and_peer_matching_data',
                'explain_group_recommendations',
                'prepare_facilitator_report_from_supplied_dashboard_data',
            ];
            $caps['matching'][]='recommend_balanced_student_groups_when_evidence_is_available';
        }

        return [
            'platform_role'=>$platformRole,
            'server_role'=>$serverRole,
            'persona'=>$isFacilitator?'facilitator':'student',
            'surface'=>$surfaceName,
            'capabilities'=>$caps,
            'guardrails'=>[
                'resource_permissions_always_override_role',
                'never_invent_dashboard_assessment_or_skill_evidence',
                'recommendations_must_explain_supporting_evidence',
                'document_help_preserves_user_tone_unless_rewrite_is_requested',
                'whiteboard_changes_are_suggestions_until_user_confirms_an_enabled_edit_action',
                'destructive_or_administrative_actions_require_explicit_backend_authority',
            ],
        ];
    }

    private function normalizeSurface(array $surface): string
    {
        if((int)($surface['document_id']??0)>0) return 'document';
        if((int)($surface['whiteboard_id']??0)>0) return 'whiteboard';
        $s=strtolower(trim((string)($surface['surface']??'chat')));
        if(str_contains($s,'facilitator')) return 'facilitator_dashboard';
        if(str_contains($s,'assessment')||str_contains($s,'quiz')) return 'assessment';
        if(str_contains($s,'voice')) return 'voice';
        return $s ?: 'chat';
    }
}
