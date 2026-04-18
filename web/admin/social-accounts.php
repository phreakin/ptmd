<?php
/**
 * PTMD Admin — Social Accounts
 *
 * Manage platform OAuth credentials for automated social posting.
 * Each row in social_accounts links a platform to its auth_config_json
 * (encrypted in production; shown here as a masked password field).
 *
 * The page also runs a live health check on each account and persists
 * the result so operators can see token expiry warnings at a glance.
 */

$pageTitle      = 'Social Accounts | PTMD Admin';
$activePage     = 'social-accounts';
$pageHeading    = 'Social Accounts';
$pageSubheading = 'Connect and manage platform credentials for automated posting.';

include __DIR__ . '/_admin_head.php';

require_once __DIR__ . '/../inc/social_platform_rules.php';
require_once __DIR__ . '/../inc/social_account_health.php';

$pdo = get_db();

// ── POST handlers ─────────────────────────────────────────────────────────────

if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/social-accounts.php', 'Invalid CSRF token.', 'danger');
    }

    $postAction = $_POST['_action'] ?? '';

    // Add new account
    if ($postAction === 'add') {
        $platform = trim((string) ($_POST['platform'] ?? ''));
        $handle   = trim((string) ($_POST['handle']   ?? ''));

        if ($platform !== '' && $handle !== '') {
            // Collect credential fields for this platform
            $credFields  = ptmd_platform_credential_fields($platform);
            $authConfig  = [];
            foreach ($credFields as $field) {
                $val = trim((string) ($_POST['cred_' . $field['key']] ?? ''));
                if ($val !== '') {
                    $authConfig[$field['key']] = $val;
                }
            }

            $pdo->prepare(
                'INSERT INTO social_accounts
                 (platform, handle, auth_config_json, is_active, status,
                  onboarding_step, created_at, updated_at)
                 VALUES (:platform, :handle, :auth, 0, "active",
                         "credentials", NOW(), NOW())'
            )->execute([
                'platform' => $platform,
                'handle'   => $handle,
                'auth'     => empty($authConfig) ? null : json_encode($authConfig, JSON_UNESCAPED_UNICODE),
            ]);
            redirect('/admin/social-accounts.php', 'Account added. Review credentials and activate.', 'success');
        }
        redirect('/admin/social-accounts.php', 'Platform and handle are required.', 'warning');
    }

    // Save credentials for existing account
    if ($postAction === 'save_credentials') {
        $acctId     = (int) ($_POST['id'] ?? 0);
        $handle     = trim((string) ($_POST['handle'] ?? ''));
        $expiresAt  = trim((string) ($_POST['token_expires_at'] ?? ''));
        $tokenScope = trim((string) ($_POST['token_scope'] ?? ''));

        if ($acctId > 0) {
            // Load existing account to get platform
            $existing = $pdo->prepare('SELECT * FROM social_accounts WHERE id = :id LIMIT 1');
            $existing->execute(['id' => $acctId]);
            $existing = $existing->fetch();

            if ($existing) {
                $credFields = ptmd_platform_credential_fields($existing['platform']);
                $authConfig = [];

                // Load existing auth_config so we can merge (not overwrite with blanks)
                $prevAuth = [];
                if (!empty($existing['auth_config_json'])) {
                    $prevAuth = json_decode($existing['auth_config_json'], true) ?: [];
                }

                foreach ($credFields as $field) {
                    $val = trim((string) ($_POST['cred_' . $field['key']] ?? ''));
                    // Keep existing value if the field was left blank (masking behaviour)
                    $authConfig[$field['key']] = $val !== '' ? $val : ($prevAuth[$field['key']] ?? '');
                }

                $pdo->prepare(
                    'UPDATE social_accounts
                     SET handle          = :handle,
                         auth_config_json = :auth,
                         token_expires_at = :expires,
                         token_scope      = :scope,
                         onboarding_step  = "complete",
                         onboarding_completed_at = CASE WHEN onboarding_completed_at IS NULL THEN NOW() ELSE onboarding_completed_at END,
                         updated_at       = NOW()
                     WHERE id = :id'
                )->execute([
                    'handle'  => $handle !== '' ? $handle : $existing['handle'],
                    'auth'    => empty(array_filter($authConfig)) ? null : json_encode($authConfig, JSON_UNESCAPED_UNICODE),
                    'expires' => $expiresAt !== '' ? $expiresAt : null,
                    'scope'   => $tokenScope !== '' ? $tokenScope : null,
                    'id'      => $acctId,
                ]);
                redirect('/admin/social-accounts.php', 'Credentials saved.', 'success');
            }
        }
    }

    // Toggle active/inactive
    if ($postAction === 'toggle') {
        $togId  = (int) ($_POST['id']        ?? 0);
        $active = (int) ($_POST['is_active'] ?? 0);
        if ($togId > 0) {
            $pdo->prepare(
                'UPDATE social_accounts SET is_active = :a, updated_at = NOW() WHERE id = :id'
            )->execute(['a' => $active ? 0 : 1, 'id' => $togId]);
            redirect('/admin/social-accounts.php', 'Account ' . ($active ? 'deactivated' : 'activated') . '.', 'success');
        }
    }

    // Run health check
    if ($postAction === 'health_check') {
        $checkId = (int) ($_POST['id'] ?? 0);
        if ($checkId > 0) {
            $acctRow = $pdo->prepare('SELECT * FROM social_accounts WHERE id = :id LIMIT 1');
            $acctRow->execute(['id' => $checkId]);
            $acctRow = $acctRow->fetch();
            if ($acctRow) {
                $result = ptmd_check_account_health($acctRow);
                ptmd_persist_health_check($pdo, $checkId, $result);
                $msg = $result['healthy']
                    ? 'Health check passed. Account looks good.'
                    : 'Health check completed — issues found: ' . implode('; ', $result['issues']);
                redirect('/admin/social-accounts.php', $msg, $result['healthy'] ? 'success' : 'warning');
            }
        }
    }

    // Delete account
    if ($postAction === 'delete') {
        $delId = (int) ($_POST['id'] ?? 0);
        if ($delId > 0) {
            $pdo->prepare('DELETE FROM social_accounts WHERE id = :id')->execute(['id' => $delId]);
            redirect('/admin/social-accounts.php', 'Account deleted.', 'success');
        }
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────

$accounts = $pdo
    ? $pdo->query('SELECT * FROM social_accounts ORDER BY platform, handle')->fetchAll()
    : [];

$platforms   = array_keys(PTMD_PLATFORMS);
$editingId   = (int) ($_GET['edit'] ?? 0);
$editAccount = null;
if ($editingId > 0 && $pdo) {
    $stmt = $pdo->prepare('SELECT * FROM social_accounts WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editingId]);
    $editAccount = $stmt->fetch() ?: null;
}

// Collect health per account (pure function, no DB)
$healthMap = [];
foreach ($accounts as $acct) {
    $healthMap[(int) $acct['id']] = ptmd_check_account_health($acct);
}

// ── Status badge helper ────────────────────────────────────────────────────────

function acct_status_badge(string $status): string
{
    $map = [
        'ok'            => ['bg-success',  'Healthy'],
        'expiring_soon' => ['bg-warning text-dark', 'Expiring Soon'],
        'expired'       => ['bg-danger',   'Expired'],
        'revoked'       => ['bg-danger',   'Revoked'],
        'error'         => ['bg-danger',   'Error'],
        'unconfigured'  => ['bg-secondary','Unconfigured'],
    ];
    [$cls, $label] = $map[$status] ?? ['bg-secondary', $status];
    return '<span class="badge ' . e($cls) . '" style="font-size:.65rem">' . e($label) . '</span>';
}
?>

<!-- ── Add Account form ───────────────────────────────────────────────────── -->
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-plus me-2 ptmd-text-teal"></i>Connect Platform Account
    </h2>
    <form method="post" action="/admin/social-accounts.php">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action"    value="add">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Platform</label>
                <select class="form-select" name="platform" id="addPlatformSelect" required>
                    <option value="">— Select platform —</option>
                    <?php foreach ($platforms as $p): ?>
                        <option><?php ee($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Handle / Username</label>
                <input class="form-control" name="handle" placeholder="@papertrailmd" required>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button class="btn btn-ptmd-primary w-100" type="submit">
                    <i class="fa-solid fa-plug me-1"></i>Add Account
                </button>
            </div>
        </div>
        <p class="ptmd-muted small mt-2 mb-0">
            <i class="fa-solid fa-info-circle me-1"></i>
            After adding, click <strong>Edit</strong> on the account row to save credentials.
        </p>
    </form>
</div>

<?php if ($editAccount): ?>
<!-- ── Credential editor ───────────────────────────────────────────────────── -->
<div class="ptmd-panel p-xl mb-4" style="border:1px solid var(--ptmd-teal,#00c6b0)">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-key me-2 ptmd-text-teal"></i>
        Edit Credentials — <?php ee($editAccount['platform']); ?> (<?php ee($editAccount['handle']); ?>)
    </h2>
    <form method="post" action="/admin/social-accounts.php">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action"    value="save_credentials">
        <input type="hidden" name="id"         value="<?php ee((string) $editAccount['id']); ?>">

        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <label class="form-label">Handle</label>
                <input class="form-control" name="handle" value="<?php ee($editAccount['handle']); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Token Expires At (UTC)</label>
                <input class="form-control" type="datetime-local" name="token_expires_at"
                       value="<?php echo !empty($editAccount['token_expires_at'])
                           ? e(date('Y-m-d\TH:i', strtotime($editAccount['token_expires_at'])))
                           : ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Token Scopes (space-separated)</label>
                <input class="form-control" name="token_scope"
                       value="<?php ee((string) ($editAccount['token_scope'] ?? '')); ?>"
                       placeholder="video.upload tweet.write …">
            </div>
        </div>

        <?php $credFields = ptmd_platform_credential_fields($editAccount['platform']); ?>
        <?php if ($credFields): ?>
            <h3 class="h6 mb-3 ptmd-muted small">
                <i class="fa-solid fa-lock me-1"></i>API Credentials
                <span class="ms-2 text-warning" style="font-size:.7rem">
                    ⚠ Credentials are stored in the database; use an encrypted column or secrets vault in production.
                </span>
            </h3>
            <div class="row g-3">
                <?php foreach ($credFields as $field): ?>
                    <div class="col-md-6">
                        <label class="form-label"><?php ee($field['label']); ?></label>
                        <?php if ($field['type'] === 'secret'): ?>
                            <input class="form-control" type="password"
                                   name="cred_<?php ee($field['key']); ?>"
                                   placeholder="<?php echo '••••••••  (leave blank to keep current)'; ?>"
                                   autocomplete="new-password">
                        <?php else: ?>
                            <input class="form-control" type="text"
                                   name="cred_<?php ee($field['key']); ?>"
                                   placeholder="<?php ee($field['key']); ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="ptmd-muted small">No credential fields defined for this platform.</p>
        <?php endif; ?>

        <?php $policyList = ptmd_platform_policy_checklist($editAccount['platform']); ?>
        <?php if ($policyList): ?>
            <div class="mt-4">
                <h3 class="h6 mb-2 ptmd-muted small">
                    <i class="fa-solid fa-clipboard-check me-1"></i>Platform Policy Checklist
                </h3>
                <div class="d-flex flex-column gap-1" style="font-size:var(--text-xs)">
                    <?php foreach ($policyList as $item): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="policy_<?php ee($item['label']); ?>">
                            <label class="form-check-label" for="policy_<?php ee($item['label']); ?>">
                                <?php ee($item['label']); ?>
                                <?php if (!empty($item['url'])): ?>
                                    <a href="<?php ee($item['url']); ?>" target="_blank" rel="noopener"
                                       class="ms-1 ptmd-text-teal" style="font-size:.7rem">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                    </a>
                                <?php endif; ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php
        $scopes = ptmd_platform_required_scopes($editAccount['platform']);
        if ($scopes):
        ?>
            <div class="mt-3">
                <h3 class="h6 mb-2 ptmd-muted small">
                    <i class="fa-solid fa-shield me-1"></i>Required OAuth Scopes
                </h3>
                <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($scopes as $scope): ?>
                        <code style="font-size:.65rem;background:var(--ptmd-surface-3,#23272e);
                              padding:2px 6px;border-radius:4px;color:var(--ptmd-teal,#00c6b0)">
                            <?php ee($scope); ?>
                        </code>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-ptmd-primary" type="submit">
                <i class="fa-solid fa-floppy-disk me-1"></i>Save Credentials
            </button>
            <a href="/admin/social-accounts.php" class="btn btn-ptmd-ghost">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ── Accounts table ──────────────────────────────────────────────────────── -->
<div class="ptmd-panel p-lg">
    <h2 class="h6 mb-4">Connected Accounts</h2>
    <?php if ($accounts): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>Platform</th>
                        <th>Handle</th>
                        <th>Health</th>
                        <th>Issues</th>
                        <th>Token Expires</th>
                        <th>Last Check</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $acct): ?>
                        <?php $health = $healthMap[(int) $acct['id']]; ?>
                        <tr <?php echo $editingId === (int) $acct['id'] ? 'style="outline:1px solid var(--ptmd-teal)"' : ''; ?>>
                            <td class="fw-500"><?php ee($acct['platform']); ?></td>
                            <td class="ptmd-muted small"><?php ee($acct['handle']); ?></td>
                            <td><?php echo acct_status_badge($health['status']); ?></td>
                            <td style="font-size:var(--text-xs)">
                                <?php if ($health['issues']): ?>
                                    <span class="ptmd-text-warning" data-tippy-content="<?php ee(implode(' | ', $health['issues'])); ?>">
                                        <?php ee(count($health['issues'])); ?> issue(s)
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                    </span>
                                <?php else: ?>
                                    <span class="ptmd-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo !empty($acct['token_expires_at'])
                                    ? e(date('M j Y', strtotime($acct['token_expires_at'])))
                                    : '—'; ?>
                                <?php if ($health['days_until_expiry'] !== null && $health['days_until_expiry'] <= 7 && $health['days_until_expiry'] >= 0): ?>
                                    <span class="badge bg-warning text-dark" style="font-size:.55rem">
                                        <?php ee((string) $health['days_until_expiry']); ?>d
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo !empty($acct['last_health_check_at'])
                                    ? e(date('M j g:ia', strtotime($acct['last_health_check_at'])))
                                    : 'Never'; ?>
                            </td>
                            <td>
                                <form method="post" action="/admin/social-accounts.php" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                    <input type="hidden" name="_action"    value="toggle">
                                    <input type="hidden" name="id"         value="<?php ee((string) $acct['id']); ?>">
                                    <input type="hidden" name="is_active"  value="<?php ee((string) $acct['is_active']); ?>">
                                    <button type="submit"
                                            class="btn btn-sm <?php echo $acct['is_active'] ? 'btn-ptmd-teal' : 'btn-ptmd-outline'; ?>">
                                        <?php echo $acct['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <!-- Edit credentials -->
                                    <a href="/admin/social-accounts.php?edit=<?php ee((string) $acct['id']); ?>"
                                       class="btn btn-ptmd-ghost btn-sm"
                                       data-tippy-content="Edit credentials">
                                        <i class="fa-solid fa-key"></i>
                                    </a>
                                    <!-- Run health check -->
                                    <form method="post" action="/admin/social-accounts.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                        <input type="hidden" name="_action"    value="health_check">
                                        <input type="hidden" name="id"         value="<?php ee((string) $acct['id']); ?>">
                                        <button type="submit" class="btn btn-ptmd-ghost btn-sm"
                                                data-tippy-content="Run health check">
                                            <i class="fa-solid fa-heartbeat"></i>
                                        </button>
                                    </form>
                                    <!-- Delete -->
                                    <form method="post" action="/admin/social-accounts.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                        <input type="hidden" name="_action"    value="delete">
                                        <input type="hidden" name="id"         value="<?php ee((string) $acct['id']); ?>">
                                        <button type="submit" class="btn btn-ptmd-ghost btn-sm"
                                                style="color:var(--ptmd-error)"
                                                data-confirm="Delete this platform account?"
                                                data-tippy-content="Delete">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="ptmd-muted small">
            No platform accounts yet. Use the form above to connect your first platform.
        </p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_admin_footer.php'; ?>
