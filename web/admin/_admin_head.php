<?php
/**
 * PTMD Admin — Shared head + sidebar partial
 *
 * Callers MUST set $pageTitle and $activePage before including this file.
 * This file calls require_login() — any admin page is protected automatically.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$canonicalAdminPath = ptmd_admin_route_from_script($_SERVER['SCRIPT_NAME'] ?? '');
if (
    in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)
    && $canonicalAdminPath
    && $requestPath !== $canonicalAdminPath
) {
    header('Location: ' . $canonicalAdminPath, true, 301);
    exit;
}

$adminUser  = current_admin();
$flash      = pull_flash();
$pageTitle  = $pageTitle  ?? 'Admin | Paper Trail MD';
$activePage = $activePage ?? '';
$pageShellClass = trim((string) ($pageShellClass ?? ''));
$mainClasses = 'ptmd-admin-content ptmd-page-shell';
if ($pageShellClass !== '') {
    $mainClasses .= ' ' . preg_replace('/[^A-Za-z0-9 _-]/', '', $pageShellClass);
}

$navItems = [
    ['href' => route_admin('dashboard'),        'label' => 'Dashboard',       'icon' => 'fa-gauge',          'id' => 'dashboard'],
    ['href' => route_admin('site-editor'),      'label' => 'Site Editor',     'icon' => 'fa-sliders',        'id' => 'site-editor'],
    ['href' => route_admin('cases'),            'label' => 'Cases',           'icon' => 'fa-film',           'id' => 'cases'],
    ['href' => route_admin('video-processor'),  'label' => 'Video Processor', 'icon' => 'fa-scissors',       'id' => 'video-processor'],
    ['href' => route_admin('overlay-tool'),     'label' => 'Overlay Tool',    'icon' => 'fa-layer-group',    'id' => 'overlay-tool'],
    ['href' => route_admin('edit-jobs'),        'label' => 'Edit Jobs',       'icon' => 'fa-film-simple',    'id' => 'edit-jobs'],
    ['href' => route_admin('media'),            'label' => 'Media Library',   'icon' => 'fa-photo-film',     'id' => 'media'],
    ['href' => route_admin('ai-tools'),         'label' => 'AI Content',      'icon' => 'fa-wand-magic-sparkles', 'id' => 'ai-tools'],
    ['href' => route_admin('ai-assistant'),     'label' => 'The Analyst',     'icon' => 'fa-robot',          'id' => 'ai-assistant'],
    ['href' => route_admin('posts'),            'label' => 'Dispatch',        'icon' => 'fa-calendar-check', 'id' => 'posts'],
    ['href' => route_admin('social-schedule'),  'label' => 'Post Schedule',   'icon' => 'fa-clock',          'id' => 'social-schedule'],
    ['href' => route_admin('monitor'),          'label' => 'Intelligence',    'icon' => 'fa-chart-line',     'id' => 'monitor'],
    ['href' => route_admin('content-workflow'), 'label' => 'Content Workflow','icon' => 'fa-gears',          'id' => 'content-workflow'],
    ['href' => route_admin('posting-sites'),    'label' => 'Posting Sites',   'icon' => 'fa-share-nodes',    'id' => 'posting-sites'],
    ['href' => route_admin('blueprints'),       'label' => 'Blueprints',      'icon' => 'fa-layer-group',    'id' => 'blueprints'],
    ['href' => route_admin('chat'),             'label' => 'Case Chat',       'icon' => 'fa-comments',       'id' => 'chat'],
    ['href' => route_admin('chat-rooms'),       'label' => 'Chat Rooms',      'icon' => 'fa-door-open',      'id' => 'chat-rooms'],
    ['href' => route_admin('chat-users'),       'label' => 'Chat Users',      'icon' => 'fa-users',          'id' => 'chat-users'],
    ['href' => route_admin('settings'),         'label' => 'Settings',        'icon' => 'fa-gear',           'id' => 'settings'],
    ['href' => route_admin('site-tests'),       'label' => 'Site Tests',      'icon' => 'fa-flask-vial',     'id' => 'site-tests'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php ee(csrf_token()); ?>">
    <title><?php ee($pageTitle); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@latest/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tippy.js@latest/dist/tippy.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@latest/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="/assets/css/styles.css">
    <link rel="stylesheet" href="/assets/css/admin/ptmd-ui-tokens.css">
    <link rel="stylesheet" href="/assets/css/admin/ptmd-ui-base.css">
    <link rel="stylesheet" href="/assets/css/admin/ptmd-ui-glass.css">
    <link rel="stylesheet" href="/assets/css/admin/ptmd-ui-components.css">
    <link rel="stylesheet" href="/assets/css/admin/ptmd-ui-utilities.css">
    <link rel="stylesheet" href="/assets/css/admin/ptmd-ui-motion.css">
    <link rel="stylesheet" href="/assets/css/admin/ptmd-ui-screens.css">
</head>
<body>
<div class="ptmd-admin-shell">
    <header class="ptmd-admin-topbar d-flex align-items-center justify-content-between px-4">
    <div class="d-flex align-items-center gap-3">
        <!-- Mobile menu toggle (Bootstrap offcanvas could be wired here) -->
        <button class="btn btn-ptmd-ghost d-lg-none" type="button" id="sidebarToggle" aria-label="Toggle sidebar">
            <i class="fa-solid fa-bars"></i>
        </button>
        <a href="<?php ee(route_admin('dashboard')); ?>" class="d-flex align-items-center gap-2 text-decoration-none">
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

    <a href="#" class="ptmd-topbar-command d-none d-lg-inline-flex" data-ptmd-command-open aria-label="Open command palette">
        <i class="fa-solid fa-magnifying-glass"></i>
        <span>Search cases, queue, assets, settings</span>
        <kbd>⌘K</kbd>
    </a>

    <div class="d-flex align-items-center gap-3">
        <a href="<?php ee(route_home()); ?>" target="_blank" rel="noopener"
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
            <a href="<?php ee(route_admin_logout()); ?>" class="btn btn-ptmd-ghost btn-sm" data-tippy-content="Logout">
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
            <?php foreach (array_slice($navItems, 0, 7) as $item): ?>
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
            <?php foreach (array_slice($navItems, 7, 8) as $item): ?>
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
            <?php foreach (array_slice($navItems, 15) as $item): ?>
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
            <a href="<?php ee(route_admin_logout()); ?>" class="ptmd-nav-item">
                <span class="nav-icon"><i class="fa-solid fa-right-from-bracket" style="color:var(--ptmd-error)"></i></span>
                <span style="color:var(--ptmd-error)">Logout</span>
            </a>
        </div>

    </nav>
</aside>

<!-- ── Main content ────────────────────────────────────────────────────────── -->
<main class="<?php echo e(trim($mainClasses)); ?>">

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
