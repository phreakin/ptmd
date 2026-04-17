<?php
/**
 * PTMD Admin — Posting Sites
 *
 * CRUD management for the canonical list of social media posting targets
 * (posting_sites) and their per-site default options (site_posting_options).
 */

$pageTitle      = 'Posting Sites | PTMD Admin';
$activePage     = 'posting-sites';
$pageHeading    = 'Posting Sites';
$pageSubheading = 'Manage active social media platforms and their default posting options.';

include __DIR__ . '/_admin_head.php';

$pdo = get_db();

// ---------------------------------------------------------------------------
// POST actions
// ---------------------------------------------------------------------------
if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/posting-sites.php', 'Invalid CSRF token.', 'danger');
    }

    $postAction = $_POST['_action'] ?? '';

    if ($postAction === 'add') {
        $rawKey      = trim((string) ($_POST['site_key']     ?? ''));
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $sortOrder   = (int) ($_POST['sort_order'] ?? 0);

        // Normalise site_key: lowercase alphanumeric + underscores only
        $siteKey = preg_replace('/[^a-z0-9_]/', '_', strtolower($rawKey));
        $siteKey = preg_replace('/_+/', '_', trim($siteKey, '_'));

        if ($siteKey !== '' && $displayName !== '') {
            $pdo->prepare(
                'INSERT INTO posting_sites (site_key, display_name, is_active, sort_order, created_at, updated_at)
                 VALUES (:key, :name, 1, :order, NOW(), NOW())'
            )->execute(['key' => $siteKey, 'name' => $displayName, 'order' => $sortOrder]);
            redirect('/admin/posting-sites.php', 'Site added.', 'success');
        }
        redirect('/admin/posting-sites.php', 'Site key and display name are required.', 'warning');
    }

    if ($postAction === 'toggle') {
        $siteId   = (int) ($_POST['id']        ?? 0);
        $isActive = (int) ($_POST['is_active'] ?? 0);
        if ($siteId > 0) {
            $pdo->prepare('UPDATE posting_sites SET is_active = :a, updated_at = NOW() WHERE id = :id')
                ->execute(['a' => $isActive ? 0 : 1, 'id' => $siteId]);
            redirect('/admin/posting-sites.php', 'Site updated.', 'success');
        }
    }

    if ($postAction === 'update_order') {
        $siteId    = (int) ($_POST['id']         ?? 0);
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        if ($siteId > 0) {
            $pdo->prepare('UPDATE posting_sites SET sort_order = :order, updated_at = NOW() WHERE id = :id')
                ->execute(['order' => $sortOrder, 'id' => $siteId]);
            redirect('/admin/posting-sites.php', 'Sort order updated.', 'success');
        }
    }

    if ($postAction === 'delete') {
        $siteId = (int) ($_POST['id'] ?? 0);
        if ($siteId > 0) {
            $pdo->prepare('DELETE FROM posting_sites WHERE id = :id')->execute(['id' => $siteId]);
            redirect('/admin/posting-sites.php', 'Site deleted.', 'success');
        }
    }

    if ($postAction === 'save_options') {
        $siteId        = (int) ($_POST['site_id']               ?? 0);
        $contentType   = trim((string) ($_POST['default_content_type']   ?? ''));
        $captionPrefix = trim((string) ($_POST['default_caption_prefix'] ?? ''));
        $hashtags      = trim((string) ($_POST['default_hashtags']       ?? ''));
        $defaultStatus = trim((string) ($_POST['default_status']         ?? 'queued'));

        if (!in_array($defaultStatus, ['draft', 'queued', 'scheduled'], true)) {
            $defaultStatus = 'queued';
        }

        if ($siteId > 0) {
            $pdo->prepare(
                'INSERT INTO site_posting_options
                 (site_id, default_content_type, default_caption_prefix, default_hashtags, default_status, created_at, updated_at)
                 VALUES (:sid, :ct, :caption, :hashtags, :status, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    default_content_type   = VALUES(default_content_type),
                    default_caption_prefix = VALUES(default_caption_prefix),
                    default_hashtags       = VALUES(default_hashtags),
                    default_status         = VALUES(default_status),
                    updated_at             = NOW()'
            )->execute([
                'sid'     => $siteId,
                'ct'      => $contentType,
                'caption' => $captionPrefix,
                'hashtags'=> $hashtags,
                'status'  => $defaultStatus,
            ]);
            redirect('/admin/posting-sites.php', 'Posting options saved.', 'success');
        }
    }
}

// ---------------------------------------------------------------------------
// Load all sites joined with their posting options
// ---------------------------------------------------------------------------
$sites = $pdo ? $pdo->query(
    'SELECT ps.id, ps.site_key, ps.display_name, ps.is_active, ps.sort_order,
            spo.id         AS opt_id,
            spo.default_content_type,
            spo.default_caption_prefix,
            spo.default_hashtags,
            spo.default_status
     FROM posting_sites ps
     LEFT JOIN site_posting_options spo ON spo.site_id = ps.id
     ORDER BY ps.sort_order, ps.display_name'
)->fetchAll() : [];
?>

