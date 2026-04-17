<?php
/**
 * PTMD — Chat Authentication Helpers
 *
 * Public chat user session management, entirely separate from admin auth.
 * Include this file in any page or API that needs to know about the chat user.
 */

/** Return true if a chat user is signed in for this session. */
function is_chat_logged_in(): bool
{
    return !empty($_SESSION['chat_user_id']);
}

/** Return the current chat user row (cached per-request). */
function current_chat_user(): ?array
{
    static $loaded = false;
    static $user   = null;

    if ($loaded) {
        return $user;
    }

    $loaded = true;

    if (is_chat_logged_in()) {
        $user = get_chat_user_by_id((int) $_SESSION['chat_user_id']);
        return $user;
    }

    // Try remember-me cookie
    $user = _chat_auth_remember_me();
    return $user;
}

/** Load a chat user row by id (static cache, password_hash excluded). */
function get_chat_user_by_id(int $id): ?array
{
    static $cache = [];

    if ($id <= 0) {
        return null;
    }

    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }

    $pdo = get_db();
    if (!$pdo) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, username, email, display_name, avatar_color, role, status, muted_until, badge_label
         FROM chat_users WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $cache[$id] = $stmt->fetch() ?: null;

    return $cache[$id];
}

/**
 * Numeric rank for role comparisons.
 * Higher = more privileged.
 */
function chat_role_rank(string $role): int
{
    return match ($role) {
        'super_admin' => 5,
        'admin'       => 4,
        'moderator'   => 3,
        'registered'  => 2,
        'guest'       => 1,
        default       => 0,
    };
}

/**
 * Return true if the current chat user holds at least one of the given roles.
 * Roles are checked by rank, so 'moderator' also satisfies 'registered'.
 */
function chat_user_has_role(string ...$roles): bool
{
    $user = current_chat_user();
    if (!$user) {
        return false;
    }

    $userRank = chat_role_rank($user['role']);
    foreach ($roles as $role) {
        if ($userRank >= chat_role_rank($role)) {
            return true;
        }
    }

    return false;
}

/** Shortcut: is the current user a moderator or higher? */
function is_chat_moderator(): bool
{
    return chat_user_has_role('moderator');
}

/**
 * Set the chat session and, optionally, a 30-day remember-me cookie.
 */
function chat_login(int $userId, bool $remember = false): void
{
    $_SESSION['chat_user_id'] = $userId;

    if (!$remember) {
        return;
    }

    $token = bin2hex(random_bytes(32));
    $pdo   = get_db();
    if ($pdo) {
        $pdo->prepare('UPDATE chat_users SET remember_token = :t, updated_at = NOW() WHERE id = :id')
            ->execute(['t' => $token, 'id' => $userId]);
    }

    $hmac   = hash_hmac('sha256', $userId . ':' . $token, _chat_hmac_key());
    $cookie = $userId . ':' . $hmac;

    setcookie('chat_remember', $cookie, [
        'expires'  => time() + 60 * 60 * 24 * 30,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Clear session and remember-me cookie.
 */
function chat_logout(): void
{
    unset($_SESSION['chat_user_id']);

    $cookie = $_COOKIE['chat_remember'] ?? '';
    if ($cookie === '') {
        return;
    }

    $parts  = explode(':', $cookie, 2);
    $userId = (int) ($parts[0] ?? 0);

    if ($userId > 0) {
        $pdo = get_db();
        if ($pdo) {
            $pdo->prepare('UPDATE chat_users SET remember_token = NULL, updated_at = NOW() WHERE id = :id')
                ->execute(['id' => $userId]);
        }
    }

    setcookie('chat_remember', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Check whether the current user may send in the given room.
 * Returns null on success, or an error string to surface to the client.
 */
function chat_user_send_guard(?int $roomId = null): ?string
{
    $user = current_chat_user();
    if (!$user) {
        return null; // anonymous; caller checks members_only separately
    }

    if ($user['status'] === 'banned') {
        return 'Your account has been banned from this chat.';
    }

    if ($user['status'] === 'muted') {
        $until = $user['muted_until'];
        if ($until === null || strtotime($until) > time()) {
            $untilStr = $until ? date('M j \a\t g:ia', strtotime($until)) : 'permanently';
            return "You are muted until {$untilStr}.";
        }
        // Mute has expired — let it through; status will be cleared next background cycle
    }

    if ($roomId !== null) {
        $pdo = get_db();
        if ($pdo) {
            $stmt = $pdo->prepare(
                'SELECT id FROM chat_user_bans
                 WHERE chat_user_id = :uid
                   AND (room_id = :room OR room_id IS NULL)
                   AND (expires_at IS NULL OR expires_at > NOW())
                 LIMIT 1'
            );
            $stmt->execute(['uid' => (int) $user['id'], 'room' => $roomId]);
            if ($stmt->fetch()) {
                return 'You are banned from this chat.';
            }
        }
    }

    return null;
}

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

/** Try to authenticate via the remember-me cookie; restores session on success. */
function _chat_auth_remember_me(): ?array
{
    $cookie = $_COOKIE['chat_remember'] ?? '';
    if ($cookie === '') {
        return null;
    }

    $parts = explode(':', $cookie, 2);
    if (count($parts) !== 2) {
        return null;
    }

    [$userId, $cookieHmac] = $parts;
    $userId = (int) $userId;

    $pdo = get_db();
    if (!$pdo || $userId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, username, email, display_name, avatar_color, role, status, muted_until, badge_label, remember_token
         FROM chat_users WHERE id = :id AND status != "banned" LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch() ?: null;

    if (!$row || empty($row['remember_token'])) {
        return null;
    }

    $expected = hash_hmac('sha256', $userId . ':' . $row['remember_token'], _chat_hmac_key());
    if (!hash_equals($expected, $cookieHmac)) {
        return null;
    }

    $_SESSION['chat_user_id'] = $userId;
    unset($row['remember_token']);

    return $row;
}

/** Derive a stable HMAC key from config. */
function _chat_hmac_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $config = $GLOBALS['config'] ?? [];
    $key    = hash('sha256', ($config['session_name'] ?? 'ptmd') . '_chat_hmac_v1');
    return $key;
}
