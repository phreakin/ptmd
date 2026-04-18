<section class="ptmd-screen-case-detail ptmd-stack-md">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div class="ptmd-stack-sm">
            <h2 class="h6 mb-0">Case Detail</h2>
            <p class="ptmd-muted mb-0 small">Investigative workflow view with dependency links, blockers, and next actions.</p>
        </div>
        <span class="ptmd-status ptmd-status-critical">Workflow blocked</span>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <article class="glass-card p-4 ptmd-stack-md">
                <div class="d-flex align-items-center justify-content-between">
                    <h3 class="h6 mb-0">Case Timeline: Harbor Response</h3>
                    <span class="ptmd-chip"><i class="fa-solid fa-folder-tree"></i> 12 linked assets</span>
                </div>
                <div class="ptmd-evidence-list">
                    <div class="ptmd-evidence-item"><span>Evidence marker: Timestamp discrepancy at filing segment 03.</span><span class="ptmd-status ptmd-status-warning">Review</span></div>
                    <div class="ptmd-evidence-item"><span>Narrative draft linked to transcript TX-118 and source packet DOC-91.</span><span class="ptmd-status ptmd-status-good">Linked</span></div>
                    <div class="ptmd-evidence-item"><span>Publishing track blocked by missing rights confirmation for clip CLIP-77.</span><span class="ptmd-status ptmd-status-critical">Blocked</span></div>
                </div>
                <div class="ptmd-state-row">
                    <article class="ptmd-state-card"><h4>Empty state</h4><p>No unresolved contradictions for this subsection.</p></article>
                    <article class="ptmd-state-card"><h4>Loading state</h4><div class="ptmd-skeleton-line"></div></article>
                    <article class="ptmd-state-card ptmd-state-card--error"><h4>Error state</h4><p>Failed to fetch external filing metadata.</p></article>
                </div>
            </article>
        </div>
        <div class="col-xl-4">
            <aside class="ptmd-case-detail-rail">
                <article class="ptmd-state-card ptmd-state-card--blocked">
                    <h4>Blocked workflow indicator</h4>
                    <p>Cannot move to “Ready for Queue” until legal verification is complete.</p>
                </article>
                <article class="ptmd-state-card ptmd-state-card--stale">
                    <h4>Stale data indicator</h4>
                    <p>Last sync with social readiness checks: 18m ago.</p>
                </article>
                <article class="ptmd-state-card">
                    <h4>Next best action</h4>
                    <p>Assign legal follow-up and regenerate queue brief with updated citations.</p>
                </article>
            </aside>
        </div>
    </div>
</section>
