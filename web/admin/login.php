<?php
/**
 * PTMD — Admin Login
 */

require_once __DIR__ . '/../inc/bootstrap.php';

// Already logged in → go to dashboard
if (is_logged_in()) {
    redirect('/admin/dashboard.php');
}

if (is_post()) {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $csrf     = $_POST['csrf_token'] ?? null;

    if (!verify_csrf($csrf)) {
        redirect('/admin/login.php', 'Invalid form submission. Please try again.', 'danger');
    }

    $pdo = get_db();
    if (!$pdo) {
        redirect('/admin/login.php', 'Database connection unavailable.', 'danger');
    }

    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = (int) $user['id'];
        redirect('/admin/dashboard.php', 'Welcome back.', 'success');
    }

    // Intentionally vague error
    redirect('/admin/login.php', 'Invalid credentials.', 'danger');
}

$flash     = pull_flash();
$pageTitle = 'Admin Login | Paper Trail MD';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php ee($pageTitle); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@latest/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100dvh;background:var(--ptmd-black)">

<div class="w-100" style="max-width:420px;padding:1.5rem">

    <!-- Logo -->
    <div class="text-center mb-5">
        <img
            src="/assets/brand/logos/ptmd_lockup.png"
            alt="Paper Trail MD"
            style="height:40px;margin-bottom:1rem"
            onerror="this.style.display='none'"
        >
        <p class="ptmd-muted small">Admin access only</p>
    </div>

    <?php if ($flash): ?>
        <div class="alert ptmd-alert alert-<?php ee($flash['type']); ?> mb-4" role="alert">
            <?php ee($flash['message']); ?>
        </div>
    <?php endif; ?>

    <div class="ptmd-panel p-xl">
        <h1 class="h5 mb-4">Sign In</h1>
        <form method="post" action="/admin/login.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
            <div class="mb-3">
                <label class="form-label" for="login_username">Username</label>
                <input
                    class="form-control"
                    id="login_username"
                    name="username"
                    autocomplete="username"
                    required
                    autofocus
                >
            </div>
            <div class="mb-4">
                <label class="form-label" for="login_password">Password</label>
                <input
                    class="form-control"
                    id="login_password"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >
            </div>
            <button class="btn btn-ptmd-primary w-100" type="submit">
                <i class="fa-solid fa-right-to-bracket me-2"></i>Sign In
            </button>
        </form>
    </div>

    <p class="text-center ptmd-muted small mt-4">
        <a href="/index.php">← Back to public site</a>
    </p>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
