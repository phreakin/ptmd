<?php
/**
 * PTMD Admin — Social Post Queue
 */

$pageTitle    = 'Social Queue | PTMD Admin';
$activePage   = 'posts';
$pageHeading  = 'Social Post Queue';
$pageSubheading = 'Manage and track all scheduled social media posts.';
$pageActions  = '<a href="/admin/monitor.php" class="btn btn-ptmd-outline btn-sm">'
              . '<i class="fa-solid fa-chart-line me-2"></i>Monitor</a>';

include __DIR__ . '/_admin_head.php';

require_once __DIR__ . '/../inc/social_services.php';

$pdo = get_db();

/**
 * Validate and normalize a relative uploads path.
 * Returns empty string when the path is invalid or unsafe.
 */
function safe_upload_rel_path(?string $path): string
{
    $clean = trim((string) $path);
    if ($clean === '' || str_contains($clean, "\0")) {
        return '';
    }

    $clean = ltrim($clean, '/');
    if (str_contains($clean, '\\')) {
        return '';
    }

    $segments = explode('/', $clean);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $segment)) {
            return '';
        }
    }

    return implode('/', $segments);
}

function clip_asset_path(array $clip): string
{
    return (string) ($clip['output_path'] ?? $clip['source_path'] ?? '');
}

