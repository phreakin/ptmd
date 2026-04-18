<?php
/**
 * PTMD End-to-End Site Test Runner
 */

function ptmd_e2e_base_url(): string
{
    $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $scheme = $isHttps ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '127.0.0.1');
    if (!preg_match('/^[a-z0-9.\-:]+$/i', $host)) {
        $host = '127.0.0.1';
    }

    return $scheme . '://' . $host;
}

function ptmd_e2e_request(string $baseUrl, string $path, string $method = 'GET', array $data = [], string $cookie = ''): array
{
    $minConnectTimeout = 1;
    $maxConnectTimeout = 30;
    $defaultConnectTimeout = 5;
    $minRequestTimeout = 2;
    $maxRequestTimeout = 60;
    $defaultRequestTimeout = 10;

    $ch = curl_init($baseUrl . $path);
    if (!$ch) {
        return ['ok' => false, 'error' => 'Unable to initialize cURL'];
    }

    $headers = ['Accept: text/html,application/json'];
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }

    $connectTimeout = max($minConnectTimeout, min($maxConnectTimeout, (int) (getenv('PTMD_E2E_CONNECT_TIMEOUT') ?: $defaultConnectTimeout)));
    $requestTimeout = max($minRequestTimeout, min($maxRequestTimeout, (int) (getenv('PTMD_E2E_REQUEST_TIMEOUT') ?: $defaultRequestTimeout)));

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $requestTimeout,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerLen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if (!is_string($raw)) {
        return ['ok' => false, 'error' => $err ?: 'HTTP request failed'];
    }

    $headerStr = substr($raw, 0, $headerLen);
    $body = substr($raw, $headerLen);
    $location = null;

    foreach (preg_split('/\r\n|\n|\r/', $headerStr) as $line) {
        if (stripos($line, 'Location:') === 0) {
            $location = trim(substr($line, 9));
            break;
        }
    }

    $json = null;
    $decoded = json_decode($body, true);
    if (is_array($decoded)) {
        $json = $decoded;
    }

    return [
        'ok' => true,
        'status' => $status,
        'location' => $location,
        'body' => $body,
        'json' => $json,
    ];
}

function ptmd_e2e_record(array &$group, string $name, bool $ok, string $message, array $meta = []): void
{
    $group[] = [
        'name' => $name,
        'ok' => $ok,
        'message' => $message,
        'meta' => $meta,
    ];
}

function ptmd_e2e_location_path(?string $location): string
{
    if (!is_string($location) || $location === '') {
        return '';
    }

    $path = parse_url($location, PHP_URL_PATH);
    return is_string($path) ? $path : '';
}

function ptmd_e2e_location_query(?string $location): array
{
    if (!is_string($location) || $location === '') {
        return [];
    }

    $query = parse_url($location, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return [];
    }

    parse_str($query, $params);
    return is_array($params) ? $params : [];
}

