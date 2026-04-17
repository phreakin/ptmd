<?php
/**
 * PTMD Admin — Settings
 * All site config lives in the site_settings table.
 */

$pageTitle    = 'Settings | PTMD Admin';
$activePage   = 'settings';
$pageHeading  = 'Site Settings';
$pageSubheading = 'All configuration is stored in the database, not hardcoded.';

include __DIR__ . '/_admin_head.php';

$pdo = get_db();

if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/settings.php', 'Invalid CSRF token.', 'danger');
    }

    foreach ($_POST['settings'] ?? [] as $key => $value) {
        $stmt = $pdo->prepare(
            'INSERT INTO site_settings (setting_key, setting_value, setting_type, updated_at)
             VALUES (:key, :value, "string", NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );
        $stmt->execute([
            'key'   => (string) $key,
            'value' => (string) $value,
        ]);
    }

    redirect('/admin/settings.php', 'Settings saved.', 'success');
}

// Load all settings grouped
$allSettings = [];
if ($pdo) {
    $rows = $pdo->query('SELECT * FROM site_settings ORDER BY group_name, setting_key')->fetchAll();
    foreach ($rows as $row) {
        $allSettings[$row['group_name']][] = $row;
    }
}

$groupLabels = [
    'general'  => ['label' => 'General',          'icon' => 'fa-globe'],
    'homepage' => ['label' => 'Homepage',          'icon' => 'fa-house'],
    'social'   => ['label' => 'Social Links',      'icon' => 'fa-share-nodes'],
    'brand'    => ['label' => 'Brand Assets',      'icon' => 'fa-palette'],
    'ai'       => ['label' => 'AI Configuration',  'icon' => 'fa-wand-magic-sparkles'],
    'system'   => ['label' => 'System',            'icon' => 'fa-gear'],
];
?>

<form method="post" action="/admin/settings.php">
    <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">

    <?php foreach ($groupLabels as $groupKey => $groupMeta): ?>
        <?php if (empty($allSettings[$groupKey])) continue; ?>

        <div class="ptmd-panel p-xl mb-4">
            <h2 class="h6 mb-4 d-flex align-items-center gap-2">
                <i class="fa-solid <?php ee($groupMeta['icon']); ?> ptmd-text-teal" style="width:18px"></i>
                <?php ee($groupMeta['label']); ?>
            </h2>
            <div class="row g-3">
                <?php foreach ($allSettings[$groupKey] as $setting): ?>
                    <div class="col-md-6">
                        <label class="form-label" for="s_<?php ee($setting['setting_key']); ?>">
                            <?php ee($setting['label'] ?? $setting['setting_key']); ?>
                        </label>
                        <?php if ($setting['setting_type'] === 'secret'): ?>
                            <!-- Secret fields: show placeholder, not value -->
                            <input
                                class="form-control"
                                id="s_<?php ee($setting['setting_key']); ?>"
                                type="password"
                                name="settings[<?php ee($setting['setting_key']); ?>]"
                                placeholder="<?php echo $setting['setting_value'] ? '••••••••••••' : 'Not set'; ?>"
                                autocomplete="new-password"
                            >
                            <div class="form-text ptmd-muted" style="font-size:var(--text-xs)">
                                Leave blank to keep current value.
                            </div>
                        <?php else: ?>
                            <input
                                class="form-control"
                                id="s_<?php ee($setting['setting_key']); ?>"
                                name="settings[<?php ee($setting['setting_key']); ?>]"
                                value="<?php ee($setting['setting_value'] ?? ''); ?>"
                            >
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    <?php endforeach; ?>

    <div class="d-flex gap-3">
        <button class="btn btn-ptmd-primary" type="submit">
            <i class="fa-solid fa-floppy-disk me-2"></i>Save All Settings
        </button>
    </div>

</form>

<?php include __DIR__ . '/_admin_footer.php'; ?>
