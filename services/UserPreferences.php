<?php
declare(strict_types=1);
require_once __DIR__.'/ColorPaletteService.php';
final class UserPreferences {
 public static function defaults():array {
return [
    'connection_requests'=>1,'direct_messages'=>1,'activity_status'=>1,'read_receipts'=>1,'screenshot_alerts'=>1,'ai_matching'=>1,
    'profile_visibility'=>'everyone','avatar_gradient'=>'#a855f7,#ec4899','theme'=>'dark','color_palette'=>ColorPaletteService::DEFAULT,'compact_mode'=>0,'reduce_motion'=>0,
    'high_contrast'=>0,'screen_reader_mode'=>0,'notification_desktop'=>1,'notification_messages'=>1,'notification_mentions'=>1,
    'notification_matches'=>1,'notification_sound'=>1,'input_device'=>null,'output_device'=>null,'mic_volume'=>100,'output_volume'=>100,
    'noise_suppression'=>1,'echo_cancellation'=>1,'auto_gain_control'=>1,
    'font_scale'=>100,'message_density'=>'cozy','sidebar_scale'=>100,'typing_indicators'=>1,'link_previews'=>1,'media_previews'=>1,
    'autoplay_gifs'=>1,'show_timestamps'=>1,'enter_to_send'=>1,'notification_groups'=>1,'notification_replies'=>1,'notification_threads'=>1,
    'notification_reactions'=>1,'notification_connections'=>1,'notification_announcements'=>1,'camera_device'=>null,'voice_mode'=>'voice_activity',
    'input_sensitivity'=>50,'jarred_enabled'=>1,'jarred_response_style'=>'balanced','jarred_personalization'=>1,'jarred_memory'=>0,'jarred_saved_context'=>''
];
 }
 public static function get(PDO $db,int $uid):array {
  $s=$db->prepare('SELECT * FROM user_settings WHERE user_id=? LIMIT 1');$s->execute([$uid]);return array_replace(self::defaults(),$s->fetch(PDO::FETCH_ASSOC)?:[]);
 }
 public static function assertJarred(array $prefs):void {if(!(int)$prefs['jarred_enabled'])throw new RuntimeException('Jarred is disabled in your settings.',403);}
 public static function mayViewProfile(PDO $db,int $viewer,int $owner):bool {
  if($viewer===$owner)return true;$setting=$db->prepare('SELECT profile_visibility FROM user_settings WHERE user_id=?');$setting->execute([$owner]);$visibility=$setting->fetchColumn()?:'everyone';
  if($visibility==='everyone')return true;
  if($visibility==='connections'){$q=$db->prepare("SELECT 1 FROM friendships WHERE status='accepted' AND ((requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?)) LIMIT 1");$q->execute([$owner,$viewer,$viewer,$owner]);}
  elseif($visibility==='servers'){$q=$db->prepare("SELECT 1 FROM server_members a JOIN server_members b ON b.server_id=a.server_id JOIN servers s ON s.id=a.server_id AND s.status='active' WHERE a.user_id=? AND b.user_id=? LIMIT 1");$q->execute([$owner,$viewer]);}
  else return false;
  return (bool)$q->fetchColumn();
 }
 public static function partnerRead(PDO $db,int $conversation,int $viewer,int $partner):?int {
  $q=$db->prepare("SELECT cr.last_message_id FROM chat_read_cursors cr WHERE cr.scope='dm' AND cr.conversation_id=? AND cr.user_id=? AND NOT EXISTS(SELECT 1 FROM user_settings us WHERE us.user_id IN (?,?) AND us.read_receipts=0) LIMIT 1");$q->execute([$conversation,$partner,$viewer,$partner]);$id=$q->fetchColumn();return $id===false?null:(int)$id;
 }

}
