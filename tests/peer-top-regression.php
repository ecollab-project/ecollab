<?php
declare(strict_types=1);
function peerReply(string $action):array {
    $output=shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/peer-top-fixture.php').' '.escapeshellarg($action));
    $reply=json_decode((string)$output,true,512,JSON_THROW_ON_ERROR);
    if (!($reply['ok']??false)) throw new RuntimeException('Fixture endpoint failed: '.json_encode($reply));
    return $reply;
}
$matches=peerReply('get_matches')['matches'];
$top=peerReply('get_leaderboard')['leaderboard'];
if(count($matches)!==3||count($top)!==3)throw new RuntimeException('Candidates lost');
foreach($top as $index=>$row){
    $match=$matches[$index];
    if($row['peer_id']!==$match['id']||$row['score_total']!==$match['score_total'])throw new RuntimeException('Top differs from current match scores or ranking');
    if($row['already_connected']!==$match['already_connected']||$row['request_status']!==$match['request_status'])throw new RuntimeException('Connection state differs');
}
if(!$top[0]['already_connected']||$top[1]['request_status']!=='pending'||$top[2]['already_connected'])throw new RuntimeException('Connection fixtures not exercised');
if($matches[0]['engine']!=='rules-behavior-v1')throw new RuntimeException('Behavior-weighted score was not exercised');
echo "PASS: actual Matches/Top endpoint uses identical current scores, rankings and connection states\n";
