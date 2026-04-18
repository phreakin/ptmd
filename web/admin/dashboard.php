<?php
/**
 * PTMD Admin — Dashboard
 */

require_once __DIR__ . '/../inc/bootstrap.php';

$pageTitle      = 'Control Room | PTMD Admin';
$activePage     = 'dashboard';
$pageHeading    = 'Control Room';
$pageSubheading = 'Live operational overview for publishing, queue health, AI activity, and case momentum.';

include __DIR__ . '/_admin_head.php';

$pdo = get_db();
$stats = ['cases' => 0, 'queue' => 0, 'chat' => 0, 'ai' => 0];

if ($pdo) {
    $stats['cases'] = (int) $pdo->query('SELECT COUNT(*) FROM cases WHERE status = "published"')->fetchColumn();
    $stats['queue']    = (int) $pdo->query('SELECT COUNT(*) FROM social_post_queue WHERE status IN ("queued","scheduled")'  )->fetchColumn();
    $stats['chat']     = (int) $pdo->query('SELECT COUNT(*) FROM chat_messages WHERE status = "approved"')->fetchColumn();
    $stats['ai']       = (int) $pdo->query('SELECT COUNT(*) FROM ai_generations')->fetchColumn();
}

// Recent Cases
$recentEps = [];
if ($pdo) {
    $recentEps = $pdo->query('SELECT id, title, slug, status, published_at FROM cases ORDER BY updated_at DESC LIMIT 6')->fetchAll();
}

// Recent queue
$recentQueue = [];
if ($pdo) {
    $recentQueue = $pdo->query(
        'SELECT q.id, q.platform, q.status, q.scheduled_for, e.title AS case_title
         FROM social_post_queue q
         LEFT JOIN cases e ON e.id = q.case_id
         ORDER BY q.scheduled_for ASC
         LIMIT 6'
    )->fetchAll();
}

$blockedQueue = 0;
$newCases = 0;
$publishedCases = 0;
foreach ($recentQueue as $item) {
    if (in_array((string) ($item['status'] ?? ''), ['failed', 'canceled'], true)) {
        $blockedQueue++;
    }
}
foreach ($recentEps as $caseItem) {
    if (($caseItem['status'] ?? '') === 'published') {
        $publishedCases++;
    }
    if (!empty($caseItem['published_at']) && strtotime((string) $caseItem['published_at']) >= strtotime('-7 days')) {
        $newCases++;
    }
}
?>

<div class="ptmd-screen-dashboard">
<div class="ptmd-alert-banner mb-4">
    <div class="ptmd-alert-banner__icon"><i class="fa-solid fa-sparkles"></i></div>
    <div class="ptmd-alert-banner__content">
        <div class="ptmd-kicker">Control Pulse</div>
        <div class="ptmd-alert-banner__title">Attention: <?php ee((string) $blockedQueue); ?> blocked queue items · New this week: <?php ee((string) $newCases); ?> cases</div>
    </div>
    <div class="ptmd-alert-banner__actions">
        <a href="/admin/posts.php" class="btn btn-ptmd-outline btn-sm"><i class="fa-solid fa-calendar-check me-2"></i>Resolve Queue</a>
        <a href="/admin/cases.php?action=new" class="btn btn-ptmd-teal btn-sm"><i class="fa-solid fa-plus me-2"></i>Create Case</a>
    </div>
</div>

