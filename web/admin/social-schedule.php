<?php
/**
 * PTMD Admin — Social Post Schedule
 *
 * Manage recurring posting windows per platform.
 * Seed data is already in social_post_schedules via seed.sql.
 */

require_once __DIR__ . '/../inc/bootstrap.php';

$pageTitle    = 'Post Schedule | PTMD Admin';
$activePage   = 'social-schedule';
$pageHeading  = 'Social Post Schedule';
$pageSubheading = 'Recurring posting windows by platform. These drive the social queue.';

include __DIR__ . '/_admin_head.php';

$pdo = get_db();

if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect(route_admin('social-schedule'), 'Invalid CSRF token.', 'danger');
    }

    $postAction = $_POST['_action'] ?? 'toggle';

    if ($postAction === 'delete') {
        $delId = (int) ($_POST['id'] ?? 0);
        if ($delId > 0) {
            $pdo->prepare('DELETE FROM social_post_schedules WHERE id = :id')->execute(['id' => $delId]);
            redirect(route_admin('social-schedule'), 'Schedule deleted.', 'success');
        }
    }

    if ($postAction === 'toggle') {
        $togId  = (int) ($_POST['id'] ?? 0);
        $active = (int) ($_POST['is_active'] ?? 0);
        if ($togId > 0) {
            $pdo->prepare('UPDATE social_post_schedules SET is_active = :a, updated_at = NOW() WHERE id = :id')
                ->execute(['a' => $active ? 0 : 1, 'id' => $togId]);
            redirect(route_admin('social-schedule'), 'Schedule updated.', 'success');
        }
    }

    if ($postAction === 'add') {
        $platform    = trim((string) ($_POST['platform']     ?? ''));
        $contentType = trim((string) ($_POST['content_type'] ?? ''));
        $dayOfWeek   = trim((string) ($_POST['day_of_week']  ?? ''));
        $postTime    = trim((string) ($_POST['post_time']    ?? ''));
        $timezone    = trim((string) ($_POST['timezone']     ?? 'America/Phoenix'));

        if ($platform && $dayOfWeek && $postTime) {
            $pdo->prepare(
                'INSERT INTO social_post_schedules (platform, content_type, day_of_week, post_time, timezone, is_active, created_at, updated_at)
                 VALUES (:platform, :ct, :dow, :time, :tz, 1, NOW(), NOW())'
            )->execute([
                'platform' => $platform,
                'ct'       => $contentType,
                'dow'      => $dayOfWeek,
                'time'     => $postTime,
                'tz'       => $timezone,
            ]);
            redirect(route_admin('social-schedule'), 'Schedule added.', 'success');
        }
    }
}

$schedules = $pdo
    ? $pdo->query('SELECT * FROM social_post_schedules ORDER BY FIELD(day_of_week,"Sunday","Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"), post_time')
           ->fetchAll()
    : [];

// Load active platforms from DB; fall back to hardcoded list for graceful degradation
$activeSites = get_posting_sites(true);
$platforms   = $activeSites
    ? array_column($activeSites, 'display_name')
    : ['YouTube', 'YouTube Shorts', 'TikTok', 'Instagram Reels', 'Facebook Reels', 'X'];
$days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
?>

<!-- Add schedule form -->
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-plus me-2 ptmd-text-teal"></i>Add Posting Window
    </h2>
    <form method="post" action="<?php echo e(route_admin('social-schedule')); ?>">
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
                <label class="form-label">Content Type</label>
                <input class="form-control" name="content_type" placeholder="teaser, clip, full documentary…">
            </div>
            <div class="col-md-2">
                <label class="form-label">Day of Week</label>
                <select class="form-select" name="day_of_week" required>
                    <option value="">— Select —</option>
                    <?php foreach ($days as $d): ?>
                        <option><?php ee($d); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Time (Phoenix)</label>
                <input class="form-control" type="time" name="post_time" required>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-ptmd-primary w-100" type="submit">
                    <i class="fa-solid fa-plus me-1"></i>Add
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Schedule table -->
<div class="ptmd-panel p-lg">
    <h2 class="h6 mb-4">Current Schedule</h2>
    <?php if ($schedules): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr><th>Platform</th><th>Content Type</th><th>Day</th><th>Time</th><th>TZ</th><th>Active</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($schedules as $s): ?>
                        <tr>
                            <td class="fw-500"><?php ee($s['platform']); ?></td>
                            <td class="ptmd-muted small"><?php ee($s['content_type']); ?></td>
                            <td><?php ee($s['day_of_week']); ?></td>
                            <td class="ptmd-text-teal"><?php echo e(date('g:ia', strtotime($s['post_time']))); ?></td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)"><?php ee($s['timezone']); ?></td>
                            <td>
                                <form method="post" action="<?php echo e(route_admin('social-schedule')); ?>" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                    <input type="hidden" name="_action" value="toggle">
                                    <input type="hidden" name="id" value="<?php ee((string) $s['id']); ?>">
                                    <input type="hidden" name="is_active" value="<?php ee((string) $s['is_active']); ?>">
                                    <button class="btn btn-sm <?php echo $s['is_active'] ? 'btn-ptmd-teal' : 'btn-ptmd-outline'; ?>" type="submit">
                                        <?php echo $s['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <form method="post" action="<?php echo e(route_admin('social-schedule')); ?>" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="id" value="<?php ee((string) $s['id']); ?>">
                                    <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                        style="color:var(--ptmd-error)"
                                        data-confirm="Delete this schedule?"
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
        <p class="ptmd-muted small">No schedules yet. Run <code>database/seed.sql</code> to load the default PTMD cadence.</p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_admin_footer.php'; ?>
