<?php
/**
 * PTMD Admin — Social Accounts
 *
 * Manage connected social media accounts: onboarding lifecycle,
 * credential storage, health checks, and account-level controls.
 */

$pageTitle      = 'Social Accounts | PTMD Admin';
$activePage     = 'social-accounts';
$pageHeading    = 'Social Accounts';
$pageSubheading = 'Connect and manage platform accounts for content distribution.';

include __DIR__ . '/_admin_head.php';

require_once __DIR__ . '/../inc/social_platform_rules.php';
require_once __DIR__ . '/../inc/social_account_health.php';

$pdo = get_db();

// ---------------------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------------------

if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/social-accounts.php', 'Invalid CSRF token.', 'danger');
    }

    $action = $_POST['_action'] ?? '';

    // Add new account
    if ($action === 'add') {
        $platform    = trim((string) ($_POST['platform']    ?? ''));
        $handle      = trim((string) ($_POST['handle']      ?? ''));
        $geoRestrict = trim((string) ($_POST['geo_restrict'] ?? ''));
        $ageRestrict = in_array($_POST['age_restrict'] ?? 'none', ['none', '18+'], true)
            ? (string) $_POST['age_restrict'] : 'none';
        $visibility  = in_array($_POST['visibility_default'] ?? 'public', ['public','private','unlisted','friends'], true)
            ? (string) $_POST['visibility_default'] : 'public';

        if ($platform !== '' && $handle !== '') {
            $requiredScopes = ptmd_platform_required_scopes($platform);
            $pdo->prepare(
                'INSERT INTO social_accounts
                    (platform, handle, onboard_status, required_scopes_json,
                     geo_restrict, age_restrict, visibility_default,
                     health_status, is_active, created_at, updated_at)
                 VALUES
                    (:platform, :handle, :status, :req_scopes,
                     :geo, :age, :vis,
                     :health, 1, NOW(), NOW())'
            )->execute([
                'platform'   => $platform,
                'handle'     => $handle,
                'status'     => 'pending',
                'req_scopes' => json_encode($requiredScopes),
                'geo'        => $geoRestrict !== '' ? $geoRestrict : null,
                'age'        => $ageRestrict,
                'vis'        => $visibility,
                'health'     => 'unknown',
            ]);
            redirect('/admin/social-accounts.php', "Account '{$handle}' added. Configure credentials to complete onboarding.", 'success');
        }
        redirect('/admin/social-accounts.php', 'Platform and handle are required.', 'danger');
    }

    // Update onboard status / controls
    if ($action === 'update') {
        $id          = (int) ($_POST['id'] ?? 0);
        $onboardStatus = in_array($_POST['onboard_status'] ?? '', ['pending','connected','active','error','deactivated'], true)
            ? (string) $_POST['onboard_status'] : 'pending';
        $geoRestrict = trim((string) ($_POST['geo_restrict'] ?? ''));
        $ageRestrict = in_array($_POST['age_restrict'] ?? 'none', ['none','18+'], true)
            ? (string) $_POST['age_restrict'] : 'none';
        $visibility  = in_array($_POST['visibility_default'] ?? 'public', ['public','private','unlisted','friends'], true)
            ? (string) $_POST['visibility_default'] : 'public';

        if ($id > 0) {
            $pdo->prepare(
                'UPDATE social_accounts
                 SET onboard_status    = :status,
                     geo_restrict      = :geo,
                     age_restrict      = :age,
                     visibility_default= :vis,
                     updated_at        = NOW()
                 WHERE id = :id'
            )->execute([
                'status' => $onboardStatus,
                'geo'    => $geoRestrict !== '' ? $geoRestrict : null,
                'age'    => $ageRestrict,
                'vis'    => $visibility,
                'id'     => $id,
            ]);
            redirect('/admin/social-accounts.php', 'Account updated.', 'success');
        }
    }

    // Toggle active/inactive
    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare(
                'UPDATE social_accounts
                 SET is_active  = CASE WHEN is_active = 1 THEN 0 ELSE 1 END,
                     updated_at = NOW()
                 WHERE id = :id'
            )->execute(['id' => $id]);
            redirect('/admin/social-accounts.php', 'Account toggled.', 'success');
        }
    }

    // Run health check
    if ($action === 'health_check') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT * FROM social_accounts WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $account = $stmt->fetch();
            if ($account) {
                $result = ptmd_check_account_health($account);
                ptmd_persist_health_check($pdo, $id, $result);
                $statusLabel = $result['ok'] ? 'healthy' : (empty($result['notes']) ? 'unknown' : 'needs attention');
                redirect('/admin/social-accounts.php', "Health check complete: account is {$statusLabel}.", $result['ok'] ? 'success' : 'warning');
            }
        }
        redirect('/admin/social-accounts.php', 'Account not found.', 'danger');
    }

    // Save policy checklist for an account
    if ($action === 'save_checklist') {
        $id       = (int) ($_POST['id'] ?? 0);
        $platform = trim((string) ($_POST['platform'] ?? ''));
        if ($id > 0 && $platform !== '') {
            $checklist = [];
            foreach (ptmd_platform_policy_checklist($platform) as $item) {
                $checklist[$item['key']] = isset($_POST['checklist'][$item['key']]);
            }
            $pdo->prepare(
                'UPDATE social_accounts
                 SET policy_checklist_json = :json, updated_at = NOW()
                 WHERE id = :id'
            )->execute(['json' => json_encode($checklist), 'id' => $id]);
            redirect('/admin/social-accounts.php', 'Policy checklist saved.', 'success');
        }
    }

    // Delete account
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM social_accounts WHERE id = :id')->execute(['id' => $id]);
            redirect('/admin/social-accounts.php', 'Account removed.', 'success');
        }
    }
}

