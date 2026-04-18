<section class="ptmd-screen-dashboard ptmd-stack-lg">
    <header class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div class="ptmd-stack-sm">
            <div class="ptmd-heading-section">
                Investigative command status
            </div>
            <h2 class="ptmd-heading-hero mb-0">
                Control Room Dashboard
            </h2>
            <p class="ptmd-muted mb-0">
                Prioritized by workflow risk, evidence gaps, and publish impact.</p>
        </div>
        <div class="ptmd-state-card ptmd-state-card--stale">
            <h4>Data freshness</h4>
            <p>Telemetry is 9m old. Last ingest from social APIs delayed.</p>
        </div>
    </header>

    <div class="row g-4">
        <div class="col-6 col-xl-3"><article class="glass-card ptmd-card-stat p-4"><div class="ptmd-kicker">Active Cases</div><div class="ptmd-card-metric-value ptmd-text-forensic">42</div><span class="ptmd-status ptmd-status-good">+6 this week</span></article></div>
        <div class="col-6 col-xl-3"><article class="glass-card ptmd-card-stat p-4"><div class="ptmd-kicker">Queue Health</div><div class="ptmd-card-metric-value">87%</div><span class="ptmd-status ptmd-status-warning">2 blocked</span></article></div>
        <div class="col-6 col-xl-3"><article class="glass-card ptmd-card-stat p-4"><div class="ptmd-kicker">Top Hook CTR</div><div class="ptmd-card-metric-value ptmd-text-gold">9.8%</div><span class="ptmd-status ptmd-status-good">Winning cohort</span></article></div>
        <div class="col-6 col-xl-3"><article class="glass-card ptmd-card-stat p-4"><div class="ptmd-kicker">AI Cost / 24h</div><div class="ptmd-card-metric-value ptmd-text-evidence">$28.20</div><span class="ptmd-status">Within budget</span></article></div>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <article class="ptmd-analytics-panel p-4 ptmd-stack-md">
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <h2 class="h6 mb-0">Trend Signals</h2>
                    <span class="ptmd-chip"><i class="fa-solid fa-wave-square"></i> Live ingest</span>
                </div>
                <div class="ptmd-chart-shell">
                    <div class="ptmd-signal-grid">
                        <article class="ptmd-signal-card"><div class="ptmd-signal-card__label">Retention drop</div><div class="ptmd-signal-card__value ptmd-text-evidence">-11%</div><div class="small ptmd-muted">Case: Harbor Pattern</div></article>
                        <article class="ptmd-signal-card"><div class="ptmd-signal-card__label">Best posting slot</div><div class="ptmd-signal-card__value ptmd-text-forensic">7:30 PM</div><div class="small ptmd-muted">Based on last 14 posts</div></article>
                        <article class="ptmd-signal-card"><div class="ptmd-signal-card__label">Hook fatigue risk</div><div class="ptmd-signal-card__value ptmd-text-gold">Moderate</div><div class="small ptmd-muted">3 variants overused</div></article>
                    </div>
                </div>
            </article>
        </div>
        <div class="col-xl-4">
            <article class="glass-card p-4 ptmd-stack-md">
                <div class="d-flex align-items-center justify-content-between">
                    <h2 class="h6 mb-0">Needs Attention Now</h2>
                    <span class="ptmd-status ptmd-status-critical">3 urgent</span>
                </div>
                <div class="ptmd-attention-list">
                    <div class="ptmd-attention-item"><span>Blocked dispatch: “Records Buried”</span><span class="ptmd-status ptmd-status-critical">Blocked</span></div>
                    <div class="ptmd-attention-item"><span>Asset dependency missing transcript reference</span><span class="ptmd-status ptmd-status-warning">Warning</span></div>
                    <div class="ptmd-attention-item"><span>Queue item stale for 14h in “Review”</span><span class="ptmd-status">Stale</span></div>
                </div>
                <div class="ptmd-state-row">
                    <article class="ptmd-state-card"><h4>Loading state</h4><div class="ptmd-skeleton-line"></div></article>
                    <article class="ptmd-state-card ptmd-state-card--error"><h4>Error state</h4><p>Signal API timeout. Last good snapshot retained.</p></article>
                </div>
            </article>
        </div>
    </div>
</section>