if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/posts.php', 'Invalid CSRF token.', 'danger');
    }

    $postAction = $_POST['_action'] ?? 'update_status';

    if ($postAction === 'add') {
        $caseId = (int) ($_POST['case_id'] ?? 0) ?: null;
        $clipId    = (int) ($_POST['clip_id'] ?? 0) ?: null;
        $platform  = trim((string) ($_POST['platform'] ?? ''));
        $contentType = trim((string) ($_POST['content_type'] ?? ''));
        $caption     = trim((string) ($_POST['caption'] ?? ''));
        $assetPath   = trim((string) ($_POST['asset_path'] ?? ''));
        $scheduledFor = trim((string) ($_POST['scheduled_for'] ?? ''));

        // Check posting_sites.is_active for the selected platform
        $siteCheck = $pdo->prepare(
            'SELECT is_active FROM posting_sites WHERE site_key = :key LIMIT 1'
        );
        $siteCheck->execute(['key' => ptmd_platform_to_site_key($platform)]);
        $siteRow = $siteCheck->fetch();
        if ($siteRow !== false && (int) $siteRow['is_active'] !== 1) {
            redirect('/admin/posts.php', 'This platform is currently inactive.', 'warning');
        }

        $prefStmt = $pdo->prepare(
            'SELECT is_enabled, default_content_type, default_caption_prefix, default_hashtags, default_status
             FROM social_platform_preferences
             WHERE platform = :platform
             LIMIT 1'
        );
        $prefStmt->execute(['platform' => $platform]);
        $pref = $prefStmt->fetch();

        if ($pref && (int) $pref['is_enabled'] !== 1) {
            redirect('/admin/posts.php', 'This platform is disabled in posting preferences.', 'warning');
        }

        if ($contentType === '' && !empty($pref['default_content_type'])) {
            $contentType = (string) $pref['default_content_type'];
        }
        if ($caption === '' && !empty($pref['default_caption_prefix'])) {
            $caption = (string) $pref['default_caption_prefix'];
        }
        if (!empty($pref['default_hashtags'])) {
            $caption = trim($caption . ' ' . (string) $pref['default_hashtags']);
        }
        $status = (!empty($pref['default_status']) && in_array($pref['default_status'], ['draft','queued','scheduled'], true))
            ? (string) $pref['default_status']
            : 'queued';

        $pdo->prepare(
            'INSERT INTO social_post_queue (case_id, clip_id, platform, content_type, caption, asset_path, scheduled_for, status, created_at, updated_at)
             VALUES (:eid, :clip_id, :platform, :ct, :caption, :asset, :sched, :status, NOW(), NOW())'
        )->execute([
            'eid'      => $caseId,
            'clip_id'  => $clipId,
            'platform' => $platform,
            'ct'       => $contentType,
            'caption'  => $caption,
            'asset'    => $assetPath,
            'sched'    => $scheduledFor,
            'status'   => $status,
        ]);
        redirect('/admin/posts.php', 'Post queued.', 'success');
    }

    if ($postAction === 'update_status') {
        $queueId   = (int) ($_POST['id']     ?? 0);
        $newStatus = trim((string) ($_POST['status'] ?? ''));
        $allowed   = ['draft','queued','scheduled','posted','failed','canceled'];

        if ($queueId > 0 && in_array($newStatus, $allowed, true)) {
            $pdo->prepare(
                'UPDATE social_post_queue SET status = :status, updated_at = NOW() WHERE id = :id'
            )->execute(['status' => $newStatus, 'id' => $queueId]);
            redirect('/admin/posts.php', 'Status updated.', 'success');
        }
    }

    if ($postAction === 'delete') {
        $delId = (int) ($_POST['id'] ?? 0);
        if ($delId > 0) {
            $pdo->prepare('DELETE FROM social_post_queue WHERE id = :id')->execute(['id' => $delId]);
            redirect('/admin/posts.php', 'Queue item deleted.', 'success');
        }
    }

    if ($postAction === 'save_preferences') {
        foreach ($_POST['prefs'] ?? [] as $platform => $pref) {
            $platformName = trim((string) $platform);
            if ($platformName === '') {
                continue;
            }
            $defaultStatus = trim((string) ($pref['default_status'] ?? 'queued'));
            if (!in_array($defaultStatus, ['draft','queued','scheduled'], true)) {
                $defaultStatus = 'queued';
            }

            $pdo->prepare(
                'INSERT INTO social_platform_preferences
                 (platform, default_content_type, default_caption_prefix, default_hashtags, default_status, is_enabled, created_at, updated_at)
                 VALUES (:platform, :ct, :caption, :hashtags, :status, :enabled, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    default_content_type = VALUES(default_content_type),
                    default_caption_prefix = VALUES(default_caption_prefix),
                    default_hashtags = VALUES(default_hashtags),
                    default_status = VALUES(default_status),
                    is_enabled = VALUES(is_enabled),
                    updated_at = NOW()'
            )->execute([
                'platform' => $platformName,
                'ct'       => trim((string) ($pref['default_content_type'] ?? '')),
                'caption'  => trim((string) ($pref['default_caption_prefix'] ?? '')),
                'hashtags' => trim((string) ($pref['default_hashtags'] ?? '')),
                'status'   => $defaultStatus,
                'enabled'  => !empty($pref['is_enabled']) ? 1 : 0,
            ]);
        }
        redirect('/admin/posts.php', 'Platform preferences saved.', 'success');
    }

    if ($postAction === 'publish_now') {
        $qId = (int) ($_POST['id'] ?? 0);
        if ($qId > 0) {
            $item = $pdo->prepare('SELECT * FROM social_post_queue WHERE id = :id');
            $item->execute(['id' => $qId]);
            $item = $item->fetch();
            if ($item) {
                $result = dispatch_social_post($item);
                $msg = $result['ok'] ? 'Post dispatched successfully.' : 'Dispatch failed: ' . ($result['error'] ?? 'unknown error');
                $type = $result['ok'] ? 'success' : 'warning';
                redirect('/admin/posts.php', $msg, $type);
            }
        }
    }

    if ($postAction === 'remove_site_post') {
        $qId = (int) ($_POST['id'] ?? 0);
        if ($qId > 0) {
            $itemStmt = $pdo->prepare('SELECT * FROM social_post_queue WHERE id = :id LIMIT 1');
            $itemStmt->execute(['id' => $qId]);
            $item = $itemStmt->fetch();
            if ($item) {
                $pdo->prepare(
                    'UPDATE social_post_queue
                     SET status = "canceled",
                         last_error = :note,
                         updated_at = NOW()
                     WHERE id = :id'
                )->execute([
                    'note' => 'Removed by admin at ' . date('Y-m-d H:i:s'),
                    'id'   => $qId,
                ]);

                $pdo->prepare(
                    'INSERT INTO social_post_logs
                     (queue_id, platform, request_payload_json, response_payload_json, status, created_at)
                     VALUES (:qid, :platform, :req, :res, "removed", NOW())'
                )->execute([
                    'qid'      => $qId,
                    'platform' => $item['platform'],
                    'req'      => json_encode(['action' => 'remove_site_post', 'queue_item' => $item], JSON_UNESCAPED_UNICODE),
                    'res'      => json_encode(['ok' => true, 'message' => 'Marked removed by admin'], JSON_UNESCAPED_UNICODE),
                ]);

                redirect('/admin/posts.php', 'Post removed for this platform.', 'success');
            }
        }
    }

    if ($postAction === 'reupload') {
        $qId = (int) ($_POST['id'] ?? 0);
        if ($qId > 0) {
            $itemStmt = $pdo->prepare('SELECT * FROM social_post_queue WHERE id = :id LIMIT 1');
            $itemStmt->execute(['id' => $qId]);
            $item = $itemStmt->fetch();

            if ($item) {
                $pdo->prepare(
                    'INSERT INTO social_post_queue
                     (case_id, clip_id, platform, content_type, caption, asset_path, scheduled_for, status, created_at, updated_at)
                     VALUES (:eid, :clip_id, :platform, :ct, :caption, :asset, NOW(), "queued", NOW(), NOW())'
                )->execute([
                    'eid'      => $item['case_id'] ? (int) $item['case_id'] : null,
                    'clip_id'  => $item['clip_id'] ? (int) $item['clip_id'] : null,
                    'platform' => $item['platform'],
                    'ct'       => $item['content_type'],
                    'caption'  => $item['caption'],
                    'asset'    => $item['asset_path'],
                ]);

                $newId = (int) $pdo->lastInsertId();
                $newStmt = $pdo->prepare('SELECT * FROM social_post_queue WHERE id = :id LIMIT 1');
                $newStmt->execute(['id' => $newId]);
                $newItem = $newStmt->fetch();

                if ($newItem) {
                    $result = dispatch_social_post($newItem);
                    $msg = $result['ok'] ? 'Reupload dispatched successfully.' : 'Reupload failed: ' . ($result['error'] ?? 'unknown error');
                    $type = $result['ok'] ? 'success' : 'warning';
                    redirect('/admin/posts.php', $msg, $type);
                }
            }
        }
    }
}

