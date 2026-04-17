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
                            <i class="fa-solid fa-film fa-sm me-1"></i>Open Cases
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/index.php?page=cold-cases">
                            <i class="fa-solid fa-book fa-sm me-1"></i>Cold Cases
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/index.php?page=case-chat">
                            <i class="fa-solid fa-comments fa-sm me-1"></i>Case Chat
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/index.php?page=most-wanted">
                            <i class="fa-solid fa-envelope fa-sm me-1"></i>Most Wanted
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/index.php?page=solved-cases">
                            <i class="fa-solid fa-check fa-sm me-1"></i>Solved Cases
                        </a>
                    </li>
                    <li class="nav-item ms-lg-2">
                        <a class="btn btn-ptmd-outline btn-sm" href="/admin/login.php">
                            <i class="fa-solid fa-lock fa-sm me-1"></i>Admin
                        </a>
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
