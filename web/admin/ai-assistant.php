<?php
$pageTitle = 'The Analyst | PTMD Admin';
$activePage = 'ai-assistant';
$pageHeading = 'The Analyst';
$pageSubheading = 'Context-aware operational intelligence for cases, dispatch, assets, and workflow pressure.';
$pageShellClass = 'ptmd-screen-ai-assistant';
$extraScripts = '<script src="/assets/js/admin/ai-assistant.js"></script>';
require __DIR__ . '/_admin_head.php';
?>

<section class="analyst-page ptmd-ai-bot">
    <header class="analyst-header">
        <div class="analyst-header__content">
            <p class="analyst-eyebrow">Operator Workspace</p>
            <h2 class="analyst-hero-title">Operational insight, recommendations, and context-aware reasoning in one working surface.</h2>
            <p class="analyst-subtitle">
                Ask what changed, what is blocked, or what deserves attention next without losing the live admin context around you.
            </p>
        </div>

        <div class="analyst-header-actions">
            <button type="button" class="btn btn-ptmd-ghost btn-sm analyst-header-prompt" data-analyst-prompt="What needs attention right now?">
                <i class="fa-solid fa-bolt"></i>
                <span>What needs attention?</span>
            </button>
            <button type="button" class="btn btn-ptmd-ghost btn-sm analyst-header-prompt" data-analyst-prompt="Summarize this page">
                <i class="fa-solid fa-file-lines"></i>
                <span>Summarize this page</span>
            </button>
            <button type="button" class="btn btn-ptmd-ghost btn-sm analyst-header-prompt" data-analyst-prompt="Show current bottlenecks">
                <i class="fa-solid fa-road-circle-exclamation"></i>
                <span>Show bottlenecks</span>
            </button>
        </div>
    </header>

    <section class="analyst-context-grid">
        <article class="analyst-summary-card glass-card">
            <div class="analyst-summary-card__top">
                <div class="analyst-summary-card__title-group">
                    <span class="analyst-summary-card__icon"><i class="fa-solid fa-bullseye"></i></span>
                    <div>
                        <h3>Current Focus</h3>
                        <span class="ptmd-chip analyst-chip">System</span>
                    </div>
                </div>
                <div class="analyst-summary-card__metric">3 alerts</div>
            </div>
            <p class="analyst-copy analyst-summary-card__body">
                Dispatch and review pressure are elevated across active workflow areas.
            </p>
            <div class="analyst-summary-card__footer">
                <span class="analyst-meta analyst-summary-card__source">Control Room</span>
                <a href="<?php ee(route_admin('control-room')); ?>" class="btn btn-ptmd-ghost btn-sm analyst-action-link">Open Control Room</a>
            </div>
        </article>

        <article class="analyst-summary-card glass-card">
            <div class="analyst-summary-card__top">
                <div class="analyst-summary-card__title-group">
                    <span class="analyst-summary-card__icon"><i class="fa-solid fa-folder-open"></i></span>
                    <div>
                        <h3>Active Object</h3>
                        <span class="ptmd-chip analyst-chip">Case</span>
                    </div>
                </div>
            </div>
            <p class="analyst-copy analyst-summary-card__body">
                No object selected yet. Open a case, clip, or post to let The Analyst anchor recommendations.
            </p>
            <div class="analyst-summary-card__footer">
                <span class="analyst-meta analyst-summary-card__source">Current Page</span>
            </div>
        </article>

        <article class="analyst-summary-card glass-card">
            <div class="analyst-summary-card__top">
                <div class="analyst-summary-card__title-group">
                    <span class="analyst-summary-card__icon"><i class="fa-solid fa-waveform-lines"></i></span>
                    <div>
                        <h3>System Watch</h3>
                        <span class="ptmd-chip analyst-chip">Signal</span>
                    </div>
                </div>
                <div class="analyst-summary-card__metric">2 warnings</div>
            </div>
            <p class="analyst-copy analyst-summary-card__body">
                Recent failures and stale content are beginning to cluster around Dispatch and Asset Vault.
            </p>
            <div class="analyst-summary-card__footer">
                <span class="analyst-meta analyst-summary-card__source">Watchtower</span>
                <a href="<?php ee(route_admin('intelligence')); ?>" class="btn btn-ptmd-ghost btn-sm analyst-action-link">Open Watchtower</a>
            </div>
        </article>
    </section>

    <div class="analyst-main-layout">
        <div class="analyst-conversation-column">
            <section class="analyst-top-strip glass-card">
                <div class="analyst-section-head analyst-section-head--inline">
                    <div>
                        <p class="analyst-section-kicker">Conversation</p>
                        <h3 class="analyst-section-title">Quick Analysis</h3>
                        <p class="analyst-section-copy">Use a prompt, choose a scope, or ask your own question.</p>
                    </div>
                </div>
                <div class="analyst-top-strip__right">
                    <span class="ptmd-chip analyst-chip">Ledger Intelligence</span>
                    <span class="ptmd-chip analyst-chip analyst-chip--muted">Context Aware</span>
                </div>
            </section>

            <div class="analyst-prompt-chips">
                <button type="button" class="analyst-prompt-chip" data-analyst-prompt="What needs attention right now?">What needs attention right now?</button>
                <button type="button" class="analyst-prompt-chip" data-analyst-prompt="Summarize this page">Summarize this page</button>
                <button type="button" class="analyst-prompt-chip" data-analyst-prompt="Show current bottlenecks">Show current bottlenecks</button>
                <button type="button" class="analyst-prompt-chip" data-analyst-prompt="What is blocked?">What is blocked?</button>
                <button type="button" class="analyst-prompt-chip" data-analyst-prompt="What changed recently?">What changed recently?</button>
            </div>

            <div class="analyst-conversation glass-panel" id="analystConversation" aria-live="polite">
                <div class="analyst-empty ptmd-empty-state glass-card" id="analystEmptyState">
                    <div class="analyst-empty__icon"><i class="fa-solid fa-sparkles"></i></div>
                    <h3>Start a new analysis</h3>
                    <p>Use a quick prompt or type your own question to open a session.</p>
                </div>
            </div>

            <div class="analyst-loading glass-card d-none" id="analystLoadingState">
                <div class="analyst-loading__pulse pulse"></div>
                <div>
                    <strong>Analyzing</strong>
                    <p class="analyst-meta mb-0">Reviewing the current scope and preparing a response.</p>
                </div>
            </div>

            <section class="analyst-composer glass-card">
                <header class="analyst-section-head analyst-section-head--compact">
                    <div>
                        <p class="analyst-section-kicker">Composer</p>
                        <h3 class="analyst-section-title">Ask The Analyst</h3>
                    </div>
                    <p class="analyst-section-copy analyst-section-copy--tight">Scope narrows the answer. Mode changes the reasoning style.</p>
                </header>

                <div class="analyst-composer__controls">
                    <div>
                        <label class="visually-hidden" for="analystScope">Scope</label>
                        <select id="analystScope" class="form-select glass-input analyst-select">
                            <option>Current Page</option>
                            <option>Current Case</option>
                            <option>Dispatch</option>
                            <option>All Content</option>
                        </select>
                    </div>

                    <div>
                        <label class="visually-hidden" for="analystMode">Mode</label>
                        <select id="analystMode" class="form-select glass-input analyst-select">
                            <option>Ask</option>
                            <option>Explain</option>
                            <option>Recommend</option>
                            <option>Investigate</option>
                        </select>
                    </div>
                </div>

                <div class="analyst-composer__input-row">
                    <div class="analyst-composer__input-shell">
                        <label class="visually-hidden" for="analystPromptInput">Prompt</label>
                        <textarea
                            id="analystPromptInput"
                            class="form-control glass-input analyst-composer__textarea"
                            rows="3"
                            placeholder="Ask The Analyst something..."
                        ></textarea>
                    </div>

                    <button type="button" class="btn btn-ptmd-primary analyst-send-btn" id="analystSendButton">
                        <i class="fa-solid fa-paper-plane"></i>
                        <span>Send</span>
                    </button>
                </div>
            </section>

            <section class="analyst-citations">
                <header class="analyst-section-head">
                    <div>
                        <p class="analyst-section-kicker">Evidence</p>
                        <h3 class="analyst-section-title">Citations &amp; Sources</h3>
                    </div>
                </header>
                <div class="analyst-citation-grid">
                    <article class="analyst-citation-card glass-card">
                        <strong class="analyst-citation-card__title">Dispatch Summary</strong>
                        <p class="analyst-citation-card__source">Dispatch</p>
                        <span class="analyst-meta">Queued Posts - 3 failures</span>
                    </article>
                    <article class="analyst-citation-card glass-card">
                        <strong class="analyst-citation-card__title">Asset Warning</strong>
                        <p class="analyst-citation-card__source">Asset Vault</p>
                        <span class="analyst-meta">2 missing thumbnails</span>
                    </article>
                </div>
            </section>
        </div>

        <aside class="analyst-side-panel">
            <section class="analyst-recommendations">
                <header class="analyst-section-head">
                    <div>
                        <p class="analyst-section-kicker">Recommendation</p>
                        <h3 class="analyst-section-title">Analyst Recommendation</h3>
                    </div>
                </header>

                <article class="analyst-recommendation-card glass-card">
                    <div class="analyst-recommendation-card__top">
                        <div>
                            <h4>Review failed queue items</h4>
                            <div class="analyst-recommendation-card__chips">
                                <span class="ptmd-chip analyst-chip">Priority</span>
                                <span class="ptmd-chip analyst-chip analyst-chip--muted">High confidence</span>
                            </div>
                        </div>
                        <div class="analyst-recommendation-card__priority">Urgent</div>
                    </div>

                    <p class="analyst-copy">Three posts failed during the latest dispatch cycle and should be reviewed first.</p>

                    <div class="analyst-recommendation-card__footer">
                        <span class="analyst-meta">Dispatch</span>
                        <a href="<?php ee(route_admin('dispatch')); ?>" class="btn btn-ptmd-ghost btn-sm analyst-action-link">Open Dispatch</a>
                    </div>
                </article>
            </section>

            <section class="analyst-notes">
                <header class="analyst-section-head">
                    <div>
                        <p class="analyst-section-kicker">Notes</p>
                        <h3 class="analyst-section-title">Analyst Notes</h3>
                    </div>
                </header>

                <article class="analyst-notes-card glass-card">
                    <div class="analyst-notes-card__top">
                        <h4>Hook pattern drift detected</h4>
                        <span class="ptmd-chip analyst-chip analyst-chip--muted">Insight</span>
                    </div>

                    <p class="analyst-copy">Recent hooks are becoming longer and less curiosity-driven than the strongest historical variants.</p>

                    <div class="analyst-notes-card__footer">
                        <span class="analyst-meta">Hook Lab</span>
                        <span class="analyst-meta">12m ago</span>
                    </div>
                </article>
            </section>

            <section class="analyst-history">
                <header class="analyst-section-head">
                    <div>
                        <p class="analyst-section-kicker">Sessions</p>
                        <h3 class="analyst-section-title">Recent Sessions</h3>
                    </div>
                    <button type="button" class="btn btn-ptmd-ghost btn-sm analyst-history-reset" id="analystNewSessionBtn">New Session</button>
                </header>

                <div class="analyst-history-rail" id="analystSessionList">
                    <article class="analyst-history-item glass-card">
                        <div class="analyst-history-item__top">
                            <span class="analyst-history-item__label">Dispatch bottleneck review</span>
                            <span class="analyst-history-item__time">8m ago</span>
                        </div>
                        <div class="analyst-history-item__meta">
                            <span class="analyst-history-item__type">Report</span>
                            <span class="analyst-history-item__context">Dispatch</span>
                        </div>
                    </article>

                    <article class="analyst-history-item glass-card is-active">
                        <div class="analyst-history-item__top">
                            <span class="analyst-history-item__label">Current case summary</span>
                            <span class="analyst-history-item__time">27m ago</span>
                        </div>
                        <div class="analyst-history-item__meta">
                            <span class="analyst-history-item__type">Summary</span>
                            <span class="analyst-history-item__context">Cases</span>
                        </div>
                    </article>
                </div>
            </section>
        </aside>
    </div>
</section>

<?php require __DIR__ . '/_admin_footer.php'; ?>
