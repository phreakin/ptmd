<?php
/**
 * PTMD Admin — Episodes CRUD
 */

$pageTitle   = 'Episodes | PTMD Admin';
$activePage  = 'episodes';
$pageHeading = 'Episodes';

$pdo    = get_db();
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$action = $_GET['action'] ?? ($editId > 0 ? 'edit' : 'list');

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($pdo && is_post()) {
    require_once __DIR__ . '/../inc/bootstrap.php';

    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/episodes.php', 'Invalid CSRF token.', 'danger');
    }

    $postAction = $_POST['_action'] ?? 'save';

    if ($postAction === 'delete') {
        $delId = (int) ($_POST['id'] ?? 0);
        if ($delId > 0) {
            $pdo->prepare('DELETE FROM episodes WHERE id = :id')->execute(['id' => $delId]);
            redirect('/admin/episodes.php', 'Episode deleted.', 'success');
        }
    }

    // ── Trigger: add ─────────────────────────────────────────────────────────
    if ($postAction === 'save_trigger') {
        $epId         = (int) ($_POST['episode_id']      ?? 0);
        $triggerLabel = trim((string) ($_POST['trigger_label']  ?? ''));
        $tsIn         = max(0.0, (float) ($_POST['timestamp_in']  ?? 0));
        $tsOut        = max(0.0, (float) ($_POST['timestamp_out'] ?? 0));
        $ovPath       = trim((string) ($_POST['overlay_path']   ?? ''));
        $pos          = in_array($_POST['position'] ?? '', ['top-left','top-right','bottom-left','bottom-right','center','full'], true)
                          ? $_POST['position'] : 'bottom-right';
        $opacity      = max(0.0, min(1.0, (float) ($_POST['opacity'] ?? 1.0)));
        $scale        = max(5, min(100, (int) ($_POST['scale'] ?? 30)));
        $anim         = in_array($_POST['animation_style'] ?? '', ['none','fade','slide-up','slide-down'], true)
                          ? $_POST['animation_style'] : 'none';

        if ($epId > 0 && $ovPath !== '' && $tsOut > $tsIn) {
            $pdo->prepare(
                'INSERT INTO episode_overlay_triggers
                 (episode_id, label, timestamp_in, timestamp_out, overlay_path, position, opacity, scale, animation_style, sort_order, created_at, updated_at)
                 VALUES (:eid, :label, :tin, :tout, :path, :pos, :opacity, :scale, :anim,
                         (SELECT COALESCE(MAX(t.sort_order),0)+1 FROM episode_overlay_triggers t WHERE t.episode_id = :eid2),
                         NOW(), NOW())'
            )->execute([
                'eid'   => $epId,
                'label' => $triggerLabel,
                'tin'   => $tsIn,
                'tout'  => $tsOut,
                'path'  => $ovPath,
                'pos'   => $pos,
                'opacity' => number_format($opacity, 2, '.', ''),
                'scale' => $scale,
                'anim'  => $anim,
                'eid2'  => $epId,
            ]);
        }
        redirect('/admin/episodes.php?edit=' . $epId . '#triggers', 'Trigger added.', 'success');
    }

    // ── Trigger: delete ───────────────────────────────────────────────────────
    if ($postAction === 'delete_trigger') {
        $epId      = (int) ($_POST['episode_id'] ?? 0);
        $triggerId = (int) ($_POST['trigger_id'] ?? 0);
        if ($epId > 0 && $triggerId > 0) {
            $pdo->prepare('DELETE FROM episode_overlay_triggers WHERE id = :id AND episode_id = :eid')
                ->execute(['id' => $triggerId, 'eid' => $epId]);
        }
        redirect('/admin/episodes.php?edit=' . $epId . '#triggers', 'Trigger removed.', 'success');
    }

    // ── Generate social queue from schedules ──────────────────────────────────
    if ($postAction === 'generate_queue') {
        $epId = (int) ($_POST['episode_id'] ?? 0);
        if ($epId > 0) {
            $epRow = $pdo->prepare('SELECT * FROM episodes WHERE id = :id');
            $epRow->execute(['id' => $epId]);
            $epRow = $epRow->fetch();

            if ($epRow) {
                $schedules = $pdo->query(
                    'SELECT * FROM social_post_schedules WHERE is_active = 1'
                )->fetchAll();

                $refDate = !empty($epRow['published_at'])
                    ? new DateTimeImmutable($epRow['published_at'])
                    : new DateTimeImmutable();

                $dayOrder = ['Sunday'=>0,'Monday'=>1,'Tuesday'=>2,'Wednesday'=>3,'Thursday'=>4,'Friday'=>5,'Saturday'=>6];
                $insertStmt = $pdo->prepare(
                    'INSERT INTO social_post_queue
                     (episode_id, platform, content_type, caption, asset_path, scheduled_for, status, created_at, updated_at)
                     VALUES (:eid, :platform, :ct, :caption, :asset, :sched, "queued", NOW(), NOW())'
                );
                $count = 0;
                foreach ($schedules as $s) {
                    $targetDay = $dayOrder[$s['day_of_week']] ?? 0;
                    $currentDay = (int) $refDate->format('w');
                    $diff = ($targetDay - $currentDay + 7) % 7;
                    if ($diff === 0) $diff = 7;
                    $schedDate = $refDate->modify("+{$diff} days")->format('Y-m-d')
                                 . ' ' . $s['post_time'];
                    $insertStmt->execute([
                        'eid'     => $epId,
                        'platform' => $s['platform'],
                        'ct'      => $s['content_type'],
                        'caption' => '',
                        'asset'   => '',
                        'sched'   => $schedDate,
                    ]);
                    $count++;
                }
                redirect('/admin/episodes.php?edit=' . $epId, "Generated {$count} social queue entries.", 'success');
            }
        }
        redirect('/admin/episodes.php', 'Episode not found.', 'danger');
    }

    // Save / Update
    $id        = (int) ($_POST['id'] ?? 0);
    $title     = trim((string) ($_POST['title']     ?? ''));
    $slug      = slugify(trim((string) ($_POST['slug'] ?? '')) ?: $title);
    $excerpt   = trim((string) ($_POST['excerpt']   ?? ''));
    $body      = trim((string) ($_POST['body']      ?? ''));
    $videoUrl  = trim((string) ($_POST['video_url'] ?? ''));
    $duration  = trim((string) ($_POST['duration']  ?? ''));
    $introPath = trim((string) ($_POST['intro_asset_path'] ?? ''));
    $outroPath = trim((string) ($_POST['outro_asset_path'] ?? ''));
    $keywordsRaw = trim((string) ($_POST['keywords'] ?? ''));
    $status    = in_array($_POST['status'] ?? '', ['draft','published','archived']) ? $_POST['status'] : 'draft';
    $publishedAt = ($status === 'published')
        ? (trim((string) ($_POST['published_at'] ?? '')) ?: date('Y-m-d H:i:s'))
        : null;

    $keywords = [];
    if ($keywordsRaw !== '') {
        $parts = preg_split('/[\r\n,]+/', $keywordsRaw) ?: [];
        $seen = [];
        foreach ($parts as $part) {
            $keyword = trim($part);
            if ($keyword === '') {
                continue;
            }
            $keyword = mb_substr($keyword, 0, 120);
            $key = mb_strtolower($keyword);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $keywords[] = $keyword;
        }
    }

    // Handle thumbnail upload
    $thumbPath = trim((string) ($_POST['thumbnail_image'] ?? ''));
    if (!empty($_FILES['thumbnail_file']['name'])) {
        $saved = save_upload(
            $_FILES['thumbnail_file'],
            'thumbnails',
            $GLOBALS['config']['allowed_image_ext']
        );
        if ($saved) {
            $thumbPath = $saved;
        }
    }

    // Handle video file upload
    $videoFilePath = trim((string) ($_POST['video_file_path'] ?? ''));
    if (!empty($_FILES['video_file']['name'])) {
        $savedVid = save_upload(
            $_FILES['video_file'],
            'episodes',
            $GLOBALS['config']['allowed_video_ext']
        );
        if ($savedVid) {
            $videoFilePath = $savedVid;
        }
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE episodes SET title=:title, slug=:slug, excerpt=:excerpt, body=:body,
             thumbnail_image=:thumb, video_url=:video_url, video_file_path=:vfp,
             intro_asset_path=:intro, outro_asset_path=:outro,
             duration=:duration, status=:status, published_at=:pub,
             updated_at=NOW() WHERE id=:id'
        );
        $stmt->execute([
            'title'=>$title, 'slug'=>$slug, 'excerpt'=>$excerpt, 'body'=>$body,
            'thumb'=>$thumbPath, 'video_url'=>$videoUrl, 'vfp'=>$videoFilePath,
            'intro'=>$introPath, 'outro'=>$outroPath,
            'duration'=>$duration, 'status'=>$status, 'pub'=>$publishedAt, 'id'=>$id,
        ]);
        $episodeId = $id;
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO episodes (title, slug, excerpt, body, thumbnail_image, video_url,
             video_file_path, intro_asset_path, outro_asset_path, duration, status, published_at, created_at, updated_at)
             VALUES (:title,:slug,:excerpt,:body,:thumb,:video_url,:vfp,:intro,:outro,:duration,:status,:pub,NOW(),NOW())'
        );
        $stmt->execute([
            'title'=>$title, 'slug'=>$slug, 'excerpt'=>$excerpt, 'body'=>$body,
            'thumb'=>$thumbPath, 'video_url'=>$videoUrl, 'vfp'=>$videoFilePath,
            'intro'=>$introPath, 'outro'=>$outroPath,
            'duration'=>$duration, 'status'=>$status, 'pub'=>$publishedAt,
        ]);
        $episodeId = (int) $pdo->lastInsertId();
    }

    if ($episodeId > 0) {
        $pdo->prepare('DELETE FROM episode_tag_map WHERE episode_id = :episode_id')->execute([
            'episode_id' => $episodeId,
        ]);

        foreach ($keywords as $keyword) {
            $tagSlug = slugify($keyword);
            if ($tagSlug === '') {
                continue;
            }

            $pdo->prepare(
                'INSERT IGNORE INTO episode_tags (name, slug, created_at, updated_at)
                 VALUES (:name, :slug, NOW(), NOW())'
            )->execute([
                'name' => $keyword,
                'slug' => $tagSlug,
            ]);

            $tagStmt = $pdo->prepare('SELECT id FROM episode_tags WHERE slug = :slug LIMIT 1');
            $tagStmt->execute(['slug' => $tagSlug]);
            $tagId = (int) ($tagStmt->fetchColumn() ?: 0);
            if ($tagId > 0) {
                $pdo->prepare(
                    'INSERT IGNORE INTO episode_tag_map (episode_id, tag_id)
                     VALUES (:episode_id, :tag_id)'
                )->execute([
                    'episode_id' => $episodeId,
                    'tag_id'     => $tagId,
                ]);
            }
        }
    }

    if ($id > 0) {
        redirect('/admin/episodes.php', 'Episode updated.', 'success');
    }

    redirect('/admin/episodes.php', 'Episode created.', 'success');
}

