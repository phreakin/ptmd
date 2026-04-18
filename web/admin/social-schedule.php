<?php
/**
 * PTMD Admin — Social Post Schedule
 *
 * Manage recurring posting windows per platform.
 * On add or edit, immediately pre-generates queue items for the scheduler horizon.
 */

require_once __DIR__ . '/../inc/bootstrap.php';

$pageTitle    = 'Post Schedule | PTMD Admin';
$activePage   = 'social-schedule';
$pageHeading  = 'Social Post Schedule';
$pageSubheading = 'Recurring posting windows by platform. These drive the social queue.';

include __DIR__ . '/_admin_head.php';

require_once __DIR__ . '/../inc/scheduler.php';
require_once __DIR__ . '/../inc/social_platform_rules.php';

$pdo = get_db();

if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect(route_admin('social-schedule'), 'Invalid CSRF token.', 'danger');
    }

    $postAction = $_POST['_action'] ?? 'toggle';

    if ($postAction === 'delete') {
        $delId = (int) ($_POST['id'] ?? 0);
        if ($delId > 0) {
            // Cancel future unposted queue items for this rule before deleting the rule
            $pdo->prepare(
                'UPDATE social_post_queue
                 SET status = "canceled", last_error = "Schedule rule deleted.", updated_at = NOW()
                 WHERE schedule_id = :sid
                   AND status IN ("draft","queued","scheduled")
                   AND scheduled_for > NOW()'
            )->execute(['sid' => $delId]);
            $pdo->prepare('DELETE FROM social_post_schedules WHERE id = :id')->execute(['id' => $delId]);
            redirect(route_admin('social-schedule'), 'Schedule deleted and future queue items canceled.', 'success');
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
        $platform       = trim((string) ($_POST['platform']        ?? ''));
        $contentType    = trim((string) ($_POST['content_type']    ?? ''));
        $dayOfWeek      = trim((string) ($_POST['day_of_week']     ?? ''));
        $postTime       = trim((string) ($_POST['post_time']       ?? ''));
        $timezone       = trim((string) ($_POST['timezone']        ?? 'America/Phoenix'));
        $recurrenceType = trim((string) ($_POST['recurrence_type'] ?? 'weekly'));
        if (!in_array($recurrenceType, ['daily', 'weekly', 'monthly'], true)) {
            $recurrenceType = 'weekly';
        }

        if ($platform && $dayOfWeek && $postTime) {
            $pdo->prepare(
                'INSERT INTO social_post_schedules
                 (platform, content_type, day_of_week, post_time, timezone, recurrence_type, is_active, created_at, updated_at)
                 VALUES (:platform, :ct, :dow, :time, :tz, :recur, 1, NOW(), NOW())'
            )->execute([
                'platform' => $platform,
                'ct'       => $contentType,
                'dow'      => $dayOfWeek,
                'time'     => $postTime,
                'tz'       => $timezone,
                'recur'    => $recurrenceType,
            ]);

            $newId = (int) $pdo->lastInsertId();

            // Phase 3: immediately pre-generate queue items for the horizon
            if ($newId > 0) {
                $newSchedule = $pdo->prepare('SELECT * FROM social_post_schedules WHERE id = :id LIMIT 1');
                $newSchedule->execute(['id' => $newId]);
                $newSchedule = $newSchedule->fetch();

                if ($newSchedule) {
                    $horizonDays = max(1, (int) _scheduler_setting($pdo, 'scheduler_horizon_days', '30'));
                    $contentAuto = _scheduler_setting($pdo, 'scheduler_content_auto', '0') === '1';
                    $genResult   = scheduler_expand_schedule_to_queue($pdo, $newSchedule, $horizonDays, false, null, $contentAuto);
                    $msg = 'Schedule added. Pre-generated ' . $genResult['generated'] . ' queue item(s) for the next ' . $horizonDays . ' days.';
                    redirect(route_admin('social-schedule'), $msg, 'success');
                }
            }

            redirect(route_admin('social-schedule'), 'Schedule added.', 'success');
        }
        redirect(route_admin('social-schedule'), 'Platform, day, and time are required.', 'warning');
    }

    if ($postAction === 'edit') {
        $editId         = (int) ($_POST['id']             ?? 0);
        $platform       = trim((string) ($_POST['platform']        ?? ''));
        $contentType    = trim((string) ($_POST['content_type']    ?? ''));
        $dayOfWeek      = trim((string) ($_POST['day_of_week']     ?? ''));
        $postTime       = trim((string) ($_POST['post_time']       ?? ''));
        $timezone       = trim((string) ($_POST['timezone']        ?? 'America/Phoenix'));
        $recurrenceType = trim((string) ($_POST['recurrence_type'] ?? 'weekly'));
        if (!in_array($recurrenceType, ['daily', 'weekly', 'monthly'], true)) {
            $recurrenceType = 'weekly';
        }

        if ($editId > 0 && $platform && $dayOfWeek && $postTime) {
            $pdo->prepare(
                'UPDATE social_post_schedules
                 SET platform = :platform, content_type = :ct, day_of_week = :dow,
                     post_time = :time, timezone = :tz, recurrence_type = :recur,
                     updated_at = NOW()
                 WHERE id = :id'
            )->execute([
                'platform' => $platform,
                'ct'       => $contentType,
                'dow'      => $dayOfWeek,
                'time'     => $postTime,
                'tz'       => $timezone,
                'recur'    => $recurrenceType,
                'id'       => $editId,
            ]);

            // Cancel future unposted items so re-expansion creates the correct new pattern
            $pdo->prepare(
                'UPDATE social_post_queue
                 SET status = "canceled", last_error = "Schedule rule edited — regenerated.", updated_at = NOW()
                 WHERE schedule_id = :sid
                   AND status IN ("draft","queued","scheduled")
                   AND scheduled_for > NOW()'
            )->execute(['sid' => $editId]);

            // Re-expand with new rule
            $updatedSchedule = $pdo->prepare('SELECT * FROM social_post_schedules WHERE id = :id LIMIT 1');
            $updatedSchedule->execute(['id' => $editId]);
            $updatedSchedule = $updatedSchedule->fetch();

            if ($updatedSchedule) {
                $horizonDays = max(1, (int) _scheduler_setting($pdo, 'scheduler_horizon_days', '30'));
                $contentAuto = _scheduler_setting($pdo, 'scheduler_content_auto', '0') === '1';
                $genResult   = scheduler_expand_schedule_to_queue($pdo, $updatedSchedule, $horizonDays, false, null, $contentAuto);
                $msg = 'Schedule updated. Re-generated ' . $genResult['generated'] . ' queue item(s) for the next ' . $horizonDays . ' days.';
                redirect(route_admin('social-schedule'), $msg, 'success');
            }

            redirect(route_admin('social-schedule'), 'Schedule updated.', 'success');
        }
        redirect(route_admin('social-schedule'), 'Platform, day, and time are required.', 'warning');
    }
}

