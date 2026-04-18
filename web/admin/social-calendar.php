<?php
/**
 * PTMD Admin — Social Calendar
 *
 * Month-view calendar showing social_post_queue items with status colour-coding.
 * Supports quick-create for single queue items and viewing recurrence rules.
 */

$pageTitle      = 'Social Calendar | PTMD Admin';
$activePage     = 'social-calendar';
$pageHeading    = 'Social Calendar';
$pageSubheading = 'Scheduled social posts by date. Colour-coded by status.';

include __DIR__ . '/_admin_head.php';

require_once __DIR__ . '/../inc/scheduler.php';

$pdo = get_db();

// ── Handle quick-create (single queue item from calendar click) ───────────────

if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/social-calendar.php', 'Invalid CSRF token.', 'danger');
    }

    $postAction = $_POST['_action'] ?? 'add';

    if ($postAction === 'add') {
        $platform     = trim((string) ($_POST['platform']     ?? ''));
        $contentType  = trim((string) ($_POST['content_type'] ?? ''));
        $caption      = trim((string) ($_POST['caption']      ?? ''));
        $scheduledFor = trim((string) ($_POST['scheduled_for'] ?? ''));
        $episodeId    = (int) ($_POST['episode_id'] ?? 0) ?: null;

        if ($platform !== '' && $scheduledFor !== '') {
            $pdo->prepare(
                'INSERT INTO social_post_queue
                 (episode_id, platform, content_type, caption, scheduled_for,
                  status, auto_generated, retry_count, created_at, updated_at)
                 VALUES (:eid, :platform, :ct, :caption, :sched,
                         "queued", 0, 0, NOW(), NOW())'
            )->execute([
                'eid'      => $episodeId,
                'platform' => $platform,
                'ct'       => $contentType !== '' ? $contentType : 'general',
                'caption'  => $caption,
                'sched'    => $scheduledFor,
            ]);
            redirect('/admin/social-calendar.php', 'Post queued on calendar.', 'success');
        }
        redirect('/admin/social-calendar.php', 'Platform and scheduled date/time are required.', 'warning');
    }

    if ($postAction === 'delete') {
        $delId = (int) ($_POST['id'] ?? 0);
        if ($delId > 0) {
            $pdo->prepare('DELETE FROM social_post_queue WHERE id = :id')->execute(['id' => $delId]);
            redirect('/admin/social-calendar.php', 'Queue item deleted.', 'success');
        }
    }

    if ($postAction === 'update_status') {
        $qId       = (int) ($_POST['id']     ?? 0);
        $newStatus = trim((string) ($_POST['status'] ?? ''));
        $allowed   = ['draft','queued','scheduled','posted','failed','canceled'];
        if ($qId > 0 && in_array($newStatus, $allowed, true)) {
            $pdo->prepare(
                'UPDATE social_post_queue SET status = :s, updated_at = NOW() WHERE id = :id'
            )->execute(['s' => $newStatus, 'id' => $qId]);
            redirect('/admin/social-calendar.php', 'Status updated.', 'success');
        }
    }
}

// ── Determine calendar month being viewed ────────────────────────────────────

$year  = max(2020, min(2040, (int) ($_GET['year']  ?? date('Y'))));
$month = max(1,    min(12,   (int) ($_GET['month'] ?? date('n'))));

$prevMonth = $month === 1  ? 12 : $month - 1;
$prevYear  = $month === 1  ? $year - 1 : $year;
$nextMonth = $month === 12 ? 1  : $month + 1;
$nextYear  = $month === 12 ? $year + 1 : $year;

$firstDay    = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = (int) date('t', $firstDay);
$startDow    = (int) date('w', $firstDay); // 0=Sun

// ── Fetch queue items for the month ──────────────────────────────────────────

$rangeStart = sprintf('%04d-%02d-01 00:00:00', $year, $month);
$rangeEnd   = sprintf('%04d-%02d-%02d 23:59:59', $year, $month, $daysInMonth);

