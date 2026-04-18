<section class="ptmd-screen-assets ptmd-stack-md">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div class="ptmd-stack-sm">
            <h2 class="h6 mb-0">Asset Manager</h2>
            <p class="ptmd-muted mb-0 small">Trace asset usage, dependencies, and publish-readiness across active investigations.</p>
        </div>
        <span class="ptmd-chip"><i class="fa-solid fa-photo-film"></i> 486 tracked assets</span>
    </div>

    <div class="ptmd-asset-grid">
        <article class="ptmd-asset-card">
            <div class="d-flex justify-content-between align-items-center">
                <strong>CLIP-77</strong>
                <span class="ptmd-status ptmd-status-critical">Blocked</span>
            </div>
            <div class="small ptmd-muted">Used by: Harbor Response, Queue Item Q-418</div>
            <div class="small">Dependency: rights clearance pending</div>
        </article>
        <article class="ptmd-asset-card">
            <div class="d-flex justify-content-between align-items-center">
                <strong>TX-118 Transcript</strong>
                <span class="ptmd-status ptmd-status-warning">Review</span>
            </div>
            <div class="small ptmd-muted">Used by: Housing Follow-up, Hook Lab Variant A</div>
            <div class="small">Dependency: citation timestamp mismatch</div>
        </article>
        <article class="ptmd-asset-card">
            <div class="d-flex justify-content-between align-items-center">
                <strong>DOC-91 Packet</strong>
                <span class="ptmd-status ptmd-status-good">Ready</span>
            </div>
            <div class="small ptmd-muted">Used by: Policy Buried, Evidence Summary</div>
            <div class="small">All references verified in current revision</div>
        </article>
    </div>

    <div class="ptmd-state-row">
        <article class="ptmd-state-card"><h4>Empty state</h4><p>No orphaned assets for selected date range.</p></article>
        <article class="ptmd-state-card"><h4>No-results state</h4><p>Search returned no assets tagged “body-cam + unresolved”.</p></article>
        <article class="ptmd-state-card ptmd-state-card--stale"><h4>Stale indicator</h4><p>Asset usage index is 22m out of date.</p></article>
        <article class="ptmd-state-card"><h4>Loading state</h4><div class="ptmd-skeleton-line"></div></article>
        <article class="ptmd-state-card ptmd-state-card--error"><h4>Error state</h4><p>Unable to load dependency graph for one asset cluster.</p></article>
    </div>
</section>
