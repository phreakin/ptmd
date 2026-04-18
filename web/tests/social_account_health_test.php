<?php

declare(strict_types=1);

$ptmdTestFailures = $ptmdTestFailures ?? [];
$ptmdAssertions   = $ptmdAssertions   ?? 0;

require_once __DIR__ . '/../inc/social_account_health.php';

// ---------------------------------------------------------------------------
// ptmd_platform_credential_fields — all 8 platforms return non-empty specs
// ---------------------------------------------------------------------------

foreach (PTMD_PLATFORMS as $platform) {
    $fields = ptmd_platform_credential_fields($platform);
    ptmd_assert_true(!empty($fields), "ptmd_platform_credential_fields: returns fields for {$platform}");

    $hasRequired = false;
    foreach ($fields as $f) {
        ptmd_assert_true(array_key_exists('key', $f),      "ptmd_platform_credential_fields: field has 'key' for {$platform}");
        ptmd_assert_true(array_key_exists('label', $f),    "ptmd_platform_credential_fields: field has 'label' for {$platform}");
        ptmd_assert_true(array_key_exists('type', $f),     "ptmd_platform_credential_fields: field has 'type' for {$platform}");
        ptmd_assert_true(array_key_exists('required', $f), "ptmd_platform_credential_fields: field has 'required' for {$platform}");
        ptmd_assert_true(in_array($f['type'], ['text','secret','textarea'], true), "ptmd_platform_credential_fields: valid type for {$platform}/{$f['key']}");
        if ($f['required']) {
            $hasRequired = true;
        }
    }
    ptmd_assert_true($hasRequired, "ptmd_platform_credential_fields: at least one required field for {$platform}");
}

// Unknown platform returns []
ptmd_assert_same(ptmd_platform_credential_fields('LinkedIn'), [], 'ptmd_platform_credential_fields: returns [] for unknown platform');

// ---------------------------------------------------------------------------
// ptmd_platform_required_scopes — all 8 platforms return non-empty lists
// ---------------------------------------------------------------------------

foreach (PTMD_PLATFORMS as $platform) {
    $scopes = ptmd_platform_required_scopes($platform);
    ptmd_assert_true(!empty($scopes), "ptmd_platform_required_scopes: returns scopes for {$platform}");
    foreach ($scopes as $scope) {
        ptmd_assert_true(is_string($scope) && $scope !== '', "ptmd_platform_required_scopes: non-empty string scope for {$platform}");
    }
}

// Unknown platform
ptmd_assert_same(ptmd_platform_required_scopes('LinkedIn'), [], 'ptmd_platform_required_scopes: returns [] for unknown platform');

// ---------------------------------------------------------------------------
// ptmd_platform_policy_checklist — all 8 platforms have a checklist
// ---------------------------------------------------------------------------

foreach (PTMD_PLATFORMS as $platform) {
    $items = ptmd_platform_policy_checklist($platform);
    ptmd_assert_true(!empty($items), "ptmd_platform_policy_checklist: returns items for {$platform}");
    foreach ($items as $item) {
        ptmd_assert_true(array_key_exists('key', $item),         "ptmd_platform_policy_checklist: item has 'key' for {$platform}");
        ptmd_assert_true(array_key_exists('label', $item),       "ptmd_platform_policy_checklist: item has 'label' for {$platform}");
        ptmd_assert_true(array_key_exists('description', $item), "ptmd_platform_policy_checklist: item has 'description' for {$platform}");
    }
    // All platforms must have the 4 common items
    $keys = array_column($items, 'key');
    ptmd_assert_true(in_array('music_licensing_ok', $keys, true),        "ptmd_platform_policy_checklist: has music_licensing_ok for {$platform}");
    ptmd_assert_true(in_array('branded_content_ok', $keys, true),        "ptmd_platform_policy_checklist: has branded_content_ok for {$platform}");
    ptmd_assert_true(in_array('prohibited_content_reviewed', $keys, true),"ptmd_platform_policy_checklist: has prohibited_content_reviewed for {$platform}");
    ptmd_assert_true(in_array('credential_rotation_set', $keys, true),   "ptmd_platform_policy_checklist: has credential_rotation_set for {$platform}");
}

// ---------------------------------------------------------------------------
// ptmd_check_account_health — healthy account
// ---------------------------------------------------------------------------

$healthyAccount = [
    'id'                  => 1,
    'platform'            => 'TikTok',
    'handle'              => '@ptmd',
    'auth_config_json'    => json_encode(['access_token' => 'tok_abc']),
    'onboard_status'      => 'active',
    'token_expires_at'    => date('Y-m-d H:i:s', time() + 86400 * 30), // 30 days away
    'permissions_json'    => json_encode(['video.publish', 'video.upload']),
    'required_scopes_json'=> json_encode(['video.publish', 'video.upload']),
    'health_status'       => 'unknown',
    'health_notes_json'   => null,
];

$result = ptmd_check_account_health($healthyAccount);
ptmd_assert_same($result['ok'], true, 'ptmd_check_account_health: healthy account returns ok=true');
ptmd_assert_same($result['token_valid'], true, 'ptmd_check_account_health: healthy account token_valid=true');
ptmd_assert_same($result['permissions_complete'], true, 'ptmd_check_account_health: healthy account permissions_complete=true');
ptmd_assert_same($result['can_post'], true, 'ptmd_check_account_health: healthy account can_post=true');
ptmd_assert_same($result['notes'], [], 'ptmd_check_account_health: healthy account has no notes');

// ---------------------------------------------------------------------------
// ptmd_check_account_health — expired token
// ---------------------------------------------------------------------------

