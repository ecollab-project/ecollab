<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/database/config/db.php';
$db = Database::getInstance();
$report = ['note'=>'Counts only; no claim of sufficient training data','tables'=>[]];
foreach (['users','server_members','message_bookmarks','thread_post_bookmarks','pm_match_feedback','recommendation_books','recommendation_book_events'] as $table) {
    $q=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $q->execute([$table]);
    $report['tables'][$table]=$q->fetchColumn() ? (int)$db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() : null;
}
if ($report['tables']['recommendation_book_events'] !== null) {
    $report['book_events']=$db->query('SELECT event_type, COUNT(*) events, COUNT(DISTINCT user_id) users, COUNT(DISTINCT work_key) books, MIN(created_at) first_event, MAX(created_at) last_event FROM recommendation_book_events GROUP BY event_type')->fetchAll(PDO::FETCH_ASSOC);
}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