<!-- Add new site -->
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-plus me-2 ptmd-text-teal"></i>Add Posting Site
    </h2>
    <form method="post" action="/admin/posting-sites.php">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action" value="add">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Site Key <span class="ptmd-muted small">(stable slug)</span></label>
                <input class="form-control" name="site_key" required placeholder="youtube_shorts">
            </div>
            <div class="col-md-4">
                <label class="form-label">Display Name</label>
                <input class="form-control" name="display_name" required placeholder="YouTube Shorts">
            </div>
            <div class="col-md-2">
                <label class="form-label">Sort Order</label>
                <input class="form-control" type="number" name="sort_order" value="0" min="0">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button class="btn btn-ptmd-primary w-100" type="submit">
                    <i class="fa-solid fa-plus me-1"></i>Add Site
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Site list with inline option editing -->
<div class="ptmd-panel p-lg">
    <h2 class="h6 mb-4">Configured Sites</h2>
    <?php if ($sites): ?>
        <?php foreach ($sites as $site): ?>
            <div class="ptmd-panel p-md mb-3" style="border:1px solid var(--ptmd-border)">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                    <div class="d-flex align-items-center gap-3">
                        <span class="fw-700"><?php ee($site['display_name']); ?></span>
                        <code class="small ptmd-muted"><?php ee($site['site_key']); ?></code>
                        <span class="badge <?php echo $site['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                            <?php echo $site['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                    <div class="d-flex gap-2">
                        <!-- Toggle active -->
                        <form method="post" action="/admin/posting-sites.php" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                            <input type="hidden" name="_action" value="toggle">
                            <input type="hidden" name="id" value="<?php ee((string) $site['id']); ?>">
                            <input type="hidden" name="is_active" value="<?php ee((string) $site['is_active']); ?>">
                            <button class="btn btn-sm <?php echo $site['is_active'] ? 'btn-ptmd-teal' : 'btn-ptmd-outline'; ?>" type="submit"
                                data-tippy-content="<?php echo $site['is_active'] ? 'Disable' : 'Enable'; ?>">
                                <i class="fa-solid <?php echo $site['is_active'] ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i>
                            </button>
                        </form>
                        <!-- Delete -->
                        <form method="post" action="/admin/posting-sites.php" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                            <input type="hidden" name="_action" value="delete">
                            <input type="hidden" name="id" value="<?php ee((string) $site['id']); ?>">
                            <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                style="color:var(--ptmd-error)"
                                data-confirm="Delete <?php ee($site['display_name']); ?>? This cannot be undone."
                                data-tippy-content="Delete site">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Sort order + posting options inline form -->
                <form method="post" action="/admin/posting-sites.php">
                    <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                    <input type="hidden" name="_action" value="save_options">
                    <input type="hidden" name="site_id" value="<?php ee((string) $site['id']); ?>">
                    <div class="row g-2">
                        <div class="col-md-2">
                            <label class="form-label small">Sort Order</label>
                            <div class="input-group input-group-sm">
                                <input class="form-control form-control-sm" type="number" name="_sort_order_display"
                                    value="<?php ee((string) $site['sort_order']); ?>" min="0" disabled>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Default Content Type</label>
                            <input class="form-control form-control-sm" name="default_content_type"
                                value="<?php ee($site['default_content_type'] ?? ''); ?>"
                                placeholder="teaser, clip…">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Default Status</label>
                            <select class="form-select form-select-sm" name="default_status">
                                <?php foreach (['draft','queued','scheduled'] as $s): ?>
                                    <option value="<?php ee($s); ?>"
                                        <?php echo (($site['default_status'] ?? 'queued') === $s) ? 'selected' : ''; ?>>
                                        <?php ee(ucfirst($s)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Caption Prefix</label>
                            <input class="form-control form-control-sm" name="default_caption_prefix"
                                value="<?php ee($site['default_caption_prefix'] ?? ''); ?>"
                                placeholder="Default caption text">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Hashtags</label>
                            <input class="form-control form-control-sm" name="default_hashtags"
                                value="<?php ee($site['default_hashtags'] ?? ''); ?>"
                                placeholder="#shorts #ptmd">
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button class="btn btn-ptmd-primary btn-sm w-100" type="submit"
                                data-tippy-content="Save options for <?php ee($site['display_name']); ?>">
                                <i class="fa-solid fa-floppy-disk"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="ptmd-muted small">
            No posting sites found. Run <code>database/seed.sql</code> to load the defaults,
            or add a site using the form above.
        </p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_admin_footer.php'; ?>