<div class="row g-4 mb-5">
    <div class="col-6 col-lg-3">
        <div class="ptmd-card-stat">
            <div class="stat-icon" style="background:rgba(46,196,182,0.15)">
                <i class="fa-solid fa-film ptmd-text-teal"></i>
            </div>
            <div class="stat-value ptmd-text-teal">
                <?php ee((string) $stats['cases']); ?>
            </div>
            <div class="stat-label">
                Published Cases
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ptmd-card-stat">
            <div class="stat-icon" style="background:rgba(255,214,10,0.15)">
                <i class="fa-solid fa-calendar-check ptmd-text-yellow"></i>
            </div>
            <div class="stat-value ptmd-text-yellow"><?php ee((string) $stats['queue']); ?></div>
            <div class="stat-label">Queue Load</div>
            <span class="ptmd-chip"><i class="fa-solid fa-triangle-exclamation"></i><?php ee((string) $blockedQueue); ?> blocked</span>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ptmd-card-stat">
            <div class="stat-icon" style="background:rgba(193,18,31,0.12)">
                <i class="fas fa-comments" style="color:#ff4d5a"></i>
            </div>
            <div class="stat-value" style="color:#ff4d5a"><?php ee((string) $stats['chat']); ?></div>
            <div class="stat-label">
                <span class="ptmd-status ptmd-status-approved" style="font-size:var(--text-xs)">Approved</span>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ptmd-card-stat">
            <div class="stat-icon">
                <i class="fa-solid fa-wand-magic-sparkles" style="color:#c084fc"></i>
            </div>
            <div class="stat-value" style="color:#c084fc"><?php ee((string) $stats['ai']); ?></div>
            <div class="stat-label">AI Throughput</div>
            <span class="ptmd-chip"><i class="fa-solid fa-wand-magic-sparkles"></i>On Budget</span>
        </div>
    </div>
</div>

<div class="row g-4 mb-5">
    <div class="col-lg-8">
        <div class="ptmd-panel p-lg h-100">
            <div class="d-flex align-items-center justify-content-between mb-4">
                <h2 class="h6 mb-0"><i class="fa-solid fa-list-check me-2 ptmd-text-teal"></i>What Needs Action</h2>
                <span class="ptmd-chip">Priority Queue</span>
            </div>
            <div class="ptmd-timeline">
                <div class="ptmd-timeline-item">
                    <span class="ptmd-timeline-dot ptmd-timeline-dot--warn"></span>
                    <div>
                        <div class="fw-600"><?php ee((string) $blockedQueue); ?> blocked social dispatches</div>
                        <div class="ptmd-muted small">Review failures and requeue where needed.</div>
                    </div>
                    <a href="/admin/posts.php" class="btn btn-ptmd-ghost btn-sm">Open</a>
                </div>
                <div class="ptmd-timeline-item">
                    <span class="ptmd-timeline-dot ptmd-timeline-dot--ok"></span>
                    <div>
                        <div class="fw-600"><?php ee((string) $publishedCases); ?> of last 6 cases are published</div>
                        <div class="ptmd-muted small">Editorial cadence is stable.</div>
                    </div>
                    <a href="/admin/cases.php" class="btn btn-ptmd-ghost btn-sm">Review</a>
                </div>
                <div class="ptmd-timeline-item">
                    <span class="ptmd-timeline-dot ptmd-timeline-dot--info"></span>
                    <div>
                        <div class="fw-600"><?php ee((string) $stats['ai']); ?> AI generations available for reuse</div>
                        <div class="ptmd-muted small">Promote winning hooks into queue content.</div>
                    </div>
                    <a href="/admin/ai-tools.php" class="btn btn-ptmd-ghost btn-sm">Launch</a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="ptmd-panel p-lg h-100">
            <h2 class="h6 mb-4"><i class="fa-solid fa-robot me-2" style="color:#c084fc"></i>The Analyst Insight</h2>
            <div class="ptmd-insight-card">
                <div class="ptmd-kicker">Suggested Next Move</div>
                <div class="fw-600 mb-2">Boost posts tied to newest published case this week.</div>
                <div class="ptmd-muted small mb-3">Queue engagement is strongest when posts follow within 4 hours of publish.</div>
                <a href="/admin/ai-assistant.php" class="btn btn-ptmd-outline btn-sm"><i class="fa-solid fa-brain me-2"></i>Ask The Analyst</a>
            </div>
        </div>
    </div>
</div>

