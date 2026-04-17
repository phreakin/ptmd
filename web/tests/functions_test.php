<?php

declare(strict_types=1);

$ptmdTestFailures = $ptmdTestFailures ?? [];
$ptmdAssertions = $ptmdAssertions ?? 0;

if (!function_exists('ptmd_assert_true')) {
    function ptmd_assert_true(bool $condition, string $message): void
    {
        global $ptmdTestFailures, $ptmdAssertions;
        $ptmdAssertions++;
        if (!$condition) {
            $ptmdTestFailures[] = $message;
        }
    }
}

if (!function_exists('ptmd_assert_same')) {
    function ptmd_assert_same(mixed $actual, mixed $expected, string $message): void
    {
        ptmd_assert_true($actual === $expected, $message . ' (expected: ' . var_export($expected, true) . ', actual: ' . var_export($actual, true) . ')');
    }
}

if (!function_exists('get_db')) {
    function get_db(): ?PDO
    {
        return null;
    }
}

require_once __DIR__ . '/../inc/functions.php';

// ---------------------------------------------------------------------------
// e() — HTML output escaping
// ---------------------------------------------------------------------------

ptmd_assert_same(e('<b>bold</b>'), '&lt;b&gt;bold&lt;/b&gt;', 'e() escapes angle brackets');
ptmd_assert_same(e('"quoted"'), '&quot;quoted&quot;', 'e() escapes double quotes');
ptmd_assert_same(e("it's"), "it&#039;s", 'e() escapes single quotes');
ptmd_assert_same(e('safe text'), 'safe text', 'e() passes safe text through unchanged');
ptmd_assert_same(e(''), '', 'e() returns empty string for empty input');
ptmd_assert_same(e(42), '42', 'e() coerces integer to string');
ptmd_assert_same(e(null), '', 'e() coerces null to empty string');
ptmd_assert_same(e(3.14), '3.14', 'e() coerces float to string');
ptmd_assert_same(e('<script>alert("xss")</script>'), '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', 'e() neutralises a basic XSS payload');

// ---------------------------------------------------------------------------
// asset()
// ---------------------------------------------------------------------------

ptmd_assert_same(asset('css/styles.css'), '/assets/css/styles.css', 'asset() prepends /assets/ to path');
ptmd_assert_same(asset('/css/styles.css'), '/assets/css/styles.css', 'asset() strips leading slash before prepending');
ptmd_assert_same(asset(''), '/assets/', 'asset() handles empty path');
ptmd_assert_same(asset('js/app.js'), '/assets/js/app.js', 'asset() works with nested path');

// ---------------------------------------------------------------------------
// upload_url()
// ---------------------------------------------------------------------------

ptmd_assert_same(upload_url('episodes/thumb.jpg'), '/uploads/episodes/thumb.jpg', 'upload_url() prepends /uploads/ to path');
ptmd_assert_same(upload_url('/episodes/thumb.jpg'), '/uploads/episodes/thumb.jpg', 'upload_url() strips leading slash before prepending');
ptmd_assert_same(upload_url(''), '/uploads/', 'upload_url() handles empty path');
ptmd_assert_same(upload_url('clips/processed/out.mp4'), '/uploads/clips/processed/out.mp4', 'upload_url() works with deeply nested path');

// ---------------------------------------------------------------------------
// slugify()
// ---------------------------------------------------------------------------

ptmd_assert_same(slugify('Hello World'), 'hello-world', 'slugify() lowercases and replaces spaces with hyphens');
ptmd_assert_same(slugify('  trim me  '), 'trim-me', 'slugify() trims surrounding whitespace');
ptmd_assert_same(slugify('hello--double'), 'hello-double', 'slugify() collapses consecutive hyphens');
ptmd_assert_same(slugify('-leading-trailing-'), 'leading-trailing', 'slugify() trims leading and trailing hyphens');
ptmd_assert_same(slugify('Special!@#$Chars'), 'special-chars', 'slugify() replaces special characters with hyphens');
ptmd_assert_same(slugify('already-a-slug'), 'already-a-slug', 'slugify() leaves a valid slug unchanged');
ptmd_assert_same(slugify(''), '', 'slugify() returns empty string for empty input');
ptmd_assert_same(slugify('UPPERCASE'), 'uppercase', 'slugify() lowercases all characters');
ptmd_assert_same(slugify('123-numbers-456'), '123-numbers-456', 'slugify() preserves numbers');
ptmd_assert_same(slugify('My Episode: Part 1'), 'my-episode--part-1', 'slugify() converts colon and spaces to hyphens');

// ---------------------------------------------------------------------------
// is_post()
// ---------------------------------------------------------------------------

$savedMethod = $_SERVER['REQUEST_METHOD'] ?? null;

$_SERVER['REQUEST_METHOD'] = 'POST';
ptmd_assert_true(is_post(), 'is_post() returns true for POST');

$_SERVER['REQUEST_METHOD'] = 'GET';
ptmd_assert_true(!is_post(), 'is_post() returns false for GET');

$_SERVER['REQUEST_METHOD'] = 'post';
ptmd_assert_true(is_post(), 'is_post() is case-insensitive');

$_SERVER['REQUEST_METHOD'] = 'PUT';
ptmd_assert_true(!is_post(), 'is_post() returns false for PUT');

unset($_SERVER['REQUEST_METHOD']);
ptmd_assert_true(!is_post(), 'is_post() returns false when REQUEST_METHOD is absent');

