<?php
/**
 * PTMD — Front Controller / Router
 *
 * All requests are routed here via .htaccess.
 * Admin pages have their own entry points under /admin/.
 */

require_once __DIR__ . '/inc/bootstrap.php';

// ── Allowed public pages ──────────────────────────────────────────────────────
$allowedPages = ['home', 'episodes', 'episode', 'about', 'contact', 'case-chat'];

// ── Resolve page ──────────────────────────────────────────────────────────────
$page = isset($_GET['page']) ? trim((string) $_GET['page']) : 'home';

if (!in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = 'home';
}

// ── Episode detail — resolve slug early ───────────────────────────────────────
$currentEpisode = null;

if ($page === 'episode') {
    $slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';

    if ($slug === '') {
        redirect('/index.php?page=episodes', 'No episode selected.', 'warning');
    }

    $currentEpisode = find_episode_by_slug($slug);

    if (!$currentEpisode) {
        http_response_code(404);
        redirect('/index.php?page=episodes', 'Episode not found.', 'warning');
    }
}

// ── Page title ────────────────────────────────────────────────────────────────
$pageTitle = page_title($page, $currentEpisode);

// ── Render ────────────────────────────────────────────────────────────────────
include __DIR__ . '/inc/head.php';
include __DIR__ . '/inc/header.php';
include __DIR__ . '/pages/' . $page . '.php';
include __DIR__ . '/inc/footer.php';
