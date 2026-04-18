<?php
/**
 * PTMD Admin — Blueprint Library
 *
 * Manage reusable content templates:
 *   • Video Blueprints  (long-form structures: documentary, teaser, reaction…)
 *   • Clip Blueprints   (short-clip formats: 30s teaser, 45s reveal…)
 *   • Posting Blueprints (platform posting templates: TikTok teaser, YT Shorts…)
 */

$pageTitle      = 'Blueprint Library | PTMD Admin';
$activePage     = 'blueprints';
$pageHeading    = 'Blueprint Library';
$pageSubheading = 'Create and manage reusable video, clip, and posting templates.';

include __DIR__ . '/_admin_head.php';

$pdo = get_db();

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
$tabParam    = $_GET['tab']    ?? 'video';
$allowedTabs = ['video', 'clip', 'posting'];
$activeTab   = in_array($tabParam, $allowedTabs, true) ? $tabParam : 'video';

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

// ---------------------------------------------------------------------------
// POST actions
// ---------------------------------------------------------------------------
if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect(route_admin('blueprints'), 'Invalid CSRF token.', 'danger');
    }

    $postAction = $_POST['_action'] ?? '';
    $postTab    = $_POST['_tab']    ?? 'video';

    // ── Video blueprints ───────────────────────────────────────────────────
    if ($postAction === 'save_video') {
        $id              = (int) ($_POST['id'] ?? 0);
        $title           = trim((string) ($_POST['title'] ?? ''));
        $slug            = slugify(trim((string) ($_POST['slug'] ?? '')) ?: $title);
        $bpType          = $_POST['blueprint_type'] ?? 'documentary';
        $allowedTypes    = ['documentary', 'teaser', 'reaction', 'follow_up', 'custom'];
        $bpType          = in_array($bpType, $allowedTypes, true) ? $bpType : 'documentary';
        $status          = in_array($_POST['status'] ?? '', ['active', 'draft', 'archived'], true)
                         ? $_POST['status'] : 'draft';
        $objective       = trim((string) ($_POST['objective']          ?? ''));
        $brandNotes      = trim((string) ($_POST['brand_notes']        ?? ''));
        $targetDuration  = (int) ($_POST['target_duration_sec'] ?? 0) ?: null;
        $adminId         = (current_admin()['id'] ?? null) ? (int) current_admin()['id'] : null;

        if ($title === '') {
            redirect(route_admin('blueprints', ['tab' => 'video']), 'Title is required.', 'warning');
        }

        if ($id > 0) {
            $pdo->prepare(
                'UPDATE video_blueprints
                 SET title = :title, slug = :slug, blueprint_type = :type, status = :status,
                     objective = :obj, brand_notes = :brand, target_duration_sec = :dur,
                     updated_at = NOW()
                 WHERE id = :id'
            )->execute([
                'title' => $title, 'slug' => $slug, 'type' => $bpType, 'status' => $status,
                'obj'   => $objective, 'brand' => $brandNotes, 'dur' => $targetDuration,
                'id'    => $id,
            ]);
            redirect(route_admin('blueprints', ['tab' => 'video']), 'Video blueprint updated.', 'success');
        } else {
            $pdo->prepare(
                'INSERT INTO video_blueprints
                 (title, slug, blueprint_type, status, objective, brand_notes, target_duration_sec, created_by, created_at, updated_at)
                 VALUES (:title, :slug, :type, :status, :obj, :brand, :dur, :by, NOW(), NOW())'
            )->execute([
                'title' => $title, 'slug' => $slug, 'type' => $bpType, 'status' => $status,
                'obj'   => $objective, 'brand' => $brandNotes, 'dur' => $targetDuration,
                'by'    => $adminId,
            ]);
            redirect(route_admin('blueprints', ['tab' => 'video']), 'Video blueprint created.', 'success');
        }
    }

    if ($postAction === 'archive_video') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE video_blueprints SET status = "archived", updated_at = NOW() WHERE id = :id')
                ->execute(['id' => $id]);
            redirect(route_admin('blueprints', ['tab' => 'video']), 'Video blueprint archived.', 'success');
        }
    }

    // ── Clip blueprints ────────────────────────────────────────────────────
    if ($postAction === 'save_clip') {
        $id            = (int) ($_POST['id'] ?? 0);
        $title         = trim((string) ($_POST['title'] ?? ''));
        $slug          = slugify(trim((string) ($_POST['slug'] ?? '')) ?: $title);
        $clipType      = $_POST['clip_type'] ?? 'teaser';
        $allowedCTypes = ['teaser', 'reveal', 'punch', 'humor', 'follow_up', 'custom'];
        $clipType      = in_array($clipType, $allowedCTypes, true) ? $clipType : 'teaser';
        $status        = in_array($_POST['status'] ?? '', ['active', 'draft', 'archived'], true)
                       ? $_POST['status'] : 'draft';
        $duration      = (int) ($_POST['target_duration_sec'] ?? 0) ?: null;
        $aspectRatio   = trim((string) ($_POST['aspect_ratio'] ?? ''));
        $brandNotes    = trim((string) ($_POST['brand_notes']  ?? ''));
        $adminId       = (current_admin()['id'] ?? null) ? (int) current_admin()['id'] : null;

        // Parse platform_targets checkboxes into JSON array
        $platformTargets = array_values(array_filter(
            array_map('trim', (array) ($_POST['platform_targets'] ?? []))
        ));
        $platformJson = !empty($platformTargets) ? json_encode($platformTargets) : null;

        if ($title === '') {
            redirect(route_admin('blueprints', ['tab' => 'clip']), 'Title is required.', 'warning');
        }

        if ($id > 0) {
            $pdo->prepare(
                'UPDATE clip_format_templates
                 SET title = :title, slug = :slug, clip_type = :type, status = :status,
                     target_duration_sec = :dur, aspect_ratio = :ar, brand_notes = :brand,
                     platform_targets = :pt, updated_at = NOW()
                 WHERE id = :id'
            )->execute([
                'title' => $title, 'slug' => $slug, 'type' => $clipType, 'status' => $status,
                'dur'   => $duration, 'ar' => $aspectRatio, 'brand' => $brandNotes,
                'pt'    => $platformJson, 'id' => $id,
            ]);
            redirect(route_admin('blueprints', ['tab' => 'clip']), 'Clip blueprint updated.', 'success');
        } else {
            $pdo->prepare(
                'INSERT INTO clip_format_templates
                 (title, slug, clip_type, status, target_duration_sec, aspect_ratio, brand_notes,
                  platform_targets, created_by, created_at, updated_at)
                 VALUES (:title, :slug, :type, :status, :dur, :ar, :brand, :pt, :by, NOW(), NOW())'
            )->execute([
                'title' => $title, 'slug' => $slug, 'type' => $clipType, 'status' => $status,
                'dur'   => $duration, 'ar' => $aspectRatio, 'brand' => $brandNotes,
                'pt'    => $platformJson, 'by' => $adminId,
            ]);
            redirect(route_admin('blueprints', ['tab' => 'clip']), 'Clip blueprint created.', 'success');
        }
    }

    if ($postAction === 'archive_clip') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE clip_format_templates SET status = "archived", updated_at = NOW() WHERE id = :id')
                ->execute(['id' => $id]);
            redirect(route_admin('blueprints', ['tab' => 'clip']), 'Clip blueprint archived.', 'success');
        }
    }

    // ── Posting blueprints ─────────────────────────────────────────────────
    if ($postAction === 'save_posting') {
        $id              = (int) ($_POST['id'] ?? 0);
        $title           = trim((string) ($_POST['title']            ?? ''));
        $slug            = slugify(trim((string) ($_POST['slug'] ?? '')) ?: $title);
        $siteKey         = trim((string) ($_POST['site_key']         ?? ''));
        $contentType     = trim((string) ($_POST['content_type']     ?? ''));
        $status          = in_array($_POST['status'] ?? '', ['active', 'draft', 'archived'], true)
                         ? $_POST['status'] : 'draft';
        $captionTemplate = trim((string) ($_POST['caption_template'] ?? ''));
        $reqHashtags     = trim((string) ($_POST['required_hashtags'] ?? ''));
        $bannedPhrases   = trim((string) ($_POST['banned_phrases']   ?? ''));
        $ctaPattern      = trim((string) ($_POST['cta_pattern']      ?? ''));
        $adminId         = (current_admin()['id'] ?? null) ? (int) current_admin()['id'] : null;

        if ($title === '' || $siteKey === '') {
            redirect(route_admin('blueprints', ['tab' => 'posting']), 'Title and platform are required.', 'warning');
        }

        // Verify site_key exists
        $siteCheck = $pdo->prepare('SELECT id FROM posting_sites WHERE site_key = :key LIMIT 1');
        $siteCheck->execute(['key' => $siteKey]);
        if (!$siteCheck->fetch()) {
            redirect(route_admin('blueprints', ['tab' => 'posting']), 'Unknown platform site key.', 'warning');
        }

        if ($id > 0) {
            $pdo->prepare(
                'UPDATE posting_blueprints
                 SET title = :title, slug = :slug, site_key = :sk, content_type = :ct, status = :status,
                     caption_template = :cap, required_hashtags = :rh, banned_phrases = :bp,
                     cta_pattern = :cta, updated_at = NOW()
                 WHERE id = :id'
            )->execute([
                'title' => $title, 'slug' => $slug, 'sk' => $siteKey, 'ct' => $contentType,
                'status' => $status, 'cap' => $captionTemplate, 'rh' => $reqHashtags,
                'bp' => $bannedPhrases, 'cta' => $ctaPattern, 'id' => $id,
            ]);
            redirect(route_admin('blueprints', ['tab' => 'posting']), 'Posting blueprint updated.', 'success');
        } else {
            $pdo->prepare(
                'INSERT INTO posting_blueprints
                 (title, slug, site_key, content_type, status, caption_template, required_hashtags,
                  banned_phrases, cta_pattern, created_by, created_at, updated_at)
                 VALUES (:title, :slug, :sk, :ct, :status, :cap, :rh, :bp, :cta, :by, NOW(), NOW())'
            )->execute([
                'title' => $title, 'slug' => $slug, 'sk' => $siteKey, 'ct' => $contentType,
                'status' => $status, 'cap' => $captionTemplate, 'rh' => $reqHashtags,
                'bp' => $bannedPhrases, 'cta' => $ctaPattern, 'by' => $adminId,
            ]);
            redirect(route_admin('blueprints', ['tab' => 'posting']), 'Posting blueprint created.', 'success');
        }
    }

    if ($postAction === 'archive_posting') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE posting_blueprints SET status = "archived", updated_at = NOW() WHERE id = :id')
                ->execute(['id' => $id]);
            redirect(route_admin('blueprints', ['tab' => 'posting']), 'Posting blueprint archived.', 'success');
        }
    }

    // ── Schedule rules ────────────────────────────────────────────────────
    if ($postAction === 'save_rule') {
        $blueprintId = (int) ($_POST['posting_blueprint_id'] ?? 0);
        $siteKey     = trim((string) ($_POST['site_key']     ?? ''));
        $dow         = trim((string) ($_POST['day_of_week']  ?? ''));
        $postTime    = trim((string) ($_POST['post_time']    ?? ''));
        $timezone    = trim((string) ($_POST['timezone']     ?? 'America/Phoenix'));
        $priority    = max(1, min(10, (int) ($_POST['priority']      ?? 5)));
        $minGap      = max(0, (int) ($_POST['min_gap_hours']         ?? 0));
        $maxPerDay   = max(1, (int) ($_POST['max_per_day']           ?? 1));

        $allowedDow = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        if ($blueprintId > 0 && $siteKey !== '' && in_array($dow, $allowedDow, true) && $postTime !== '') {
            $pdo->prepare(
                'INSERT INTO blueprint_schedule_rules
                 (posting_blueprint_id, site_key, day_of_week, post_time, timezone,
                  priority, min_gap_hours, max_per_day, is_active, created_at, updated_at)
                 VALUES (:bid, :sk, :dow, :pt, :tz, :pri, :gap, :mpd, 1, NOW(), NOW())'
            )->execute([
                'bid' => $blueprintId, 'sk' => $siteKey, 'dow' => $dow,
                'pt'  => $postTime, 'tz' => $timezone, 'pri' => $priority,
                'gap' => $minGap, 'mpd' => $maxPerDay,
            ]);
            redirect(route_admin('blueprints', ['tab' => 'posting']), 'Schedule rule added.', 'success');
        }
        redirect(route_admin('blueprints', ['tab' => 'posting']), 'Invalid schedule rule — check all fields.', 'warning');
    }

    if ($postAction === 'delete_rule') {
        $ruleId = (int) ($_POST['id'] ?? 0);
        if ($ruleId > 0) {
            $pdo->prepare('DELETE FROM blueprint_schedule_rules WHERE id = :id')->execute(['id' => $ruleId]);
            redirect(route_admin('blueprints', ['tab' => 'posting']), 'Schedule rule deleted.', 'success');
        }
    }
}

