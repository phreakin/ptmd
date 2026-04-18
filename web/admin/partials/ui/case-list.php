<section class="ptmd-stack-md">
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div class="ptmd-stack-sm">
            <h2 class="h6 mb-0">Case List</h2>
            <p class="ptmd-muted mb-0 small">Triage by publish state, blocked dependencies, and queue urgency.</p>
        </div>
        <div class="ptmd-tabs" data-view-switch="#ptmdCaseHybrid">
            <button class="ptmd-tab" type="button" data-view="table" aria-pressed="true">Table</button>
            <button class="ptmd-tab" type="button" data-view="card" aria-pressed="false">Card</button>
        </div>
    </div>

    <div class="ptmd-state-row">
        <article class="ptmd-state-card ptmd-state-card--warning">
            <h4>Needs triage</h4>
            <p>5 cases have unresolved workflow blockers.</p>
        </article>
        <article class="ptmd-state-card ptmd-state-card--stale">
            <h4>Stale data</h4>
            <p>2 records not synced with analytics in 30m.</p>
        </article>
        <article class="ptmd-state-card">
            <h4>No results state</h4>
            <p>Filter: “High Risk + Draft” returned no matches.</p>
        </article>
    </div>

    <div id="ptmdCaseHybrid" class="ptmd-list-hybrid" data-view-mode="table">
        <article class="ptmd-list-hybrid-item glass-table-row">
            <div><strong>How Policy Buried the Evidence</strong><div class="small ptmd-muted">Updated 2h ago</div></div>
            <span class="ptmd-status ptmd-status-good">Published</span>
            <span class="small ptmd-muted">Queue: 5</span>
            <span class="small ptmd-muted">Risk: Low</span>
            <button class="btn btn-ptmd-ghost btn-sm" type="button" data-drawer-target="#ptmdRightDrawer">Inspect</button>
        </article>
        <article class="ptmd-list-hybrid-item glass-table-row">
            <div><strong>Records Withheld in Housing Board Inquiry</strong><div class="small ptmd-muted">Updated 28m ago</div></div>
            <span class="ptmd-status ptmd-status-warning">In Review</span>
            <span class="small ptmd-muted">Queue: 2</span>
            <span class="small ptmd-muted">Risk: Moderate</span>
            <button class="btn btn-ptmd-ghost btn-sm" type="button" data-drawer-target="#ptmdRightDrawer">Inspect</button>
        </article>
        <article class="ptmd-list-hybrid-item glass-table-row">
            <div><strong>Timeline Conflict: Harbor Response</strong><div class="small ptmd-muted">Updated 7m ago</div></div>
            <span class="ptmd-status ptmd-status-critical">Blocked</span>
            <span class="small ptmd-muted">Queue: 1</span>
            <span class="small ptmd-muted">Risk: High</span>
            <button class="btn btn-ptmd-ghost btn-sm" type="button" data-drawer-target="#ptmdRightDrawer">Inspect</button>
        </article>
    </div>

    <div class="glass-card p-4 ptmd-stack-sm">
        <div class="d-flex align-items-center justify-content-between">
            <h3 class="h6 mb-0">Dependency Visibility</h3>
            <span class="ptmd-chip"><i class="fa-solid fa-link"></i> Asset links</span>
        </div>
        <div class="ptmd-evidence-list">
            <div class="ptmd-evidence-item"><span>Case “Harbor Response” is waiting on transcript asset TX-118.</span><span class="ptmd-status ptmd-status-critical">Missing</span></div>
            <div class="ptmd-evidence-item"><span>Case “Housing Board Inquiry” uses footage CLIP-22 and CLIP-47.</span><span class="ptmd-status ptmd-status-good">Ready</span></div>
            <div class="ptmd-evidence-item"><span>Case “Policy Buried” references 3 external citations pending verification.</span><span class="ptmd-status ptmd-status-warning">Review</span></div>
        </div>
        <div class="ptmd-state-row">
            <article class="ptmd-state-card"><h4>Empty state</h4><p>No open dependencies for selected filter.</p></article>
            <article class="ptmd-state-card"><h4>Loading state</h4><div class="ptmd-skeleton-line"></div></article>
            <article class="ptmd-state-card ptmd-state-card--error"><h4>Error state</h4><p>Dependency graph failed to load for 1 case.</p></article>
        </div>
    </div>
</section>
