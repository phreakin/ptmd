<?php
/**
 * PTMD Admin — Shared head + sidebar partial
 *
 * Callers MUST set $pageTitle and $activePage before including this file.
 * This file calls require_login() — any admin page is protected automatically.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$adminUser  = current_admin();
$flash      = pull_flash();
$pageTitle  = $pageTitle  ?? 'Admin | Paper Trail MD';
$activePage = $activePage ?? '';

$navItems = [
    ['href' => '/admin/dashboard.php',       'label' => 'Dashboard',       'icon' => 'fa-gauge',          'id' => 'dashboard'],
    ['href' => '/admin/site-editor.php',     'label' => 'Site Editor',     'icon' => 'fa-sliders',       'id' => 'site-editor'],
    ['href' => '/admin/episodes.php',        'label' => 'Episodes',        'icon' => 'fa-film',           'id' => 'episodes'],
    ['href' => '/admin/video-processor.php', 'label' => 'Video Processor', 'icon' => 'fa-scissors',       'id' => 'video-processor'],
    ['href' => '/admin/overlay-tool.php',    'label' => 'Overlay Tool',    'icon' => 'fa-layer-group',    'id' => 'overlay-tool'],
    ['href' => '/admin/media.php',           'label' => 'Media Library',   'icon' => 'fa-photo-film',     'id' => 'media'],
    ['href' => '/admin/ai-tools.php',        'label' => 'AI Content',      'icon' => 'fa-wand-magic-sparkles', 'id' => 'ai-tools'],
    ['href' => '/admin/ai-assistant.php',    'label' => 'AI Copilot',      'icon' => 'fa-robot',          'id' => 'ai-assistant'],
    ['href' => '/admin/posts.php',            'label' => 'Social Queue',    'icon' => 'fa-calendar-check', 'id' => 'posts'],
    ['href' => '/admin/social-schedule.php', 'label' => 'Post Schedule',   'icon' => 'fa-clock',          'id' => 'social-schedule'],
    ['href' => '/admin/social-calendar.php', 'label' => 'Social Calendar', 'icon' => 'fa-calendar-days',  'id' => 'social-calendar'],
    ['href' => '/admin/chat.php',            'label' => 'Case Chat',       'icon' => 'fa-comments',       'id' => 'chat'],
    ['href' => '/admin/settings.php',        'label' => 'Settings',        'icon' => 'fa-gear',           'id' => 'settings'],
    ['href' => '/admin/site-tests.php',      'label' => 'Site Tests',      'icon' => 'fa-flask-vial',     'id' => 'site-tests'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php ee($pageTitle); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@latest/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tippy.js@latest/dist/tippy.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@latest/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="/assets/css/styles.css">
    <style>
        /* Admin-specific layout tweaks */
        body { overflow-x: hidden; }

        .ptmd-admin-shell {
            display: grid;
            grid-template-columns: 240px 1fr;
            grid-template-rows: 60px 1fr;
            min-height: 100dvh;
        }

        @media (max-width: 1024px) {
            .ptmd-admin-shell { grid-template-columns: 1fr; }
            .ptmd-admin-sidebar { display: none; }
        }

        .ptmd-admin-topbar {
            grid-column: 1 / -1;
            grid-row: 1;
        }

        .ptmd-admin-sidebar {
            grid-column: 1;
            grid-row: 2;
            height: calc(100dvh - 60px);
            position: sticky;
            top: 60px;
            overflow-y: auto;
        }

        .ptmd-admin-content {
            grid-column: 2;
            grid-row: 2;
            padding: 2rem;
            overflow-x: hidden;
        }

        @media (max-width: 1024px) {
            .ptmd-admin-content { grid-column: 1; padding: 1.25rem; }
        }
    </style>
</head>
<body>
<div class="ptmd-admin-shell">