// ---------------------------------------------------------------------------
// Load data for each tab
// ---------------------------------------------------------------------------
$videoBlueprints = $pdo ? $pdo->query(
    'SELECT vb.*, u.username AS created_by_name
     FROM video_blueprints vb
     LEFT JOIN users u ON u.id = vb.created_by
     WHERE vb.status != "archived"
     ORDER BY vb.status = "active" DESC, vb.title'
)->fetchAll() : [];

$clipBlueprints = $pdo ? $pdo->query(
    'SELECT cb.*, u.username AS created_by_name
     FROM clip_format_templates cb
     LEFT JOIN users u ON u.id = cb.created_by
     WHERE cb.status != "archived"
     ORDER BY cb.status = "active" DESC, cb.title'
)->fetchAll() : [];

$postingBlueprints = $pdo ? $pdo->query(
    'SELECT pb.*, ps.display_name AS platform_name, u.username AS created_by_name
     FROM posting_blueprints pb
     JOIN posting_sites ps ON ps.site_key = pb.site_key
     LEFT JOIN users u ON u.id = pb.created_by
     WHERE pb.status != "archived"
     ORDER BY pb.status = "active" DESC, pb.title'
)->fetchAll() : [];

$scheduleRules = $pdo ? $pdo->query(
    'SELECT bsr.*, pb.title AS blueprint_title, ps.display_name AS platform_name
     FROM blueprint_schedule_rules bsr
     JOIN posting_blueprints pb ON pb.id = bsr.posting_blueprint_id
     JOIN posting_sites ps      ON ps.site_key = bsr.site_key
     ORDER BY pb.title, FIELD(bsr.day_of_week,"Sunday","Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"), bsr.post_time'
)->fetchAll() : [];

