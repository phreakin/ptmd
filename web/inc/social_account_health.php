<?php
/**
 * PTMD — Social Account Health (inc/social_account_health.php)
 *
 * Platform onboarding lifecycle helpers:
 *  - Per-platform credential field and permission scope specs
 *  - Account health checks (token validity, permission completeness, posting capability)
 *  - Observability helpers: expiring tokens, platform failure rates, queue backlog
 *
 * Public API
 * ----------
 * ptmd_platform_credential_fields(string $platform): array
 * ptmd_platform_required_scopes(string $platform): array
 * ptmd_check_account_health(array $account): array{ok:bool, token_valid:bool,
 *     permissions_complete:bool, can_post:bool, notes:string[]}
 * ptmd_get_expiring_accounts(PDO $pdo, int $daysAhead = 7): array
 * ptmd_get_failing_platforms(PDO $pdo, int $lookbackHours = 24): array
 * ptmd_get_queue_backlog(PDO $pdo): int
 * ptmd_persist_health_check(PDO $pdo, int $accountId, array $result): void
 */

require_once __DIR__ . '/social_platform_rules.php';

// ---------------------------------------------------------------------------
// Per-platform credential field specifications
// ---------------------------------------------------------------------------

/**
 * Return the required credential input fields for a given platform.
 * Each field has: key, label, type (text|secret|textarea), required.
 *
 * @return array<int,array{key:string,label:string,type:string,required:bool}>
 */
function ptmd_platform_credential_fields(string $platform): array
{
    $specs = [
        'YouTube' => [
            ['key' => 'client_id',     'label' => 'OAuth Client ID',     'type' => 'text',   'required' => true],
            ['key' => 'client_secret', 'label' => 'OAuth Client Secret', 'type' => 'secret', 'required' => true],
            ['key' => 'refresh_token', 'label' => 'Refresh Token',       'type' => 'secret', 'required' => true],
            ['key' => 'channel_id',    'label' => 'YouTube Channel ID',  'type' => 'text',   'required' => false],
        ],
        'YouTube Shorts' => [
            ['key' => 'client_id',     'label' => 'OAuth Client ID',     'type' => 'text',   'required' => true],
            ['key' => 'client_secret', 'label' => 'OAuth Client Secret', 'type' => 'secret', 'required' => true],
            ['key' => 'refresh_token', 'label' => 'Refresh Token',       'type' => 'secret', 'required' => true],
            ['key' => 'channel_id',    'label' => 'YouTube Channel ID',  'type' => 'text',   'required' => false],
        ],
        'TikTok' => [
            ['key' => 'client_key',     'label' => 'Client Key',          'type' => 'text',   'required' => true],
            ['key' => 'client_secret',  'label' => 'Client Secret',       'type' => 'secret', 'required' => true],
            ['key' => 'access_token',   'label' => 'User Access Token',   'type' => 'secret', 'required' => true],
            ['key' => 'open_id',        'label' => 'User Open ID',        'type' => 'text',   'required' => false],
        ],
        'Instagram Reels' => [
            ['key' => 'app_id',          'label' => 'Meta App ID',         'type' => 'text',   'required' => true],
            ['key' => 'app_secret',      'label' => 'Meta App Secret',     'type' => 'secret', 'required' => true],
            ['key' => 'access_token',    'label' => 'User Access Token',   'type' => 'secret', 'required' => true],
            ['key' => 'ig_user_id',      'label' => 'Instagram User ID',   'type' => 'text',   'required' => true],
        ],
        'Facebook Reels' => [
            ['key' => 'app_id',          'label' => 'Meta App ID',         'type' => 'text',   'required' => true],
            ['key' => 'app_secret',      'label' => 'Meta App Secret',     'type' => 'secret', 'required' => true],
            ['key' => 'access_token',    'label' => 'Page Access Token',   'type' => 'secret', 'required' => true],
            ['key' => 'page_id',         'label' => 'Facebook Page ID',    'type' => 'text',   'required' => true],
        ],
        'Snapchat Spotlight' => [
            ['key' => 'client_id',       'label' => 'Snap Client ID',      'type' => 'text',   'required' => true],
            ['key' => 'client_secret',   'label' => 'Snap Client Secret',  'type' => 'secret', 'required' => true],
            ['key' => 'access_token',    'label' => 'User Access Token',   'type' => 'secret', 'required' => true],
            ['key' => 'ad_account_id',   'label' => 'Ad Account ID',       'type' => 'text',   'required' => false],
        ],
        'X' => [
            ['key' => 'api_key',         'label' => 'API Key',             'type' => 'text',   'required' => true],
            ['key' => 'api_secret',      'label' => 'API Secret',          'type' => 'secret', 'required' => true],
            ['key' => 'access_token',    'label' => 'Access Token',        'type' => 'secret', 'required' => true],
            ['key' => 'access_secret',   'label' => 'Access Token Secret', 'type' => 'secret', 'required' => true],
        ],
        'Pinterest Idea Pins' => [
            ['key' => 'app_id',          'label' => 'Pinterest App ID',    'type' => 'text',   'required' => true],
            ['key' => 'app_secret',      'label' => 'Pinterest App Secret','type' => 'secret', 'required' => true],
            ['key' => 'access_token',    'label' => 'User Access Token',   'type' => 'secret', 'required' => true],
            ['key' => 'board_id',        'label' => 'Default Board ID',    'type' => 'text',   'required' => false],
        ],
    ];

    return $specs[$platform] ?? [];
}