function run_ptmd_e2e_tests(): array
{
    $started = microtime(true);
    $groups = [];

    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'error' => 'cURL extension is required to run end-to-end tests.',
            'started_at' => date('c'),
            'duration_ms' => 0,
            'summary' => ['passed' => 0, 'failed' => 1, 'total' => 1],
            'groups' => [],
        ];
    }

    $baseUrl = ptmd_e2e_base_url();
    $authCookie = session_name() . '=' . rawurlencode(session_id());
    $sessionIsValid = is_logged_in() && current_admin() !== null;

    // Public routes
    $public = [];
    $canonicalPublicRoutes = [
        route_home() => 200,
        route_cases() => 200,
        route_chat() => 200,
        route_login() => 200,
        route_register() => 200,
    ];

    foreach ($canonicalPublicRoutes as $route => $expectedStatus) {
        $res = ptmd_e2e_request($baseUrl, $route);
        $ok = $res['ok'] && (($res['status'] ?? 0) === $expectedStatus);
        ptmd_e2e_record(
            $public,
            "GET {$route}",
            $ok,
            $ok ? "HTTP {$expectedStatus}" : 'Unexpected response',
            ['expected_status' => $expectedStatus, 'actual_status' => $res['status'] ?? null, 'error' => $res['error'] ?? null]
        );
    }

    $accountRes = ptmd_e2e_request($baseUrl, route_account());
    $accountLocation = (string) ($accountRes['location'] ?? '');
    $accountLocationPath = ptmd_e2e_location_path($accountLocation);
    $accountLocationQuery = ptmd_e2e_location_query($accountLocation);
    $accountOk = $accountRes['ok']
        && (($accountRes['status'] ?? 0) === 302)
        && $accountLocationPath === route_login()
        && (($accountLocationQuery['return'] ?? '') === route_account());
    ptmd_e2e_record(
        $public,
        'GET ' . route_account() . ' (anonymous)',
        $accountOk,
        $accountOk ? 'Redirected to login' : 'Account auth redirect failed',
        ['actual_status' => $accountRes['status'] ?? null, 'location' => $accountLocation]
    );

    $legacyPublicRedirects = [
        '/index.php' => route_home(),
        '/index.php?page=home' => route_home(),
        '/index.php?page=cases' => route_cases(),
        '/index.php?page=case-chat' => route_chat(),
        '/index.php?page=login' => route_login(),
        '/index.php?page=register' => route_register(),
        '/index.php?page=account' => route_account(),
        '/series' => route_cases(),
        '/case-chat' => route_chat(),
    ];

    foreach ($legacyPublicRedirects as $route => $expectedLocation) {
        $res = ptmd_e2e_request($baseUrl, $route);
        $actualLocationPath = ptmd_e2e_location_path((string) ($res['location'] ?? ''));
        $ok = $res['ok']
            && (($res['status'] ?? 0) === 301)
            && $actualLocationPath === $expectedLocation;
        ptmd_e2e_record(
            $public,
            "GET {$route} (legacy redirect)",
            $ok,
            $ok ? 'Permanent redirect to canonical route' : 'Legacy redirect failed',
            [
                'expected_status' => 301,
                'expected_location' => $expectedLocation,
                'actual_status' => $res['status'] ?? null,
                'location' => $res['location'] ?? null,
                'error' => $res['error'] ?? null,
            ]
        );
    }

    $caseslug = '';
    $pdo = get_db();
    if ($pdo) {
        $slugStmt = $pdo->prepare('SELECT slug FROM cases WHERE status = :status ORDER BY published_at DESC LIMIT 1');
        $slugStmt->execute(['status' => 'published']);
        $caseslug = (string) $slugStmt->fetchColumn();
    }
    if ($caseslug !== '') {
        $casePath = route_case($caseslug);
        $caseRes = ptmd_e2e_request($baseUrl, $casePath);
        $caseOk = $caseRes['ok'] && (($caseRes['status'] ?? 0) === 200);
        ptmd_e2e_record($public, "GET {$casePath}", $caseOk, $caseOk ? 'case page loaded' : 'case page failed', ['actual_status' => $caseRes['status'] ?? null]);

        $legacyCasePath = '/index.php?page=case&slug=' . rawurlencode($caseslug);
        $legacyCaseRes = ptmd_e2e_request($baseUrl, $legacyCasePath);
        $legacyCaseLocationPath = ptmd_e2e_location_path((string) ($legacyCaseRes['location'] ?? ''));
        $legacyCaseOk = $legacyCaseRes['ok']
            && (($legacyCaseRes['status'] ?? 0) === 301)
            && $legacyCaseLocationPath === $casePath;
        ptmd_e2e_record(
            $public,
            "GET {$legacyCasePath} (legacy redirect)",
            $legacyCaseOk,
            $legacyCaseOk ? 'Case redirect canonicalized' : 'Legacy case redirect failed',
            ['actual_status' => $legacyCaseRes['status'] ?? null, 'location' => $legacyCaseRes['location'] ?? null]
        );
    } else {
        ptmd_e2e_record($public, 'case detail route', true, 'Skipped: no published case found');
    }
    $groups[] = ['name' => 'Public Site', 'tests' => $public];

    // Auth and admin access
    $auth = [];
    ptmd_e2e_record(
        $auth,
        'Admin session precheck',
        $sessionIsValid,
        $sessionIsValid ? 'Admin session is active' : 'Admin session is missing or invalid'
    );

    $login = ptmd_e2e_request($baseUrl, route_admin('login'));
    $loginOk = $login['ok'] && (($login['status'] ?? 0) === 200);
    ptmd_e2e_record($auth, 'GET ' . route_admin('login') . ' (anonymous)', $loginOk, $loginOk ? 'Login page loaded' : 'Login page failed', ['actual_status' => $login['status'] ?? null]);

    $legacyAdminLogin = ptmd_e2e_request($baseUrl, '/admin/login.php');
    $legacyAdminLoginPath = ptmd_e2e_location_path((string) ($legacyAdminLogin['location'] ?? ''));
    $legacyAdminLoginOk = $legacyAdminLogin['ok']
        && (($legacyAdminLogin['status'] ?? 0) === 301)
        && $legacyAdminLoginPath === route_admin('login');
    ptmd_e2e_record(
        $auth,
        'GET /admin/login.php (legacy redirect)',
        $legacyAdminLoginOk,
        $legacyAdminLoginOk ? 'Permanent redirect to clean admin login' : 'Legacy admin login redirect failed',
        ['actual_status' => $legacyAdminLogin['status'] ?? null, 'location' => $legacyAdminLogin['location'] ?? null]
    );

    $anonymousAdminRoutes = [
        route_admin('dashboard'),
        route_admin('control-room'),
        route_admin('cases'),
        route_admin('ai-assistant'),
        route_admin('posts'),
        route_admin('monitor'),
    ];

    foreach ($anonymousAdminRoutes as $route) {
        $res = ptmd_e2e_request($baseUrl, $route);
        $location = (string) ($res['location'] ?? '');
        $locationPath = ptmd_e2e_location_path($location);
        $locationQuery = ptmd_e2e_location_query($location);
        $ok = $res['ok']
            && (($res['status'] ?? 0) === 302)
            && $locationPath === route_admin('login')
            && (($locationQuery['return'] ?? '') === $route);
        ptmd_e2e_record(
            $auth,
            "GET {$route} (anonymous)",
            $ok,
            $ok ? 'Redirected to clean admin login' : 'Admin auth redirect failed',
            ['actual_status' => $res['status'] ?? null, 'location' => $location]
        );
    }

    $adminFiles = glob(__DIR__ . '/../admin/*.php') ?: [];
    // Exclude partials and auth endpoints that are not standard in-session page views.
    $adminFiles = array_filter($adminFiles, static function (string $file): bool {
        $name = basename($file);
        return $name[0] !== '_' && !in_array($name, ['login.php', 'logout.php', 'site-tests.php'], true);
    });
    sort($adminFiles);

    if ($sessionIsValid) {
        $seenPaths = [];
        foreach ($adminFiles as $file) {
            $name = basename($file);
            $path = ptmd_admin_route_from_script($name);
            if (!is_string($path) || $path === '' || isset($seenPaths[$path])) {
                continue;
            }
            $seenPaths[$path] = true;
            $res = ptmd_e2e_request($baseUrl, $path, 'GET', [], $authCookie);
            $ok = $res['ok'] && (($res['status'] ?? 0) === 200);
            ptmd_e2e_record($auth, "GET {$path} (admin session)", $ok, $ok ? 'Admin page loaded' : 'Admin page failed', ['actual_status' => $res['status'] ?? null, 'error' => $res['error'] ?? null]);
        }
    } else {
        ptmd_e2e_record($auth, 'Authenticated admin page checks', true, 'Skipped: active admin session is required');
    }
    $groups[] = ['name' => 'Admin Access', 'tests' => $auth];

    // API coverage
    $api = [];

    $chatGet = ptmd_e2e_request($baseUrl, '/api/chat_messages.php');
    $chatGetOk = $chatGet['ok'] && (($chatGet['status'] ?? 0) === 200) && is_array($chatGet['json']) && (($chatGet['json']['ok'] ?? false) === true);
    ptmd_e2e_record($api, 'GET /api/chat_messages.php', $chatGetOk, $chatGetOk ? 'Chat API returned approved feed' : 'Chat API GET failed', ['actual_status' => $chatGet['status'] ?? null]);

    $chatPost = ptmd_e2e_request(
        $baseUrl,
        '/api/chat_messages.php',
        'POST',
        ['csrf_token' => 'invalid', 'username' => 'E2E', 'message' => 'Validation test'],
        $authCookie
    );
    $chatPostOk = $chatPost['ok'] && (($chatPost['status'] ?? 0) === 403);
    ptmd_e2e_record($api, 'POST /api/chat_messages.php (invalid csrf)', $chatPostOk, $chatPostOk ? 'CSRF protection enforced' : 'CSRF protection check failed', ['actual_status' => $chatPost['status'] ?? null]);

    $chatValidCsrf = csrf_token();
    try {
        $uniqueSuffix = bin2hex(random_bytes(3));
    } catch (Throwable $e) {
        $uniqueSuffix = (string) mt_rand(100000, 999999);
    }
    $chatValidMessage = 'E2E validation message ' . date('Y-m-d H:i:s') . ' #' . $uniqueSuffix;
    $chatPostValid = ptmd_e2e_request(
        $baseUrl,
        '/api/chat_messages.php',
        'POST',
        ['csrf_token' => $chatValidCsrf, 'username' => 'E2EValidator', 'message' => $chatValidMessage],
        $authCookie
    );
    $chatValidOk = $chatPostValid['ok']
        && (($chatPostValid['status'] ?? 0) === 200)
        && is_array($chatPostValid['json'])
        && (($chatPostValid['json']['ok'] ?? false) === true)
        && !empty($chatPostValid['json']['id']);
    $chatValidMessageText = $chatValidOk ? 'Chat API accepts valid submissions' : 'Valid chat submission failed';
    $chatCleanupOk = true;
    $chatCleanupError = null;
    if ($chatValidOk && $pdo) {
        $cleanupStmt = $pdo->prepare('DELETE FROM chat_messages WHERE id = :id');
        $chatCleanupOk = $cleanupStmt->execute(['id' => (int) $chatPostValid['json']['id']]);
        if (!$chatCleanupOk) {
            $chatCleanupError = $cleanupStmt->errorInfo();
        }
    }
    if ($chatValidOk && !$chatCleanupOk) {
        $chatValidOk = false;
        $chatValidMessageText = 'Chat submission succeeded but cleanup failed';
    }
    ptmd_e2e_record($api, 'POST /api/chat_messages.php (valid csrf)', $chatValidOk, $chatValidMessageText, ['actual_status' => $chatPostValid['status'] ?? null, 'cleanup_error' => $chatCleanupError]);

    $chatTooLong = ptmd_e2e_request(
        $baseUrl,
        '/api/chat_messages.php',
        'POST',
        ['csrf_token' => $chatValidCsrf, 'username' => 'E2EValidator', 'message' => str_repeat('a', 501)],
        $authCookie
    );
    $chatTooLongOk = $chatTooLong['ok']
        && (($chatTooLong['status'] ?? 0) === 200)
        && is_array($chatTooLong['json'])
        && (($chatTooLong['json']['ok'] ?? true) === false);
    ptmd_e2e_record($api, 'POST /api/chat_messages.php (message too long)', $chatTooLongOk, $chatTooLongOk ? 'Validation rejects overlong chat messages' : 'Overlong chat validation failed', ['actual_status' => $chatTooLong['status'] ?? null]);

    // Viewer toggle_favorite endpoint — anonymous → 405 on GET, 401 on POST
    $favAnon = ptmd_e2e_request($baseUrl, '/api/toggle_favorite.php');
    $favAnonOk = $favAnon['ok'] && (($favAnon['status'] ?? 0) === 405);
    ptmd_e2e_record($api, 'GET /api/toggle_favorite.php (anonymous, wrong method)', $favAnonOk, $favAnonOk ? 'Method guard returns 405' : 'toggle_favorite method guard failed', ['actual_status' => $favAnon['status'] ?? null]);

    $favAnonPost = ptmd_e2e_request($baseUrl, '/api/toggle_favorite.php', 'POST', ['episode_id' => 1, 'csrf_token' => 'invalid']);
    $favAnonPostOk = $favAnonPost['ok'] && (($favAnonPost['status'] ?? 0) === 401);
    ptmd_e2e_record($api, 'POST /api/toggle_favorite.php (anonymous)', $favAnonPostOk, $favAnonPostOk ? 'Unauthenticated returns 401' : 'toggle_favorite auth guard failed', ['actual_status' => $favAnonPost['status'] ?? null]);

    $aiAnon = ptmd_e2e_request($baseUrl, '/api/ai_generate.php');
    $aiAnonOk = $aiAnon['ok'] && (($aiAnon['status'] ?? 0) === 401);
    ptmd_e2e_record($api, 'GET /api/ai_generate.php (anonymous)', $aiAnonOk, $aiAnonOk ? 'Unauthorized as expected' : 'Anonymous auth check failed', ['actual_status' => $aiAnon['status'] ?? null]);

    if ($sessionIsValid) {
        $aiAuth = ptmd_e2e_request($baseUrl, '/api/ai_generate.php', 'GET', [], $authCookie);
        $aiAuthOk = $aiAuth['ok'] && (($aiAuth['status'] ?? 0) === 405);
        ptmd_e2e_record($api, 'GET /api/ai_generate.php (admin session)', $aiAuthOk, $aiAuthOk ? 'Method guard works' : 'Admin method guard failed', ['actual_status' => $aiAuth['status'] ?? null]);

        $aiPostInvalidCsrf = ptmd_e2e_request(
            $baseUrl,
            '/api/ai_generate.php',
            'POST',
            ['csrf_token' => 'invalid', 'feature' => 'title', 'title_topic' => 'E2E'],
            $authCookie
        );
        $aiPostInvalidCsrfOk = $aiPostInvalidCsrf['ok'] && (($aiPostInvalidCsrf['status'] ?? 0) === 403);
        ptmd_e2e_record($api, 'POST /api/ai_generate.php (invalid csrf)', $aiPostInvalidCsrfOk, $aiPostInvalidCsrfOk ? 'CSRF protection enforced' : 'AI generate CSRF check failed', ['actual_status' => $aiPostInvalidCsrf['status'] ?? null]);
    } else {
        ptmd_e2e_record($api, 'Authenticated ai_generate checks', true, 'Skipped: active admin session is required');
    }

    $assistantAnon = ptmd_e2e_request($baseUrl, '/api/ai_assistant.php');
    $assistantAnonOk = $assistantAnon['ok'] && (($assistantAnon['status'] ?? 0) === 401);
    ptmd_e2e_record($api, 'GET /api/ai_assistant.php (anonymous)', $assistantAnonOk, $assistantAnonOk ? 'Unauthorized as expected' : 'Anonymous assistant auth check failed', ['actual_status' => $assistantAnon['status'] ?? null]);

    if ($sessionIsValid) {
        $assistantAuth = ptmd_e2e_request($baseUrl, '/api/ai_assistant.php', 'GET', [], $authCookie);
        $assistantAuthOk = $assistantAuth['ok']
            && (($assistantAuth['status'] ?? 0) === 200)
            && is_array($assistantAuth['json'])
            && (($assistantAuth['json']['ok'] ?? false) === true)
            && array_key_exists('sessions', $assistantAuth['json']);
        ptmd_e2e_record($api, 'GET /api/ai_assistant.php (admin session)', $assistantAuthOk, $assistantAuthOk ? 'Assistant API returns sessions list' : 'Assistant API GET failed', ['actual_status' => $assistantAuth['status'] ?? null]);

        $assistantPostInvalidCsrf = ptmd_e2e_request(
            $baseUrl,
            '/api/ai_assistant.php',
            'POST',
            ['csrf_token' => 'invalid', 'message' => 'E2E invalid CSRF check'],
            $authCookie
        );
        $assistantPostInvalidCsrfOk = $assistantPostInvalidCsrf['ok'] && (($assistantPostInvalidCsrf['status'] ?? 0) === 403);
        ptmd_e2e_record($api, 'POST /api/ai_assistant.php (invalid csrf)', $assistantPostInvalidCsrfOk, $assistantPostInvalidCsrfOk ? 'CSRF protection enforced' : 'Assistant CSRF check failed', ['actual_status' => $assistantPostInvalidCsrf['status'] ?? null]);
    } else {
        ptmd_e2e_record($api, 'Authenticated ai_assistant checks', true, 'Skipped: active admin session is required');
    }

    $overlayAnon = ptmd_e2e_request($baseUrl, '/api/apply_overlays.php');
    $overlayAnonOk = $overlayAnon['ok'] && (($overlayAnon['status'] ?? 0) === 401);
    ptmd_e2e_record($api, 'GET /api/apply_overlays.php (anonymous)', $overlayAnonOk, $overlayAnonOk ? 'Unauthorized as expected' : 'Anonymous overlay auth check failed', ['actual_status' => $overlayAnon['status'] ?? null]);

    if ($sessionIsValid) {
        $overlayAuth = ptmd_e2e_request($baseUrl, '/api/apply_overlays.php?job_id=0', 'GET', [], $authCookie);
        $overlayAuthStatusOk = ($overlayAuth['status'] ?? 0) === 200;
        $overlayAuthJsonOk = is_array($overlayAuth['json']);
        $overlayAuthPayloadOk = $overlayAuthJsonOk && (($overlayAuth['json']['ok'] ?? true) === false);
        $overlayAuthOk = $overlayAuth['ok'] && $overlayAuthStatusOk && $overlayAuthPayloadOk;
        ptmd_e2e_record($api, 'GET /api/apply_overlays.php?job_id=0 (admin session)', $overlayAuthOk, $overlayAuthOk ? 'Overlay API reachable with admin session' : 'Overlay API admin check failed', ['actual_status' => $overlayAuth['status'] ?? null]);

        $overlayPostInvalidCsrf = ptmd_e2e_request(
            $baseUrl,
            '/api/apply_overlays.php',
            'POST',
            ['csrf_token' => 'invalid'],
            $authCookie
        );
        $overlayPostInvalidCsrfOk = $overlayPostInvalidCsrf['ok'] && (($overlayPostInvalidCsrf['status'] ?? 0) === 403);
        ptmd_e2e_record($api, 'POST /api/apply_overlays.php (invalid csrf)', $overlayPostInvalidCsrfOk, $overlayPostInvalidCsrfOk ? 'CSRF protection enforced' : 'Overlay CSRF check failed', ['actual_status' => $overlayPostInvalidCsrf['status'] ?? null]);
    } else {
        ptmd_e2e_record($api, 'Authenticated apply_overlays checks', true, 'Skipped: active admin session is required');
    }

    // edit_jobs API
    $editJobsAnon = ptmd_e2e_request($baseUrl, '/api/edit_jobs.php');
    $editJobsAnonOk = $editJobsAnon['ok'] && (($editJobsAnon['status'] ?? 0) === 401);
    ptmd_e2e_record($api, 'GET /api/edit_jobs.php (anonymous)', $editJobsAnonOk, $editJobsAnonOk ? 'Unauthorized as expected' : 'Anonymous edit_jobs auth check failed', ['actual_status' => $editJobsAnon['status'] ?? null]);

    if ($sessionIsValid) {
        $editJobsAuth = ptmd_e2e_request($baseUrl, '/api/edit_jobs.php', 'GET', [], $authCookie);
        $editJobsAuthOk = $editJobsAuth['ok']
            && (($editJobsAuth['status'] ?? 0) === 200)
            && is_array($editJobsAuth['json'])
            && (($editJobsAuth['json']['ok'] ?? false) === true)
            && array_key_exists('jobs', $editJobsAuth['json']);
        ptmd_e2e_record($api, 'GET /api/edit_jobs.php (admin session)', $editJobsAuthOk, $editJobsAuthOk ? 'Edit jobs API returns jobs list' : 'Edit jobs API GET failed', ['actual_status' => $editJobsAuth['status'] ?? null]);

        $editJobsInvalidCsrf = ptmd_e2e_request(
            $baseUrl,
            '/api/edit_jobs.php',
            'POST',
            ['csrf_token' => 'invalid', '_action' => 'create'],
            $authCookie
        );
        $editJobsCsrfOk = $editJobsInvalidCsrf['ok'] && (($editJobsInvalidCsrf['status'] ?? 0) === 403);
        ptmd_e2e_record($api, 'POST /api/edit_jobs.php (invalid csrf)', $editJobsCsrfOk, $editJobsCsrfOk ? 'CSRF protection enforced' : 'Edit jobs CSRF check failed', ['actual_status' => $editJobsInvalidCsrf['status'] ?? null]);

        $editJobsNoPath = ptmd_e2e_request(
            $baseUrl,
            '/api/edit_jobs.php',
            'POST',
            ['csrf_token' => csrf_token(), '_action' => 'create', 'source_path' => ''],
            $authCookie
        );
        $editJobsNoPathOk = $editJobsNoPath['ok']
            && (($editJobsNoPath['status'] ?? 0) === 200)
            && is_array($editJobsNoPath['json'])
            && (($editJobsNoPath['json']['ok'] ?? true) === false);
        ptmd_e2e_record($api, 'POST /api/edit_jobs.php (missing source_path)', $editJobsNoPathOk, $editJobsNoPathOk ? 'Validation rejects empty source_path' : 'Edit jobs source_path validation failed', ['actual_status' => $editJobsNoPath['status'] ?? null]);

        $editJobsBadPath = ptmd_e2e_request(
            $baseUrl,
            '/api/edit_jobs.php',
            'POST',
            ['csrf_token' => csrf_token(), '_action' => 'create', 'source_path' => '../../../../etc/passwd'],
            $authCookie
        );
        $editJobsBadPathOk = $editJobsBadPath['ok']
            && (($editJobsBadPath['status'] ?? 0) === 200)
            && is_array($editJobsBadPath['json'])
            && (($editJobsBadPath['json']['ok'] ?? true) === false);
        ptmd_e2e_record($api, 'POST /api/edit_jobs.php (path traversal)', $editJobsBadPathOk, $editJobsBadPathOk ? 'Path traversal rejected' : 'Edit jobs path traversal check failed', ['actual_status' => $editJobsBadPath['status'] ?? null]);

        // process_edit_jobs worker auth
        $workerAnon = ptmd_e2e_request($baseUrl, '/api/process_edit_jobs.php');
        $workerAnonOk = $workerAnon['ok'] && (($workerAnon['status'] ?? 0) === 401);
        ptmd_e2e_record($api, 'GET /api/process_edit_jobs.php (anonymous)', $workerAnonOk, $workerAnonOk ? 'Unauthorized as expected' : 'Worker anonymous auth check failed', ['actual_status' => $workerAnon['status'] ?? null]);
    } else {
        ptmd_e2e_record($api, 'Authenticated edit_jobs checks', true, 'Skipped: active admin session is required');
    }

    $groups[] = ['name' => 'API Endpoints', 'tests' => $api];

    $passed = 0;
    $failed = 0;
    foreach ($groups as $group) {
        foreach ($group['tests'] as $test) {
            if (!empty($test['ok'])) {
                $passed++;
            } else {
                $failed++;
            }
        }
    }

    return [
        'ok' => $failed === 0,
        'started_at' => date('c'),
        'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        'summary' => [
            'passed' => $passed,
            'failed' => $failed,
            'total' => $passed + $failed,
        ],
        'groups' => $groups,
    ];
}
