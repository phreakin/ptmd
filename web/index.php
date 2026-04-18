<?php
/**
 * PTMD — Front Controller / Router
 *
 * All requests are routed here via .htaccess.
 * Admin pages have their own entry points under /admin/.
 *
 * Routing modes (both supported):
 *   1. CLEAN PATHS (preferred, used by route helpers):
 *        /              → home
 *        /cases         → cases
 *        /case/{slug}   → case (slug extracted from path)
 *        /series        → series
 *        /chat          → case-chat
 *        /case-chat     → case-chat (alias)
 *        /about         → about
 *        /contact       → contact
 *        /login         → login
 *        /chat-login    → chat-login
 *        /register      → register
 *        /account       → account
 *        /logout        → logout (POST only, inline handler)
 *
 *   2. LEGACY QUERY STRING (back-compat):
 *        /index.php?page=cases
 *        /index.php?page=case&slug=foo
 *        ...
 *
 * Both map to the same internal page names in $allowedPages and include
 * the same file under /pages/<page>.php.
 */

require_once __DIR__ . '/inc/bootstrap.php';

// ── Allowed public pages ──────────────────────────────────────────────────────
$allowedPages = [
    'home', 'cases', 'case', 'about', 'series', 'contact',
    'case-chat', 'register', 'chat-login', 'login', 'account',
];

// ── Clean-path → page-name table ──────────────────────────────────────────────
$cleanRoutes = [
    ''            => 'home',
    'cases'       => 'cases',
    'series'      => 'series',
    'about'       => 'about',
    'contact'     => 'contact',
    'chat'        => 'case-chat',   // preferred clean alias
    'case-chat'   => 'case-chat',   // legacy alias still works
    'login'       => 'login',
    'chat-login'  => 'chat-login',
    'register'    => 'register',
    'account'     => 'account',
    'logout'      => 'logout',
];

// ── Resolve page ──────────────────────────────────────────────────────────────
// 1. Try clean-path routing first.
$page = null;
$slug = null;

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$trimmed = trim($uriPath, '/');

// Only interpret as a clean route if we didn't hit /index.php directly.
if ($trimmed !== 'index.php') {
    if (isset($cleanRoutes[$trimmed])) {
        $page = $cleanRoutes[$trimmed];
    } elseif (str_starts_with($trimmed, 'case/')) {
        // /case/<slug>
        $page = 'case';
        $slug = substr($trimmed, strlen('case/'));
    }
}

// 2. Fall back to legacy ?page=X query param.
if ($page === null) {
    $page = isset($_GET['page']) ? trim((string) $_GET['page']) : 'home';
}

// Slug override from query string still wins if explicitly set.
if (isset($_GET['slug']) && $_GET['slug'] !== '') {
    $slug = trim((string) $_GET['slug']);
}

// ── Logout — inline handler, no template needed ───────────────────────────────
if ($page === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
        viewer_logout();
    }
    header('Location: ' . route_home());
    exit;
}

if (!in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = 'home';
}

// ── case detail — resolve slug early ──────────────────────────────────────────
$current_case = null;

if ($page === 'case') {
    if ($slug === null || $slug === '') {
        redirect(route_cases(), 'No case selected.', 'warning');
    }

    $current_case = find_case_by_slug($slug);

    if (!$current_case) {
        http_response_code(404);
        redirect(route_cases(), 'Case not found.', 'warning');
    }
}

// ── Page title ────────────────────────────────────────────────────────────────
$pageTitle = page_title($page, $current_case);

// ── Render ────────────────────────────────────────────────────────────────────
include __DIR__ . '/inc/head.php';
include __DIR__ . '/inc/header.php';
include __DIR__ . '/pages/' . $page . '.php';
include __DIR__ . '/inc/footer.php';
