<?php
/**
 * PTMD — Public site header / navigation partial
 */
$flash = pull_flash();
?>
<header class="ptmd-header sticky-top">
    <nav class="navbar navbar-expand-lg ptmd-navbar">
        <div class="container">

            <!-- Brand lockup -->
            <a class="navbar-brand d-flex align-items-center gap-2" href="/index.php">
                <img
                    src="/assets/brand/logos/ptmd_lockup.png"
                    alt="<?php ee(site_setting('site_name', 'Paper Trail MD')); ?>"
                    class="ptmd-nav-logo"
                    onerror="this.style.display='none'"
                >
                <span class="ptmd-brand-name">
                    <?php ee(site_setting('site_name', 'Paper Trail MD')); ?>
                </span>
            </a>

            <!-- Mobile toggle -->
            <button
                class="navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#ptmdNav"
                aria-controls="ptmdNav"
                aria-expanded="false"
                aria-label="Toggle navigation"
            >
                <i class="fa-solid fa-bars"></i>
            </button>

            <!-- Nav links -->
            <div class="collapse navbar-collapse" id="ptmdNav">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-1">
                    <li class="nav-item">
                        <a class="nav-link" href="/index.php">
                            <i class="fa-solid fa-house fa-sm me-1"></i>Home
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/index.php?page=cases">
                            <i class="fa-solid fa-folder-open fa-sm me-1"></i>Cases
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/index.php?page=series">
                            <i class="fa-solid fa-layer-group fa-sm me-1"></i>Series
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-2" href="/index.php?page=case-chat">
                            <i class="fa-solid fa-comments fa-sm"></i>Case Chat
                            <span class="ptmd-live-dot ms-1" aria-hidden="true"></span>
                            <span class="visually-hidden">Live</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link ptmd-nav-live d-flex align-items-center gap-2" href="/index.php?page=case-chat">
                            <i class="fa-solid fa-tower-broadcast fa-sm"></i>Live
                            <span class="ptmd-live-dot" aria-hidden="true"></span>
                        </a>
                    </li>
                    <li class="nav-item ms-lg-2">
                        <?php
                        $navViewer     = current_viewer();
                        $navAdminLoggedIn = !empty($_SESSION['admin_user_id']);
                        ?>
                        <?php if ($navViewer): ?>
                            <!-- Logged-in viewer dropdown -->
                            <div class="dropdown ptmd-viewer-menu">
                                <button
                                    class="btn btn-ptmd-teal btn-sm dropdown-toggle"
                                    type="button"
                                    data-bs-toggle="dropdown"
                                    aria-expanded="false"
                                >
                                    <i class="fa-solid fa-circle-user fa-sm me-1"></i>
                                    <?php ee($navViewer['display_name'] ?: $navViewer['username']); ?>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end ptmd-viewer-dropdown">
                                    <li>
                                        <a class="dropdown-item" href="/index.php?page=account">
                                            <i class="fa-solid fa-heart fa-sm me-2" style="color:var(--ptmd-teal)"></i>Saved Episodes
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="post" action="/index.php?page=logout" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                            <button type="submit" class="dropdown-item">
                                                <i class="fa-solid fa-right-from-bracket fa-sm me-2"></i>Sign Out
                                            </button>
                                        </form>
                                    </li>
                                    <?php if ($navAdminLoggedIn): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a class="dropdown-item ptmd-muted" href="/admin/dashboard.php">
                                                <i class="fa-solid fa-lock fa-sm me-2"></i>Admin
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        <?php else: ?>
                            <!-- Not logged in -->
                            <a class="btn btn-ptmd-teal btn-sm me-2" href="/index.php?page=login">
                                <i class="fa-solid fa-right-to-bracket fa-sm me-1"></i>Sign In
                            </a>
                            <a class="btn btn-ptmd-ghost btn-sm" href="/admin/login.php" style="font-size:var(--text-xs);opacity:0.7">
                                <i class="fa-solid fa-lock fa-sm me-1"></i>Admin
                            </a>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>

        </div>
    </nav>
</header>

<?php if ($flash): ?>
    <div class="container mt-3">
        <div class="alert ptmd-alert alert-<?php ee($flash['type']); ?> alert-dismissible fade show" role="alert">
            <?php ee($flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    </div>
<?php endif; ?>

<main class="ptmd-main">