$activeSites = $pdo ? $pdo->query(
    'SELECT site_key, display_name FROM posting_sites WHERE is_active = 1 ORDER BY sort_order, display_name'
)->fetchAll() : [];

$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

// Pre-load blueprint for edit mode
$editVideoBlueprint   = null;
$editClipBlueprint    = null;
$editPostingBlueprint = null;
if ($editId > 0 && $pdo) {
    if ($activeTab === 'video') {
        $s = $pdo->prepare('SELECT * FROM video_blueprints WHERE id = :id LIMIT 1');
        $s->execute(['id' => $editId]);
        $editVideoBlueprint = $s->fetch() ?: null;
    } elseif ($activeTab === 'clip') {
        $s = $pdo->prepare('SELECT * FROM clip_format_templates WHERE id = :id LIMIT 1');
        $s->execute(['id' => $editId]);
        $editClipBlueprint = $s->fetch() ?: null;
    } elseif ($activeTab === 'posting') {
        $s = $pdo->prepare('SELECT * FROM posting_blueprints WHERE id = :id LIMIT 1');
        $s->execute(['id' => $editId]);
        $editPostingBlueprint = $s->fetch() ?: null;
    }
}

$tabBase = route_admin('blueprints') . '?tab=';
?>

<!-- Tab navigation -->
<ul class="nav nav-tabs mb-4">
    <?php foreach (['video' => 'Video Blueprints', 'clip' => 'Clip Blueprints', 'posting' => 'Posting Blueprints'] as $key => $label): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $activeTab === $key ? 'active' : ''; ?>"
               href="<?php echo $tabBase . $key; ?>">
                <?php ee($label); ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if ($activeTab === 'video'): ?>
