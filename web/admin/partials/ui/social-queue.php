<section class="ptmd-screen-queue ptmd-stack-md">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div class="ptmd-stack-sm">
            <h2 class="h6 mb-0">Social Queue</h2>
            <p class="ptmd-muted mb-0 small">Control-room triage for pending, blocked, stale, and at-risk dispatches.</p>
        </div>
        <span class="ptmd-status ptmd-status-warning">2 blocked items</span>
    </div>

    <div class="ptmd-queue-board">
        <section class="ptmd-queue-column">
            <div class="d-flex justify-content-between align-items-center">
                <strong>Ready</strong>
                <span class="ptmd-chip">4</span>
            </div>
            <article class="ptmd-queue-ticket">
                <strong class="small">Policy Buried — Hook A</strong>
                <div class="small ptmd-muted">YT Shorts / IG Reels</div>
                <span class="ptmd-status ptmd-status-good">Ready</span>
            </article>
            <article class="ptmd-queue-ticket ptmd-queue-ticket--warning">
                <strong class="small">Housing Board Follow-up</strong>
                <div class="small ptmd-muted">Caption confidence below threshold</div>
                <span class="ptmd-status ptmd-status-warning">Warning</span>
            </article>
        </section>

        <section class="ptmd-queue-column">
            <div class="d-flex justify-content-between align-items-center">
                <strong>Review</strong>
                <span class="ptmd-chip">3</span>
            </div>
            <article class="ptmd-queue-ticket">
                <strong class="small">Harbor Timeline</strong>
                <div class="small ptmd-muted">Awaiting editorial sign-off</div>
                <span class="ptmd-status">In review</span>
            </article>
            <article class="ptmd-queue-ticket">
                <strong class="small">Records Withheld</strong>
                <div class="small ptmd-muted">Needs citation audit</div>
                <span class="ptmd-status ptmd-status-warning">At risk</span>
            </article>
        </section>

        <section class="ptmd-queue-column">
            <div class="d-flex justify-content-between align-items-center">
                <strong>Blocked</strong>
                <span class="ptmd-chip">2</span>
            </div>
            <article class="ptmd-queue-ticket ptmd-queue-ticket--blocked">
                <strong class="small">Harbor Response Clip</strong>
                <div class="small ptmd-muted">Rights hold unresolved for CLIP-77</div>
                <span class="ptmd-status ptmd-status-critical">Blocked</span>
            </article>
            <article class="ptmd-queue-ticket ptmd-queue-ticket--blocked">
                <strong class="small">Housing Follow-up Thread</strong>
                <div class="small ptmd-muted">Missing transcript TX-118</div>
                <span class="ptmd-status ptmd-status-critical">Dependency missing</span>
            </article>
        </section>
    </div>

    <div class="ptmd-state-row">
        <article class="ptmd-state-card"><h4>No-results state</h4><p>No items matched filter “TikTok + Urgent + Approved”.</p></article>
        <article class="ptmd-state-card ptmd-state-card--stale"><h4>Stale data</h4><p>Queue execution status delayed by 11m.</p></article>
        <article class="ptmd-state-card"><h4>Loading state</h4><div class="ptmd-skeleton-line"></div></article>
        <article class="ptmd-state-card ptmd-state-card--error"><h4>Error state</h4><p>Dispatch worker unreachable for one publishing site.</p></article>
    </div>
</section>
