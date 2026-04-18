</main><!-- /.ptmd-admin-content -->
</div><!-- /.ptmd-admin-shell -->

<div class="ptmd-command-palette" id="ptmdCommandPalette" aria-hidden="true">
    <div class="ptmd-command-panel">
        <input id="ptmdCommandInput" class="form-control" type="text" placeholder="Jump to dashboard, cases, queue, analytics..." aria-label="Command palette search">
        <div class="ptmd-command-list" role="listbox" aria-label="Command results">
            <a href="/admin/dashboard.php" class="ptmd-command-item"><i class="fa-solid fa-gauge"></i> Dashboard</a>
            <a href="/admin/cases.php" class="ptmd-command-item"><i class="fa-solid fa-film"></i> Case List</a>
            <a href="/admin/ai-tools.php" class="ptmd-command-item"><i class="fa-solid fa-bolt"></i> Hook Lab / AI Content</a>
            <a href="/admin/posts.php" class="ptmd-command-item"><i class="fa-solid fa-calendar-check"></i> Social Queue Board</a>
            <a href="/admin/monitor.php" class="ptmd-command-item"><i class="fa-solid fa-chart-line"></i> Analytics</a>
            <a href="/admin/settings.php" class="ptmd-command-item"><i class="fa-solid fa-gear"></i> Settings</a>
        </div>
        <div class="p-3 border-top" style="border-color:var(--ptmd-border-soft)!important">
            <button type="button" class="btn btn-ptmd-ghost btn-sm" data-ptmd-command-close>
                <i class="fa-solid fa-xmark me-1"></i> Close
            </button>
        </div>
    </div>
</div>

<aside class="ptmd-drawer" id="ptmdRightDrawer" aria-label="Detail drawer" aria-hidden="true">
    <div class="ptmd-drawer__panel glass-modal">
        <div class="d-flex align-items-center justify-content-between">
            <h2 class="h6 mb-0">Item Details</h2>
            <button type="button" class="btn btn-ptmd-ghost btn-sm" data-drawer-close aria-label="Close drawer">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="ptmd-empty-state">
            Select an item to inspect workflow state, blockers, and next actions.
        </div>
    </div>
</aside>

<div class="ptmd-modal" id="ptmdGlobalModal" aria-hidden="true">
    <div class="ptmd-modal__panel glass-modal">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="h6 mb-0">Modal</h2>
            <button type="button" class="btn btn-ptmd-ghost btn-sm" data-modal-close aria-label="Close modal">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <p class="ptmd-muted mb-0">Use this shell for confirmations, edits, and workflow prompts.</p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@latest/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@latest/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tippy.js@latest/dist/tippy-bundle.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/clipboard@latest/dist/clipboard.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@latest/dist/sweetalert2.all.min.js"></script>
<script src="/assets/js/app.js"></script>
<script src="/assets/js/admin/ui-foundation.js"></script>
<?php if (!empty($extraScripts)) echo $extraScripts; ?>
</body>
</html>
