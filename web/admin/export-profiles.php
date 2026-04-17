<?php
/**
 * PTMD Admin — Export Profiles
 *
 * CRUD for platform-specific render presets.
 * Linked to episode edit via "Export / Render" button.
 */

$pageTitle    = 'Export Profiles | PTMD Admin';
$activePage   = 'export-profiles';
$pageHeading  = 'Export Profiles';
$pageSubheading = 'Platform render presets. One source video → multiple outputs (YouTube, Shorts, TikTok, OBS).';

$pdo = get_db();

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($pdo && is_post()) {
    require_once __DIR__ . '/../inc/bootstrap.php';

    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/export-profiles.php', 'Invalid CSRF token.', 'danger');
    }

    $postAction = $_POST['_action'] ?? 'save';

    if ($postAction === 'delete') {
        $delId = (int) ($_POST['id'] ?? 0);
        if ($delId > 0) {
            $pdo->prepare('DELETE FROM export_profiles WHERE id = :id')->execute(['id' => $delId]);
            redirect('/admin/export-profiles.php', 'Profile deleted.', 'success');
        }
    }

    if ($postAction === 'run_export') {
        $profileId = (int) ($_POST['profile_id'] ?? 0);
        $episodeId = (int) ($_POST['episode_id']  ?? 0);

        if ($profileId > 0 && $episodeId > 0) {
            require_once __DIR__ . '/../inc/video_processor.php';

            $profile = $pdo->prepare('SELECT * FROM export_profiles WHERE id = :id');
            $profile->execute(['id' => $profileId]);
            $profile = $profile->fetch();

            $episode = $pdo->prepare('SELECT * FROM episodes WHERE id = :id');
            $episode->execute(['id' => $episodeId]);
            $episode = $episode->fetch();

            if ($profile && $episode) {
                // Create export job row
                $jobStmt = $pdo->prepare(
                    'INSERT INTO export_jobs (episode_id, profile_id, status, created_by, created_at, updated_at)
                     VALUES (:eid, :pid, "pending", :uid, NOW(), NOW())'
                );
                $jobStmt->execute([
                    'eid' => $episodeId,
                    'pid' => $profileId,
                    'uid' => (int) ($_SESSION['admin_user_id'] ?? 0),
                ]);
                $jobId = (int) $pdo->lastInsertId();

                $result = run_export_job($pdo, $jobId, $episode, $profile);
                $msg  = $result['ok']
                    ? 'Export completed: ' . basename((string) ($result['output_path'] ?? ''))
                    : 'Export failed: ' . ($result['error'] ?? 'unknown');
                $type = $result['ok'] ? 'success' : 'danger';
                redirect('/admin/export-profiles.php?episode_id=' . $episodeId, $msg, $type);
            }
        }
        redirect('/admin/export-profiles.php', 'Missing profile or episode ID.', 'warning');
    }

    // Save / Update profile
    $id               = (int) ($_POST['id']     ?? 0);
    $label            = trim((string) ($_POST['label']            ?? ''));
    $platformTarget   = trim((string) ($_POST['platform_target']  ?? 'youtube'));
    $width            = max(1, (int) ($_POST['width']             ?? 1920));
    $height           = max(1, (int) ($_POST['height']            ?? 1080));
    $fps              = max(1, min(120, (int) ($_POST['fps']      ?? 30)));
    $videoBitrate     = preg_replace('/[^0-9kmKM]/', '', ($_POST['video_bitrate'] ?? '5000k')) ?: '5000k';
    $audioBitrate     = preg_replace('/[^0-9kmKM]/', '', ($_POST['audio_bitrate'] ?? '192k'))  ?: '192k';
    $useIntro         = (int) isset($_POST['use_intro']);
    $useOutro         = (int) isset($_POST['use_outro']);
    $useWatermark     = (int) isset($_POST['use_watermark']);
    $useTriggers      = (int) isset($_POST['use_triggers']);
    $isDefault        = (int) isset($_POST['is_default']);
    $extraFlags       = trim((string) ($_POST['extra_ffmpeg_flags'] ?? ''));

    if ($label === '') {
        redirect('/admin/export-profiles.php', 'Label is required.', 'warning');
    }

    if ($isDefault) {
        // Clear existing default for this platform
        $pdo->prepare('UPDATE export_profiles SET is_default = 0 WHERE platform_target = :p')
            ->execute(['p' => $platformTarget]);
    }

    if ($id > 0) {
        $pdo->prepare(
            'UPDATE export_profiles SET label=:label, platform_target=:pt, width=:w, height=:h, fps=:fps,
             video_bitrate=:vbr, audio_bitrate=:abr, use_intro=:ui, use_outro=:uo, use_watermark=:uw,
             use_triggers=:ut, extra_ffmpeg_flags=:extra, is_default=:def, updated_at=NOW()
             WHERE id=:id'
        )->execute([
            'label' => $label, 'pt' => $platformTarget, 'w' => $width, 'h' => $height, 'fps' => $fps,
            'vbr' => $videoBitrate, 'abr' => $audioBitrate, 'ui' => $useIntro, 'uo' => $useOutro,
            'uw' => $useWatermark, 'ut' => $useTriggers, 'extra' => $extraFlags ?: null,
            'def' => $isDefault, 'id' => $id,
        ]);
        redirect('/admin/export-profiles.php', 'Profile updated.', 'success');
    }

    $pdo->prepare(
        'INSERT INTO export_profiles (label, platform_target, width, height, fps, video_bitrate, audio_bitrate,
         use_intro, use_outro, use_watermark, use_triggers, extra_ffmpeg_flags, is_default, created_at, updated_at)
         VALUES (:label, :pt, :w, :h, :fps, :vbr, :abr, :ui, :uo, :uw, :ut, :extra, :def, NOW(), NOW())'
    )->execute([
        'label' => $label, 'pt' => $platformTarget, 'w' => $width, 'h' => $height, 'fps' => $fps,
        'vbr' => $videoBitrate, 'abr' => $audioBitrate, 'ui' => $useIntro, 'uo' => $useOutro,
        'uw' => $useWatermark, 'ut' => $useTriggers, 'extra' => $extraFlags ?: null,
        'def' => $isDefault,
    ]);
    redirect('/admin/export-profiles.php', 'Profile created.', 'success');
}

