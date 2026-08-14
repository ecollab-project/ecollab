<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/csrf/csrf.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
AuthMiddleware::requireAuth(true);

$userId = (int)$_SESSION['user_id'];
$db     = Database::getInstance();

try {
    // ── 1. Collect user's interest tag IDs ─────────────────────────────────
    $userTagStmt = $db->prepare("
        SELECT interest_tag_id
        FROM user_interests
        WHERE user_id = :uid
    ");
    $userTagStmt->execute([':uid' => $userId]);
    $userTagIds = $userTagStmt->fetchAll(PDO::FETCH_COLUMN);

    // ── 2. Collect user's hobby keywords ───────────────────────────────────
    $hobbyStmt = $db->prepare("
        SELECT hobby, genre FROM user_hobbies WHERE user_id = :uid
    ");
    $hobbyStmt->execute([':uid' => $userId]);
    $userHobbies = $hobbyStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 3. Fetch all public / institution servers not already joined ────────
    $serversStmt = $db->prepare("
        SELECT s.id, s.name, s.slug, s.description, s.icon_emoji,
               s.category, s.type, s.member_count, s.is_verified,
               s.created_at
        FROM servers s
        WHERE s.status = 'active'
          AND s.type IN ('public', 'institution')
          AND s.id NOT IN (
              SELECT server_id FROM server_members WHERE user_id = :uid
          )
        ORDER BY s.member_count DESC
        LIMIT 50
    ");
    $serversStmt->execute([':uid' => $userId]);
    $servers = $serversStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($servers)) {
        echo json_encode(['success' => true, 'servers' => []]);
        exit;
    }

    // ── 4. Fetch all server_tags for these servers ─────────────────────────
    $serverIds   = array_column($servers, 'id');
    $placeholders = implode(',', array_fill(0, count($serverIds), '?'));

    $tagStmt = $db->prepare("
        SELECT st.server_id, it.id AS tag_id, it.name AS tag_name, it.slug, it.category
        FROM server_tags st
        JOIN interest_tags it ON it.id = st.interest_tag_id
        WHERE st.server_id IN ($placeholders)
    ");
    $tagStmt->execute($serverIds);
    $allServerTags = $tagStmt->fetchAll(PDO::FETCH_ASSOC);

    // Group tags by server
    $tagsByServer = [];
    foreach ($allServerTags as $row) {
        $tagsByServer[$row['server_id']][] = $row;
    }

    // ── 5. Score each server ───────────────────────────────────────────────
    // Scoring algorithm:
    //   + 20 pts per matching interest tag
    //   + 15 pts per matching hobby keyword in name/description
    //   + 5  pts per member (capped at 50) → popularity bonus
    //   Score capped at 100, then expressed as percentage

    $userTagSet    = array_flip($userTagIds);
    $hobbyKeywords = [];
    foreach ($userHobbies as $h) {
        if ($h['hobby']) $hobbyKeywords[] = strtolower($h['hobby']);
        if ($h['genre']) $hobbyKeywords[] = strtolower($h['genre']);
    }

    $scored = [];
    foreach ($servers as $srv) {
        $pts         = 0;
        $maxPossible = 0;
        $matchedTags = [];

        $srvTags = $tagsByServer[$srv['id']] ?? [];

        // Tag overlap
        foreach ($srvTags as $tag) {
            $maxPossible += 20;
            if (isset($userTagSet[$tag['tag_id']])) {
                $pts += 20;
                $matchedTags[] = $tag['tag_name'];
            }
        }
        // If server has no tags, give it a base possible so it can still appear
        if ($maxPossible === 0) $maxPossible = 20;

        // Hobby keyword match in name + description
        $haystack = strtolower($srv['name'] . ' ' . ($srv['description'] ?? ''));
        foreach (array_unique($hobbyKeywords) as $kw) {
            if (str_contains($haystack, $kw)) {
                $pts += 15;
                $maxPossible += 15;
            }
        }

        // Popularity bonus (0–10 pts)
        $popularityPts  = min(10, (int)($srv['member_count'] / 10));
        $pts           += $popularityPts;
        $maxPossible   += 10;

        $score = (int)min(100, round(($pts / max($maxPossible, 1)) * 100));

        // Only include servers with some relevance OR high membership
        if ($score < 5 && $srv['member_count'] < 20) continue;

        $srv['score']        = $score;
        $srv['matched_tags'] = $matchedTags;
        $srv['tags']         = array_column($srvTags, 'tag_name');
        $scored[]            = $srv;
    }

    // Sort by score desc
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

    // Return top 12
    $result = array_slice($scored, 0, 12);

    echo json_encode(['success' => true, 'servers' => $result]);

} catch (Throwable $e) {
    error_log('[onboarding/get-server-suggestions] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not load suggestions.']);
}
