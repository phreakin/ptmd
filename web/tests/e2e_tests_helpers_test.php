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

$group = [];
ptmd_e2e_record($group, 'Sample test', true, 'Works', ['status' => 200]);
ptmd_assert_same(count($group), 1, 'ptmd_e2e_record appends one test result');
ptmd_assert_same($group[0]['name'] ?? null, 'Sample test', 'ptmd_e2e_record stores the test name');
ptmd_assert_same($group[0]['ok'] ?? null, true, 'ptmd_e2e_record stores pass/fail state');
ptmd_assert_same($group[0]['message'] ?? null, 'Works', 'ptmd_e2e_record stores message');
ptmd_assert_same($group[0]['meta']['status'] ?? null, 200, 'ptmd_e2e_record stores metadata');

$_SERVER = $originalServer;