$queueItems = [];
if ($pdo) {
    $stmt = $pdo->prepare(
        'SELECT q.id, q.platform, q.content_type, q.caption, q.scheduled_for, q.status,
                q.auto_generated, q.retry_count, q.schedule_id,
                e.title AS episode_title
         FROM social_post_queue q
         LEFT JOIN episodes e ON e.id = q.episode_id
         WHERE q.scheduled_for BETWEEN :s AND :e
         ORDER BY q.scheduled_for ASC'
    );
    $stmt->execute(['s' => $rangeStart, 'e' => $rangeEnd]);
    $rows = $stmt->fetchAll();

    // Group by day-of-month
    foreach ($rows as $row) {
        $day = (int) date('j', strtotime($row['scheduled_for']));
        $queueItems[$day][] = $row;
    }
}

// ── Fetch active schedule rules for reference sidebar ────────────────────────

$schedules = $pdo
    ? $pdo->query('SELECT * FROM social_post_schedules WHERE is_active = 1 ORDER BY day_of_week, post_time')->fetchAll()
    : [];

$platforms = ['YouTube', 'YouTube Shorts', 'TikTok', 'Instagram Reels', 'Facebook Reels', 'X'];
$episodes  = $pdo
    ? $pdo->query('SELECT id, title FROM episodes ORDER BY title')->fetchAll()
    : [];

// ── Status helpers ────────────────────────────────────────────────────────────

function cal_status_badge(string $status): string
{
    $map = [
        'draft'     => ['#6c757d', 'Draft'],
        'queued'    => ['#0d6efd', 'Queued'],
        'scheduled' => ['#6f42c1', 'Scheduled'],
        'processing'=> ['#fd7e14', 'Processing'],
        'posted'    => ['#198754', 'Posted'],
        'failed'    => ['#dc3545', 'Failed'],
        'canceled'  => ['#6c757d', 'Canceled'],
    ];
    [$colour, $label] = $map[$status] ?? ['#6c757d', $status];

    return '<span class="cal-badge" style="background:' . e($colour) . '">' . e($label) . '</span>';
}

$pageActions = '<a href="/admin/posts.php" class="btn btn-ptmd-outline btn-sm">
    <i class="fa-solid fa-list me-1"></i>Queue List
</a>
<a href="/admin/social-schedule.php" class="btn btn-ptmd-outline btn-sm">
    <i class="fa-solid fa-clock me-1"></i>Schedules
</a>';
?>

