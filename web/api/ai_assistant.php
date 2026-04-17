<?php
/**
 * PTMD API — Admin Copilot
 *
 * GET  ?session_id=N   Return message history for a session.
 * GET  (no param)      Return recent sessions list for the current admin user.
 * POST                 Send a user message; get an assistant reply.
 *
 * Requires admin session + CSRF token on POST.
 */

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo    = get_db();
$userId = (int) ($_SESSION['admin_user_id'] ?? 0);

// ── GET — session history / session list ──────────────────────────────────────
if (!is_post()) {
    if (!$pdo) {
        echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
        exit;
    }

    if (isset($_GET['session_id'])) {
        $sid = (int) $_GET['session_id'];

        // Verify this session belongs to the current admin
        $sess = $pdo->prepare('SELECT id, title FROM ai_assistant_sessions WHERE id = :id AND user_id = :uid');
        $sess->execute(['id' => $sid, 'uid' => $userId]);
        $session = $sess->fetch();

        if (!$session) {
            echo json_encode(['ok' => false, 'error' => 'Session not found']);
            exit;
        }

        $msgs = $pdo->prepare(
            'SELECT id, role, content, created_at
             FROM ai_assistant_messages
             WHERE session_id = :sid
             ORDER BY created_at ASC'
        );
        $msgs->execute(['sid' => $sid]);

        echo json_encode([
            'ok'       => true,
            'session'  => $session,
            'messages' => $msgs->fetchAll(),
        ]);
        exit;
    }

    // Return recent sessions for this user
    $sessions = $pdo->prepare(
        'SELECT id, title, updated_at
         FROM ai_assistant_sessions
         WHERE user_id = :uid
         ORDER BY updated_at DESC
         LIMIT 20'
    );
    $sessions->execute(['uid' => $userId]);

    echo json_encode([
        'ok'       => true,
        'sessions' => $sessions->fetchAll(),
    ]);
    exit;
}

// ── POST — send a message ─────────────────────────────────────────────────────
if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

if (!$pdo) {
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

$apiKeySet = site_setting('openai_api_key', '') !== '';
if (!$apiKeySet) {
    echo json_encode(['ok' => false, 'error' => 'OpenAI API key not configured. Go to Settings → AI Configuration.']);
    exit;
}

$userMessage = trim((string) ($_POST['message'] ?? ''));
if ($userMessage === '') {
    echo json_encode(['ok' => false, 'error' => 'Message cannot be empty.']);
    exit;
}

// Enforce a reasonable per-message length limit
if (mb_strlen($userMessage) > 4000) {
    echo json_encode(['ok' => false, 'error' => 'Message too long (max 4 000 characters).']);
    exit;
}

$sessionId = (int) ($_POST['session_id'] ?? 0);

// ── Resolve or create session ────────────────────────────────────────────────
if ($sessionId > 0) {
    // Verify ownership
    $chk = $pdo->prepare('SELECT id FROM ai_assistant_sessions WHERE id = :id AND user_id = :uid');
    $chk->execute(['id' => $sessionId, 'uid' => $userId]);
    if (!$chk->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Session not found.']);
        exit;
    }
} else {
    // Create a new session; title = first 60 chars of the opening message
    $title = mb_substr($userMessage, 0, 60);
    if (mb_strlen($userMessage) > 60) {
        $title .= '…';
    }

    $ins = $pdo->prepare(
        'INSERT INTO ai_assistant_sessions (user_id, title, created_at, updated_at)
         VALUES (:uid, :title, NOW(), NOW())'
    );
    $ins->execute(['uid' => $userId, 'title' => $title]);
    $sessionId = (int) $pdo->lastInsertId();
}

// ── Load conversation history (last 20 turns) ─────────────────────────────────
$histStmt = $pdo->prepare(
    'SELECT role, content
     FROM ai_assistant_messages
     WHERE session_id = :sid
     ORDER BY created_at ASC
     LIMIT 40'
);
$histStmt->execute(['sid' => $sessionId]);
$history = $histStmt->fetchAll(PDO::FETCH_ASSOC);

// Build OpenAI messages array from history + new user message
$messages   = array_map(fn($r) => ['role' => $r['role'], 'content' => $r['content']], $history);
$messages[] = ['role' => 'user', 'content' => $userMessage];

// ── Call OpenAI ───────────────────────────────────────────────────────────────
$systemPrompt = ptmd_copilot_system_prompt();
$result       = openai_chat_multiturn($systemPrompt, $messages, 1600);

if (!$result['ok']) {
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit;
}

$assistantText = $result['text'];

// ── Persist both turns ───────────────────────────────────────────────────────
$insMsg = $pdo->prepare(
    'INSERT INTO ai_assistant_messages (session_id, role, content, created_at)
     VALUES (:sid, :role, :content, NOW())'
);

$insMsg->execute(['sid' => $sessionId, 'role' => 'user',      'content' => $userMessage]);
$insMsg->execute(['sid' => $sessionId, 'role' => 'assistant', 'content' => $assistantText]);

// Touch session updated_at
$pdo->prepare('UPDATE ai_assistant_sessions SET updated_at = NOW() WHERE id = :id')
    ->execute(['id' => $sessionId]);

echo json_encode([
    'ok'         => true,
    'text'       => $assistantText,
    'session_id' => $sessionId,
]);
