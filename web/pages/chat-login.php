<?php
/**
 * PTMD — Public Chat Login
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
        $username = trim(strip_tags((string)($_POST['username'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $remember = !empty($_POST['remember']);

        if ($username === '' || $password === '') {
            $errors[] = 'Username and password are required.';
        } else {
            $pdo = get_db();
            if (!$pdo) {
                $errors[] = 'Database unavailable. Please try again later.';
            } else {
                $stmt = $pdo->prepare('SELECT id, password_hash, status FROM chat_users WHERE username = :u LIMIT 1');
                $stmt->execute(['u' => $username]);
                $row = $stmt->fetch();

                if (!$row || !password_verify($password, (string)$row['password_hash'])) {
                    $errors[] = 'Invalid username or password.';
                } elseif ($row['status'] === 'banned') {
                    $errors[] = 'This account has been banned.';
                } else {
                    chat_login((int)$row['id'], $remember);
                    $return = trim(strip_tags((string)($_GET['return'] ?? '')));
                    if ($return !== '' && str_starts_with($return, '/')) {
                        redirect($return, 'Welcome back!', 'success');
                    }
                    redirect(route_chat(), 'Welcome back!', 'success');
                }
            }
        }
    }
}
?>

<section class="container py-5" style="max-width:440px">
    <div class="mb-5" data-animate>
        <span class="ptmd-badge-teal mb-3 d-inline-block">
            <i class="fa-solid fa-comments me-1"></i>
            Case Chat
        </span>
        <h1 class="mb-2">
            <i class="fa-solid fa-right-to-bracket me-2"></i>
            Sign In
        </h1>
        <p class="ptmd-hero-sub">
            <i class="fa-solid fa-circle-info me-2"></i>
            Sign in to your chat account to post, react, and reply.
        </p>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert ptmd-alert alert-danger mb-4">
            <?php foreach ($errors as $err): ?>
                <p class="mb-0"><?php ee($err); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="ptmd-panel p-xl" data-animate>
        <form method="post" action="<?php ee(route_chat_login()); ?>" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">

            <div class="mb-4">
                <label class="form-label small fw-600">
                    <i class="fa-solid fa-user me-2"></i>
                    Username
                </label>
                <input class="form-control" type="text" name="username" maxlength="50" required
                       autocomplete="username"
                       value="<?php ee((string)($_POST['username'] ?? '')); ?>">
            </div>

            <div class="mb-4">
                <label class="form-label small fw-600">
                    <i class="fa-solid fa-lock me-2"></i>
                    Password
                </label>
                <input class="form-control" type="password" name="password" required
                       autocomplete="current-password">
            </div>

            <div class="form-check mb-5">
                <input class="form-check-input" type="checkbox" name="remember" id="rememberMe">
                <label class="form-check-label small ptmd-muted" for="rememberMe">
                    Keep me signed in for 30 days
                </label>
            </div>

            <button class="btn btn-ptmd-primary w-100" type="submit">
                <i class="fa-solid fa-right-to-bracket me-2"></i>
                Sign In
            </button>
        </form>

        <p class="ptmd-muted small text-center mt-4 mb-0">
            <a href="<?php function route_chat_forgot_password(): string
            {
                return '/chat/forgot-password';
            }

            ee(route_chat_forgot_password()); ?>" class="ptmd-text-teal">Forgot password?</a>
            No account yet?
            <a href="<?php function route_register(): string
            {
                return '/register';
            }

            ee(route_register()); ?>" class="ptmd-text-teal">Register for free</a>
        </p>
    </div>

</section>
