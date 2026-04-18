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

require_once __DIR__ . '/../inc/e2e_tests.php';

$originalServer = $_SERVER;

$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'example.com';
ptmd_assert_same(ptmd_e2e_base_url(), 'https://example.com', 'ptmd_e2e_base_url uses https host when HTTPS is enabled');

$_SERVER['HTTPS'] = '';
$_SERVER['HTTP_HOST'] = 'ptmd.local:8080';
ptmd_assert_same(ptmd_e2e_base_url(), 'http://ptmd.local:8080', 'ptmd_e2e_base_url allows host with port');

$_SERVER['HTTP_HOST'] = 'bad host<script>';
$_SERVER['SERVER_NAME'] = 'bad host<script>';
ptmd_assert_same(ptmd_e2e_base_url(), 'http://127.0.0.1', 'ptmd_e2e_base_url sanitizes invalid host values');

unset($_SERVER['HTTP_HOST']);
$_SERVER['SERVER_NAME'] = 'fallback.local';
ptmd_assert_same(ptmd_e2e_base_url(), 'http://fallback.local', 'ptmd_e2e_base_url falls back to SERVER_NAME');

$absoluteLocation = 'https://example.com/login?return=%2Faccount&foo=bar';
ptmd_assert_same(
    ptmd_e2e_location_path($absoluteLocation),
    '/login',
    'ptmd_e2e_location_path parses absolute URL paths'
);
$absoluteQuery = ptmd_e2e_location_query($absoluteLocation);
ptmd_assert_same($absoluteQuery['return'] ?? null, '/account', 'ptmd_e2e_location_query parses return value');
ptmd_assert_same($absoluteQuery['foo'] ?? null, 'bar', 'ptmd_e2e_location_query parses additional query params');

$relativeLocation = '/admin/login?return=%2Fadmin';
ptmd_assert_same(
    ptmd_e2e_location_path($relativeLocation),
    '/admin/login',
    'ptmd_e2e_location_path parses relative URL paths'
);
$relativeQuery = ptmd_e2e_location_query($relativeLocation);
ptmd_assert_same($relativeQuery['return'] ?? null, '/admin', 'ptmd_e2e_location_query parses relative query params');

ptmd_assert_same(ptmd_e2e_location_path(null), '', 'ptmd_e2e_location_path returns empty string for null input');
ptmd_assert_same(ptmd_e2e_location_path(''), '', 'ptmd_e2e_location_path returns empty string for empty input');
ptmd_assert_same(ptmd_e2e_location_query(null), [], 'ptmd_e2e_location_query returns empty array for null input');
ptmd_assert_same(ptmd_e2e_location_query('/cases'), [], 'ptmd_e2e_location_query returns empty array when query is absent');

$group = [];
ptmd_e2e_record($group, 'Sample test', true, 'Works', ['status' => 200]);
ptmd_assert_same(count($group), 1, 'ptmd_e2e_record appends one test result');
ptmd_assert_same($group[0]['name'] ?? null, 'Sample test', 'ptmd_e2e_record stores the test name');
ptmd_assert_same($group[0]['ok'] ?? null, true, 'ptmd_e2e_record stores pass/fail state');
ptmd_assert_same($group[0]['message'] ?? null, 'Works', 'ptmd_e2e_record stores message');
ptmd_assert_same($group[0]['meta']['status'] ?? null, 200, 'ptmd_e2e_record stores metadata');

$_SERVER = $originalServer;
