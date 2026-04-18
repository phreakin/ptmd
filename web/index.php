<?php
/**
 * PTMD - Front Controller / Router
 *
 * All public requests are routed here via .htaccess. Clean path-based routes
 * are canonical; legacy query-string routes are redirected to their clean
 * equivalents for GET/HEAD traffic.
 */

require_once __DIR__ . '/inc/bootstrap.php';

$allowedPages = [
    'home', 'cases', 'case', 'about', 'contact',
    'case-chat', 'register', 'chat-login', 'login', 'account',
];

$cleanRoutes = [
    ''           => 'home',
    'cases'      => 'cases',
    'about'      => 'about',
    'contact'    => 'contact',
    'chat'       => 'case-chat',
    'login'      => 'login',
    'chat-login' => 'chat-login',
    'register'   => 'register',
    'account'    => 'account',
    'logout'     => 'logout',
];

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$canRedirect = in_array($requestMethod, ['GET', 'HEAD'], true);

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$trimmed = trim($uriPath, '/');

$page = 'home';
$slug = null;

if ($trimmed === 'index.php') {
    $legacyPage = isset($_GET['page']) ? trim((string) $_GET['page']) : 'home';
    $legacySlug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';

    if ($canRedirect) {
        $canonical = ptmd_public_route_path($legacyPage, $legacySlug);
        if ($canonical !== null) {
            header('Location: ' . $canonical, true, 301);
            exit;
        }
    }

    $page = $legacyPage;
    $slug = $legacySlug !== '' ? $legacySlug : null;
} else {
    if ($trimmed === 'series') {
        if ($canRedirect) {
            header('Location: ' . route_cases(), true, 301);
            exit;
        }
        $page = 'cases';
    } elseif ($trimmed === 'case-chat') {
        if ($canRedirect) {
            header('Location: ' . route_chat(), true, 301);
            exit;
        }
        $page = 'case-chat';
    } elseif (isset($cleanRoutes[$trimmed])) {
        $page = $cleanRoutes[$trimmed];
    } elseif (str_starts_with($trimmed, 'case/')) {
        $page = 'case';
        $slug = substr($trimmed, strlen('case/'));
    } elseif ($trimmed !== '') {
        http_response_code(404);
        $page = 'home';
    }
}

if (isset($_GET['slug']) && $_GET['slug'] !== '' && $page === 'case') {
    $slug = trim((string) $_GET['slug']);
}

if ($page === 'logout') {
    if ($requestMethod === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
        viewer_logout();
    }
    header('Location: ' . route_home());
    exit;
}

if (!in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = 'home';
}

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

$routeKey = match ($page) {
    'case-chat' => 'chat',
    default => $page,
};

$canonicalPath = ptmd_public_route_path($page, $current_case['slug'] ?? ($slug ?? '')) ?? route_home();
$GLOBALS['ptmd_route_context'] = ptmd_route_context(
    $routeKey,
    $canonicalPath,
    $page === 'case' ? ($current_case['slug'] ?? $slug) : null
);

$pageTitle = page_title($page, $current_case);

include __DIR__ . '/inc/head.php';
include __DIR__ . '/inc/header.php';
include __DIR__ . '/pages/' . $page . '.php';
include __DIR__ . '/inc/footer.php';
