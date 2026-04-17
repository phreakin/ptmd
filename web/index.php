<?php
/**
 * PTMD — Front Controller / Router
 *
 * All requests are routed here via .htaccess.
 * Admin pages have their own entry points under /admin/.
 */

require_once __DIR__ . '/inc/bootstrap.php';

// ── Allowed public pages ──────────────────────────────────────────────────────
$allowedPages = ['home', 'cases', 'case', 'about', 'series', 'contact', 'case-chat', 'register', 'chat-login'];

// ── Resolve page ──────────────────────────────────────────────────────────────
$page = isset($_GET['page']) ? trim((string) $_GET['page']) : 'home';

if (!in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = 'home';
}

// ── case detail — resolve slug early ───────────────────────────────────────
$current_case = null;

if ($page === 'case') {
    $slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';

    if ($slug === '') {
        redirect('/index.php?page=cases', 'No case selected.', 'warning');
    }

    $current_case = find_case_by_slug($slug);

    if (!$current_case) {
        http_response_code(404);
        redirect('/index.php?page=cases', 'case not found.', 'warning');
    }
}

// ── Page title ────────────────────────────────────────────────────────────────
$pageTitle = page_title($page, $current_case);

// ── Render ────────────────────────────────────────────────────────────────────
include __DIR__ . '/inc/head.php';
include __DIR__ . '/inc/header.php';
include __DIR__ . '/pages/' . $page . '.php';
include __DIR__ . '/inc/footer.php';
