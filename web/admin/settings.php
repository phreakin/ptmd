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

$watermarkAssets = [];
if ($pdo) {
    $watermarkAssets = $pdo->query(
        'SELECT file_path, filename FROM media_library WHERE category = "watermark" ORDER BY created_at DESC'
    )->fetchAll();
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
                        <?php if ($setting['setting_key'] === 'watermark_asset_path'): ?>
                            <select
                                class="form-select"
                                id="s_<?php ee($setting['setting_key']); ?>"
                                name="settings[<?php ee($setting['setting_key']); ?>]"
                            >
                                <option value="<?php ee($setting['setting_value'] ?? ''); ?>">
                                    <?php ee(($setting['setting_value'] ?? '') !== '' ? 'Current: ' . $setting['setting_value'] : 'Select watermark'); ?>
                                </option>
                                <?php foreach ($watermarkAssets as $wm): ?>
                                    <?php $value = '/uploads/' . ltrim((string) $wm['file_path'], '/'); ?>
                                    <option value="<?php ee($value); ?>" <?php echo (($setting['setting_value'] ?? '') === $value) ? 'selected' : ''; ?>>
                                        <?php ee($wm['filename']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text ptmd-muted" style="font-size:var(--text-xs)">
                                Upload watermark files in <a href="/admin/media.php?category=watermark">Media Library → Watermark</a>.
                            </div>
                        <?php elseif ($setting['setting_key'] === 'watermark_auto_apply'): ?>
                            <input type="hidden" name="settings[watermark_auto_apply]" value="0">
                            <div class="form-check">
                                <input
                                    class="form-check-input"
                                    id="s_<?php ee($setting['setting_key']); ?>"
                                    type="checkbox"
                                    name="settings[<?php ee($setting['setting_key']); ?>]"
                                    value="1"
                                    <?php echo in_array(strtolower((string) ($setting['setting_value'] ?? '')), ['1', 'true', 'yes', 'on'], true) ? 'checked' : ''; ?>
                                >
                                <label class="form-check-label" for="s_<?php ee($setting['setting_key']); ?>">
                                    Automatically apply watermark to generated clips/videos
                                </label>
                            </div>
                        <?php elseif ($setting['setting_type'] === 'secret'): ?>
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