<!-- ── Inline calendar styles ──────────────────────────────────────────────── -->
<style>
.ptmd-cal-nav { display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; }
.ptmd-cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:2px; }
.ptmd-cal-header { display:grid; grid-template-columns:repeat(7,1fr); gap:2px; margin-bottom:2px; }
.ptmd-cal-header span { text-align:center; font-size:var(--text-xs); font-weight:600;
    padding:.25rem 0; color:var(--ptmd-muted,#6c757d); text-transform:uppercase; letter-spacing:.06em; }
.ptmd-cal-cell { min-height:90px; background:var(--ptmd-surface-2,#1a1d22); border-radius:6px;
    padding:.4rem; font-size:var(--text-xs); overflow:hidden; }
.ptmd-cal-cell.today { outline:2px solid var(--ptmd-teal,#00c6b0); }
.ptmd-cal-cell.empty { background:transparent; }
.ptmd-cal-day-num { font-weight:700; font-size:.75rem; margin-bottom:.25rem;
    color:var(--ptmd-muted,#6c757d); }
.cal-badge { display:inline-block; border-radius:3px; padding:1px 5px; font-size:.65rem;
    font-weight:600; color:#fff; line-height:1.4; white-space:nowrap; margin:.1rem 0; }
.cal-item { border-radius:4px; padding:2px 4px; margin:.1rem 0; cursor:pointer;
    overflow:hidden; line-height:1.3; background:var(--ptmd-surface-3,#23272e); }
.cal-item:hover { opacity:.8; }
.cal-item-platform { font-weight:600; }
.cal-more { font-size:.6rem; color:var(--ptmd-teal,#00c6b0); margin-top:.15rem; cursor:pointer; }
</style>

<div class="row g-4">
    <!-- ── Calendar ─────────────────────────────────────────────────────────── -->
    <div class="col-lg-9">
        <!-- Month nav -->
        <div class="ptmd-cal-nav">
            <a href="?year=<?php ee((string) $prevYear); ?>&month=<?php ee((string) $prevMonth); ?>"
               class="btn btn-ptmd-ghost btn-sm">
                <i class="fa-solid fa-chevron-left"></i>
            </a>
            <span class="fw-700" style="font-size:var(--text-lg)">
                <?php echo e(date('F Y', mktime(0, 0, 0, $month, 1, $year))); ?>
            </span>
            <a href="?year=<?php ee((string) $nextYear); ?>&month=<?php ee((string) $nextMonth); ?>"
               class="btn btn-ptmd-ghost btn-sm">
                <i class="fa-solid fa-chevron-right"></i>
            </a>
        </div>

        <!-- Day-of-week headers -->
        <div class="ptmd-cal-header">
            <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dow): ?>
                <span><?php ee($dow); ?></span>
            <?php endforeach; ?>
        </div>

        <!-- Calendar grid -->
        <div class="ptmd-cal-grid" id="calGrid">
            <?php
            $today    = (int) date('j');
            $isToday  = (int) date('n') === $month && (int) date('Y') === $year;
            $cellNum  = 0;

            // Leading empty cells
            for ($i = 0; $i < $startDow; $i++): ?>
                <div class="ptmd-cal-cell empty"></div>
            <?php $cellNum++; endfor; ?>

            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $cellClass = 'ptmd-cal-cell' . ($isToday && $d === $today ? ' today' : '');
                $items     = $queueItems[$d] ?? [];
                $shown     = array_slice($items, 0, 3);
                $extra     = count($items) - count($shown);
            ?>
                <div class="<?php echo $cellClass; ?>" data-day="<?php ee((string) $d); ?>">
                    <div class="ptmd-cal-day-num"><?php ee((string) $d); ?></div>

                    <?php foreach ($shown as $item): ?>
                        <div class="cal-item"
                             title="<?php ee($item['platform'] . ' — ' . $item['status'] . ' — ' . ($item['episode_title'] ?? 'no episode')); ?>"
                             data-bs-toggle="modal" data-bs-target="#calEventModal"
                             data-id="<?php ee((string) $item['id']); ?>"
                             data-platform="<?php ee($item['platform']); ?>"
                             data-status="<?php ee($item['status']); ?>"
                             data-caption="<?php ee((string) ($item['caption'] ?? '')); ?>"
                             data-scheduled="<?php ee($item['scheduled_for']); ?>"
                             data-auto="<?php ee((string) $item['auto_generated']); ?>"
                             data-retries="<?php ee((string) $item['retry_count']); ?>"
                        >
                            <?php echo cal_status_badge($item['status']); ?>
                            <span class="cal-item-platform"><?php ee($item['platform']); ?></span>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($extra > 0): ?>
                        <div class="cal-more"
                             data-bs-toggle="collapse"
                             data-bs-target="#extra-<?php ee((string) $year . $month . $d); ?>"
                             role="button">
                            +<?php ee((string) $extra); ?> more
                        </div>
                        <div class="collapse" id="extra-<?php ee((string) $year . $month . $d); ?>">
                            <?php foreach (array_slice($items, 3) as $item): ?>
                                <div class="cal-item"
                                     data-bs-toggle="modal" data-bs-target="#calEventModal"
                                     data-id="<?php ee((string) $item['id']); ?>"
                                     data-platform="<?php ee($item['platform']); ?>"
                                     data-status="<?php ee($item['status']); ?>"
                                     data-caption="<?php ee((string) ($item['caption'] ?? '')); ?>"
                                     data-scheduled="<?php ee($item['scheduled_for']); ?>"
                                     data-auto="<?php ee((string) $item['auto_generated']); ?>"
                                     data-retries="<?php ee((string) $item['retry_count']); ?>"
                                >
                                    <?php echo cal_status_badge($item['status']); ?>
                                    <span class="cal-item-platform"><?php ee($item['platform']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Quick-create button shown on hover via CSS -->
                    <div class="cal-quick-add mt-1" style="display:none">
                        <button class="btn btn-ptmd-ghost btn-sm"
                                style="font-size:.6rem;padding:1px 4px"
                                data-bs-toggle="modal" data-bs-target="#quickAddModal"
                                data-year="<?php ee((string) $year); ?>"
                                data-month="<?php ee((string) $month); ?>"
                                data-day="<?php ee((string) $d); ?>">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </div>
                </div>
            <?php endfor; ?>

            <?php
            // Trailing cells to complete the final week row
            $filled   = $startDow + $daysInMonth;
            $trailing = (7 - ($filled % 7)) % 7;
            for ($i = 0; $i < $trailing; $i++): ?>
                <div class="ptmd-cal-cell empty"></div>
            <?php endfor; ?>
        </div>

        <!-- Status legend -->
        <div class="d-flex flex-wrap gap-2 mt-3" style="font-size:var(--text-xs)">
            <?php foreach (['draft','queued','scheduled','posted','failed','canceled'] as $s): ?>
                <?php echo cal_status_badge($s); ?>
            <?php endforeach; ?>
            <span class="ptmd-muted">← Status legend</span>
        </div>
    </div>

    <!-- ── Sidebar: quick-create + schedule rules ───────────────────────────── -->
    <div class="col-lg-3">
        <!-- Quick add panel -->
        <div class="ptmd-panel p-lg mb-4">
            <h2 class="h6 mb-3"><i class="fa-solid fa-calendar-plus me-2 ptmd-text-teal"></i>Quick Add</h2>
            <form method="post" action="/admin/social-calendar.php">
                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                <input type="hidden" name="_action" value="add">
                <div class="mb-2">
                    <label class="form-label small">Platform</label>
                    <select class="form-select form-select-sm" name="platform" required>
                        <option value="">— Select —</option>
                        <?php foreach ($platforms as $p): ?>
                            <option><?php ee($p); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Episode (optional)</label>
                    <select class="form-select form-select-sm" name="episode_id">
                        <option value="">— None —</option>
                        <?php foreach ($episodes as $ep): ?>
                            <option value="<?php ee((string) $ep['id']); ?>"><?php ee($ep['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Content Type</label>
                    <input class="form-control form-control-sm" name="content_type" placeholder="teaser, clip…">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Schedule Date &amp; Time</label>
                    <input class="form-control form-control-sm" type="datetime-local"
                           name="scheduled_for" id="quickAddDateTime" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Caption (optional)</label>
                    <textarea class="form-control form-control-sm" name="caption" rows="2"
                              placeholder="Caption, hashtags…"></textarea>
                </div>
                <button class="btn btn-ptmd-primary btn-sm w-100" type="submit">
                    <i class="fa-solid fa-plus me-1"></i>Add to Queue
                </button>
            </form>
        </div>

        <!-- Active schedule rules -->
        <div class="ptmd-panel p-lg">
            <h2 class="h6 mb-3">
                <i class="fa-solid fa-clock me-2 ptmd-text-teal"></i>Active Rules
                <a href="/admin/social-schedule.php" class="btn btn-ptmd-ghost btn-sm ms-auto" style="font-size:.65rem">Edit</a>
            </h2>
            <?php if ($schedules): ?>
                <ul class="list-unstyled mb-0" style="font-size:var(--text-xs)">
                    <?php foreach ($schedules as $sched): ?>
                        <li class="d-flex justify-content-between align-items-center py-1 border-bottom"
                            style="border-color:var(--ptmd-border,rgba(255,255,255,.08))!important">
                            <div>
                                <div class="fw-600"><?php ee($sched['platform']); ?></div>
                                <div class="ptmd-muted">
                                    <?php ee($sched['day_of_week']); ?>
                                    <?php echo e(date('g:ia', strtotime($sched['post_time']))); ?>
                                    <span class="ms-1 badge bg-secondary" style="font-size:.55rem">
                                        <?php ee($sched['recurrence_type'] ?? 'weekly'); ?>
                                    </span>
                                </div>
                            </div>
                            <span class="badge bg-success" style="font-size:.55rem">Active</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="ptmd-muted small mb-0">No active schedule rules.</p>
            <?php endif; ?>
            <div class="mt-3">
                <a href="/admin/social-schedule.php" class="btn btn-ptmd-outline btn-sm w-100">
                    <i class="fa-solid fa-plus me-1"></i>Add Schedule Rule
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ── Event detail modal ────────────────────────────────────────────────────── -->
<div class="modal fade" id="calEventModal" tabindex="-1" aria-labelledby="calEventModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content" style="background:var(--ptmd-surface-2,#1a1d22);color:var(--ptmd-white,#f5f5f3)">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title h6" id="calEventModalLabel">Queue Item</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="ptmd-muted small">Platform</div>
                    <div id="modalPlatform" class="fw-600"></div>
                </div>
                <div class="mb-3">
                    <div class="ptmd-muted small">Status</div>
                    <div id="modalStatus"></div>
                </div>
                <div class="mb-3">
                    <div class="ptmd-muted small">Scheduled For</div>
                    <div id="modalScheduled" class="ptmd-text-teal"></div>
                </div>
                <div class="mb-3">
                    <div class="ptmd-muted small">Caption</div>
                    <div id="modalCaption" class="small" style="white-space:pre-wrap;max-height:80px;overflow-y:auto"></div>
                </div>
                <div class="mb-3 d-flex gap-3">
                    <div>
                        <div class="ptmd-muted small">Auto-generated</div>
                        <div id="modalAuto"></div>
                    </div>
                    <div>
                        <div class="ptmd-muted small">Retries</div>
                        <div id="modalRetries"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 d-flex gap-2">
                <!-- Update status -->
                <form method="post" action="/admin/social-calendar.php" class="d-flex gap-2 align-items-center">
                    <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                    <input type="hidden" name="_action" value="update_status">
                    <input type="hidden" name="id" id="modalStatusId">
                    <select class="form-select form-select-sm" name="status" id="modalStatusSelect" style="width:auto">
                        <?php foreach (['draft','queued','scheduled','posted','failed','canceled'] as $s): ?>
                            <option value="<?php ee($s); ?>"><?php ee(ucfirst($s)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-ptmd-primary btn-sm" type="submit">Save</button>
                </form>

                <!-- Delete -->
                <form method="post" action="/admin/social-calendar.php" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                    <input type="hidden" name="_action" value="delete">
                    <input type="hidden" name="id" id="modalDeleteId">
                    <button class="btn btn-sm btn-ptmd-ghost" style="color:var(--ptmd-error)"
                            data-confirm="Delete this queue item?" type="submit">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </form>

                <button type="button" class="btn btn-ptmd-ghost btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Quick-add modal (triggered by "+" on a cell) ─────────────────────────── -->
<div class="modal fade" id="quickAddModal" tabindex="-1" aria-labelledby="quickAddModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content" style="background:var(--ptmd-surface-2,#1a1d22);color:var(--ptmd-white,#f5f5f3)">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title h6" id="quickAddModalLabel">
                    <i class="fa-solid fa-calendar-plus me-2 ptmd-text-teal"></i>Quick Add Post
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="/admin/social-calendar.php">
                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                <input type="hidden" name="_action" value="add">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Platform</label>
                        <select class="form-select" name="platform" required>
                            <option value="">— Select —</option>
                            <?php foreach ($platforms as $p): ?>
                                <option><?php ee($p); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Episode (optional)</label>
                        <select class="form-select" name="episode_id">
                            <option value="">— None —</option>
                            <?php foreach ($episodes as $ep): ?>
                                <option value="<?php ee((string) $ep['id']); ?>"><?php ee($ep['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Content Type</label>
                        <input class="form-control" name="content_type" placeholder="teaser, clip, full documentary…">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Date &amp; Time</label>
                        <input class="form-control" type="datetime-local" name="scheduled_for" id="modalQuickDate" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Caption (optional)</label>
                        <textarea class="form-control" name="caption" rows="2"
                                  placeholder="Caption, hashtags, emojis…"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button class="btn btn-ptmd-primary" type="submit">
                        <i class="fa-solid fa-calendar-plus me-2"></i>Add to Queue
                    </button>
                    <button type="button" class="btn btn-ptmd-ghost" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<'JS'
<script>
// ── Populate event detail modal ───────────────────────────────────────────────
document.getElementById('calEventModal')?.addEventListener('show.bs.modal', (e) => {
    const btn = e.relatedTarget;
    if (!btn) return;
    const id        = btn.dataset.id        ?? '';
    const platform  = btn.dataset.platform  ?? '';
    const status    = btn.dataset.status    ?? '';
    const caption   = btn.dataset.caption   ?? '';
    const scheduled = btn.dataset.scheduled ?? '';
    const auto      = btn.dataset.auto      ?? '0';
    const retries   = btn.dataset.retries   ?? '0';

    document.getElementById('modalPlatform').textContent  = platform;
    document.getElementById('modalScheduled').textContent = scheduled;
    document.getElementById('modalCaption').textContent   = caption || '—';
    document.getElementById('modalAuto').textContent      = auto === '1' ? 'Yes' : 'No';
    document.getElementById('modalRetries').textContent   = retries;

    const statusEl = document.getElementById('modalStatus');
    if (statusEl) statusEl.innerHTML = escHtml(status.charAt(0).toUpperCase() + status.slice(1));

    const statusId  = document.getElementById('modalStatusId');
    const deleteId  = document.getElementById('modalDeleteId');
    const statusSel = document.getElementById('modalStatusSelect');
    if (statusId)  statusId.value  = id;
    if (deleteId)  deleteId.value  = id;
    if (statusSel) statusSel.value = status;
});

// ── Pre-fill quick-add modal date from cell ───────────────────────────────────
document.getElementById('quickAddModal')?.addEventListener('show.bs.modal', (e) => {
    const btn = e.relatedTarget;
    if (!btn) return;
    const y = btn.dataset.year  ?? '';
    const m = String(btn.dataset.month ?? '').padStart(2, '0');
    const d = String(btn.dataset.day   ?? '').padStart(2, '0');
    const dateInput = document.getElementById('modalQuickDate');
    if (dateInput && y && m && d) {
        dateInput.value = `${y}-${m}-${d}T09:00`;
    }
});

// ── Show "+Add" button on cell hover ─────────────────────────────────────────
document.querySelectorAll('.ptmd-cal-cell:not(.empty)').forEach(cell => {
    const btn = cell.querySelector('.cal-quick-add');
    if (!btn) return;
    cell.addEventListener('mouseenter', () => { btn.style.display = 'block'; });
    cell.addEventListener('mouseleave', () => { btn.style.display = 'none';  });
});
</script>
JS;
?>

<?php include __DIR__ . '/_admin_footer.php'; ?>