$schedules = $pdo
    ? $pdo->query('SELECT * FROM social_post_schedules ORDER BY FIELD(day_of_week,"Sunday","Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"), post_time')
           ->fetchAll()
    : [];

// Load active platforms from DB; fall back to PTMD_PLATFORMS for graceful degradation
$activeSites = get_posting_sites(true);
$platforms   = $activeSites
    ? array_column($activeSites, 'display_name')
    : array_keys(PTMD_PLATFORMS);
$days            = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$recurrenceTypes = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];

// Detect edit mode
$editingId       = (int) ($_GET['edit'] ?? 0);
$editingSchedule = null;
if ($editingId > 0 && $pdo) {
    $stmt = $pdo->prepare('SELECT * FROM social_post_schedules WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editingId]);
    $editingSchedule = $stmt->fetch() ?: null;
}
?>

<?php if ($editingSchedule): ?>
<!-- ── Edit Schedule Form ──────────────────────────────────────────────────── -->
<div class="ptmd-panel p-xl mb-4" style="border:1px solid var(--ptmd-teal,#00c6b0)">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-pencil me-2 ptmd-text-teal"></i>Editing: <?php ee($editingSchedule['platform']); ?> / <?php ee($editingSchedule['day_of_week']); ?>
    </h2>
    <form method="post" action="<?php echo e(route_admin('social-schedule')); ?>">
        <input type="hidden" name="csrf_token"  value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action"     value="edit">
        <input type="hidden" name="id"          value="<?php ee((string) $editingSchedule['id']); ?>">
        <div class="row g-3">
            <div class="col-md-2">
                <label class="form-label">Platform</label>
                <select class="form-select" name="platform" required>
                    <option value="">— Select —</option>
                    <?php foreach ($platforms as $p): ?>
                        <option <?php echo $editingSchedule['platform'] === $p ? 'selected' : ''; ?>>
                            <?php ee($p); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Content Type</label>
                <input class="form-control" name="content_type"
                       value="<?php ee($editingSchedule['content_type']); ?>"
                       placeholder="teaser, clip…">
            </div>
            <div class="col-md-2">
                <label class="form-label">Recurrence</label>
                <select class="form-select" name="recurrence_type">
                    <?php foreach ($recurrenceTypes as $val => $label): ?>
                        <option value="<?php ee($val); ?>"
                            <?php echo ($editingSchedule['recurrence_type'] ?? 'weekly') === $val ? 'selected' : ''; ?>>
                            <?php ee($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Day / Day-of-Month</label>
                <select class="form-select" name="day_of_week" required>
                    <option value="">— Select —</option>
                    <?php foreach ($days as $d): ?>
                        <option <?php echo $editingSchedule['day_of_week'] === $d ? 'selected' : ''; ?>>
                            <?php ee($d); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Time</label>
                <input class="form-control" type="time" name="post_time"
                       value="<?php ee($editingSchedule['post_time']); ?>" required>
            </div>
            <div class="col-md-2 d-flex align-items-end gap-2">
                <button class="btn btn-ptmd-primary" type="submit">
                    <i class="fa-solid fa-floppy-disk me-1"></i>Save
                </button>
                <a href="<?php echo e(route_admin('social-schedule')); ?>" class="btn btn-ptmd-ghost">Cancel</a>
            </div>
        </div>
        <p class="ptmd-muted small mt-2 mb-0">
            <i class="fa-solid fa-triangle-exclamation me-1" style="color:var(--ptmd-warning)"></i>
            Saving will cancel all future unposted queue items for this rule and re-generate them with the new pattern.
        </p>
    </form>
</div>
<?php endif; ?>

<!-- ── Add schedule form ────────────────────────────────────────────────────── -->
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-plus me-2 ptmd-text-teal"></i>Add Posting Window
    </h2>
    <form method="post" action="<?php echo e(route_admin('social-schedule')); ?>">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action" value="add">
        <div class="row g-3">
            <div class="col-md-2">
                <label class="form-label">Platform</label>
                <select class="form-select" name="platform" required>
                    <option value="">— Select —</option>
                    <?php foreach ($platforms as $p): ?>
                        <option><?php ee($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Content Type</label>
                <input class="form-control" name="content_type" placeholder="teaser, clip, full documentary…">
            </div>
            <div class="col-md-2">
                <label class="form-label">Recurrence</label>
                <select class="form-select" name="recurrence_type">
                    <?php foreach ($recurrenceTypes as $val => $label): ?>
                        <option value="<?php ee($val); ?>"
                            <?php echo $val === 'weekly' ? 'selected' : ''; ?>>
                            <?php ee($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
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
                <label class="form-label">Time (schedule TZ)</label>
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

<!-- ── Schedule table ───────────────────────────────────────────────────────── -->
<div class="ptmd-panel p-lg">
    <h2 class="h6 mb-4">Current Schedule</h2>
    <?php if ($schedules): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>Platform</th>
                        <th>Content Type</th>
                        <th>Recurrence</th>
                        <th>Day</th>
                        <th>Time</th>
                        <th>TZ</th>
                        <th>Last Generated</th>
                        <th>Status</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedules as $s): ?>
                        <tr <?php echo $editingId === (int) $s['id'] ? 'style="outline:1px solid var(--ptmd-teal)"' : ''; ?>>
                            <td class="fw-500"><?php ee($s['platform']); ?></td>
                            <td class="ptmd-muted small"><?php ee($s['content_type']); ?></td>
                            <td>
                                <span class="badge bg-secondary" style="font-size:.65rem">
                                    <?php ee($recurrenceTypes[$s['recurrence_type'] ?? 'weekly'] ?? 'Weekly'); ?>
                                </span>
                            </td>
                            <td><?php ee($s['day_of_week']); ?></td>
                            <td class="ptmd-text-teal"><?php echo e(date('g:ia', strtotime($s['post_time']))); ?></td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)"><?php ee($s['timezone']); ?></td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo !empty($s['last_generated_at'])
                                    ? e(date('M j g:ia', strtotime($s['last_generated_at'])))
                                    : '—'; ?>
                            </td>
                            <td>
                                <?php
                                $runStatus = $s['last_run_status'] ?? '';
                                $badgeClass = match($runStatus) {
                                    'ok'     => 'bg-success',
                                    'review' => 'bg-warning text-dark',
                                    'error'  => 'bg-danger',
                                    default  => 'bg-secondary',
                                };
                                ?>
                                <?php if ($runStatus): ?>
                                    <span class="badge <?php ee($badgeClass); ?>" style="font-size:.6rem">
                                        <?php ee($runStatus); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="ptmd-muted" style="font-size:var(--text-xs)">not run</span>
                                <?php endif; ?>
                            </td>
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
                                <div class="d-flex gap-2">
                                    <!-- Edit -->
                                    <a href="<?php echo e(route_admin('social-schedule')); ?>?edit=<?php ee((string) $s['id']); ?>"
                                       class="btn btn-ptmd-ghost btn-sm"
                                       data-tippy-content="Edit rule">
                                        <i class="fa-solid fa-pencil"></i>
                                    </a>
                                    <!-- Delete -->
                                    <form method="post" action="<?php echo e(route_admin('social-schedule')); ?>" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="id" value="<?php ee((string) $s['id']); ?>">
                                        <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                            style="color:var(--ptmd-error)"
                                            data-confirm="Delete this schedule rule and cancel all future unposted items?"
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
        <p class="ptmd-muted small">No schedules yet. Use the form above to add a posting window.</p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_admin_footer.php'; ?>