<!-- ===================================================================== -->
<!-- VIDEO BLUEPRINTS                                                       -->
<!-- ===================================================================== -->
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-<?php echo $editVideoBlueprint ? 'pencil' : 'plus'; ?> me-2 ptmd-text-teal"></i>
        <?php echo $editVideoBlueprint ? 'Edit Video Blueprint' : 'New Video Blueprint'; ?>
    </h2>
    <form method="post" action="<?php echo $tabBase . 'video'; ?>">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action"    value="save_video">
        <input type="hidden" name="id"         value="<?php ee((string) ($editVideoBlueprint['id'] ?? 0)); ?>">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Title <span class="text-danger">*</span></label>
                <input class="form-control" name="title" required
                    value="<?php ee($editVideoBlueprint['title'] ?? ''); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Slug</label>
                <input class="form-control" name="slug" placeholder="auto-generated"
                    value="<?php ee($editVideoBlueprint['slug'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Type</label>
                <select class="form-select" name="blueprint_type">
                    <?php foreach (['documentary' => 'Documentary', 'teaser' => 'Teaser', 'reaction' => 'Reaction', 'follow_up' => 'Follow-up', 'custom' => 'Custom'] as $v => $l): ?>
                        <option value="<?php ee($v); ?>"
                            <?php echo (($editVideoBlueprint['blueprint_type'] ?? 'documentary') === $v) ? 'selected' : ''; ?>>
                            <?php ee($l); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">Target (sec)</label>
                <input class="form-control" type="number" name="target_duration_sec" min="0"
                    value="<?php ee((string) ($editVideoBlueprint['target_duration_sec'] ?? '')); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <?php foreach (['active' => 'Active', 'draft' => 'Draft'] as $v => $l): ?>
                        <option value="<?php ee($v); ?>"
                            <?php echo (($editVideoBlueprint['status'] ?? 'draft') === $v) ? 'selected' : ''; ?>>
                            <?php ee($l); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Objective</label>
                <input class="form-control" name="objective"
                    placeholder="What should this video accomplish?"
                    value="<?php ee($editVideoBlueprint['objective'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Brand Notes</label>
                <input class="form-control" name="brand_notes"
                    placeholder="Voice, tone, required brand treatment…"
                    value="<?php ee($editVideoBlueprint['brand_notes'] ?? ''); ?>">
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-ptmd-primary" type="submit">
                    <i class="fa-solid fa-floppy-disk me-1"></i>
                    <?php echo $editVideoBlueprint ? 'Update Blueprint' : 'Create Blueprint'; ?>
                </button>
                <?php if ($editVideoBlueprint): ?>
                    <a href="<?php echo $tabBase . 'video'; ?>" class="btn btn-ptmd-ghost">Cancel</a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<div class="ptmd-panel p-lg">
    <h2 class="h6 mb-4">Video Blueprints</h2>
    <?php if ($videoBlueprints): ?>
        <div class="table-responsive">
            <table class="ptmd-table w-100">
                <thead>
                    <tr>
                        <th>Title</th><th>Type</th><th>Target</th>
                        <th>Status</th><th>Objective</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($videoBlueprints as $bp): ?>
                    <tr>
                        <td class="fw-500"><?php ee($bp['title']); ?></td>
                        <td class="ptmd-muted small"><?php ee(str_replace('_', ' ', $bp['blueprint_type'])); ?></td>
                        <td class="ptmd-muted small">
                            <?php echo $bp['target_duration_sec'] ? round($bp['target_duration_sec'] / 60, 1) . ' min' : '—'; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $bp['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php ee(ucfirst($bp['status'])); ?>
                            </span>
                        </td>
                        <td class="ptmd-muted small" style="max-width:280px;white-space:normal">
                            <?php ee(mb_strimwidth((string) ($bp['objective'] ?? ''), 0, 80, '…')); ?>
                        </td>
                        <td>
                            <a href="<?php echo $tabBase . 'video&edit=' . (int) $bp['id']; ?>"
                               class="btn btn-ptmd-ghost btn-sm me-1"
                               data-tippy-content="Edit">
                                <i class="fa-solid fa-pencil"></i>
                            </a>
                            <form method="post" action="<?php echo $tabBase . 'video'; ?>" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                <input type="hidden" name="_action"    value="archive_video">
                                <input type="hidden" name="id"         value="<?php ee((string) $bp['id']); ?>">
                                <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                    style="color:var(--ptmd-error)"
                                    data-confirm="Archive «<?php ee($bp['title']); ?>»?"
                                    data-tippy-content="Archive">
                                    <i class="fa-solid fa-box-archive"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="ptmd-muted small">No video blueprints yet. Create one above or run <code>database/seed.sql</code>.</p>
    <?php endif; ?>
</div>

<?php elseif ($activeTab === 'clip'): ?>
<!-- ===================================================================== -->
<!-- CLIP BLUEPRINTS                                                        -->
<!-- ===================================================================== -->
<?php
$activeSiteKeys = array_column($activeSites, 'site_key');
$editTargets    = [];
if ($editClipBlueprint && !empty($editClipBlueprint['platform_targets'])) {
    $decoded = json_decode((string) $editClipBlueprint['platform_targets'], true);
    $editTargets = is_array($decoded) ? $decoded : [];
}
?>
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-<?php echo $editClipBlueprint ? 'pencil' : 'plus'; ?> me-2 ptmd-text-teal"></i>
        <?php echo $editClipBlueprint ? 'Edit Clip Blueprint' : 'New Clip Blueprint'; ?>
    </h2>
    <form method="post" action="<?php echo $tabBase . 'clip'; ?>">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action"    value="save_clip">
        <input type="hidden" name="id"         value="<?php ee((string) ($editClipBlueprint['id'] ?? 0)); ?>">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Title <span class="text-danger">*</span></label>
                <input class="form-control" name="title" required
                    value="<?php ee($editClipBlueprint['title'] ?? ''); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Slug</label>
                <input class="form-control" name="slug" placeholder="auto-generated"
                    value="<?php ee($editClipBlueprint['slug'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Clip Type</label>
                <select class="form-select" name="clip_type">
                    <?php foreach (['teaser' => 'Teaser', 'reveal' => 'Reveal', 'punch' => 'Punch', 'humor' => 'Humor', 'follow_up' => 'Follow-up', 'custom' => 'Custom'] as $v => $l): ?>
                        <option value="<?php ee($v); ?>"
                            <?php echo (($editClipBlueprint['clip_type'] ?? 'teaser') === $v) ? 'selected' : ''; ?>>
                            <?php ee($l); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">Dur (sec)</label>
                <input class="form-control" type="number" name="target_duration_sec" min="5" max="600"
                    value="<?php ee((string) ($editClipBlueprint['target_duration_sec'] ?? '')); ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label">Aspect</label>
                <input class="form-control" name="aspect_ratio" placeholder="9:16"
                    value="<?php ee($editClipBlueprint['aspect_ratio'] ?? ''); ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <?php foreach (['active' => 'Active', 'draft' => 'Draft'] as $v => $l): ?>
                        <option value="<?php ee($v); ?>"
                            <?php echo (($editClipBlueprint['status'] ?? 'draft') === $v) ? 'selected' : ''; ?>>
                            <?php ee($l); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Brand Notes</label>
                <input class="form-control" name="brand_notes"
                    placeholder="Aspect ratio, watermark position, caption style…"
                    value="<?php ee($editClipBlueprint['brand_notes'] ?? ''); ?>">
            </div>
            <?php if ($activeSites): ?>
            <div class="col-md-6">
                <label class="form-label d-block">Platform Targets</label>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($activeSites as $site): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox"
                                name="platform_targets[]"
                                id="pt_<?php ee($site['site_key']); ?>"
                                value="<?php ee($site['site_key']); ?>"
                                <?php echo in_array($site['site_key'], $editTargets, true) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="pt_<?php ee($site['site_key']); ?>">
                                <?php ee($site['display_name']); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-ptmd-primary" type="submit">
                    <i class="fa-solid fa-floppy-disk me-1"></i>
                    <?php echo $editClipBlueprint ? 'Update Blueprint' : 'Create Blueprint'; ?>
                </button>
                <?php if ($editClipBlueprint): ?>
                    <a href="<?php echo $tabBase . 'clip'; ?>" class="btn btn-ptmd-ghost">Cancel</a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<div class="ptmd-panel p-lg">
    <h2 class="h6 mb-4">Clip Blueprints</h2>
    <?php if ($clipBlueprints): ?>
        <div class="table-responsive">
            <table class="ptmd-table w-100">
                <thead>
                    <tr>
                        <th>Title</th><th>Type</th><th>Duration</th><th>Aspect</th>
                        <th>Platforms</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($clipBlueprints as $bp): ?>
                    <?php
                    $targets = [];
                    if (!empty($bp['platform_targets'])) {
                        $decoded = json_decode((string) $bp['platform_targets'], true);
                        $targets = is_array($decoded) ? $decoded : [];
                    }
                    ?>
                    <tr>
                        <td class="fw-500"><?php ee($bp['title']); ?></td>
                        <td class="ptmd-muted small"><?php ee(str_replace('_', ' ', $bp['clip_type'])); ?></td>
                        <td class="ptmd-muted small">
                            <?php echo $bp['target_duration_sec'] ? $bp['target_duration_sec'] . 's' : '—'; ?>
                        </td>
                        <td class="ptmd-muted small"><?php ee($bp['aspect_ratio'] ?? '—'); ?></td>
                        <td class="ptmd-muted small" style="max-width:180px;white-space:normal">
                            <?php echo $targets ? e(implode(', ', $targets)) : '—'; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $bp['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php ee(ucfirst($bp['status'])); ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?php echo $tabBase . 'clip&edit=' . (int) $bp['id']; ?>"
                               class="btn btn-ptmd-ghost btn-sm me-1" data-tippy-content="Edit">
                                <i class="fa-solid fa-pencil"></i>
                            </a>
                            <form method="post" action="<?php echo $tabBase . 'clip'; ?>" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                <input type="hidden" name="_action"    value="archive_clip">
                                <input type="hidden" name="id"         value="<?php ee((string) $bp['id']); ?>">
                                <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                    style="color:var(--ptmd-error)"
                                    data-confirm="Archive «<?php ee($bp['title']); ?>»?"
                                    data-tippy-content="Archive">
                                    <i class="fa-solid fa-box-archive"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="ptmd-muted small">No clip blueprints yet. Create one above or run <code>database/seed.sql</code>.</p>
    <?php endif; ?>
</div>

<?php elseif ($activeTab === 'posting'): ?>
<!-- ===================================================================== -->
<!-- POSTING BLUEPRINTS + SCHEDULE RULES                                   -->
<!-- ===================================================================== -->
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-<?php echo $editPostingBlueprint ? 'pencil' : 'plus'; ?> me-2 ptmd-text-teal"></i>
        <?php echo $editPostingBlueprint ? 'Edit Posting Blueprint' : 'New Posting Blueprint'; ?>
    </h2>
    <form method="post" action="<?php echo $tabBase . 'posting'; ?>">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action"    value="save_posting">
        <input type="hidden" name="id"         value="<?php ee((string) ($editPostingBlueprint['id'] ?? 0)); ?>">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Title <span class="text-danger">*</span></label>
                <input class="form-control" name="title" required
                    value="<?php ee($editPostingBlueprint['title'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Platform <span class="text-danger">*</span></label>
                <select class="form-select" name="site_key" required>
                    <option value="">— Select —</option>
                    <?php foreach ($activeSites as $site): ?>
                        <option value="<?php ee($site['site_key']); ?>"
                            <?php echo (($editPostingBlueprint['site_key'] ?? '') === $site['site_key']) ? 'selected' : ''; ?>>
                            <?php ee($site['display_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Content Type</label>
                <input class="form-control" name="content_type" placeholder="teaser, full_doc…"
                    value="<?php ee($editPostingBlueprint['content_type'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <?php foreach (['active' => 'Active', 'draft' => 'Draft'] as $v => $l): ?>
                        <option value="<?php ee($v); ?>"
                            <?php echo (($editPostingBlueprint['status'] ?? 'draft') === $v) ? 'selected' : ''; ?>>
                            <?php ee($l); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Slug</label>
                <input class="form-control" name="slug" placeholder="auto-generated"
                    value="<?php ee($editPostingBlueprint['slug'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Caption Template</label>
                <textarea class="form-control form-control-sm" name="caption_template" rows="3"
                    placeholder="{title}&#10;&#10;{hashtags}"><?php ee($editPostingBlueprint['caption_template'] ?? ''); ?></textarea>
                <div class="form-text">Tokens: <code>{title}</code> <code>{hashtags}</code> <code>{cta}</code> <code>{body}</code></div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Required Hashtags</label>
                <input class="form-control" name="required_hashtags"
                    placeholder="#ptmd #investigation"
                    value="<?php ee($editPostingBlueprint['required_hashtags'] ?? ''); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">CTA Pattern</label>
                <input class="form-control" name="cta_pattern"
                    placeholder="Subscribe + link in bio"
                    value="<?php ee($editPostingBlueprint['cta_pattern'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Banned Phrases</label>
                <input class="form-control" name="banned_phrases"
                    placeholder="Comma-separated"
                    value="<?php ee($editPostingBlueprint['banned_phrases'] ?? ''); ?>">
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-ptmd-primary" type="submit">
                    <i class="fa-solid fa-floppy-disk me-1"></i>
                    <?php echo $editPostingBlueprint ? 'Update Blueprint' : 'Create Blueprint'; ?>
                </button>
                <?php if ($editPostingBlueprint): ?>
                    <a href="<?php echo $tabBase . 'posting'; ?>" class="btn btn-ptmd-ghost">Cancel</a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- Posting blueprints list -->
<div class="ptmd-panel p-lg mb-4">
    <h2 class="h6 mb-4">Posting Blueprints</h2>
    <?php if ($postingBlueprints): ?>
        <div class="table-responsive">
            <table class="ptmd-table w-100">
                <thead>
                    <tr>
                        <th>Title</th><th>Platform</th><th>Content Type</th>
                        <th>Status</th><th>CTA</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($postingBlueprints as $bp): ?>
                    <tr>
                        <td class="fw-500"><?php ee($bp['title']); ?></td>
                        <td class="ptmd-muted small"><?php ee($bp['platform_name']); ?></td>
                        <td class="ptmd-muted small"><?php ee($bp['content_type']); ?></td>
                        <td>
                            <span class="badge <?php echo $bp['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php ee(ucfirst($bp['status'])); ?>
                            </span>
                        </td>
                        <td class="ptmd-muted small"><?php ee($bp['cta_pattern'] ?? '—'); ?></td>
                        <td>
                            <a href="<?php echo $tabBase . 'posting&edit=' . (int) $bp['id']; ?>"
                               class="btn btn-ptmd-ghost btn-sm me-1" data-tippy-content="Edit">
                                <i class="fa-solid fa-pencil"></i>
                            </a>
                            <form method="post" action="<?php echo $tabBase . 'posting'; ?>" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                <input type="hidden" name="_action"    value="archive_posting">
                                <input type="hidden" name="id"         value="<?php ee((string) $bp['id']); ?>">
                                <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                    style="color:var(--ptmd-error)"
                                    data-confirm="Archive «<?php ee($bp['title']); ?>»?"
                                    data-tippy-content="Archive">
                                    <i class="fa-solid fa-box-archive"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="ptmd-muted small">No posting blueprints yet. Create one above or run <code>database/seed.sql</code>.</p>
    <?php endif; ?>
</div>

<!-- Schedule rules -->
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-clock me-2 ptmd-text-teal"></i>Add Schedule Rule
    </h2>
    <form method="post" action="<?php echo $tabBase . 'posting'; ?>">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action"    value="save_rule">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Posting Blueprint <span class="text-danger">*</span></label>
                <select class="form-select" name="posting_blueprint_id" required>
                    <option value="">— Select —</option>
                    <?php foreach ($postingBlueprints as $bp): ?>
                        <option value="<?php ee((string) $bp['id']); ?>">
                            <?php ee($bp['title']); ?> (<?php ee($bp['platform_name']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Platform <span class="text-danger">*</span></label>
                <select class="form-select" name="site_key" required>
                    <option value="">— Select —</option>
                    <?php foreach ($activeSites as $site): ?>
                        <option value="<?php ee($site['site_key']); ?>"><?php ee($site['display_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Day</label>
                <select class="form-select" name="day_of_week" required>
                    <?php foreach ($days as $d): ?>
                        <option><?php ee($d); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">Time</label>
                <input class="form-control" type="time" name="post_time" required>
            </div>
            <div class="col-md-1">
                <label class="form-label">Priority</label>
                <input class="form-control" type="number" name="priority" min="1" max="10" value="5">
            </div>
            <div class="col-md-1">
                <label class="form-label">Min Gap (h)</label>
                <input class="form-control" type="number" name="min_gap_hours" min="0" max="168" value="4">
            </div>
            <div class="col-md-1">
                <label class="form-label">Max/Day</label>
                <input class="form-control" type="number" name="max_per_day" min="1" max="10" value="1">
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button class="btn btn-ptmd-primary w-100" type="submit">
                    <i class="fa-solid fa-plus"></i>
                </button>
            </div>
        </div>
    </form>
</div>

<div class="ptmd-panel p-lg">
    <h2 class="h6 mb-4">Schedule Rules</h2>
    <?php if ($scheduleRules): ?>
        <div class="table-responsive">
            <table class="ptmd-table w-100">
                <thead>
                    <tr>
                        <th>Blueprint</th><th>Platform</th><th>Day</th><th>Time</th>
                        <th>TZ</th><th>Priority</th><th>Min Gap</th><th>Max/Day</th><th>Active</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($scheduleRules as $r): ?>
                    <tr>
                        <td class="fw-500 small"><?php ee($r['blueprint_title']); ?></td>
                        <td class="ptmd-muted small"><?php ee($r['platform_name']); ?></td>
                        <td><?php ee($r['day_of_week']); ?></td>
                        <td class="ptmd-text-teal"><?php echo e(date('g:ia', strtotime($r['post_time']))); ?></td>
                        <td class="ptmd-muted" style="font-size:var(--text-xs)"><?php ee($r['timezone']); ?></td>
                        <td class="ptmd-muted small"><?php ee((string) $r['priority']); ?></td>
                        <td class="ptmd-muted small"><?php echo (int) $r['min_gap_hours']; ?>h</td>
                        <td class="ptmd-muted small"><?php ee((string) $r['max_per_day']); ?></td>
                        <td>
                            <span class="badge <?php echo $r['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php echo $r['is_active'] ? 'Yes' : 'No'; ?>
                            </span>
                        </td>
                        <td>
                            <form method="post" action="<?php echo $tabBase . 'posting'; ?>" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                <input type="hidden" name="_action"    value="delete_rule">
                                <input type="hidden" name="id"         value="<?php ee((string) $r['id']); ?>">
                                <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                    style="color:var(--ptmd-error)"
                                    data-confirm="Delete this schedule rule?"
                                    data-tippy-content="Delete">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="ptmd-muted small">No schedule rules yet. Add one above or run <code>database/seed.sql</code>.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_admin_footer.php'; ?>