/**
 * Return the OAuth permission scopes required to post on the given platform.
 *
 * @return string[]
 */
function ptmd_platform_required_scopes(string $platform): array
{
    $scopes = [
        'YouTube' => [
            'https://www.googleapis.com/auth/youtube.upload',
            'https://www.googleapis.com/auth/youtube',
        ],
        'YouTube Shorts' => [
            'https://www.googleapis.com/auth/youtube.upload',
            'https://www.googleapis.com/auth/youtube',
        ],
        'TikTok' => [
            'video.publish',
            'video.upload',
        ],
        'Instagram Reels' => [
            'instagram_content_publish',
            'instagram_basic',
            'pages_read_engagement',
        ],
        'Facebook Reels' => [
            'pages_manage_posts',
            'pages_read_engagement',
            'publish_video',
        ],
        'Snapchat Spotlight' => [
            'snapchat-marketing-api',
            'media.read',
            'media.write',
        ],
        'X' => [
            'tweet.read',
            'tweet.write',
            'users.read',
            'media.write',
        ],
        'Pinterest Idea Pins' => [
            'pins:read',
            'pins:write',
            'boards:read',
        ],
    ];

    return $scopes[$platform] ?? [];
}

/**
 * Return the platform-level compliance checklist definition.
 * Each item has: key, label, description.
 *
 * @return array<int,array{key:string,label:string,description:string}>
 */
function ptmd_platform_policy_checklist(string $platform): array
{
    $common = [
        ['key' => 'music_licensing_ok',        'label' => 'Music / Licensing',          'description' => 'All audio in clips is either original, license-free, or properly licensed.'],
        ['key' => 'branded_content_ok',        'label' => 'Branded Content Disclosure', 'description' => 'Paid partnerships or branded content are disclosed per platform policy.'],
        ['key' => 'prohibited_content_reviewed','label' => 'Prohibited Content Review',  'description' => "Content has been reviewed against the platform's prohibited categories."],
        ['key' => 'credential_rotation_set',   'label' => 'Credential Rotation',        'description' => 'Token rotation schedule and secret storage standards are in place.'],
    ];

    $extra = match ($platform) {
        'TikTok'              => [['key' => 'creator_marketplace_ok', 'label' => 'Creator Marketplace',      'description' => 'Account is eligible and compliant with TikTok Creator Marketplace rules.']],
        'YouTube',
        'YouTube Shorts'      => [['key' => 'content_id_ok',          'label' => 'Content ID',               'description' => 'Music and video assets will not trigger YouTube Content ID claims.']],
        'Instagram Reels',
        'Facebook Reels'      => [['key' => 'meta_commerce_ok',       'label' => 'Meta Commerce Policy',     'description' => "Content complies with Meta's commerce and advertising policies."]],
        'Snapchat Spotlight'  => [['key' => 'snap_community_ok',      'label' => 'Snap Community Guidelines','description' => 'Content reviewed against Snapchat Community Guidelines.']],
        'Pinterest Idea Pins' => [['key' => 'pinterest_ads_ok',       'label' => 'Pinterest Advertising',    'description' => "Idea Pins comply with Pinterest's Advertising Standards."]],
        default               => [],
    };

    return array_merge($common, $extra);
}