<!-- ── Topbar ──────────────────────────────────────────────────────────────── -->
<header class="ptmd-admin-topbar d-flex align-items-center justify-content-between px-4">
    <div class="d-flex align-items-center gap-3">
        <!-- Mobile menu toggle (Bootstrap offcanvas could be wired here) -->
        <button class="btn btn-ptmd-ghost d-lg-none" type="button" id="sidebarToggle" aria-label="Toggle sidebar">
            <i class="fa-solid fa-bars"></i>
        </button>
        <a href="/admin/dashboard.php" class="d-flex align-items-center gap-2 text-decoration-none">
            <img
                src="/assets/brand/logos/ptmd_lockup.png"
                alt="Paper Trail MD"
                style="height:26px;width:auto"
                onerror="this.style.display='none'"
            >
            <span class="fw-700 d-none d-md-inline" style="font-size:var(--text-sm);font-family:'Plus Jakarta Sans',sans-serif">
                PTMD Admin
            </span>
        </a>
    </div>

    <div class="d-flex align-items-center gap-3">
        <a href="/index.php" target="_blank" rel="noopener"
           class="btn btn-ptmd-ghost btn-sm d-none d-md-inline-flex align-items-center gap-2"
           data-tippy-content="View public site">
            <i class="fa-solid fa-arrow-up-right-from-square"></i>
            <span>View Site</span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <div
                style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--ptmd-teal),var(--ptmd-navy));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:var(--text-sm);color:var(--ptmd-black)"
                data-tippy-content="<?php ee($adminUser['username'] ?? 'Admin'); ?>"
            >
                <?php echo e(strtoupper(substr($adminUser['username'] ?? 'A', 0, 1))); ?>
            </div>
            <a href="/admin/logout.php" class="btn btn-ptmd-ghost btn-sm" data-tippy-content="Logout">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </div>
</header>

<!-- ── Sidebar ─────────────────────────────────────────────────────────────── -->
<aside class="ptmd-admin-sidebar" id="adminSidebar">
    <nav class="py-3">

        <!-- Site & Content -->
        <div class="ptmd-nav-group">
            <div class="nav-group-label">Content</div>
            <?php foreach (array_slice($navItems, 0, 6) as $item): ?>
                <a
                    href="<?php ee($item['href']); ?>"
                    class="ptmd-nav-item <?php echo $activePage === $item['id'] ? 'active' : ''; ?>"
                >
                    <span class="nav-icon">
                        <i class="fa-solid <?php ee($item['icon']); ?>"></i>
                    </span>
                    <?php ee($item['label']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Publishing -->
        <div class="ptmd-nav-group">
            <div class="nav-group-label">Publishing</div>
            <?php foreach (array_slice($navItems, 6, 5) as $item): ?>
                <a
                    href="<?php ee($item['href']); ?>"
                    class="ptmd-nav-item <?php echo $activePage === $item['id'] ? 'active' : ''; ?>"
                >
                    <span class="nav-icon">
                        <i class="fa-solid <?php ee($item['icon']); ?>"></i>
                    </span>
                    <?php ee($item['label']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- System -->
        <div class="ptmd-nav-group">
            <div class="nav-group-label">System</div>
            <?php foreach (array_slice($navItems, 11) as $item): ?>
                <a
                    href="<?php ee($item['href']); ?>"
                    class="ptmd-nav-item <?php echo $activePage === $item['id'] ? 'active' : ''; ?>"
                >
                    <span class="nav-icon">
                        <i class="fa-solid <?php ee($item['icon']); ?>"></i>
                    </span>
                    <?php ee($item['label']); ?>
                </a>
            <?php endforeach; ?>
            <a href="/admin/logout.php" class="ptmd-nav-item">
                <span class="nav-icon"><i class="fa-solid fa-right-from-bracket" style="color:var(--ptmd-error)"></i></span>
                <span style="color:var(--ptmd-error)">Logout</span>
            </a>
        </div>

    </nav>
</aside>

<!-- ── Main content ────────────────────────────────────────────────────────── -->
<main class="ptmd-admin-content">

    <?php if ($flash): ?>
        <div class="alert ptmd-alert alert-<?php ee($flash['type']); ?> alert-dismissible fade show mb-4" role="alert">
            <?php ee($flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Page header -->
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-5">
        <div>
            <h1 class="h3 mb-0"><?php echo $pageHeading ?? $pageTitle; ?></h1>
            <?php if (!empty($pageSubheading)): ?>
                <p class="ptmd-muted small mb-0 mt-1"><?php ee($pageSubheading); ?></p>
            <?php endif; ?>
        </div>
        <?php if (!empty($pageActions)) echo $pageActions; ?>
    </div>

<!-- NOTE: closing tags are in _admin_footer.php -->
