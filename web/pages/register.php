<?php
/**
 * PTMD — Public Chat Registration
 */
require_once __DIR__ . '/../inc/chat_auth.php';

// Already logged in?
if (is_chat_logged_in()) {
    redirect(route_chat());
}

$errors = [];

if (is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    } else {
        $username    = trim(strip_tags((string) ($_POST['username']     ?? '')));
        $displayName = trim(strip_tags((string) ($_POST['display_name'] ?? '')));
        $email       = trim((string) ($_POST['email']    ?? ''));
        $password    = (string) ($_POST['password']  ?? '');
        $password2   = (string) ($_POST['password2'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9_\-]{3,50}$/', $username)) {
            $errors[] = 'Username must be 3–50 characters using only letters, numbers, underscores, or hyphens.';
        }
        if (strlen($displayName) < 1 || strlen($displayName) > 80) {
            $errors[] = 'Display name must be 1–80 characters.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($password !== $password2) {
            $errors[] = 'Passwords do not match.';
        }

        if (empty($errors)) {
            $pdo = get_db();
            if (!$pdo) {
                $errors[] = 'Database unavailable. Please try again later.';
            } else {
                $checkStmt = $pdo->prepare('SELECT id FROM chat_users WHERE username = :u LIMIT 1');
                $checkStmt->execute(['u' => $username]);
                if ($checkStmt->fetch()) {
                    $errors[] = 'That username is already taken.';
                }

                if ($email !== '') {
                    $checkEmail = $pdo->prepare('SELECT id FROM chat_users WHERE email = :e LIMIT 1');
                    $checkEmail->execute(['e' => $email]);
                    if ($checkEmail->fetch()) {
                        $errors[] = 'That email address is already registered.';
                    }
                }

                if (empty($errors)) {
                    $palette     = ['#2EC4B6','#FFD60A','#C1121F','#6A0DAD','#2563EB','#F97316','#22C55E'];
                    $avatarColor = $palette[abs(crc32($username)) % count($palette)];

                    $stmt = $pdo->prepare(
                        'INSERT INTO chat_users
                             (username, email, password_hash, display_name, avatar_color, role, status, created_at, updated_at)
                         VALUES
                             (:username, :email, :pw, :display_name, :color, "registered", "active", NOW(), NOW())'
                    );
                    $stmt->execute([
                        'username'     => $username,
                        'email'        => $email !== '' ? $email : null,
                        'pw'           => password_hash($password, PASSWORD_BCRYPT),
                        'display_name' => $displayName,
                        'color'        => $avatarColor,
                    ]);

                    $newId = (int) $pdo->lastInsertId();
                    chat_login($newId, false);
                    redirect(route_chat(), 'Welcome! You can now post in Case Chat.', 'success');
                }
            }
        }
    }
}
?>

<section class="container py-5" style="max-width:500px">

    <div class="mb-5" data-animate>
        <span class="ptmd-badge-teal mb-3 d-inline-block">
            <i class="fa-solid fa-user-plus me-1"></i> New Account
        </span>
        <h1 class="mb-2">Join Case Chat</h1>
        <p class="ptmd-hero-sub">
            Create a free account to post messages, react, and reply in the chat.
        </p>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert ptmd-alert alert-danger mb-4">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $err): ?>
                    <li><?php ee($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="ptmd-panel p-xl" data-animate>
        <form method="post" action="<?php ee(route_register()); ?>" autocomplete="off" novalidate>
            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">

            <div class="mb-4">
                <label class="form-label small fw-600">Username <span class="ptmd-text-red">*</span></label>
                <input class="form-control" type="text" name="username" maxlength="50" required
                       autocomplete="username"
                       value="<?php ee((string) ($_POST['username'] ?? '')); ?>"
                       placeholder="e.g. FactCheckFan">
                <small class="ptmd-muted">Letters, numbers, underscores, hyphens. Shown in chat.</small>
            </div>

            <div class="mb-4">
                <label class="form-label small fw-600">Display Name <span class="ptmd-text-red">*</span></label>
                <input class="form-control" type="text" name="display_name" maxlength="80" required
                       value="<?php ee((string) ($_POST['display_name'] ?? '')); ?>"
                       placeholder="e.g. Fact Check Fan">
            </div>

            <div class="mb-4">
                <label class="form-label small fw-600">Email <span class="ptmd-muted">(optional)</span></label>
                <input class="form-control" type="email" name="email" maxlength="150"
                       autocomplete="email"
                       value="<?php ee((string) ($_POST['email'] ?? '')); ?>">
            </div>

            <div class="mb-4">
                <label class="form-label small fw-600">Password <span class="ptmd-text-red">*</span></label>
                <input class="form-control" type="password" name="password" minlength="8" required
                       autocomplete="new-password">
                <small class="ptmd-muted">At least 8 characters.</small>
            </div>

            <div class="mb-5">
                <label class="form-label small fw-600">Confirm Password <span class="ptmd-text-red">*</span></label>
                <input class="form-control" type="password" name="password2" minlength="8" required
                       autocomplete="new-password">
            </div>

            <button class="btn btn-ptmd-primary w-100" type="submit">
                <i class="fa-solid fa-user-plus me-2"></i>Create Account
            </button>
        </form>

        <p class="ptmd-muted small text-center mt-4 mb-0">
            Already have an account?
            <a href="<?php ee(route_chat_login()); ?>" class="ptmd-text-teal">Sign in</a>
        </p>
    </div>

</section>
