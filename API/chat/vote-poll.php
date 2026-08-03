<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

AuthMiddleware::verifyCsrf();

try {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $pollId   = filter_var($body['poll_id']   ?? 0, FILTER_VALIDATE_INT);
    $optionId = filter_var($body['option_id'] ?? 0, FILTER_VALIDATE_INT);

    if (!$pollId || !$optionId) {
        http_response_code(400);
        echo json_encode(['error' => 'poll_id and option_id are required']);
        exit;
    }

    $db = Database::getInstance();

    // Verify option belongs to poll
    $chk = $db->prepare("SELECT id FROM poll_options WHERE id = :oid AND poll_id = :pid");
    $chk->execute([':oid' => $optionId, ':pid' => $pollId]);
    if (!$chk->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Invalid poll option']);
        exit;
    }

    // Check if already voted
    $existing = $db->prepare("SELECT option_id FROM poll_votes WHERE poll_id = :pid AND user_id = :uid");
    $existing->execute([':pid' => $pollId, ':uid' => $user['id']]);
    $prevVote = $existing->fetch();

    if ($prevVote) {
        if ((int)$prevVote['option_id'] === (int)$optionId) {
            // Unvote (toggle off)
            $db->prepare("DELETE FROM poll_votes WHERE poll_id = :pid AND user_id = :uid")
               ->execute([':pid' => $pollId, ':uid' => $user['id']]);
            $db->prepare("UPDATE poll_options SET vote_count = GREATEST(vote_count - 1, 0) WHERE id = :oid")
               ->execute([':oid' => $prevVote['option_id']]);
            $db->prepare("UPDATE polls SET total_votes = GREATEST(total_votes - 1, 0) WHERE id = :pid")
               ->execute([':pid' => $pollId]);
        } else {
            // Change vote
            $db->prepare("UPDATE poll_votes SET option_id = :oid, voted_at = NOW() WHERE poll_id = :pid AND user_id = :uid")
               ->execute([':oid' => $optionId, ':pid' => $pollId, ':uid' => $user['id']]);
            $db->prepare("UPDATE poll_options SET vote_count = GREATEST(vote_count - 1, 0) WHERE id = :oid")
               ->execute([':oid' => $prevVote['option_id']]);
            $db->prepare("UPDATE poll_options SET vote_count = vote_count + 1 WHERE id = :oid")
               ->execute([':oid' => $optionId]);
        }
    } else {
        // New vote
        $db->prepare("INSERT INTO poll_votes (poll_id, option_id, user_id) VALUES (:pid, :oid, :uid)")
           ->execute([':pid' => $pollId, ':oid' => $optionId, ':uid' => $user['id']]);
        $db->prepare("UPDATE poll_options SET vote_count = vote_count + 1 WHERE id = :oid")
           ->execute([':oid' => $optionId]);
        $db->prepare("UPDATE polls SET total_votes = total_votes + 1 WHERE id = :pid")
           ->execute([':pid' => $pollId]);
    }

    // Return updated poll state
    $pollStmt = $db->prepare("SELECT total_votes FROM polls WHERE id = :pid");
    $pollStmt->execute([':pid' => $pollId]);
    $poll = $pollStmt->fetch();

    $optsStmt = $db->prepare("SELECT id, option_text, vote_count, position FROM poll_options WHERE poll_id = :pid ORDER BY position ASC");
    $optsStmt->execute([':pid' => $pollId]);
    $options = $optsStmt->fetchAll();

    $myVote = $db->prepare("SELECT option_id FROM poll_votes WHERE poll_id = :pid AND user_id = :uid");
    $myVote->execute([':pid' => $pollId, ':uid' => $user['id']]);
    $myVoteRow = $myVote->fetch();

    echo json_encode([
        'success'      => true,
        'total_votes'  => (int)($poll['total_votes'] ?? 0),
        'my_vote'      => $myVoteRow ? (int)$myVoteRow['option_id'] : null,
        'options'      => array_map(fn($o) => [
            'id'         => (int)$o['id'],
            'text'       => $o['option_text'],
            'vote_count' => (int)$o['vote_count'],
        ], $options),
    ]);

} catch (Throwable $e) {
    error_log('[vote-poll] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error']);
}