$expiredAccount = $healthyAccount;
$expiredAccount['token_expires_at'] = date('Y-m-d H:i:s', time() - 3600); // expired 1h ago
$result = ptmd_check_account_health($expiredAccount);
ptmd_assert_same($result['ok'], false, 'ptmd_check_account_health: expired token returns ok=false');
ptmd_assert_same($result['token_valid'], false, 'ptmd_check_account_health: expired token returns token_valid=false');
ptmd_assert_same($result['can_post'], false, 'ptmd_check_account_health: expired token returns can_post=false');
ptmd_assert_true(!empty($result['notes']), 'ptmd_check_account_health: expired token returns notes');
ptmd_assert_true(str_contains(strtolower($result['notes'][0]), 'expired'), 'ptmd_check_account_health: expired token note mentions expiry');

// ---------------------------------------------------------------------------
// ptmd_check_account_health — token expiring within 7 days (warning only)
// ---------------------------------------------------------------------------

$expiringAccount = $healthyAccount;
$expiringAccount['token_expires_at'] = date('Y-m-d H:i:s', time() + 86400 * 5); // expires in 5 days
$result = ptmd_check_account_health($expiringAccount);
// Still ok (not expired yet), but should have a warning note
ptmd_assert_same($result['ok'], true, 'ptmd_check_account_health: expiring-soon token still ok');
ptmd_assert_same($result['can_post'], true, 'ptmd_check_account_health: expiring-soon token can still post');
ptmd_assert_true(!empty($result['notes']), 'ptmd_check_account_health: expiring-soon token has warning note');

// ---------------------------------------------------------------------------
// ptmd_check_account_health — missing scopes
// ---------------------------------------------------------------------------

$missingScopes = $healthyAccount;
$missingScopes['permissions_json']     = json_encode(['video.upload']); // missing video.publish
$missingScopes['required_scopes_json'] = json_encode(['video.publish', 'video.upload']);
$result = ptmd_check_account_health($missingScopes);
ptmd_assert_same($result['ok'], false, 'ptmd_check_account_health: missing scopes returns ok=false');
ptmd_assert_same($result['permissions_complete'], false, 'ptmd_check_account_health: missing scopes returns permissions_complete=false');
ptmd_assert_same($result['can_post'], false, 'ptmd_check_account_health: missing scopes returns can_post=false');
ptmd_assert_true(!empty($result['notes']), 'ptmd_check_account_health: missing scopes returns notes');

// ---------------------------------------------------------------------------
// ptmd_check_account_health — deactivated account
// ---------------------------------------------------------------------------

$deactivated = $healthyAccount;
$deactivated['onboard_status'] = 'deactivated';
$result = ptmd_check_account_health($deactivated);
ptmd_assert_same($result['can_post'], false, 'ptmd_check_account_health: deactivated account cannot post');
ptmd_assert_true(!empty($result['notes']), 'ptmd_check_account_health: deactivated account has notes');

// ---------------------------------------------------------------------------
// ptmd_check_account_health — no credentials stored
// ---------------------------------------------------------------------------

$noCreds = $healthyAccount;
$noCreds['auth_config_json'] = null;
$result = ptmd_check_account_health($noCreds);
ptmd_assert_same($result['can_post'], false, 'ptmd_check_account_health: no credentials returns can_post=false');
ptmd_assert_true(!empty($result['notes']), 'ptmd_check_account_health: no credentials has notes');

// ---------------------------------------------------------------------------
// ptmd_check_account_health — pending onboarding
// ---------------------------------------------------------------------------

$pending = $healthyAccount;
$pending['onboard_status'] = 'pending';
$result = ptmd_check_account_health($pending);
ptmd_assert_same($result['can_post'], false, 'ptmd_check_account_health: pending onboarding cannot post');

// ---------------------------------------------------------------------------
// format_caption_from_queue_item (A/B variant)
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../inc/social_formatter.php';

$queueItem = [
    'caption'        => 'Primary caption text.',
    'hashtags'       => '#ptmd #investigation',
    'caption_b'      => 'Alternate caption for testing!',
    'hashtags_b'     => '#test #alternate',
    'active_variant' => 'a',
];

// Variant A (default)
$resultA = format_caption_from_queue_item($queueItem, 'TikTok');
ptmd_assert_true(str_contains($resultA, 'Primary caption'), 'format_caption_from_queue_item: variant a uses primary caption');
ptmd_assert_true(str_contains($resultA, '#ptmd'), 'format_caption_from_queue_item: variant a uses primary hashtags');

// Variant B via parameter
$resultB = format_caption_from_queue_item($queueItem, 'TikTok', 'b');
ptmd_assert_true(str_contains($resultB, 'Alternate caption'), 'format_caption_from_queue_item: variant b uses alternate caption');
ptmd_assert_true(str_contains($resultB, '#test'), 'format_caption_from_queue_item: variant b uses alternate hashtags');

// Variant B via active_variant field
$queueItemB = $queueItem;
$queueItemB['active_variant'] = 'b';
$resultBField = format_caption_from_queue_item($queueItemB, 'TikTok');
ptmd_assert_true(str_contains($resultBField, 'Alternate caption'), 'format_caption_from_queue_item: active_variant=b uses alternate caption');

// B fallback to A when caption_b is empty
$queueItemNoB = $queueItem;
$queueItemNoB['caption_b'] = '';
$resultNoB = format_caption_from_queue_item($queueItemNoB, 'TikTok', 'b');
ptmd_assert_true(str_contains($resultNoB, 'Primary caption'), 'format_caption_from_queue_item: b falls back to a when caption_b empty');

// Unknown variant falls back to a
$resultUnknown = format_caption_from_queue_item($queueItem, 'TikTok', 'x');
ptmd_assert_true(str_contains($resultUnknown, 'Primary caption'), 'format_caption_from_queue_item: unknown variant falls back to a');