$queue = $pdo ? $pdo->query(
    'SELECT
         q.id, q.case_id, q.clip_id, q.platform, q.content_type, q.caption, q.asset_path,
         q.scheduled_for, q.status, q.external_post_id, q.last_error, q.created_at, q.updated_at,
         e.title AS case_title,
         vc.label AS clip_label, vc.output_path AS clip_output_path, vc.source_path AS clip_source_path
     FROM social_post_queue q
     LEFT JOIN cases e ON e.id = q.case_id
     LEFT JOIN video_clips vc ON vc.id = q.clip_id
     ORDER BY q.scheduled_for ASC'
)->fetchAll() : [];

$cases  = $pdo ? $pdo->query('SELECT id, title FROM cases ORDER BY title')->fetchAll() : [];
$clips     = $pdo ? $pdo->query('SELECT id, label, output_path, source_path FROM video_clips ORDER BY created_at DESC')->fetchAll() : [];

// Load active platforms from DB; fall back to hardcoded list for graceful degradation
$activeSites = get_posting_sites(true);
$platforms   = $activeSites
    ? array_column($activeSites, 'display_name')
    : ['YouTube', 'YouTube Shorts', 'TikTok', 'Instagram Reels', 'Facebook Reels', 'X'];
$statuses  = ['draft','queued','scheduled','posted','failed','canceled'];
$prefRows  = $pdo ? $pdo->query('SELECT * FROM social_platform_preferences ORDER BY platform')->fetchAll() : [];
$prefMap   = [];
foreach ($prefRows as $row) {
    $prefMap[$row['platform']] = $row;
}
$clipsById = [];
foreach ($clips as $clip) {
    $clipsById[(int) $clip['id']] = $clip;
}

