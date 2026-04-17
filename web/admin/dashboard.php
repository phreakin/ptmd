<?php
/**
 * PTMD Admin — Dashboard
 */

$pageTitle    = 'Dashboard | PTMD Admin';
$activePage   = 'dashboard';
$pageHeading  = 'Dashboard';
$pageSubheading = 'Welcome back, ' . (current_admin()['username'] ?? 'Admin');

include __DIR__ . '/_admin_head.php';

$pdo = get_db();
$stats = ['episodes' => 0, 'queue' => 0, 'chat' => 0, 'ai' => 0];

if ($pdo) {
    $stats['episodes'] = (int) $pdo->query('SELECT COUNT(*) FROM episodes WHERE status = "published"')->fetchColumn();
    $stats['queue']    = (int) $pdo->query('SELECT COUNT(*) FROM social_post_queue WHERE status IN ("queued","scheduled")'  )->fetchColumn();
    $stats['chat']     = (int) $pdo->query('SELECT COUNT(*) FROM chat_messages WHERE status = "approved"')->fetchColumn();
    $stats['ai']       = (int) $pdo->query('SELECT COUNT(*) FROM ai_generations')->fetchColumn();
}

// Recent episodes
$recentEps = [];
if ($pdo) {
    $recentEps = $pdo->query('SELECT id, title, slug, status, published_at FROM episodes ORDER BY updated_at DESC LIMIT 6')->fetchAll();
}

// Recent queue
$recentQueue = [];
if ($pdo) {
    $recentQueue = $pdo->query(
        'SELECT q.id, q.platform, q.status, q.scheduled_for, e.title AS episode_title
         FROM social_post_queue q
         LEFT JOIN episodes e ON e.id = q.episode_id
         ORDER BY q.scheduled_for ASC
         LIMIT 6'
    )->fetchAll();
}
?>

<!-- Stat cards -->
<div class="row g-4 mb-5">
    <div class="col-6 col-lg-3">
        <div class="ptmd-card-stat">
            <div class="stat-icon" style="background:rgba(46,196,182,0.15)">
                <i class="fa-solid fa-film ptmd-text-teal"></i>
            </div>
            <div class="stat-value ptmd-text-teal"><?php ee((string) $stats['episodes']); ?></div>
            <div class="stat-label">Published Episodes</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ptmd-card-stat">
            <div class="stat-icon" style="background:rgba(255,214,10,0.15)">
                <i class="fa-solid fa-calendar-check ptmd-text-yellow"></i>
            </div>
            <div class="stat-value ptmd-text-yellow"><?php ee((string) $stats['queue']); ?></div>
            <div class="stat-label">Queued Posts</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ptmd-card-stat">
            <div class="stat-icon" style="background:rgba(193,18,31,0.12)">
                <i class="fa-solid fa-comments" style="color:#ff4d5a"></i>
            </div>
            <div class="stat-value" style="color:#ff4d5a"><?php ee((string) $stats['chat']); ?></div>
            <div class="stat-label">Approved Chat</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ptmd-card-stat">
            <div class="stat-icon" style="background:rgba(106,13,173,0.15)">
                <i class="fa-solid fa-wand-magic-sparkles" style="color:#c084fc"></i>
            </div>
            <div class="stat-value" style="color:#c084fc"><?php ee((string) $stats['ai']); ?></div>
            <div class="stat-label">AI Generations</div>
        </div>
    </div>
</div>

<!-- Quick actions -->
<div class="row g-3 mb-5">
    <div class="col-12">
        <div class="ptmd-panel p-lg">
            <h2 class="h6 mb-4 ptmd-muted text-uppercase" style="letter-spacing:.08em">Quick Actions</h2>
            <div class="d-flex flex-wrap gap-3">
                <a href="/admin/episodes.php" class="btn btn-ptmd-outline">
                    <i class="fa-solid fa-plus me-2"></i>New Episode
                </a>
                <a href="/admin/video-processor.php" class="btn btn-ptmd-outline">
                    <i class="fa-solid fa-scissors me-2"></i>Process Video
                </a>
                <a href="/admin/overlay-tool.php" class="btn btn-ptmd-outline">
                    <i class="fa-solid fa-layer-group me-2"></i>Apply Overlays
                </a>
                <a href="/admin/ai-tools.php" class="btn btn-ptmd-outline" style="border-color:rgba(106,13,173,0.4);color:#c084fc">
                    <i class="fa-solid fa-wand-magic-sparkles me-2"></i>AI Content Studio
                </a>
                <a href="/admin/social-schedule.php" class="btn btn-ptmd-outline">
                    <i class="fa-solid fa-calendar me-2"></i>Schedule Post
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Recent content -->
<div class="row g-4">

    <!-- Recent episodes -->
    <div class="col-lg-7">
        <div class="ptmd-panel p-lg">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="h6 mb-0">Recent Episodes</h2>
                <a href="/admin/episodes.php" class="btn btn-ptmd-ghost btn-sm">
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
                                    <a href="/admin/episodes.php?edit=<?php ee((string) $ep['id']); ?>"
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
                <p class="ptmd-muted small">No episodes yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Upcoming queue -->
    <div class="col-lg-5">
        <div class="ptmd-panel p-lg">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="h6 mb-0">Upcoming Queue</h2>
                <a href="/admin/posts.php" class="btn btn-ptmd-ghost btn-sm">
                    View All <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
            <?php if ($recentQueue): ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($recentQueue as $item): ?>
                        <div class="d-flex justify-content-between align-items-start gap-3"
                             style="padding-bottom:.75rem;border-bottom:1px solid var(--ptmd-border)">
                            <div>
                                <div class="small fw-600 mb-1"><?php ee($item['platform']); ?></div>
                                <div class="ptmd-muted" style="font-size:var(--text-xs)">
                                    <?php ee($item['episode_title'] ?? 'Manual'); ?>
                                </div>
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

<?php include __DIR__ . '/_admin_footer.php'; ?>
