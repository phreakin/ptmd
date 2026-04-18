<?php
/**
 * PTMD Admin — Video Processor
 *
 * Allows admins to:
 *  1. Upload a source video
 *  2. Define clip segments (start/end time)
 *  3. Extract clips via FFmpeg
 *  4. Track clips and link to cases
 *  5. Send clips to the overlay tool or social queue
 */

require_once __DIR__ . '/../inc/bootstrap.php';

$pageTitle    = 'Video Processor | PTMD Admin';
$activePage   = 'video-processor';
$pageHeading  = 'Video Processor';
$pageSubheading = 'Upload videos, extract clips, and prepare them for overlay processing and social publishing.';
$pageActions  = '<a href="' . e(route_admin('monitor')) . '" class="btn btn-ptmd-outline btn-sm">'
              . '<i class="fa-solid fa-chart-line me-2"></i>Intelligence</a>';

include __DIR__ . '/_admin_head.php';

require_once __DIR__ . '/../inc/video_processor.php';

$pdo = get_db();

// ── Handle upload + extract ───────────────────────────────────────────────────
if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect(route_admin('video-processor'), 'Invalid CSRF token.', 'danger');
    }

    $postAction = $_POST['_action'] ?? 'upload';

    if ($postAction === 'upload') {
        if (empty($_FILES['video_file']['name'])) {
            redirect(route_admin('video-processor'), 'No file selected.', 'warning');
        }

        $savedPath = save_upload(
            $_FILES['video_file'],
            'cases',
            $GLOBALS['config']['allowed_video_ext']
        );

        if (!$savedPath) {
            redirect(route_admin('video-processor'), 'Upload failed. Check file type and size.', 'danger');
        }

        // Insert video_clip row for this raw upload
        $label     = trim((string) ($_POST['label'] ?? basename((string) $savedPath)));
        $caseId = (int) ($_POST['case_id'] ?? 0) ?: null;

        $absPath = $GLOBALS['config']['upload_dir'] . '/' . $savedPath;
        $meta    = probe_video($absPath);
        $duration = $meta['duration'] ?? null;

        $stmt = $pdo->prepare(
            'INSERT INTO video_clips (case_id, label, source_path, duration_sec, status, created_at, updated_at)
             VALUES (:eid, :label, :src, :dur, "raw", NOW(), NOW())'
        );
        $stmt->execute([
            'eid'   => $caseId,
            'label' => $label,
            'src'   => $savedPath,
            'dur'   => $duration,
        ]);

        redirect(route_admin('video-processor'), 'Video uploaded.', 'success');
    }

    if ($postAction === 'extract_clip') {
        $clipId   = (int) ($_POST['clip_id']   ?? 0);
        $start    = trim((string) ($_POST['start_time'] ?? ''));
        $end      = trim((string) ($_POST['end_time']   ?? ''));
        $label    = trim((string) ($_POST['clip_label'] ?? 'clip'));
        $platform = trim((string) ($_POST['platform_target'] ?? ''));

        if (!$clipId || !$start || !$end) {
            redirect(route_admin('video-processor'), 'Missing clip ID, start, or end time.', 'warning');
        }

        $parentClip = $pdo->prepare('SELECT * FROM video_clips WHERE id = :id');
        $parentClip->execute(['id' => $clipId]);
        $parentClip = $parentClip->fetch();

        if (!$parentClip) {
            redirect(route_admin('video-processor'), 'Source clip not found.', 'danger');
        }

        $inputAbs = $GLOBALS['config']['upload_dir'] . '/' . ltrim((string) $parentClip['source_path'], '/');
        $outDir   = $GLOBALS['config']['upload_dir'] . '/clips';

        $outAbs = extract_clip($inputAbs, $outDir, $start, $end, $label);

        if (!$outAbs) {
            redirect(route_admin('video-processor'), 'Clip extraction failed. Check FFmpeg is installed.', 'danger');
        }

        $relPath = 'clips/' . basename($outAbs);

        $stmt = $pdo->prepare(
            'INSERT INTO video_clips (case_id, label, source_path, output_path, start_time, end_time,
             platform_target, status, created_at, updated_at)
             VALUES (:eid, :label, :src, :out, :start, :end, :platform, "ready", NOW(), NOW())'
        );
        $stmt->execute([
            'eid'      => $parentClip['case_id'],
            'label'    => $label,
            'src'      => $parentClip['source_path'],
            'out'      => $relPath,
            'start'    => $start,
            'end'      => $end,
            'platform' => $platform,
        ]);

        redirect(route_admin('video-processor'), 'Clip extracted: ' . basename($outAbs), 'success');
    }
}

// Load cases for dropdown
$cases = $pdo ? $pdo->query('SELECT id, title FROM cases ORDER BY title')->fetchAll() : [];

// Load all clips
$clips = $pdo ? $pdo->query(
    'SELECT vc.*, e.title AS case_title
     FROM video_clips vc
     LEFT JOIN cases e ON e.id = vc.case_id
     ORDER BY vc.created_at DESC'
)->fetchAll() : [];
?>

