<?php
/**
 * PTMD Admin — UI Foundation Showcase
 *
 * Internal reference page for the forensic glass component system.
 */

$pageTitle      = 'UI Foundation | PTMD Admin';
$activePage     = 'dashboard';
$pageHeading    = 'UI Foundation Showcase';
$pageSubheading = 'Reference implementations for dashboard, case list, hook lab, AI drawer, analytics, and shell primitives.';

include __DIR__ . '/_admin_head.php';
?>

<div class="ptmd-stack-lg">
    <?php include __DIR__ . '/partials/ui/dashboard-overview.php'; ?>
    <?php include __DIR__ . '/partials/ui/case-list.php'; ?>
    <?php include __DIR__ . '/partials/ui/hook-lab.php'; ?>
    <?php include __DIR__ . '/partials/ui/analytics-panels.php'; ?>

    <div class="d-flex gap-2">
        <button class="btn btn-ptmd-outline" type="button" data-drawer-target="#ptmdAiBotDrawer">
            <i class="fa-solid fa-robot me-2"></i>Open AI Bot Drawer
        </button>
        <button class="btn btn-ptmd-outline" type="button" data-modal-target="#ptmdGlobalModal">
            <i class="fa-solid fa-window-restore me-2"></i>Open Global Modal
        </button>
    </div>
</div>

<?php include __DIR__ . '/partials/ui/ai-bot-drawer.php'; ?>

<?php include __DIR__ . '/_admin_footer.php'; ?>
