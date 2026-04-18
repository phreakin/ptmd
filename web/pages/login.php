<?php
/**
 * PTMD — Viewer Login / Register page
 *
 * Handles both Sign In (POST action=login) and Create Account (POST action=register).
 * On success the viewer is logged in via viewer_login() and redirected.
 */

// Already logged in → go to account
if (is_viewer_logged_in()) {
    $returnPath = isset($_GET['return']) ? trim((string) $_GET['return']) : '';
    redirect(ptmd_safe_public_return_path($returnPath));
}

$flash     = pull_flash();
$activeTab = 'signin'; // default tab

// ── POST handler ──────────────────────────────────────────────────────────────
if (is_post()) {
    $action = trim((string) ($_POST['action'] ?? ''));
    $returnRaw = trim((string) ($_POST['return_path'] ?? ($_GET['return'] ?? '')));
    $returnSlug = trim((string) ($_POST['return_slug'] ?? ($_GET['slug'] ?? '')));
    $loginQuery = [];
    if ($returnRaw !== '') {
        $loginQuery['return'] = $returnRaw;
    }
    if ($returnSlug !== '') {
        $loginQuery['slug'] = $returnSlug;
    }
    $loginUrl = !empty($loginQuery) ? url('/login', $loginQuery) : route_login();
    $registerTabUrl = url('/login', array_merge($loginQuery, ['tab' => 'register']));
    $errorReturnUrl = $action === 'register' ? $registerTabUrl : $loginUrl;

    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('Invalid form submission. Please try again.', 'danger');
        redirect($errorReturnUrl);
    }

    $pdo = get_db();
    if (!$pdo) {
        set_flash('Database connection unavailable.', 'danger');
        redirect($errorReturnUrl);
    }

    $returnPath = ptmd_safe_public_return_path($returnRaw, $returnSlug);

    // ── Sign In ───────────────────────────────────────────────────────────────
    if ($action === 'login') {
        $email    = trim((string) ($_POST['email']    ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            set_flash('Email and password are required.', 'danger');
            redirect($loginUrl);
        }

        $stmt = $pdo->prepare(
            'SELECT id, password_hash FROM viewer_users
             WHERE email = :email AND status = :status LIMIT 1'
        );
        $stmt->execute(['email' => $email, 'status' => 'active']);
        $viewer = $stmt->fetch();

        if ($viewer && password_verify($password, $viewer['password_hash'])) {
            viewer_login((int) $viewer['id']);
            redirect($returnPath, 'Welcome back!', 'success');
        }

        set_flash('Invalid email or password.', 'danger');
        redirect($loginUrl);
    }

    // ── Register ──────────────────────────────────────────────────────────────
    if ($action === 'register') {
        $activeTab    = 'register';
        $username     = trim((string) ($_POST['username']         ?? ''));
        $email        = trim((string) ($_POST['reg_email']        ?? ''));
        $displayName  = trim((string) ($_POST['display_name']     ?? ''));
        $password     = (string) ($_POST['reg_password']          ?? '');
        $passwordConf = (string) ($_POST['reg_password_confirm']  ?? '');

        // Validate
        if ($username === '' || $email === '' || $password === '') {
            set_flash('Username, email, and password are required.', 'danger');
            redirect($registerTabUrl);
        }

        if (!preg_match('/^[a-zA-Z0-9_\-]{3,50}$/', $username)) {
            set_flash('Username must be 3–50 characters and contain only letters, numbers, underscores, or hyphens.', 'danger');
            redirect($registerTabUrl);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            set_flash('Please enter a valid email address.', 'danger');
            redirect($registerTabUrl);
        }

        if (strlen($password) < 8) {
            set_flash('Password must be at least 8 characters.', 'danger');
            redirect($registerTabUrl);
        }

        if ($password !== $passwordConf) {
            set_flash('Passwords do not match.', 'danger');
            redirect($registerTabUrl);
        }

        // Uniqueness checks
        $checkUser = $pdo->prepare('SELECT id FROM viewer_users WHERE username = :u LIMIT 1');
        $checkUser->execute(['u' => $username]);
        if ($checkUser->fetchColumn()) {
            set_flash('That username is already taken.', 'danger');
            redirect($registerTabUrl);
        }

        $checkEmail = $pdo->prepare('SELECT id FROM viewer_users WHERE email = :e LIMIT 1');
        $checkEmail->execute(['e' => $email]);
        if ($checkEmail->fetchColumn()) {
            set_flash('An account with that email already exists.', 'danger');
            redirect($registerTabUrl);
        }

        // Insert
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $dn   = $displayName !== '' ? $displayName : $username;

        $ins = $pdo->prepare(
            'INSERT INTO viewer_users
                (username, email, password_hash, display_name, status, created_at, updated_at)
             VALUES (:u, :e, :h, :d, :s, NOW(), NOW())'
        );
        $ins->execute([
            'u' => $username,
            'e' => $email,
            'h' => $hash,
            'd' => $dn,
            's' => 'active',
        ]);

        viewer_login((int) $pdo->lastInsertId());
        redirect($returnPath, 'Account created! Welcome to Paper Trail MD.', 'success');
    }

    // Unknown action
    redirect($loginUrl);
}