$pageSubheading = $action === 'edit' ? 'Edit Episode' : ($action === 'new' ? 'New Episode' : 'All Episodes');
$pageActions    = '';
if ($action === 'list') {
    $pageActions = '<a href="/admin/episodes.php?action=new" class="btn btn-ptmd-primary"><i class="fa-solid fa-plus me-2"></i>New Episode</a>';
} elseif ($action === 'edit' || $action === 'new') {
    $pageActions = '<a href="/admin/episodes.php" class="btn btn-ptmd-outline"><i class="fa-solid fa-arrow-left me-2"></i>Back</a>';
}

include __DIR__ . '/_admin_head.php';

// ── Fetch episode for edit ────────────────────────────────────────────────────
$ep = null;
$epKeywords = '';
$epTriggers = [];
if ($editId > 0 && $pdo) {
    $stmt = $pdo->prepare('SELECT * FROM episodes WHERE id = :id');
    $stmt->execute(['id' => $editId]);
    $ep = $stmt->fetch();
    if ($ep) {
        $epKeywords = implode(', ', get_episode_tags((int) $ep['id']));
        $trigStmt = $pdo->prepare(
            'SELECT * FROM episode_overlay_triggers WHERE episode_id = :eid ORDER BY sort_order, id'
        );
        $trigStmt->execute(['eid' => $editId]);
        $epTriggers = $trigStmt->fetchAll();
    }
}