// ── Data ──────────────────────────────────────────────────────────────────────
$profiles  = $pdo ? $pdo->query('SELECT * FROM export_profiles ORDER BY platform_target, label')->fetchAll() : [];
$episodes  = $pdo ? $pdo->query('SELECT id, title FROM episodes ORDER BY title')->fetchAll() : [];
$editId    = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$episodeId = isset($_GET['episode_id']) ? (int) $_GET['episode_id'] : 0;

$editProfile = null;
if ($editId > 0 && $pdo) {
    $stmt = $pdo->prepare('SELECT * FROM export_profiles WHERE id = :id');
    $stmt->execute(['id' => $editId]);
    $editProfile = $stmt->fetch() ?: null;
}

// Recent export jobs (latest 20)
$recentJobs = [];
if ($pdo) {
    $recentJobs = $pdo->query(
        'SELECT j.*, e.title AS episode_title, p.label AS profile_label
         FROM export_jobs j
         LEFT JOIN episodes e ON e.id = j.episode_id
         LEFT JOIN export_profiles p ON p.id = j.profile_id
         ORDER BY j.created_at DESC LIMIT 20'
    )->fetchAll();
}

$platforms = ['youtube','youtube_shorts','tiktok','instagram_reels','facebook_reels','x','obs_source'];

$pageActions = '<a href="/admin/export-profiles.php?edit=new" class="btn btn-ptmd-primary"><i class="fa-solid fa-plus me-2"></i>New Profile</a>';
if ($editId > 0 || $editId === -1) {
    $pageActions = '<a href="/admin/export-profiles.php" class="btn btn-ptmd-outline"><i class="fa-solid fa-arrow-left me-2"></i>Back</a>';
}

include __DIR__ . '/_admin_head.php';

require_once __DIR__ . '/../inc/video_processor.php';
?>