<!-- Quick actions -->
<div class="row g-3 mb-5">
    <div class="col-12">
        <div class="ptmd-panel p-lg">
            <h2 class="h6 mb-4 ptmd-muted text-uppercase" style="letter-spacing:.08em">Quick Actions</h2>
            <div class="d-flex flex-wrap gap-3">
                <a href="<?php ee(route_admin('cases')); ?>" class="btn btn-ptmd-outline">
                    <i class="fa-solid fa-plus me-2"></i>New case
                </a>
                <a href="<?php ee(route_admin('video-processor')); ?>" class="btn btn-ptmd-outline">
                    <i class="fa-solid fa-scissors me-2"></i>Process Video
                </a>
                <a href="<?php ee(route_admin('overlay-tool')); ?>" class="btn btn-ptmd-outline">
                    <i class="fa-solid fa-layer-group me-2"></i>Apply Overlays
                </a>
                <a href="<?php ee(route_admin('ai-tools')); ?>" class="btn btn-ptmd-outline" style="border-color:rgba(106,13,173,0.4);color:#c084fc">
                    <i class="fa-solid fa-wand-magic-sparkles me-2"></i>AI Content Studio
                </a>
                <a href="<?php ee(route_admin('ai-assistant')); ?>" class="btn btn-ptmd-outline" style="border-color:rgba(46,196,182,0.4);color:var(--ptmd-teal)">
                    <i class="fa-solid fa-robot me-2"></i>The Analyst
                </a>
                <a href="<?php ee(route_admin('social-schedule')); ?>" class="btn btn-ptmd-outline">
                    <i class="fa-solid fa-calendar me-2"></i>Schedule Post
                </a>
                <a href="<?php ee(route_admin('monitor')); ?>" class="btn btn-ptmd-outline">
                    <i class="fa-solid fa-chart-line me-2"></i>Intelligence
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Recent content -->
<div class="row g-4">

    <!-- Recent cases -->
    <div class="col-lg-7">
        <div class="ptmd-panel p-lg">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="h6 mb-0">Recent Cases</h2>
                <a href="<?php ee(route_admin('cases')); ?>" class="btn btn-ptmd-ghost btn-sm">
                    View All <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
            <?php if ($recentEps): ?>
                <table class="ptmd-table w-100">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Status</th>
                            <th>Published</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentEps as $ep): ?>
                            <tr>
                                <td>
                                    <a href="<?php ee(route_admin('cases', ['edit' => (string) $ep['id']])); ?>"
                                       class="ptmd-text-muted fw-500">
                                        <?php ee($ep['title']); ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="ptmd-status ptmd-status-<?php ee($ep['status']); ?>">
                                        <?php ee($ep['status']); ?>
                                    </span>
                                </td>
                                <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                    <?php echo $ep['published_at'] ? e(date('M j, Y', strtotime($ep['published_at']))) : '—'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="ptmd-muted small">No cases yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Upcoming queue -->
    <div class="col-lg-5">
        <div class="ptmd-panel p-lg">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="h6 mb-0">Upcoming Queue</h2>
                <a href="<?php ee(route_admin('posts')); ?>" class="btn btn-ptmd-ghost btn-sm">
                    View All <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
            <?php if ($recentQueue): ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($recentQueue as $item): ?>
                        <div class="ptmd-queue-mini-card">
                            <div>
                                <div class="small fw-600 mb-1"><?php ee($item['platform']); ?></div>
                                <div class="ptmd-muted" style="font-size:var(--text-xs)"><?php ee($item['case_title'] ?? 'Manual'); ?></div>
                            </div>
                            <div class="text-end">
                                <span class="ptmd-status ptmd-status-<?php ee($item['status']); ?> d-block mb-1" style="font-size:var(--text-xs)">
                                    <?php ee($item['status']); ?>
                                </span>
                                <span class="ptmd-muted" style="font-size:var(--text-xs)">
                                    <?php echo $item['scheduled_for'] ? e(date('M j, g:ia', strtotime($item['scheduled_for']))) : '—'; ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="ptmd-muted small">Queue is empty.</p>
            <?php endif; ?>
        </div>
    </div>

</div>

</div>
<?php include __DIR__ . '/_admin_footer.php'; ?>