// ── Available overlays for trigger picker ─────────────────────────────────────
$availableOverlays = [];
$brandOverlayDir   = $_SERVER['DOCUMENT_ROOT'] . '/assets/brand/overlays';
if (is_dir($brandOverlayDir)) {
    foreach (glob($brandOverlayDir . '/*.{png,gif,webp}', GLOB_BRACE) ?: [] as $f) {
        $availableOverlays[] = '/assets/brand/overlays/' . basename($f);
    }
}
if ($pdo) {
    $dbOvRows = $pdo->query(
        'SELECT file_path FROM media_library WHERE category = "overlay" ORDER BY created_at DESC'
    )->fetchAll();
    foreach ($dbOvRows as $row) {
        $availableOverlays[] = '/uploads/' . $row['file_path'];
    }
}

// ── List view ─────────────────────────────────────────────────────────────────
if ($action === 'list'):
    $episodes = $pdo ? $pdo->query('SELECT * FROM episodes ORDER BY updated_at DESC')->fetchAll() : [];
?>
    <div class="ptmd-panel p-lg">
        <?php if ($episodes): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Duration</th>
                        <th>Published</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($episodes as $ep): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <?php if ($ep['thumbnail_image']): ?>
                                        <img
                                            src="<?php ee($ep['thumbnail_image']); ?>"
                                            alt=""
                                            style="width:48px;height:32px;object-fit:cover;border-radius:4px;border:1px solid var(--ptmd-border)"
                                            loading="lazy"
                                        >
                                    <?php endif; ?>
                                    <a href="/admin/episodes.php?edit=<?php ee((string) $ep['id']); ?>"
                                       class="fw-500 ptmd-text-muted">
                                        <?php ee($ep['title']); ?>
                                    </a>
                                </div>
                            </td>
                            <td>
                                <span class="ptmd-status ptmd-status-<?php ee($ep['status']); ?>">
                                    <?php ee($ep['status']); ?>
                                </span>
                            </td>
                            <td class="ptmd-muted small"><?php ee($ep['duration'] ?? '—'); ?></td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo $ep['published_at'] ? e(date('M j, Y', strtotime($ep['published_at']))) : '—'; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <a href="/admin/episodes.php?edit=<?php ee((string) $ep['id']); ?>"
                                       class="btn btn-ptmd-ghost btn-sm" data-tippy-content="Edit">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                    <a href="/index.php?page=episode&slug=<?php ee($ep['slug']); ?>"
                                       target="_blank" rel="noopener"
                                       class="btn btn-ptmd-ghost btn-sm" data-tippy-content="View public">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                    </a>
                                    <form method="post" action="/admin/episodes.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="id" value="<?php ee((string) $ep['id']); ?>">
                                        <button
                                            class="btn btn-ptmd-ghost btn-sm"
                                            type="submit"
                                            style="color:var(--ptmd-error)"
                                            data-confirm="Delete &quot;<?php ee($ep['title']); ?>&quot;? This cannot be undone."
                                            data-tippy-content="Delete"
                                        >
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
            <p class="ptmd-muted">No episodes yet. <a href="/admin/episodes.php?action=new">Create your first episode</a>.</p>
        <?php endif; ?>
    </div>

