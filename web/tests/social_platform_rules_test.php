<?php
/**
 * PTMD — social_platform_rules_test.php
 *
 * Tests for inc/social_platform_rules.php
 * Pure-function tests; no DB, no HTTP.
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/social_platform_rules.php';

// ===========================================================================
// 1. PTMD_PLATFORMS constant has exactly 8 entries
// ===========================================================================

ptmd_assert_same(
    count(PTMD_PLATFORMS),
    8,
    'platform_rules: PTMD_PLATFORMS has exactly 8 entries'
);

// ===========================================================================
// 2. All expected platform names are present
// ===========================================================================

$expected = [
    'YouTube', 'YouTube Shorts', 'TikTok',
    'Instagram Reels', 'Facebook Reels', 'Snapchat Spotlight',
    'X', 'Pinterest Idea Pins',
];
foreach ($expected as $name) {
    ptmd_assert_true(
        isset(PTMD_PLATFORMS[$name]),
        'platform_rules: PTMD_PLATFORMS contains "' . $name . '"'
    );
}

// ===========================================================================
// 3. ptmd_platform_credential_fields — returns non-empty arrays for all 8
// ===========================================================================

foreach ($expected as $name) {
    $fields = ptmd_platform_credential_fields($name);
    ptmd_assert_true(
        count($fields) > 0,
        'platform_rules: credential fields non-empty for "' . $name . '"'
    );
    // Each field has required keys
    foreach ($fields as $field) {
        ptmd_assert_true(
            isset($field['key'], $field['label'], $field['type']),
            'platform_rules: credential field for "' . $name . '" has key/label/type'
        );
        ptmd_assert_true(
            in_array($field['type'], ['text', 'secret', 'json'], true),
            'platform_rules: field type valid for "' . $name . '"::"' . $field['key'] . '"'
        );
    }
}

// ===========================================================================
// 4. ptmd_platform_credential_fields — unknown platform returns []
// ===========================================================================

ptmd_assert_same(
    ptmd_platform_credential_fields('NonExistentPlatform'),
    [],
    'platform_rules: unknown platform returns empty credential fields'
);

// ===========================================================================
// 5. ptmd_platform_required_scopes — returns non-empty for all 8
// ===========================================================================

foreach ($expected as $name) {
    $scopes = ptmd_platform_required_scopes($name);
    ptmd_assert_true(
        count($scopes) > 0,
        'platform_rules: required scopes non-empty for "' . $name . '"'
    );
}

// ===========================================================================
// 6. ptmd_platform_required_scopes — unknown platform returns []
// ===========================================================================

ptmd_assert_same(
    ptmd_platform_required_scopes('Fax Machine'),
    [],
    'platform_rules: unknown platform returns empty scopes'
);

// ===========================================================================
// 7. ptmd_platform_policy_checklist — returns non-empty for all 8
// ===========================================================================

foreach ($expected as $name) {
    $checklist = ptmd_platform_policy_checklist($name);
    ptmd_assert_true(
        count($checklist) > 0,
        'platform_rules: policy checklist non-empty for "' . $name . '"'
    );
    foreach ($checklist as $item) {
        ptmd_assert_true(
            isset($item['label']),
            'platform_rules: policy item has "label" for "' . $name . '"'
        );
        ptmd_assert_true(
            array_key_exists('url', $item),
            'platform_rules: policy item has "url" key (may be null) for "' . $name . '"'
        );
    }
}

// ===========================================================================
// 8. ptmd_platform_constraints — returns well-formed struct for all 8
// ===========================================================================

$constraintKeys = [
    'max_duration_s', 'min_duration_s', 'aspect_ratio',
    'max_file_size_mb', 'accepted_formats', 'max_caption_chars',
];

foreach ($expected as $name) {
    $c = ptmd_platform_constraints($name);
    foreach ($constraintKeys as $k) {
        ptmd_assert_true(
            array_key_exists($k, $c),
            'platform_rules: constraint key "' . $k . '" present for "' . $name . '"'
        );
    }
    ptmd_assert_true(
        is_array($c['accepted_formats']),
        'platform_rules: accepted_formats is array for "' . $name . '"'
    );
    if ($c['max_duration_s'] !== null) {
        ptmd_assert_true(
            $c['max_duration_s'] > 0,
            'platform_rules: max_duration_s > 0 for "' . $name . '"'
        );
    }
}

// ===========================================================================
// 9. Specific constraint spot checks
// ===========================================================================

$ytShorts = ptmd_platform_constraints('YouTube Shorts');
ptmd_assert_same($ytShorts['max_duration_s'], 60, 'platform_rules: YouTube Shorts max 60s');
ptmd_assert_same($ytShorts['aspect_ratio'],   '9:16', 'platform_rules: YouTube Shorts 9:16');

$x = ptmd_platform_constraints('X');
ptmd_assert_same($x['max_caption_chars'], 280, 'platform_rules: X caption max 280 chars');

$tiktok = ptmd_platform_constraints('TikTok');
ptmd_assert_same($tiktok['aspect_ratio'], '9:16', 'platform_rules: TikTok 9:16');
ptmd_assert_true($tiktok['max_duration_s'] >= 60, 'platform_rules: TikTok max_duration >= 60s');

// ===========================================================================
// 10. YouTube credential fields contain client_id and refresh_token
// ===========================================================================

$ytFields = ptmd_platform_credential_fields('YouTube');
$ytKeys   = array_column($ytFields, 'key');
ptmd_assert_true(in_array('client_id',     $ytKeys, true), 'platform_rules: YouTube creds have client_id');
ptmd_assert_true(in_array('refresh_token', $ytKeys, true), 'platform_rules: YouTube creds have refresh_token');

// ===========================================================================
// 11. TikTok credential fields contain open_id
// ===========================================================================

$tikFields = ptmd_platform_credential_fields('TikTok');
$tikKeys   = array_column($tikFields, 'key');
ptmd_assert_true(in_array('open_id', $tikKeys, true), 'platform_rules: TikTok creds have open_id');

// ===========================================================================
// 12. X credential fields contain all four OAuth 1.0a fields
// ===========================================================================

$xFields = ptmd_platform_credential_fields('X');
$xKeys   = array_column($xFields, 'key');
foreach (['api_key', 'api_key_secret', 'access_token', 'access_token_secret'] as $xk) {
    ptmd_assert_true(in_array($xk, $xKeys, true), 'platform_rules: X creds have ' . $xk);
}

// ===========================================================================
// 13. Pinterest credential fields contain board_id
// ===========================================================================

$pinFields = ptmd_platform_credential_fields('Pinterest Idea Pins');
$pinKeys   = array_column($pinFields, 'key');
ptmd_assert_true(in_array('board_id', $pinKeys, true), 'platform_rules: Pinterest creds have board_id');
