<?php
/**
 * PTMD Admin — Site Tests
 */

$pageTitle      = 'Site Tests | PTMD Admin';
$activePage     = 'site-tests';
$pageHeading    = 'Site End-to-End Tests';
$pageSubheading = 'Run a full smoke test and access control suite across public pages, admin pages, and APIs.';

include __DIR__ . '/_admin_head.php';
require_once __DIR__ . '/../inc/e2e_tests.php';

$results = null;

if (is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/site-tests.php', 'Invalid CSRF token.', 'danger');
    }

    $results = run_ptmd_e2e_tests();
}
?>

<div class="ptmd-panel p-xl mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h2 class="h5 mb-1">Run PTMD E2E Suite</h2>
            <p class="ptmd-muted small mb-0">This runs HTTP checks against this live environment using your current admin session.</p>
        </div>
        <form method="post" action="/admin/site-tests.php">
            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
            <button class="btn btn-ptmd-primary" type="submit">
                <i class="fa-solid fa-flask-vial me-2"></i>Run Tests
            </button>
        </form>
    </div>
</div>

<?php if ($results): ?>
    <div class="ptmd-panel p-xl mb-4">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <span class="ptmd-status <?php echo $results['ok'] ? 'ptmd-status-published' : 'ptmd-status-draft'; ?>">
                <?php echo $results['ok'] ? 'PASS' : 'FAIL'; ?>
            </span>
            <span class="ptmd-muted small">
                <?php ee((string) $results['summary']['passed']); ?> passed /
                <?php ee((string) $results['summary']['failed']); ?> failed /
                <?php ee((string) $results['summary']['total']); ?> total
            </span>
            <span class="ptmd-muted small">
                Runtime: <?php ee((string) $results['duration_ms']); ?>ms
            </span>
        </div>

        <?php if (!empty($results['error'])): ?>
            <div class="alert ptmd-alert alert-danger mb-0"><?php ee($results['error']); ?></div>
        <?php endif; ?>
    </div>

    <?php foreach ($results['groups'] as $group): ?>
        <div class="ptmd-panel p-lg mb-4">
            <h3 class="h6 mb-3"><?php ee($group['name']); ?></h3>
            <div class="table-responsive">
                <table class="ptmd-table w-100">
                    <thead>
                    <tr>
                        <th style="width:36%">Test</th>
                        <th style="width:10%">Result</th>
                        <th style="width:24%">Message</th>
                        <th style="width:30%">Details</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($group['tests'] as $test): ?>
                        <tr>
                            <td><?php ee($test['name']); ?></td>
                            <td>
                                <span class="ptmd-status <?php echo $test['ok'] ? 'ptmd-status-published' : 'ptmd-status-draft'; ?>">
                                    <?php echo $test['ok'] ? 'PASS' : 'FAIL'; ?>
                                </span>
                            </td>
                            <td><?php ee($test['message']); ?></td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php if (!empty($test['meta']) && is_array($test['meta'])): ?>
                                    <pre class="mb-0" style="white-space:pre-wrap;font-size:var(--text-xs);line-height:1.4"><?php ee(json_encode($test['meta'], JSON_PRETTY_PRINT)); ?></pre>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/_admin_footer.php'; ?>