<?php
// ── Create / Edit form ────────────────────────────────────────────────────────
else:
?>
    <form method="post" action="/admin/episodes.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php ee((string) ($ep['id'] ?? 0)); ?>">
        <input type="hidden" name="_action" value="save">

        <div class="row g-4">

            <!-- Left: main fields -->
            <div class="col-lg-8">

                <div class="ptmd-panel p-xl mb-4">
                    <h2 class="h6 mb-4">Episode Details</h2>
                    <div class="mb-3">
                        <label class="form-label" for="ep_title">Title <span style="color:var(--ptmd-error)">*</span></label>
                        <input class="form-control" id="ep_title" name="title"
                            value="<?php ee($ep['title'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ep_slug">Slug</label>
                        <input class="form-control" id="ep_slug" name="slug"
                            value="<?php ee($ep['slug'] ?? ''); ?>"
                            placeholder="auto-generated from title if blank">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ep_excerpt">Excerpt</label>
                        <textarea class="form-control" id="ep_excerpt" name="excerpt"
                            rows="3"><?php ee($ep['excerpt'] ?? ''); ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ep_body">Body / Article Copy</label>
                        <textarea class="form-control" id="ep_body" name="body"
                            rows="12"><?php ee($ep['body'] ?? ''); ?></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="ep_keywords">Keywords</label>
                        <input class="form-control" id="ep_keywords" name="keywords"
                            value="<?php ee($epKeywords); ?>"
                            placeholder="comma-separated keywords (e.g. politics, corruption, city hall)">
                        <div class="form-text ptmd-muted">Use commas to separate keywords.</div>
                    </div>
                </div>

                <div class="ptmd-panel p-xl mb-4">
                    <h2 class="h6 mb-4">Video</h2>
                    <div class="mb-3">
                        <label class="form-label" for="ep_video_url">Video Embed URL</label>
                        <input class="form-control" id="ep_video_url" name="video_url"
                            value="<?php ee($ep['video_url'] ?? ''); ?>"
                            placeholder="https://www.youtube.com/embed/…">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ep_video_file">Upload Video File</label>
                        <input class="form-control" id="ep_video_file" type="file"
                            name="video_file"
                            accept=".mp4,.mov,.webm,.avi,.mkv">
                        <?php if (!empty($ep['video_file_path'])): ?>
                            <div class="form-text ptmd-muted">
                                Current: <?php ee($ep['video_file_path']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ep_duration">Duration</label>
                        <input class="form-control" id="ep_duration" name="duration"
                            value="<?php ee($ep['duration'] ?? ''); ?>"
                            placeholder="e.g. 18:22">
                    </div>
                </div>

                <div class="ptmd-panel p-xl mb-4">
                    <h2 class="h6 mb-4">
                        <i class="fa-solid fa-clapperboard me-2 ptmd-text-yellow"></i>Intro / Outro
                    </h2>
                    <div class="mb-3">
                        <label class="form-label" for="ep_intro">Intro Asset Path</label>
                        <input class="form-control" id="ep_intro" name="intro_asset_path"
                            value="<?php ee($ep['intro_asset_path'] ?? ''); ?>"
                            placeholder="Leave blank to use site default (<?php ee(site_setting('intro_asset_path','none')); ?>)">
                        <div class="form-text ptmd-muted">
                            Path to intro video file (web-root relative). Blank uses the global intro from Settings.
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="ep_outro">Outro Asset Path</label>
                        <input class="form-control" id="ep_outro" name="outro_asset_path"
                            value="<?php ee($ep['outro_asset_path'] ?? ''); ?>"
                            placeholder="Leave blank to use site default">
                        <div class="form-text ptmd-muted">
                            Path to outro video file (web-root relative). Blank uses the global outro from Settings.
                        </div>
                    </div>
                </div>

            </div>

            <!-- Right: meta -->
            <div class="col-lg-4">

                <div class="ptmd-panel p-xl mb-4">
                    <h2 class="h6 mb-4">Publish</h2>
                    <div class="mb-3">
                        <label class="form-label" for="ep_status">Status</label>
                        <select class="form-select" id="ep_status" name="status">
                            <?php foreach (['draft','published','archived'] as $s): ?>
                                <option value="<?php ee($s); ?>"
                                    <?php echo ($ep['status'] ?? 'draft') === $s ? 'selected' : ''; ?>>
                                    <?php ee(ucfirst($s)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ep_pub_at">Published Date</label>
                        <input class="form-control" id="ep_pub_at" type="datetime-local"
                            name="published_at"
                            value="<?php echo !empty($ep['published_at']) ? e(date('Y-m-d\TH:i', strtotime($ep['published_at']))) : ''; ?>">
                    </div>
                </div>

                <div class="ptmd-panel p-xl mb-4">
                    <h2 class="h6 mb-4">Thumbnail</h2>
                    <?php if (!empty($ep['thumbnail_image'])): ?>
                        <img
                            src="<?php ee($ep['thumbnail_image']); ?>"
                            alt="Thumbnail"
                            class="w-100 rounded mb-3"
                            style="aspect-ratio:16/9;object-fit:cover;border:1px solid var(--ptmd-border)"
                        >
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Upload Image</label>
                        <input class="form-control" type="file" name="thumbnail_file"
                            accept=".jpg,.jpeg,.png,.webp,.gif">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ep_thumb_url">Or enter URL / path</label>
                        <input class="form-control" id="ep_thumb_url" name="thumbnail_image"
                            value="<?php ee($ep['thumbnail_image'] ?? ''); ?>"
                            placeholder="/uploads/thumbnails/…">
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button class="btn btn-ptmd-primary" type="submit">
                        <i class="fa-solid fa-floppy-disk me-2"></i>
                        <?php echo $editId > 0 ? 'Save Changes' : 'Create Episode'; ?>
                    </button>
                    <?php if ($editId > 0): ?>
                        <a href="/admin/ai-tools.php" class="btn btn-ptmd-outline" style="border-color:rgba(106,13,173,0.4);color:#c084fc">
                            <i class="fa-solid fa-wand-magic-sparkles me-2"></i>AI Content
                        </a>
                        <a href="/admin/export-profiles.php?episode_id=<?php ee((string) $editId); ?>" class="btn btn-ptmd-outline">
                            <i class="fa-solid fa-file-export me-2"></i>Export / Render
                        </a>
                        <a href="/api/obs_pack.php?episode_id=<?php ee((string) $editId); ?>" class="btn btn-ptmd-outline">
                            <i class="fa-solid fa-box-archive me-2"></i>OBS Pack
                        </a>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </form>

<?php endif; // end: if ($action === 'list') ... else ?>

<?php if ($editId > 0 && $ep): ?>

<!-- ── Timeline Overlay Triggers ──────────────────────────────────────────── -->
<div class="ptmd-panel p-xl mt-4" id="triggers">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h5 mb-0">
            <i class="fa-solid fa-clock me-2 ptmd-text-teal"></i>Timeline Overlay Triggers
            <?php if ($epTriggers): ?>
                <span class="badge bg-secondary ms-2" style="font-size:var(--text-xs)"><?php ee((string) count($epTriggers)); ?></span>
            <?php endif; ?>
        </h2>
        <span class="ptmd-muted small">Overlays that appear/disappear at specific timestamps when the video is rendered.</span>
    </div>

    <!-- Existing triggers -->
    <?php if ($epTriggers): ?>
        <div class="table-responsive mb-4">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Label</th>
                        <th>In (s)</th>
                        <th>Out (s)</th>
                        <th>Overlay</th>
                        <th>Position</th>
                        <th>Opacity</th>
                        <th>Scale</th>
                        <th>Animation</th>
                        <th>Delete</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($epTriggers as $trig): ?>
                        <tr>
                            <td class="ptmd-muted"><?php ee((string) $trig['sort_order']); ?></td>
                            <td><?php ee($trig['label'] ?: '—'); ?></td>
                            <td class="ptmd-text-teal"><?php ee((string) $trig['timestamp_in']); ?></td>
                            <td class="ptmd-text-teal"><?php ee((string) $trig['timestamp_out']); ?></td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php ee(basename((string) $trig['overlay_path'])); ?>
                            </td>
                            <td><span class="ptmd-badge-muted"><?php ee($trig['position']); ?></span></td>
                            <td><?php ee(number_format((float) $trig['opacity'] * 100)); ?>%</td>
                            <td><?php ee((string) $trig['scale']); ?>%</td>
                            <td class="ptmd-muted small"><?php ee($trig['animation_style']); ?></td>
                            <td>
                                <form method="post" action="/admin/episodes.php" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                    <input type="hidden" name="_action" value="delete_trigger">
                                    <input type="hidden" name="episode_id" value="<?php ee((string) $editId); ?>">
                                    <input type="hidden" name="trigger_id" value="<?php ee((string) $trig['id']); ?>">
                                    <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                        style="color:var(--ptmd-error)"
                                        data-confirm="Remove this trigger?"
                                        data-tippy-content="Delete trigger">
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
        <p class="ptmd-muted small mb-4">No triggers yet. Add one below.</p>
    <?php endif; ?>

    <!-- Add trigger form -->
    <form method="post" action="/admin/episodes.php" class="border-top pt-4" style="border-color:var(--ptmd-border)!important">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action" value="save_trigger">
        <input type="hidden" name="episode_id" value="<?php ee((string) $editId); ?>">
        <h3 class="h6 mb-3">Add Trigger</h3>
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Label</label>
                <input class="form-control" name="trigger_label" placeholder="e.g. Lower Third">
            </div>
            <div class="col-md-2">
                <label class="form-label">In (seconds)</label>
                <input class="form-control" name="timestamp_in" type="number" step="0.001" min="0" placeholder="5.000" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Out (seconds)</label>
                <input class="form-control" name="timestamp_out" type="number" step="0.001" min="0" placeholder="10.000" required>
            </div>
            <div class="col-md-5">
                <label class="form-label">Overlay Asset</label>
                <?php if ($availableOverlays): ?>
                    <select class="form-select" name="overlay_path" required>
                        <option value="">— Select overlay —</option>
                        <?php foreach ($availableOverlays as $ovp): ?>
                            <option value="<?php ee($ovp); ?>"><?php ee(basename($ovp)); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input class="form-control" name="overlay_path" placeholder="/assets/brand/overlays/ptmd_overlay.png" required>
                    <div class="form-text ptmd-muted">No overlays found. <a href="/admin/media.php">Upload one</a> first.</div>
                <?php endif; ?>
            </div>
            <div class="col-md-2">
                <label class="form-label">Position</label>
                <select class="form-select" name="position">
                    <?php foreach (['bottom-right','bottom-left','top-right','top-left','center','full'] as $pos): ?>
                        <option value="<?php ee($pos); ?>"><?php ee($pos); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Opacity (0–1)</label>
                <input class="form-control" name="opacity" type="number" step="0.05" min="0" max="1" value="1.00"
                    placeholder="1.00">
            </div>
            <div class="col-md-2">
                <label class="form-label">Scale (%)</label>
                <input class="form-control" name="scale" type="number" step="5" min="5" max="100" value="30">
            </div>
            <div class="col-md-3">
                <label class="form-label">Animation</label>
                <select class="form-select" name="animation_style">
                    <option value="none">None</option>
                    <option value="fade">Fade</option>
                    <option value="slide-up">Slide Up</option>
                    <option value="slide-down">Slide Down</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button class="btn btn-ptmd-teal w-100" type="submit">
                    <i class="fa-solid fa-plus me-2"></i>Add Trigger
                </button>
            </div>
        </div>
    </form>
</div>

<!-- ── Generate Social Queue ─────────────────────────────────────────────── -->
<div class="ptmd-panel p-xl mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">
            <i class="fa-solid fa-calendar-plus me-2 ptmd-text-yellow"></i>Generate Social Queue
        </h2>
        <span class="ptmd-muted small">Creates posts from active schedule templates, anchored to this episode's publish date.</span>
    </div>
    <form method="post" action="/admin/episodes.php" class="d-flex align-items-center gap-3 flex-wrap">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action" value="generate_queue">
        <input type="hidden" name="episode_id" value="<?php ee((string) $editId); ?>">
        <button class="btn btn-ptmd-primary" type="submit"
            data-confirm="This will create queue entries for all active schedules anchored to this episode's publish date. Continue?">
            <i class="fa-solid fa-calendar-plus me-2"></i>Generate Social Queue Entries
        </button>
        <a href="/admin/posts.php" class="btn btn-ptmd-outline">
            <i class="fa-solid fa-list me-2"></i>View Social Queue
        </a>
        <span class="ptmd-muted small">Based on: <a href="/admin/social-schedule.php">Post Schedule</a></span>
    </form>
</div>

<?php endif; ?>

<?php include __DIR__ . '/_admin_footer.php'; ?>