<?php if ($editProfile || isset($_GET['edit'])): ?>
<!-- ── Add / Edit Form ────────────────────────────────────────────────────── -->
<div class="ptmd-panel p-xl mb-5">
    <h2 class="h6 mb-4"><?php echo $editProfile ? 'Edit Profile' : 'New Export Profile'; ?></h2>
    <form method="post" action="/admin/export-profiles.php">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php ee((string) ($editProfile['id'] ?? 0)); ?>">
        <input type="hidden" name="_action" value="save">

        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label">Label <span style="color:var(--ptmd-error)">*</span></label>
                <input class="form-control" name="label" required
                    value="<?php ee($editProfile['label'] ?? ''); ?>"
                    placeholder="e.g. YouTube — Full Documentary">
            </div>
            <div class="col-md-3">
                <label class="form-label">Platform Target</label>
                <select class="form-select" name="platform_target">
                    <?php foreach ($platforms as $p): ?>
                        <option value="<?php ee($p); ?>"
                            <?php echo ($editProfile['platform_target'] ?? 'youtube') === $p ? 'selected' : ''; ?>>
                            <?php ee($p); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Width px</label>
                <input class="form-control" name="width" type="number" min="1"
                    value="<?php ee((string) ($editProfile['width'] ?? 1920)); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Height px</label>
                <input class="form-control" name="height" type="number" min="1"
                    value="<?php ee((string) ($editProfile['height'] ?? 1080)); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">FPS</label>
                <input class="form-control" name="fps" type="number" min="1" max="120"
                    value="<?php ee((string) ($editProfile['fps'] ?? 30)); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Video Bitrate</label>
                <input class="form-control" name="video_bitrate"
                    value="<?php ee($editProfile['video_bitrate'] ?? '5000k'); ?>"
                    placeholder="5000k">
            </div>
            <div class="col-md-2">
                <label class="form-label">Audio Bitrate</label>
                <input class="form-control" name="audio_bitrate"
                    value="<?php ee($editProfile['audio_bitrate'] ?? '192k'); ?>"
                    placeholder="192k">
            </div>
            <div class="col-md-6">
                <label class="form-label">Extra FFmpeg Flags</label>
                <input class="form-control" name="extra_ffmpeg_flags"
                    value="<?php ee($editProfile['extra_ffmpeg_flags'] ?? ''); ?>"
                    placeholder="-vf scale=…  or additional filter flags">
            </div>
            <div class="col-12">
                <div class="d-flex gap-4 flex-wrap">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="chk_intro" name="use_intro" value="1"
                            <?php echo ($editProfile['use_intro'] ?? 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="chk_intro">Use Intro</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="chk_outro" name="use_outro" value="1"
                            <?php echo ($editProfile['use_outro'] ?? 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="chk_outro">Use Outro</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="chk_watermark" name="use_watermark" value="1"
                            <?php echo ($editProfile['use_watermark'] ?? 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="chk_watermark">Watermark</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="chk_triggers" name="use_triggers" value="1"
                            <?php echo ($editProfile['use_triggers'] ?? 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="chk_triggers">Timeline Triggers</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="chk_default" name="is_default" value="1"
                            <?php echo ($editProfile['is_default'] ?? 0) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="chk_default">Set as Default for Platform</label>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <button class="btn btn-ptmd-primary" type="submit">
                    <i class="fa-solid fa-floppy-disk me-2"></i>
                    <?php echo $editProfile ? 'Save Changes' : 'Create Profile'; ?>
                </button>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ── Run Export panel (shown when episode_id param present) ─────────────── -->
<?php if ($episodeId > 0 && $profiles): ?>
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-play me-2 ptmd-text-teal"></i>Run Export for Episode
    </h2>
    <?php
    $epRow = null;
    if ($pdo) {
        $epStmt = $pdo->prepare('SELECT id, title, video_file_path FROM episodes WHERE id = :id');
        $epStmt->execute(['id' => $episodeId]);
        $epRow = $epStmt->fetch();
    }
    ?>
    <?php if ($epRow): ?>
        <p class="ptmd-muted mb-3">Episode: <strong><?php ee($epRow['title']); ?></strong></p>
        <?php if (empty($epRow['video_file_path'])): ?>
            <div class="alert ptmd-alert alert-warning">No video file uploaded for this episode. Upload one in <a href="/admin/episodes.php?edit=<?php ee((string) $episodeId); ?>">Episode Edit</a>.</div>
        <?php else: ?>
            <form method="post" action="/admin/export-profiles.php">
                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                <input type="hidden" name="_action" value="run_export">
                <input type="hidden" name="episode_id" value="<?php ee((string) $episodeId); ?>">
                <div class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label">Export Profile</label>
                        <select class="form-select" name="profile_id" required>
                            <option value="">— Select profile —</option>
                            <?php foreach ($profiles as $p): ?>
                                <option value="<?php ee((string) $p['id']); ?>">
                                    <?php ee($p['label']); ?> (<?php ee($p['platform_target']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-ptmd-primary" type="submit">
                            <i class="fa-solid fa-play me-2"></i>Start Export
                        </button>
                        <span class="ptmd-muted small ms-2">Runs FFmpeg synchronously.</span>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <p class="ptmd-muted">Episode #<?php ee((string) $episodeId); ?> not found.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── OBS Pack Download ─────────────────────────────────────────────────── -->
<div class="ptmd-panel p-xl mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">
            <i class="fa-solid fa-box-archive me-2 ptmd-text-teal"></i>OBS-Ready Overlay Pack
        </h2>
        <span class="ptmd-muted small">ZIP of overlay assets + OBS scene config + README</span>
    </div>
    <div class="d-flex gap-3 flex-wrap">
        <?php if ($episodeId > 0): ?>
            <a href="/api/obs_pack.php?episode_id=<?php ee((string) $episodeId); ?>"
               class="btn btn-ptmd-teal">
                <i class="fa-solid fa-download me-2"></i>Download Episode Pack
            </a>
        <?php endif; ?>
        <a href="/api/obs_pack.php" class="btn btn-ptmd-outline">
            <i class="fa-solid fa-download me-2"></i>Download Global Pack
        </a>
        <span class="ptmd-muted small align-self-center">
            Episode pack includes timed cue points from your overlay triggers.
        </span>
    </div>
</div>

<!-- ── Profile list ──────────────────────────────────────────────────────── -->
<div class="ptmd-panel p-lg mb-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h6 mb-0">
            <i class="fa-solid fa-list me-2 ptmd-text-teal"></i>Profiles
        </h2>
        <?php if (!$editProfile): ?>
            <a href="/admin/export-profiles.php?edit=new" class="btn btn-ptmd-outline btn-sm">
                <i class="fa-solid fa-plus me-1"></i>New
            </a>
        <?php endif; ?>
    </div>

    <?php if ($profiles): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>Label</th>
                        <th>Platform</th>
                        <th>Resolution</th>
                        <th>FPS</th>
                        <th>V/A Bitrate</th>
                        <th>Options</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($profiles as $p): ?>
                        <tr>
                            <td class="fw-500">
                                <?php ee($p['label']); ?>
                                <?php if ($p['is_default']): ?>
                                    <span class="ptmd-badge-muted ms-1" style="font-size:var(--text-xs)">default</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="ptmd-badge-muted"><?php ee($p['platform_target']); ?></span></td>
                            <td class="ptmd-muted small"><?php ee($p['width'] . '×' . $p['height']); ?></td>
                            <td class="ptmd-muted small"><?php ee((string) $p['fps']); ?></td>
                            <td class="ptmd-muted small"><?php ee($p['video_bitrate'] . ' / ' . $p['audio_bitrate']); ?></td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php
                                $opts = [];
                                if ($p['use_intro'])     $opts[] = 'intro';
                                if ($p['use_outro'])     $opts[] = 'outro';
                                if ($p['use_watermark']) $opts[] = 'watermark';
                                if ($p['use_triggers'])  $opts[] = 'triggers';
                                echo $opts ? e(implode(', ', $opts)) : '—';
                                ?>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <a href="/admin/export-profiles.php?edit=<?php ee((string) $p['id']); ?>"
                                       class="btn btn-ptmd-ghost btn-sm" data-tippy-content="Edit">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                    <form method="post" action="/admin/export-profiles.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="id" value="<?php ee((string) $p['id']); ?>">
                                        <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                            style="color:var(--ptmd-error)"
                                            data-confirm="Delete &quot;<?php ee($p['label']); ?>&quot;?"
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
        <p class="ptmd-muted small">No profiles yet. Run <code>database/seed.sql</code> to load PTMD defaults or create one above.</p>
    <?php endif; ?>
</div>

<!-- ── Export job history ─────────────────────────────────────────────────── -->
<?php if ($recentJobs): ?>
<div class="ptmd-panel p-lg">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-clock-rotate-left me-2 ptmd-text-teal"></i>Recent Export Jobs
    </h2>
    <div class="table-responsive">
        <table class="ptmd-table">
            <thead>
                <tr><th>#</th><th>Episode</th><th>Profile</th><th>Status</th><th>Output</th><th>Created</th></tr>
            </thead>
            <tbody>
                <?php foreach ($recentJobs as $job): ?>
                    <tr>
                        <td class="ptmd-muted">#<?php ee((string) $job['id']); ?></td>
                        <td class="ptmd-muted small"><?php ee($job['episode_title'] ?? '—'); ?></td>
                        <td class="ptmd-muted small"><?php ee($job['profile_label'] ?? '—'); ?></td>
                        <td>
                            <span class="ptmd-status ptmd-status-<?php ee($job['status']); ?>">
                                <?php ee($job['status']); ?>
                            </span>
                        </td>
                        <td style="font-size:var(--text-xs)">
                            <?php if ($job['output_path']): ?>
                                <a href="/uploads/<?php ee($job['output_path']); ?>" target="_blank" rel="noopener">
                                    <?php ee(basename((string) $job['output_path'])); ?>
                                </a>
                            <?php else: ?>
                                <span class="ptmd-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="ptmd-muted" style="font-size:var(--text-xs)">
                            <?php echo e(date('M j, Y g:ia', strtotime($job['created_at']))); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_admin_footer.php'; ?>