// Detect which tab to show based on anchor or flash
if (isset($_GET['tab']) && $_GET['tab'] === 'register') {
    $activeTab = 'register';
}

$returnParam = isset($_GET['return']) ? trim((string) $_GET['return']) : route_account();
$returnSlugParam = isset($_GET['slug']) ? trim($_GET['slug']) : '';
$loginPageQuery = [];
if ($returnParam !== '') {
    $loginPageQuery['return'] = $returnParam;
}
if ($returnSlugParam !== '') {
    $loginPageQuery['slug'] = $returnSlugParam;
}
$signInTabUrl = !empty($loginPageQuery) ? url('/login', $loginPageQuery) : route_login();
$registerTabUrl = url('/login', array_merge($loginPageQuery, ['tab' => 'register']));
?>

<section class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5">

            <div class="text-center mb-5" data-animate>
                <a href="<?php ee(route_home()); ?>" class="d-inline-block mb-3">
                    <img
                        src="/assets/brand/logos/ptmd_lockup.png"
                        alt="<?php ee(site_setting('site_name', 'Paper Trail MD')); ?>"
                        style="height:36px"
                        onerror="this.style.display='none'"
                    >
                </a>
                <h1 class="h4 mb-1">Your Account</h1>
                <p class="ptmd-muted small">Save cases. Track your favorites.</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert ptmd-alert alert-<?php ee($flash['type']); ?> mb-4" role="alert">
                    <?php ee($flash['message']); ?>
                </div>
            <?php endif; ?>

            <div class="ptmd-auth-card" data-animate>

                <!-- Tabs -->
                <ul class="nav nav-tabs ptmd-auth-tabs" id="authTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link <?php echo $activeTab === 'signin' ? 'active' : ''; ?>"
                            id="signinTab"
                            data-bs-toggle="tab"
                            data-bs-target="#signinPane"
                            type="button"
                            role="tab"
                            aria-controls="signinPane"
                            aria-selected="<?php echo $activeTab === 'signin' ? 'true' : 'false'; ?>"
                        >
                            <i class="fa-solid fa-right-to-bracket me-1"></i>Sign In
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link <?php echo $activeTab === 'register' ? 'active' : ''; ?>"
                            id="registerTab"
                            data-bs-toggle="tab"
                            data-bs-target="#registerPane"
                            type="button"
                            role="tab"
                            aria-controls="registerPane"
                            aria-selected="<?php echo $activeTab === 'register' ? 'true' : 'false'; ?>"
                        >
                            <i class="fa-solid fa-user-plus me-1"></i>Create Account
                        </button>
                    </li>
                </ul>

                <div class="tab-content ptmd-auth-tab-body">

                    <!-- ── Sign In pane ───────────────────────────────────── -->
                    <div
                        class="tab-pane fade <?php echo $activeTab === 'signin' ? 'show active' : ''; ?>"
                        id="signinPane"
                        role="tabpanel"
                        aria-labelledby="signinTab"
                    >
                        <form method="post" action="<?php ee(route_login()); ?>" novalidate>
                            <input type="hidden" name="csrf_token"   value="<?php ee(csrf_token()); ?>">
                            <input type="hidden" name="action"        value="login">
                            <input type="hidden" name="return_path"  value="<?php ee($returnParam); ?>">
                            <input type="hidden" name="return_slug"  value="<?php ee($returnSlugParam); ?>">

                            <div class="mb-3">
                                <label class="form-label" for="signin_email">Email address</label>
                                <input
                                    class="form-control"
                                    id="signin_email"
                                    name="email"
                                    type="email"
                                    autocomplete="email"
                                    required
                                    autofocus
                                >
                            </div>
                            <div class="mb-4">
                                <label class="form-label" for="signin_password">Password</label>
                                <input
                                    class="form-control"
                                    id="signin_password"
                                    name="password"
                                    type="password"
                                    autocomplete="current-password"
                                    required
                                >
                            </div>
                            <button class="btn btn-ptmd-primary w-100" type="submit">
                                <i class="fa-solid fa-right-to-bracket me-2"></i>Sign In
                            </button>
                        </form>

                        <p class="text-center ptmd-muted small mt-4 mb-0">
                            No account?
                            <a
                                class="btn btn-link btn-sm p-0"
                                href="<?php ee($registerTabUrl); ?>"
                                data-bs-toggle="tab"
                                data-bs-target="#registerPane"
                                role="button"
                                aria-controls="registerPane"
                                aria-selected="<?php echo $activeTab === 'register' ? 'true' : 'false'; ?>"
                                style="color:var(--ptmd-teal)"
                            >
                                Create one
                            </a>
                        </p>
                    </div>

                    <!-- ── Create Account pane ────────────────────────────── -->
                    <div
                        class="tab-pane fade <?php echo $activeTab === 'register' ? 'show active' : ''; ?>"
                        id="registerPane"
                        role="tabpanel"
                        aria-labelledby="registerTab"
                    >
                        <form method="post" action="<?php ee(route_login()); ?>" novalidate>
                            <input type="hidden" name="csrf_token"  value="<?php ee(csrf_token()); ?>">
                            <input type="hidden" name="action"       value="register">
                            <input type="hidden" name="return_path" value="<?php ee($returnParam); ?>">
                            <input type="hidden" name="return_slug" value="<?php ee($returnSlugParam); ?>">

                            <div class="mb-3">
                                <label class="form-label" for="reg_username">Username</label>
                                <input
                                    class="form-control"
                                    id="reg_username"
                                    name="username"
                                    type="text"
                                    autocomplete="username"
                                    maxlength="50"
                                    required
                                >
                                <div class="form-text">Letters, numbers, underscores, hyphens — 3 to 50 chars.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="reg_display_name">Display name <span class="ptmd-muted">(optional)</span></label>
                                <input
                                    class="form-control"
                                    id="reg_display_name"
                                    name="display_name"
                                    type="text"
                                    maxlength="100"
                                >
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="reg_email">Email address</label>
                                <input
                                    class="form-control"
                                    id="reg_email"
                                    name="reg_email"
                                    type="email"
                                    autocomplete="email"
                                    required
                                >
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="reg_password">Password</label>
                                <input
                                    class="form-control"
                                    id="reg_password"
                                    name="reg_password"
                                    type="password"
                                    autocomplete="new-password"
                                    minlength="8"
                                    required
                                >
                                <div class="form-text">At least 8 characters.</div>
                            </div>
                            <div class="mb-4">
                                <label class="form-label" for="reg_password_confirm">Confirm password</label>
                                <input
                                    class="form-control"
                                    id="reg_password_confirm"
                                    name="reg_password_confirm"
                                    type="password"
                                    autocomplete="new-password"
                                    required
                                >
                            </div>
                            <button class="btn btn-ptmd-primary w-100" type="submit">
                                <i class="fa-solid fa-user-plus me-2"></i>Create Account
                            </button>
                        </form>

                        <p class="text-center ptmd-muted small mt-4 mb-0">
                            Already have an account?
                            <a
                                class="btn btn-link btn-sm p-0"
                                href="<?php ee($signInTabUrl); ?>"
                                data-bs-toggle="tab"
                                data-bs-target="#signinPane"
                                role="button"
                                aria-controls="signinPane"
                                aria-selected="<?php echo $activeTab === 'signin' ? 'true' : 'false'; ?>"
                                style="color:var(--ptmd-teal)"
                            >
                                Sign in
                            </a>
                        </p>
                    </div>

                </div><!-- /tab-content -->
            </div><!-- /ptmd-auth-card -->

            <p class="text-center ptmd-muted small mt-4">
                <a href="<?php ee(route_home()); ?>">← Back to site</a>
            </p>

        </div>
    </div>
</section>