// ---------------------------------------------------------------------------
// Account health check
// ---------------------------------------------------------------------------

/**
 * Run a health check on a social_accounts row (array).
 *
 * Checks:
 *  1. Token validity (token_expires_at, if known)
 *  2. Permission completeness (required_scopes_json vs. permissions_json)
 *  3. Posting capability (onboard_status = active, both credential sets present)
 *
 * NOTE: This is a structural / metadata check only — it does NOT make live
 *       API calls.  Real online validation requires the platform SDK.
 *
 * @param  array<string,mixed> $account  Row from social_accounts.
 * @return array{ok:bool, token_valid:bool, permissions_complete:bool,
 *               can_post:bool, notes:string[]}
 */
function ptmd_check_account_health(array $account): array
{
    $notes             = [];
    $tokenValid        = true;
    $permissionsOk     = true;
    $canPost           = true;

    // 1. Token expiry check
    $tokenExpiresAt = $account['token_expires_at'] ?? null;
    if ($tokenExpiresAt !== null && $tokenExpiresAt !== '') {
        $expiresTs = is_int($tokenExpiresAt) ? $tokenExpiresAt : strtotime((string) $tokenExpiresAt);
        if ($expiresTs !== false) {
            $secsRemaining = $expiresTs - time();
            if ($secsRemaining <= 0) {
                $tokenValid = false;
                $canPost    = false;
                $notes[]    = 'Token has expired. Re-authorize the account to restore posting access.';
            } elseif ($secsRemaining < 86400 * 3) {
                $notes[] = 'Token expires in less than 3 days. Plan credential rotation soon.';
            } elseif ($secsRemaining < 86400 * 7) {
                $notes[] = 'Token expires within 7 days.';
            }
        }
    }

    // 2. Permission / scope completeness
    $platform       = (string) ($account['platform'] ?? '');
    $requiredScopes = [];
    if (!empty($account['required_scopes_json'])) {
        $decoded = json_decode((string) $account['required_scopes_json'], true);
        if (is_array($decoded)) {
            $requiredScopes = $decoded;
        }
    }
    if (empty($requiredScopes)) {
        $requiredScopes = ptmd_platform_required_scopes($platform);
    }

    $grantedScopes = [];
    if (!empty($account['permissions_json'])) {
        $decoded = json_decode((string) $account['permissions_json'], true);
        if (is_array($decoded)) {
            $grantedScopes = $decoded;
        }
    }

    if (!empty($requiredScopes) && !empty($grantedScopes)) {
        $missing = array_diff($requiredScopes, $grantedScopes);
        if (!empty($missing)) {
            $permissionsOk = false;
            $canPost       = false;
            $notes[]       = 'Missing required scopes: ' . implode(', ', $missing) . '.';
        }
    } elseif (!empty($requiredScopes) && empty($grantedScopes)) {
        $notes[] = 'Granted scopes have not been recorded. Re-authorize to capture permissions.';
    }

    // 3. Onboard status check
    $onboardStatus = (string) ($account['onboard_status'] ?? 'pending');
    if ($onboardStatus === 'deactivated') {
        $canPost = false;
        $notes[] = 'Account is deactivated.';
    } elseif ($onboardStatus === 'error') {
        $canPost = false;
        $notes[] = 'Account is in error state. Review auth configuration.';
    } elseif ($onboardStatus === 'pending') {
        $canPost = false;
        $notes[] = 'Account has not completed onboarding.';
    } elseif ($onboardStatus !== 'active' && $onboardStatus !== 'connected') {
        $notes[] = "Unexpected onboard status: {$onboardStatus}.";
    }

    // 4. Auth config presence
    if (empty($account['auth_config_json']) || $account['auth_config_json'] === 'null') {
        $canPost = false;
        $notes[] = 'No credentials stored. Add the platform credentials to enable posting.';
    }

    $ok = $tokenValid && $permissionsOk && $canPost;

    return [
        'ok'                   => $ok,
        'token_valid'          => $tokenValid,
        'permissions_complete' => $permissionsOk,
        'can_post'             => $canPost,
        'notes'                => $notes,
    ];
}

/**
 * Run health checks on all active accounts and persist results.
 *
 * @return array<int,array>  Map of account id → health check result.
 */
