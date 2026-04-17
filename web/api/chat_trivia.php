<?php
/**
 * PTMD API — Chat Trivia
 *
 * GET  ?room=<slug>                  → active session + question for room
 * GET  ?action=scores&session=<id>   → answer results / leaderboard
 * GET  ?action=questions             → list available questions (mod+)
 * POST action=start                  → start new session (mod+)
 * POST action=answer                 → submit answer (registered+)
 * POST action=close                  → close active session early (mod+)
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/chat_auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

// ── GET ───────────────────────────────────────────────────────────────────────
if (!is_post()) {
    $action = trim((string) ($_GET['action'] ?? ''));

    if ($action === 'questions') {
        if (!is_chat_logged_in() || !is_chat_moderator()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Moderator access required.']);
            exit;
        }
        $rows = $pdo->query(
            'SELECT id, question, answer_a, answer_b, answer_c, answer_d, correct, category, difficulty, is_active
             FROM chat_trivia_questions ORDER BY category, difficulty, id'
        )->fetchAll();
        echo json_encode(['ok' => true, 'questions' => $rows]);
        exit;
    }

    if ($action === 'scores') {
        $sessionId = max(0, (int) ($_GET['session'] ?? 0));
        if ($sessionId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'session required']);
            exit;
        }
        $sess = $pdo->prepare('SELECT s.*, q.question, q.correct FROM chat_trivia_sessions s JOIN chat_trivia_questions q ON q.id = s.question_id WHERE s.id = :id LIMIT 1');
        $sess->execute(['id' => $sessionId]);
        $session = $sess->fetch();
        if (!$session) {
            echo json_encode(['ok' => false, 'error' => 'Session not found']);
            exit;
        }
        $answers = $pdo->prepare(
            'SELECT a.answer, a.is_correct, a.answered_at, cu.display_name, cu.username
             FROM chat_trivia_answers a
             JOIN chat_users cu ON cu.id = a.chat_user_id
             WHERE a.session_id = :sid
             ORDER BY a.is_correct DESC, a.answered_at ASC'
        );
        $answers->execute(['sid' => $sessionId]);
        $answerRows = $answers->fetchAll();
        $correctCount = array_sum(array_column($answerRows, 'is_correct'));
        echo json_encode([
            'ok'            => true,
            'session'       => $session,
            'answers'       => $answerRows,
            'correct_count' => $correctCount,
            'total_answers' => count($answerRows),
        ]);
        exit;
    }

    // Default: active session for room
    $roomSlug = trim(strip_tags((string) ($_GET['room'] ?? 'case-chat')));
    $roomStmt = $pdo->prepare('SELECT id, trivia_enabled FROM chat_rooms WHERE slug = :slug AND is_archived = 0 LIMIT 1');
    $roomStmt->execute(['slug' => $roomSlug]);
    $room = $roomStmt->fetch();
    if (!$room) {
        echo json_encode(['ok' => false, 'error' => 'Room not found']);
        exit;
    }

    // Expire old sessions
    $pdo->prepare("UPDATE chat_trivia_sessions SET status = 'expired' WHERE room_id = :rid AND status = 'active' AND closes_at < NOW()")
        ->execute(['rid' => (int) $room['id']]);

    $sessStmt = $pdo->prepare(
        'SELECT s.id, s.status, s.closes_at, s.winner_user_id,
                q.question, q.answer_a, q.answer_b, q.answer_c, q.answer_d, q.category, q.difficulty
         FROM   chat_trivia_sessions s
         JOIN   chat_trivia_questions q ON q.id = s.question_id
         WHERE  s.room_id = :rid AND s.status = "active"
         ORDER  BY s.created_at DESC LIMIT 1'
    );
    $sessStmt->execute(['rid' => (int) $room['id']]);
    $session = $sessStmt->fetch() ?: null;

    // Don't reveal correct answer while session is active
    if ($session) {
        $isMod = is_chat_logged_in() && is_chat_moderator();
        if (!$isMod) unset($session['correct']); // safe — column not selected above
        // Has current user answered?
        $myAnswer = null;
        if (is_chat_logged_in()) {
            $uid = (int) $_SESSION['chat_user_id'];
            $aStmt = $pdo->prepare('SELECT answer, is_correct FROM chat_trivia_answers WHERE session_id = :sid AND chat_user_id = :uid LIMIT 1');
            $aStmt->execute(['sid' => $session['id'], 'uid' => $uid]);
            $myAnswer = $aStmt->fetch() ?: null;
        }
        $session['my_answer'] = $myAnswer;
        // Answer count
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM chat_trivia_answers WHERE session_id = :sid');
        $cnt->execute(['sid' => $session['id']]);
        $session['answer_count'] = (int) $cnt->fetchColumn();
    }

    echo json_encode(['ok' => true, 'session' => $session, 'trivia_enabled' => (bool) $room['trivia_enabled']]);
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────
if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action   = trim((string) ($_POST['action'] ?? ''));
$roomSlug = trim(strip_tags((string) ($_POST['room'] ?? 'case-chat')));

$roomStmt = $pdo->prepare('SELECT id, trivia_enabled FROM chat_rooms WHERE slug = :slug AND is_archived = 0 LIMIT 1');
$roomStmt->execute(['slug' => $roomSlug]);
$room = $roomStmt->fetch();
if (!$room) {
    echo json_encode(['ok' => false, 'error' => 'Room not found']);
    exit;
}
$roomId = (int) $room['id'];

if ($action === 'start') {
    if (!is_chat_logged_in() || !chat_can('start_trivia')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Moderator access required.']);
        exit;
    }
    $questionId  = (int) ($_POST['question_id'] ?? 0);
    $durationSec = max(15, min(300, (int) ($_POST['duration'] ?? 60)));

    // Close any existing active session
    $pdo->prepare("UPDATE chat_trivia_sessions SET status = 'expired' WHERE room_id = :rid AND status = 'active'")
        ->execute(['rid' => $roomId]);

    if ($questionId <= 0) {
        // Pick a random active question
        $q = $pdo->query('SELECT id FROM chat_trivia_questions WHERE is_active = 1 ORDER BY RAND() LIMIT 1')->fetch();
        if (!$q) {
            echo json_encode(['ok' => false, 'error' => 'No trivia questions available.']);
            exit;
        }
        $questionId = (int) $q['id'];
    }
    $startedBy = (int) ($_SESSION['chat_user_id'] ?? 0);
    $pdo->prepare(
        'INSERT INTO chat_trivia_sessions (room_id, question_id, started_by, status, closes_at, created_at)
         VALUES (:rid, :qid, :uid, "active", DATE_ADD(NOW(), INTERVAL :sec SECOND), NOW())'
    )->execute(['rid' => $roomId, 'qid' => $questionId, 'uid' => $startedBy ?: null, 'sec' => $durationSec]);
    $sessionId = (int) $pdo->lastInsertId();

    $qStmt = $pdo->prepare('SELECT question, answer_a, answer_b, answer_c, answer_d, category, difficulty FROM chat_trivia_questions WHERE id = :id LIMIT 1');
    $qStmt->execute(['id' => $questionId]);
    $q = $qStmt->fetch();
    echo json_encode(['ok' => true, 'session_id' => $sessionId, 'question' => $q, 'closes_in' => $durationSec]);
    exit;
}

if ($action === 'answer') {
    if (!is_chat_logged_in() || !chat_user_has_role('registered')) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Sign in to answer trivia.']);
        exit;
    }
    $sessionId = (int) ($_POST['session_id'] ?? 0);
    $answer    = trim(strtolower((string) ($_POST['answer'] ?? '')));
    if (!in_array($answer, ['a','b','c','d'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid answer choice.']);
        exit;
    }
    // Verify session is active
    $sess = $pdo->prepare('SELECT s.id, s.closes_at, q.correct FROM chat_trivia_sessions s JOIN chat_trivia_questions q ON q.id = s.question_id WHERE s.id = :id AND s.room_id = :rid AND s.status = "active" AND s.closes_at > NOW() LIMIT 1');
    $sess->execute(['id' => $sessionId, 'rid' => $roomId]);
    $session = $sess->fetch();
    if (!$session) {
        echo json_encode(['ok' => false, 'error' => 'No active trivia session or session has closed.']);
        exit;
    }
    $userId    = (int) $_SESSION['chat_user_id'];
    $isCorrect = $answer === $session['correct'] ? 1 : 0;
    try {
        $pdo->prepare(
            'INSERT INTO chat_trivia_answers (session_id, chat_user_id, answer, is_correct, answered_at)
             VALUES (:sid, :uid, :ans, :correct, NOW())'
        )->execute(['sid' => $sessionId, 'uid' => $userId, 'ans' => $answer, 'correct' => $isCorrect]);
    } catch (\PDOException $e) {
        echo json_encode(['ok' => false, 'error' => 'You have already answered this question.']);
        exit;
    }
    // Set winner if first correct
    if ($isCorrect) {
        $pdo->prepare('UPDATE chat_trivia_sessions SET winner_user_id = COALESCE(winner_user_id, :uid) WHERE id = :id')
            ->execute(['uid' => $userId, 'id' => $sessionId]);
    }
    echo json_encode(['ok' => true, 'is_correct' => (bool) $isCorrect]);
    exit;
}

if ($action === 'close') {
    if (!is_chat_logged_in() || !chat_can('close_trivia')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Moderator access required.']);
        exit;
    }
    $sessionId = (int) ($_POST['session_id'] ?? 0);
    $pdo->prepare("UPDATE chat_trivia_sessions SET status = 'closed' WHERE id = :id AND room_id = :rid")
        ->execute(['id' => $sessionId, 'rid' => $roomId]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Invalid action.']);