$selectedClipId = (int) ($_GET['clip_id'] ?? 0);
$selectedClip = $selectedClipId > 0 ? ($clipsById[$selectedClipId] ?? null) : null;
$selectedClipAsset = safe_upload_rel_path($selectedClip ? clip_asset_path($selectedClip) : '');

$pageActions = '<a href="/admin/social-schedule.php" class="btn btn-ptmd-outline">
    <i class="fa-solid fa-clock me-2"></i>Manage Schedule
</a>';
?>

<div class="ptmd-screen-queue">
<!-- Platform preferences -->
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-sliders me-2 ptmd-text-teal"></i>Platform Posting Preferences
    </h2>
    <form method="post" action="/admin/posts.php">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action" value="save_preferences">
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>Platform</th>
                        <th>Enabled</th>
                        <th>Default Content Type</th>
                        <th>Default Status</th>
                        <th>Caption Prefix</th>
                        <th>Hashtags</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($platforms as $platform): ?>
                        <?php $pref = $prefMap[$platform] ?? null; ?>
                        <tr>
                            <td class="fw-500"><?php ee($platform); ?></td>
                            <td>
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="prefs[<?php ee($platform); ?>][is_enabled]"
                                    value="1"
                                    <?php echo !$pref || (int) $pref['is_enabled'] === 1 ? 'checked' : ''; ?>
                                >
                            </td>
                            <td>
                                <input
                                    class="form-control form-control-sm"
                                    name="prefs[<?php ee($platform); ?>][default_content_type]"
                                    value="<?php ee($pref['default_content_type'] ?? ''); ?>"
                                    placeholder="teaser, clip, full documentary"
                                >
                            </td>
                            <td>
                                <select class="form-select form-select-sm" name="prefs[<?php ee($platform); ?>][default_status]">
                                    <?php foreach (['draft','queued','scheduled'] as $status): ?>
                                        <option value="<?php ee($status); ?>" <?php echo (($pref['default_status'] ?? 'queued') === $status) ? 'selected' : ''; ?>>
                                            <?php ee(ucfirst($status)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input
                                    class="form-control form-control-sm"
                                    name="prefs[<?php ee($platform); ?>][default_caption_prefix]"
                                    value="<?php ee($pref['default_caption_prefix'] ?? ''); ?>"
                                    placeholder="Default caption text"
                                >
                            </td>
                            <td>
                                <input
                                    class="form-control form-control-sm"
                                    name="prefs[<?php ee($platform); ?>][default_hashtags]"
                                    value="<?php ee($pref['default_hashtags'] ?? ''); ?>"
                                    placeholder="#shorts #ptmd"
                                >
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            <button class="btn btn-ptmd-primary" type="submit">
                <i class="fa-solid fa-floppy-disk me-2"></i>Save Platform Preferences
            </button>
        </div>
    </form>
</div>

<!-- Add to queue form -->
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-calendar-plus me-2 ptmd-text-teal"></i>Add to Queue
    </h2>
    <form method="post" action="/admin/posts.php">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action" value="add">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">case (optional)</label>
                <select class="form-select" name="case_id">
                    <option value="">— None (manual post) —</option>
                    <?php foreach ($cases as $ep): ?>
                        <option value="<?php ee((string) $ep['id']); ?>"><?php ee($ep['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Platform</label>
                <select class="form-select" name="platform" required>
                    <?php foreach ($platforms as $p): ?>
                        <option><?php ee($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Video Clip (optional)</label>
                <select class="form-select" name="clip_id">
                    <option value="">— None —</option>
                    <?php foreach ($clips as $clip): ?>
                        <option value="<?php ee((string) $clip['id']); ?>" <?php echo ($selectedClip && (int) $selectedClip['id'] === (int) $clip['id']) ? 'selected' : ''; ?>>
                            <?php ee($clip['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Content Type</label>
                <input class="form-control" name="content_type" placeholder="teaser, clip…">
            </div>
            <div class="col-md-2">
                <label class="form-label">Scheduled For</label>
                <input class="form-control" type="datetime-local" name="scheduled_for" required>
            </div>
            <div class="col-md-12">
                <label class="form-label">Asset Path (optional)</label>
                <input
                    class="form-control"
                    name="asset_path"
                    value="<?php ee($selectedClipAsset); ?>"
                    placeholder="clips/..., cases/..., or /uploads/..."
                >
            </div>
            <div class="col-12">
                <label class="form-label">Caption</label>
                <textarea class="form-control" name="caption" rows="2"
                    placeholder="Social caption, hashtags, emojis…"></textarea>
            </div>
            <div class="col-12">
                <button class="btn btn-ptmd-primary" type="submit">
                    <i class="fa-solid fa-calendar-plus me-2"></i>Add to Queue
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Queue table -->
<div class="ptmd-panel p-lg">
    <h2 class="h6 mb-4">Queue</h2>
    <?php if ($queue): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr><th>Platform</th><th>Video</th><th>case</th><th>Scheduled</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($queue as $item): ?>
                        <tr>
                            <td class="fw-500"><?php ee($item['platform']); ?></td>
                            <td class="ptmd-muted small">
                                <?php if (!empty($item['clip_id'])): ?>
                                    <?php $safeClipPath = safe_upload_rel_path(clip_asset_path(['output_path' => $item['clip_output_path'], 'source_path' => $item['clip_source_path']])); ?>
                                    <div class="fw-500"><?php ee($item['clip_label'] ?: ('Clip #' . $item['clip_id'])); ?></div>
                                    <?php if ($safeClipPath !== ''): ?>
                                        <a href="/uploads/<?php ee($safeClipPath); ?>" target="_blank" rel="noopener" style="font-size:var(--text-xs)">
                                            Open video
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="ptmd-muted small"><?php ee($item['case_title'] ?? '—'); ?></td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo $item['scheduled_for'] ? e(date('M j, Y g:ia', strtotime($item['scheduled_for']))) : '—'; ?>
                            </td>
                            <td>
                                <form method="post" action="/admin/posts.php" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                    <input type="hidden" name="_action" value="update_status">
                                    <input type="hidden" name="id" value="<?php ee((string) $item['id']); ?>">
                                    <select class="form-select form-select-sm"
                                        name="status"
                                        style="width:auto;display:inline-block"
                                        data-auto-submit>
                                        <?php foreach ($statuses as $s): ?>
                                            <option value="<?php ee($s); ?>"
                                                <?php echo $item['status'] === $s ? 'selected' : ''; ?>>
                                                <?php ee(ucfirst($s)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <form method="post" action="/admin/posts.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                        <input type="hidden" name="_action" value="publish_now">
                                        <input type="hidden" name="id" value="<?php ee((string) $item['id']); ?>">
                                        <button class="btn btn-ptmd-ghost btn-sm" type="submit" data-tippy-content="Publish now (stub)">
                                            <i class="fa-solid fa-rocket"></i>
                                        </button>
                                    </form>
                                    <form method="post" action="/admin/posts.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                        <input type="hidden" name="_action" value="reupload">
                                        <input type="hidden" name="id" value="<?php ee((string) $item['id']); ?>">
                                        <button class="btn btn-ptmd-ghost btn-sm" type="submit" data-tippy-content="Reupload to this platform">
                                            <i class="fa-solid fa-upload"></i>
                                        </button>
                                    </form>
                                    <form method="post" action="/admin/posts.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                        <input type="hidden" name="_action" value="remove_site_post">
                                        <input type="hidden" name="id" value="<?php ee((string) $item['id']); ?>">
                                        <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                            style="color:var(--ptmd-warning)"
                                            data-confirm="Mark this post as removed from this platform?"
                                            data-tippy-content="Remove from this site">
                                            <i class="fa-solid fa-link-slash"></i>
                                        </button>
                                    </form>
                                    <form method="post" action="/admin/posts.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="id" value="<?php ee((string) $item['id']); ?>">
                                        <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                            style="color:var(--ptmd-error)"
                                            data-confirm="Delete this queue item?"
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
        <p class="ptmd-muted small">Queue is empty.</p>
    <?php endif; ?>
</div>
</div>

<?php include __DIR__ . '/_admin_footer.php'; ?>