// ---------------------------------------------------------------------------
// Load data
// ---------------------------------------------------------------------------

$accounts = $pdo
    ? $pdo->query('SELECT * FROM social_accounts ORDER BY platform, handle')->fetchAll()
    : [];

$platforms        = PTMD_PLATFORMS;
$onboardStatuses  = ['pending','connected','active','error','deactivated'];
$visibilityOptions= ['public','private','unlisted','friends'];

// Alert summary (expiring tokens, failing platforms, backlog)
$alerts = $pdo ? ptmd_get_alert_summary($pdo) : ['expiring_accounts' => [], 'failing_platforms' => [], 'queue_backlog' => 0, 'has_alerts' => false];
?>

<?php if ($alerts['has_alerts']): ?>
<!-- Alert banner -->
<div class="ptmd-panel p-lg mb-4" style="border-left:3px solid var(--ptmd-warning)">
    <h2 class="h6 mb-3" style="color:var(--ptmd-warning)">
        <i class="fa-solid fa-triangle-exclamation me-2"></i>Active Alerts
    </h2>
    <div class="d-flex flex-wrap gap-4">
        <?php if (!empty($alerts['expiring_accounts'])): ?>
            <div>
                <div class="fw-600" style="font-size:var(--text-sm)">
                    <i class="fa-solid fa-key me-1"></i>Token Expiry
                </div>
                <div class="ptmd-muted" style="font-size:var(--text-xs)">
                    <?php echo count($alerts['expiring_accounts']); ?> account(s) token expiring within 7 days
                </div>
            </div>
        <?php endif; ?>
        <?php if (!empty($alerts['failing_platforms'])): ?>
            <div>
                <div class="fw-600" style="font-size:var(--text-sm)">
                    <i class="fa-solid fa-circle-exclamation me-1"></i>Platform Failures
                </div>
                <div class="ptmd-muted" style="font-size:var(--text-xs)">
                    High failure rate: <?php ee(implode(', ', array_keys($alerts['failing_platforms']))); ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($alerts['queue_backlog'] > 50): ?>
            <div>
                <div class="fw-600" style="font-size:var(--text-sm)">
                    <i class="fa-solid fa-list me-1"></i>Queue Backlog
                </div>
                <div class="ptmd-muted" style="font-size:var(--text-xs)">
                    <?php ee((string) $alerts['queue_backlog']); ?> items queued
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Add account form -->
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-plus me-2 ptmd-text-teal"></i>Connect New Account
    </h2>
    <form method="post" action="/admin/social-accounts.php">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action" value="add">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Platform</label>
                <select class="form-select" name="platform" required>
                    <option value="">— Select —</option>
                    <?php foreach ($platforms as $p): ?>
                        <option><?php ee($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Handle / Name</label>
                <input class="form-control" name="handle" placeholder="@ptmd or channel name" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Visibility Default</label>
                <select class="form-select" name="visibility_default">
                    <?php foreach ($visibilityOptions as $v): ?>
                        <option value="<?php ee($v); ?>" <?php echo $v === 'public' ? 'selected' : ''; ?>>
                            <?php ee(ucfirst($v)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Age Restriction</label>
                <select class="form-select" name="age_restrict">
                    <option value="none" selected>None</option>
                    <option value="18+">18+</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Geo Restrict</label>
                <input class="form-control" name="geo_restrict" placeholder="US,CA (ISO codes)">
            </div>
            <div class="col-12">
                <button class="btn btn-ptmd-primary" type="submit">
                    <i class="fa-solid fa-plug me-2"></i>Add Account
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Accounts list -->
<div class="ptmd-panel p-lg">
    <h2 class="h6 mb-4">Connected Accounts</h2>
    <?php if ($accounts): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>Platform</th>
                        <th>Handle</th>
                        <th>Status</th>
                        <th>Health</th>
                        <th>Token Expiry</th>
                        <th>Visibility</th>
                        <th>Policy</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $acc): ?>
                        <?php
                        $healthColor = match ($acc['health_status'] ?? 'unknown') {
                            'ok'      => 'var(--ptmd-teal)',
                            'warning' => 'var(--ptmd-warning)',
                            'error'   => 'var(--ptmd-error)',
                            default   => 'var(--ptmd-muted)',
                        };
                        $healthIcon = match ($acc['health_status'] ?? 'unknown') {
                            'ok'      => 'fa-circle-check',
                            'warning' => 'fa-triangle-exclamation',
                            'error'   => 'fa-circle-xmark',
                            default   => 'fa-circle-question',
                        };
                        $checklist      = !empty($acc['policy_checklist_json'])
                            ? (array) json_decode((string) $acc['policy_checklist_json'], true) : [];
                        $checklistTotal = count(ptmd_platform_policy_checklist((string) $acc['platform']));
                        $checklistDone  = count(array_filter($checklist));
                        $healthNotes    = [];
                        if (!empty($acc['health_notes_json'])) {
                            $decoded = json_decode((string) $acc['health_notes_json'], true);
                            if (is_array($decoded)) {
                                $healthNotes = $decoded;
                            }
                        }
                        ?>
                        <tr>
                            <td class="fw-500"><?php ee($acc['platform']); ?></td>
                            <td class="ptmd-muted"><?php ee($acc['handle']); ?></td>
                            <td>
                                <form method="post" action="/admin/social-accounts.php" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                    <input type="hidden" name="_action" value="update">
                                    <input type="hidden" name="id" value="<?php ee((string) $acc['id']); ?>">
                                    <input type="hidden" name="platform" value="<?php ee($acc['platform']); ?>">
                                    <input type="hidden" name="geo_restrict" value="<?php ee($acc['geo_restrict'] ?? ''); ?>">
                                    <input type="hidden" name="age_restrict" value="<?php ee($acc['age_restrict'] ?? 'none'); ?>">
                                    <input type="hidden" name="visibility_default" value="<?php ee($acc['visibility_default'] ?? 'public'); ?>">
                                    <select class="form-select form-select-sm" name="onboard_status" style="width:auto;display:inline-block" data-auto-submit>
                                        <?php foreach ($onboardStatuses as $s): ?>
                                            <option value="<?php ee($s); ?>" <?php echo ($acc['onboard_status'] ?? 'pending') === $s ? 'selected' : ''; ?>>
                                                <?php ee(ucfirst($s)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <span style="color:<?php echo $healthColor; ?>">
                                    <i class="fa-solid <?php ee($healthIcon); ?> me-1"></i><?php ee(ucfirst($acc['health_status'] ?? 'unknown')); ?>
                                </span>
                                <?php if (!empty($healthNotes)): ?>
                                    <div data-tippy-content="<?php ee(implode(' | ', $healthNotes)); ?>"
                                         style="font-size:var(--text-xs);cursor:help;opacity:.7">
                                        <i class="fa-solid fa-circle-info"></i> <?php echo count($healthNotes); ?> note(s)
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($acc['last_health_check_at'])): ?>
                                    <div style="font-size:var(--text-xs);opacity:.5">
                                        Checked <?php echo e(date('M j g:ia', strtotime($acc['last_health_check_at']))); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:var(--text-xs)">
                                <?php if (!empty($acc['token_expires_at'])): ?>
                                    <?php
                                    $expiresTs     = strtotime($acc['token_expires_at']);
                                    $secsRemaining = $expiresTs - time();
                                    $expColor      = $secsRemaining < 86400 * 3 ? 'var(--ptmd-error)' : ($secsRemaining < 86400 * 7 ? 'var(--ptmd-warning)' : 'inherit');
                                    ?>
                                    <span style="color:<?php echo $expColor; ?>">
                                        <?php echo e(date('M j, Y', $expiresTs)); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="ptmd-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:var(--text-xs)">
                                <?php ee(ucfirst($acc['visibility_default'] ?? 'public')); ?>
                                <?php if (($acc['age_restrict'] ?? 'none') !== 'none'): ?>
                                    <span class="badge" style="background:var(--ptmd-error);font-size:.65em">18+</span>
                                <?php endif; ?>
                                <?php if (!empty($acc['geo_restrict'])): ?>
                                    <div class="ptmd-muted" style="font-size:.75em">
                                        <i class="fa-solid fa-globe me-1"></i><?php ee($acc['geo_restrict']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:var(--text-xs)">
                                <?php if ($checklistTotal > 0): ?>
                                    <a href="#checklist-<?php ee((string) $acc['id']); ?>"
                                       data-bs-toggle="collapse"
                                       style="text-decoration:none">
                                        <span style="color:<?php echo $checklistDone >= $checklistTotal ? 'var(--ptmd-teal)' : 'var(--ptmd-warning)'; ?>">
                                            <?php ee((string) $checklistDone); ?>/<?php ee((string) $checklistTotal); ?> ✓
                                        </span>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-1 flex-wrap">
                                    <!-- Health check -->
                                    <form method="post" action="/admin/social-accounts.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                        <input type="hidden" name="_action" value="health_check">
                                        <input type="hidden" name="id" value="<?php ee((string) $acc['id']); ?>">
                                        <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                            data-tippy-content="Run health check">
                                            <i class="fa-solid fa-stethoscope"></i>
                                        </button>
                                    </form>
                                    <!-- Toggle active -->
                                    <form method="post" action="/admin/social-accounts.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                        <input type="hidden" name="_action" value="toggle">
                                        <input type="hidden" name="id" value="<?php ee((string) $acc['id']); ?>">
                                        <button class="btn btn-sm <?php echo $acc['is_active'] ? 'btn-ptmd-teal' : 'btn-ptmd-outline'; ?>"
                                            type="submit"
                                            data-tippy-content="<?php echo $acc['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                            <?php echo $acc['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </button>
                                    </form>
                                    <!-- Delete -->
                                    <form method="post" action="/admin/social-accounts.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="id" value="<?php ee((string) $acc['id']); ?>">
                                        <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                            style="color:var(--ptmd-error)"
                                            data-confirm="Remove this account?"
                                            data-tippy-content="Delete">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <!-- Policy checklist collapse row -->
                        <?php if ($checklistTotal > 0): ?>
                            <tr>
                                <td colspan="8" class="p-0">
                                    <div class="collapse" id="checklist-<?php ee((string) $acc['id']); ?>">
                                        <div class="p-3" style="background:var(--ptmd-surface-alt)">
                                            <form method="post" action="/admin/social-accounts.php">
                                                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                                <input type="hidden" name="_action" value="save_checklist">
                                                <input type="hidden" name="id" value="<?php ee((string) $acc['id']); ?>">
                                                <input type="hidden" name="platform" value="<?php ee($acc['platform']); ?>">
                                                <div class="fw-600 mb-2" style="font-size:var(--text-sm)">
                                                    <i class="fa-solid fa-shield-check me-2 ptmd-text-teal"></i>
                                                    Policy Checklist — <?php ee($acc['platform']); ?>
                                                </div>
                                                <div class="row g-2">
                                                    <?php foreach (ptmd_platform_policy_checklist((string) $acc['platform']) as $item): ?>
                                                        <div class="col-md-6">
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="checkbox"
                                                                    name="checklist[<?php ee($item['key']); ?>]"
                                                                    id="cl-<?php ee((string) $acc['id']); ?>-<?php ee($item['key']); ?>"
                                                                    <?php echo !empty($checklist[$item['key']]) ? 'checked' : ''; ?>>
                                                                <label class="form-check-label"
                                                                    for="cl-<?php ee((string) $acc['id']); ?>-<?php ee($item['key']); ?>"
                                                                    style="font-size:var(--text-sm)"
                                                                    data-tippy-content="<?php ee($item['description']); ?>">
                                                                    <?php ee($item['label']); ?>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <button class="btn btn-ptmd-teal btn-sm mt-3" type="submit">
                                                    <i class="fa-solid fa-floppy-disk me-1"></i>Save Checklist
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Required credentials reference card -->
        <details class="mt-4">
            <summary class="ptmd-muted" style="cursor:pointer;font-size:var(--text-sm)">
                <i class="fa-solid fa-circle-info me-2"></i>Required credential fields per platform
            </summary>
            <div class="row g-3 mt-2">
                <?php foreach (PTMD_PLATFORMS as $p): ?>
                    <?php $fields = ptmd_platform_credential_fields($p); ?>
                    <?php if (!empty($fields)): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="ptmd-panel p-sm" style="font-size:var(--text-xs)">
                                <div class="fw-600 mb-2"><?php ee($p); ?></div>
                                <?php foreach ($fields as $f): ?>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <i class="fa-solid <?php echo $f['type'] === 'secret' ? 'fa-lock' : 'fa-tag'; ?> ptmd-muted" style="width:12px"></i>
                                        <span class="fw-500"><?php ee($f['label']); ?></span>
                                        <?php if ($f['required']): ?>
                                            <span class="badge" style="background:var(--ptmd-teal);font-size:.65em">required</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </details>

    <?php else: ?>
        <p class="ptmd-muted small">No accounts connected yet. Use the form above to add a platform account.</p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_admin_footer.php'; ?>