function ptmd_run_all_health_checks(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM social_accounts WHERE is_active = 1');
    if (!$stmt) {
        return [];
    }

    $results = [];
    foreach ($stmt->fetchAll() as $account) {
        $result = ptmd_check_account_health($account);
        ptmd_persist_health_check($pdo, (int) $account['id'], $result);
        $results[(int) $account['id']] = $result;
    }

    return $results;
}

/**
 * Persist the outcome of a health check back to the social_accounts row.
 *
 * @param PDO   $pdo
 * @param int   $accountId
 * @param array $result  Return value of ptmd_check_account_health().
 */
function ptmd_persist_health_check(PDO $pdo, int $accountId, array $result): void
{
    $status = $result['ok'] ? 'ok' : ($result['can_post'] ? 'warning' : 'error');
    $stmt = $pdo->prepare(
        'UPDATE social_accounts
         SET health_status        = :status,
             last_health_check_at = NOW(),
             health_notes_json    = :notes,
             updated_at           = NOW()
         WHERE id = :id'
    );
    $stmt->execute([
        'status' => $status,
        'notes'  => json_encode($result['notes'], JSON_UNESCAPED_UNICODE),
        'id'     => $accountId,
    ]);
}

// ---------------------------------------------------------------------------
// Observability helpers
// ---------------------------------------------------------------------------

/**
 * Return accounts whose token_expires_at falls within the next $daysAhead days.
 *
 * @param PDO $pdo
 * @param int $daysAhead  Alert window in days (default 7).
 * @return array[]  Rows from social_accounts.
 */
function ptmd_get_expiring_accounts(PDO $pdo, int $daysAhead = 7): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM social_accounts
         WHERE is_active = 1
           AND token_expires_at IS NOT NULL
           AND token_expires_at <= DATE_ADD(NOW(), INTERVAL :days DAY)
           AND token_expires_at > NOW()
         ORDER BY token_expires_at ASC'
    );
    $stmt->execute(['days' => $daysAhead]);
    return $stmt->fetchAll();
}

/**
 * Return platforms that have had a higher-than-expected failure rate in the
 * last $lookbackHours hours.  Threshold: any platform with ≥ 3 failures
 * and a failure rate ≥ 50% is returned.
 *
 * @param PDO $pdo
 * @param int $lookbackHours  Window in hours (default 24).
 * @return array<string,array{platform:string, total:int, failed:int, rate:float}>
 */
function ptmd_get_failing_platforms(PDO $pdo, int $lookbackHours = 24): array
{
    $stmt = $pdo->prepare(
        "SELECT
             platform,
             COUNT(*) AS total,
             SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
         FROM social_post_logs
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
         GROUP BY platform
         HAVING total > 0"
    );
    $stmt->execute(['hours' => $lookbackHours]);
    $rows = $stmt->fetchAll();

    $failing = [];
    foreach ($rows as $row) {
        $total  = (int) $row['total'];
        $failed = (int) $row['failed'];
        $rate   = $total > 0 ? round($failed / $total, 3) : 0.0;
        if ($failed >= 3 && $rate >= 0.5) {
            $failing[$row['platform']] = [
                'platform' => $row['platform'],
                'total'    => $total,
                'failed'   => $failed,
                'rate'     => $rate,
            ];
        }
    }

    return $failing;
}

/**
 * Return the count of queue items currently waiting to be dispatched
 * (status = 'queued' or 'scheduled').
 *
 * @param PDO $pdo
 * @return int
 */
function ptmd_get_queue_backlog(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM social_post_queue WHERE status IN ('queued','scheduled')"
    );
    return $stmt ? (int) $stmt->fetchColumn() : 0;
}

/**
 * Return a summary of alert conditions to surface in the admin UI.
 *
 * @return array{expiring_accounts:array, failing_platforms:array,
 *               queue_backlog:int, has_alerts:bool}
 */
function ptmd_get_alert_summary(PDO $pdo): array
{
    $expiring         = ptmd_get_expiring_accounts($pdo);
    $failingPlatforms = ptmd_get_failing_platforms($pdo);
    $backlog          = ptmd_get_queue_backlog($pdo);

    $hasAlerts = !empty($expiring) || !empty($failingPlatforms) || $backlog > 50;

    return [
        'expiring_accounts'  => $expiring,
        'failing_platforms'  => $failingPlatforms,
        'queue_backlog'      => $backlog,
        'has_alerts'         => $hasAlerts,
    ];
}
