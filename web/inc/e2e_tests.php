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
    $publicRoutes = [
        '/index.php' => 200,
        '/index.php?page=episodes' => 200,
        '/index.php?page=about' => 200,
        '/index.php?page=contact' => 200,
        '/index.php?page=case-chat' => 200,
        '/index.php?page=missing-page' => 404,
    ];

    foreach ($publicRoutes as $route => $expectedStatus) {
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

    $episodeSlug = '';
    $pdo = get_db();
    if ($pdo) {
        $slugStmt = $pdo->prepare('SELECT slug FROM episodes WHERE status = :status ORDER BY published_at DESC LIMIT 1');
        $slugStmt->execute(['status' => 'published']);
        $episodeSlug = (string) $slugStmt->fetchColumn();
    }
    if ($episodeSlug !== '') {
        $epPath = '/index.php?page=episode&slug=' . rawurlencode($episodeSlug);
        $res = ptmd_e2e_request($baseUrl, $epPath);
        $ok = $res['ok'] && (($res['status'] ?? 0) === 200);
        ptmd_e2e_record($public, "GET {$epPath}", $ok, $ok ? 'Episode page loaded' : 'Episode page failed', ['actual_status' => $res['status'] ?? null]);
    } else {
        ptmd_e2e_record($public, 'Episode detail route', true, 'Skipped: no published episode found');
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

    $login = ptmd_e2e_request($baseUrl, '/admin/login.php');
    $loginOk = $login['ok'] && (($login['status'] ?? 0) === 200);
    ptmd_e2e_record($auth, 'GET /admin/login.php (anonymous)', $loginOk, $loginOk ? 'Login page loaded' : 'Login page failed', ['actual_status' => $login['status'] ?? null]);

    $anonDashboard = ptmd_e2e_request($baseUrl, '/admin/dashboard.php');
    $anonLocation = (string) ($anonDashboard['location'] ?? '');
    $redirectsToLogin = str_contains($anonLocation, '/admin/login.php');
    $anonDashOk = $anonDashboard['ok'] && (($anonDashboard['status'] ?? 0) === 302) && $redirectsToLogin;
    ptmd_e2e_record($auth, 'GET /admin/dashboard.php (anonymous)', $anonDashOk, $anonDashOk ? 'Redirected to login' : 'Auth redirect failed', ['actual_status' => $anonDashboard['status'] ?? null, 'location' => $anonDashboard['location'] ?? null]);

    $adminFiles = glob(__DIR__ . '/../admin/*.php') ?: [];
    // Exclude partials and auth endpoints that are not standard in-session page views.
    $adminFiles = array_filter($adminFiles, static function (string $file): bool {
        $name = basename($file);
        return $name[0] !== '_' && !in_array($name, ['login.php', 'logout.php', 'site-tests.php'], true);
    });
    sort($adminFiles);

    if ($sessionIsValid) {
        foreach ($adminFiles as $file) {
            $name = basename($file);
            $path = '/admin/' . $name;
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

    $aiAnon = ptmd_e2e_request($baseUrl, '/api/ai_generate.php');
    $aiAnonOk = $aiAnon['ok'] && (($aiAnon['status'] ?? 0) === 401);
    ptmd_e2e_record($api, 'GET /api/ai_generate.php (anonymous)', $aiAnonOk, $aiAnonOk ? 'Unauthorized as expected' : 'Anonymous auth check failed', ['actual_status' => $aiAnon['status'] ?? null]);

    $aiAuth = ptmd_e2e_request($baseUrl, '/api/ai_generate.php', 'GET', [], $authCookie);
    $aiAuthOk = $aiAuth['ok'] && (($aiAuth['status'] ?? 0) === 405);
    ptmd_e2e_record($api, 'GET /api/ai_generate.php (admin session)', $aiAuthOk, $aiAuthOk ? 'Method guard works' : 'Admin method guard failed', ['actual_status' => $aiAuth['status'] ?? null]);

    $overlayAnon = ptmd_e2e_request($baseUrl, '/api/apply_overlays.php');
    $overlayAnonOk = $overlayAnon['ok'] && (($overlayAnon['status'] ?? 0) === 401);
    ptmd_e2e_record($api, 'GET /api/apply_overlays.php (anonymous)', $overlayAnonOk, $overlayAnonOk ? 'Unauthorized as expected' : 'Anonymous overlay auth check failed', ['actual_status' => $overlayAnon['status'] ?? null]);

    $overlayAuth = ptmd_e2e_request($baseUrl, '/api/apply_overlays.php?job_id=0', 'GET', [], $authCookie);
    $overlayAuthStatusOk = ($overlayAuth['status'] ?? 0) === 200;
    $overlayAuthJsonOk = is_array($overlayAuth['json']);
    $overlayAuthPayloadOk = $overlayAuthJsonOk && (($overlayAuth['json']['ok'] ?? true) === false);
    $overlayAuthOk = $overlayAuth['ok'] && $overlayAuthStatusOk && $overlayAuthPayloadOk;
    ptmd_e2e_record($api, 'GET /api/apply_overlays.php?job_id=0 (admin session)', $overlayAuthOk, $overlayAuthOk ? 'Overlay API reachable with admin session' : 'Overlay API admin check failed', ['actual_status' => $overlayAuth['status'] ?? null]);

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