if ($savedMethod !== null) {
    $_SERVER['REQUEST_METHOD'] = $savedMethod;
}

// ---------------------------------------------------------------------------
// is_ajax()
// ---------------------------------------------------------------------------

$savedXrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? null;

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
ptmd_assert_true(is_ajax(), 'is_ajax() returns true for XMLHttpRequest header');

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
ptmd_assert_true(is_ajax(), 'is_ajax() is case-insensitive');

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
ptmd_assert_true(!is_ajax(), 'is_ajax() returns false for non-XHR value');

unset($_SERVER['HTTP_X_REQUESTED_WITH']);
ptmd_assert_true(!is_ajax(), 'is_ajax() returns false when header is absent');

if ($savedXrw !== null) {
    $_SERVER['HTTP_X_REQUESTED_WITH'] = $savedXrw;
}

// ---------------------------------------------------------------------------
// csrf_token() / verify_csrf()
// ---------------------------------------------------------------------------

if (!isset($_SESSION)) {
    $_SESSION = [];
}

unset($_SESSION['csrf_token']);
$csrfToken = csrf_token();
ptmd_assert_true(is_string($csrfToken) && strlen($csrfToken) === 64, 'csrf_token() returns a 64-char hex string');
ptmd_assert_same(csrf_token(), $csrfToken, 'csrf_token() returns the same token on repeated calls within a request');

ptmd_assert_true(verify_csrf($csrfToken), 'verify_csrf() returns true for the correct token');
ptmd_assert_true(!verify_csrf('wrong-token'), 'verify_csrf() returns false for a wrong token');
ptmd_assert_true(!verify_csrf(null), 'verify_csrf() returns false for null');
ptmd_assert_true(!verify_csrf(''), 'verify_csrf() returns false for empty string');

unset($_SESSION['csrf_token']);
ptmd_assert_true(!verify_csrf($csrfToken), 'verify_csrf() returns false when session has no csrf_token');

// Restore so later tests can use session normally
$_SESSION['csrf_token'] = $csrfToken;

// ---------------------------------------------------------------------------
// pull_flash()
// ---------------------------------------------------------------------------

unset($_SESSION['flash']);
ptmd_assert_same(pull_flash(), null, 'pull_flash() returns null when no flash message is set');

$_SESSION['flash'] = ['message' => 'Saved!', 'type' => 'success'];
$flash = pull_flash();
ptmd_assert_same($flash['message'] ?? null, 'Saved!', 'pull_flash() returns the stored flash message');
ptmd_assert_same($flash['type'] ?? null, 'success', 'pull_flash() returns the stored flash type');
ptmd_assert_same(pull_flash(), null, 'pull_flash() clears the flash message after reading it');

$_SESSION['flash'] = ['message' => 'Error!', 'type' => 'error'];
$flash2 = pull_flash();
ptmd_assert_same($flash2['type'] ?? null, 'error', 'pull_flash() works with error type');

// ---------------------------------------------------------------------------
// page_title() — site_setting() returns fallback 'Paper Trail MD' when DB is null
// ---------------------------------------------------------------------------

ptmd_assert_same(page_title('home'), 'Paper Trail MD', 'page_title() returns site_name for home');
ptmd_assert_same(page_title('episodes'), 'Episodes | Paper Trail MD', 'page_title() returns prefixed title for episodes');
ptmd_assert_same(page_title('about'), 'About | Paper Trail MD', 'page_title() returns prefixed title for about');
ptmd_assert_same(page_title('contact'), 'Contact | Paper Trail MD', 'page_title() returns prefixed title for contact');
ptmd_assert_same(page_title('case-chat'), 'Case Chat | Paper Trail MD', 'page_title() returns prefixed title for case-chat');
ptmd_assert_same(page_title('unknown-page'), 'Paper Trail MD', 'page_title() falls back to site_name for unknown page');

$episode = ['title' => 'My Doc <Episode>'];
ptmd_assert_same(
    page_title('episode', $episode),
    'My Doc &lt;Episode&gt; | Paper Trail MD',
    'page_title() HTML-escapes the episode title'
);
ptmd_assert_same(page_title('episode', null), 'Paper Trail MD', 'page_title() falls back when episode page has no episode array');
ptmd_assert_same(page_title('episode'), 'Paper Trail MD', 'page_title() falls back for episode page without second argument');

// ---------------------------------------------------------------------------
// ptmd_ai_system_prompt()
// ---------------------------------------------------------------------------

$aiPrompt = ptmd_ai_system_prompt();
ptmd_assert_true(is_string($aiPrompt) && strlen($aiPrompt) > 0, 'ptmd_ai_system_prompt() returns a non-empty string');
ptmd_assert_true(str_contains($aiPrompt, 'Paper Trail MD'), 'ptmd_ai_system_prompt() includes the site name');
ptmd_assert_true(str_contains($aiPrompt, 'documentary'), 'ptmd_ai_system_prompt() includes brand context');

// ---------------------------------------------------------------------------
// ptmd_copilot_context() — no-DB path produces a string with date/time
// ---------------------------------------------------------------------------

$copilotCtx = ptmd_copilot_context();
ptmd_assert_true(is_string($copilotCtx), 'ptmd_copilot_context() returns a string');
ptmd_assert_true(str_contains($copilotCtx, 'Current date/time:'), 'ptmd_copilot_context() includes current date/time line when DB is unavailable');