<!-- Upload section -->
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-cloud-arrow-up me-2 ptmd-text-teal"></i>Upload Source Video
    </h2>
    <form method="post" action="<?php echo e(route_admin('video-processor')); ?>" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action" value="upload">
        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label">Video File</label>
                <input class="form-control" type="file" name="video_file"
                    accept=".mp4,.mov,.webm,.avi,.mkv" required>
                <div class="form-text ptmd-muted" style="font-size:var(--text-xs)">
                    Max <?php echo round($GLOBALS['config']['max_video_upload'] / 1024 / 1024); ?> MB.
                    Supported: mp4, mov, webm, avi, mkv
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="vp_label">Label</label>
                <input class="form-control" id="vp_label" name="label" placeholder="e.g. case 1 raw footage">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="vp_case">Link to case (optional)</label>
                <select class="form-select" id="vp_case" name="case_id">
                    <option value="">— None —</option>
                    <?php foreach ($cases as $ep): ?>
                        <option value="<?php ee((string) $ep['id']); ?>"><?php ee($ep['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <button class="btn btn-ptmd-primary" type="submit">
                    <i class="fa-solid fa-cloud-arrow-up me-2"></i>Upload Video
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Clip extraction -->
<?php
$rawClips = array_filter($clips, fn($c) => $c['status'] === 'raw' || $c['status'] === 'ready');
$rawClips = array_values($rawClips);
if ($rawClips):
?>
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-scissors me-2 ptmd-text-yellow"></i>Extract Clip from Source
    </h2>
    <form method="post" action="<?php echo e(route_admin('video-processor')); ?>">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action" value="extract_clip">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Source Video</label>
                <select class="form-select" name="clip_id" required>
                    <option value="">— Select source —</option>
                    <?php foreach ($rawClips as $c): ?>
                        <option value="<?php ee((string) $c['id']); ?>">
                            <?php ee($c['label']); ?>
                            <?php if ($c['duration_sec']): ?>(<?php echo e(gmdate('H:i:s', (int) $c['duration_sec'])); ?>)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="clip_start">Start Time</label>
                <input class="form-control" id="clip_start" name="start_time"
                    placeholder="00:00:30" required pattern="\d{2}:\d{2}:\d{2}">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="clip_end">End Time</label>
                <input class="form-control" id="clip_end" name="end_time"
                    placeholder="00:01:00" required pattern="\d{2}:\d{2}:\d{2}">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="clip_label_field">Clip Label</label>
                <input class="form-control" id="clip_label_field" name="clip_label" placeholder="teaser-clip-1">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="clip_platform">Target Platform</label>
                <select class="form-select" id="clip_platform" name="platform_target">
                    <option value="">Any</option>
                    <option value="youtube_shorts">YouTube Shorts</option>
                    <option value="tiktok">TikTok</option>
                    <option value="instagram_reels">Instagram Reels</option>
                    <option value="facebook_reels">Facebook Reels</option>
                    <option value="x">X / Twitter</option>
                    <option value="youtube">YouTube (Full)</option>
                </select>
            </div>
            <div class="col-12">
                <button class="btn btn-ptmd-teal" type="submit">
                    <i class="fa-solid fa-scissors me-2"></i>Extract Clip
                </button>
                <span class="ptmd-muted small ms-3">
                    Requires FFmpeg to be installed on the server.
                </span>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Clip library table -->
<div class="ptmd-panel p-lg">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h6 mb-0">
            <i class="fa-solid fa-film me-2 ptmd-text-teal"></i>Clip Library
        </h2>
        <a href="<?php ee(route_admin('overlay-tool')); ?>" class="btn btn-ptmd-outline btn-sm">
            <i class="fa-solid fa-layer-group me-2"></i>Apply Overlays
        </a>
    </div>

    <?php if ($clips): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>Label</th>
                        <th>case</th>
                        <th>Duration</th>
                        <th>Platform</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clips as $clip): ?>
                        <tr>
                            <td class="fw-500 ptmd-text-muted"><?php ee($clip['label']); ?></td>
                            <td class="ptmd-muted small"><?php ee($clip['case_title'] ?? '—'); ?></td>
                            <td class="ptmd-muted small">
                                <?php echo $clip['duration_sec'] ? e(gmdate('H:i:s', (int) $clip['duration_sec'])) : '—'; ?>
                            </td>
                            <td>
                                <?php if ($clip['platform_target']): ?>
                                    <span class="ptmd-badge-muted"><?php ee($clip['platform_target']); ?></span>
                                <?php else: ?>
                                    <span class="ptmd-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="ptmd-status ptmd-status-<?php ee($clip['status']); ?>">
                                    <?php ee($clip['status']); ?>
                                </span>
                            </td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo e(date('M j, Y', strtotime($clip['created_at']))); ?>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <?php if ($clip['output_path'] || $clip['source_path']): ?>
                                        <a
                                            href="/uploads/<?php ee($clip['output_path'] ?: $clip['source_path']); ?>"
                                            class="btn btn-ptmd-ghost btn-sm"
                                            target="_blank"
                                            rel="noopener"
                                            data-tippy-content="Download / View"
                                        >
                                            <i class="fa-solid fa-download"></i>
                                        </a>
                                    <?php endif; ?>
                                    <a
                                        href="<?php ee(route_admin('overlay-tool')); ?>"
                                        class="btn btn-ptmd-ghost btn-sm"
                                        data-tippy-content="Send to Overlay Tool"
                                    >
                                        <i class="fa-solid fa-layer-group"></i>
                                    </a>
                                    <a
                                        href="<?php ee(route_admin('posts', ['clip_id' => (string) $clip['id']])); ?>"
                                        class="btn btn-ptmd-ghost btn-sm"
                                        data-tippy-content="Add to Social Queue"
                                    >
                                        <i class="fa-solid fa-calendar-plus"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="ptmd-muted small">No clips yet. Upload a source video and extract clips above.</p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_admin_footer.php'; ?>
