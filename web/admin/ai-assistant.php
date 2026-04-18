<?php
<<<<<<< HEAD
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
=======
/**
 * PTMD Admin — The Analyst
 *
 * A conversational admin assistant powered by OpenAI.
 * Helps with cases, social publishing, media, moderation,
 * content drafting, and any other site admin task.
 */

$pageTitle      = 'The Analyst | PTMD Admin';
$activePage     = 'ai-assistant';
$pageHeading    = 'The Analyst';
$pageSubheading = 'Premium intelligence panel for case strategy, queue optimization, and rapid drafting.';

include __DIR__ . '/_admin_head.php';

$pdo       = get_db();
$apiKeySet = site_setting('openai_api_key', '') !== '';

// Recent sessions for the sidebar
$sessions = [];
if ($pdo) {
    $stmt = $pdo->prepare(
        'SELECT id, title, updated_at
         FROM ai_assistant_sessions
         WHERE user_id = :uid
         ORDER BY updated_at DESC
         LIMIT 20'
    );
    $stmt->execute(['uid' => (int) ($_SESSION['admin_user_id'] ?? 0)]);
    $sessions = $stmt->fetchAll();
}
?>

<?php if (!$apiKeySet): ?>
    <div class="alert ptmd-alert alert-warning mb-5" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i>
        <strong>OpenAI API key not configured.</strong>
        Go to <a href="/admin/settings.php">Settings</a> and set the
        <strong>OpenAI API Key</strong> to enable The Analyst.
    </div>
<?php endif; ?>

<div class="ptmd-screen-ai-bot">
<!-- Copilot layout: sessions sidebar + main chat -->
<div class="ptmd-copilot-layout">

    <!-- ── Session sidebar ─────────────────────────────────────────────── -->
    <aside class="ptmd-copilot-sessions">
        <div class="copilot-sessions-header">
            <span class="copilot-sessions-title">Intel Sessions</span>
            <button class="btn btn-ptmd-teal btn-sm" id="newConvoBtn" title="Start a new conversation">
                <i class="fa-solid fa-plus"></i>
>>>>>>> ed91b0b00085c31bb54401dc4f172e51e1c727e9
            </button>
        </div>
    </header>

<<<<<<< HEAD
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
=======
        <div id="sessionList" class="copilot-session-list">
            <?php if ($sessions): ?>
                <?php foreach ($sessions as $s): ?>
                    <button
                        class="copilot-session-item"
                        data-session-id="<?php ee((string) $s['id']); ?>"
                        title="<?php ee($s['title']); ?>"
                    >
                        <i class="fa-regular fa-message copilot-session-icon"></i>
                        <span class="copilot-session-label"><?php ee($s['title']); ?></span>
                    </button>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="copilot-sessions-empty">No conversations yet.</p>
            <?php endif; ?>
        </div>

        <div class="ptmd-insight-card mb-3">
            <div class="ptmd-kicker">Live Insight</div>
            <div class="fw-600 mb-2">What should happen next</div>
            <div class="ptmd-muted small">Use The Analyst to prioritize blocked queue items, generate hooks, and sequence social follow-ups.</div>
        </div>

        <!-- Starter prompts -->
        <div class="copilot-starters">
            <div class="copilot-starters-label">Quick starts</div>
            <?php
            $starters = [
                ['icon' => 'fa-film',           'text' => 'Draft titles for a new case about housing policy'],
                ['icon' => 'fa-tags',           'text' => 'Generate YouTube keywords for my latest case'],
                ['icon' => 'fa-calendar-check', 'text' => 'How do I schedule a social post?'],
                ['icon' => 'fa-lightbulb',      'text' => 'Suggest 5 documentary ideas for the PTMD brand'],
                ['icon' => 'fa-align-left',     'text' => 'Write a description for my most recent case'],
                ['icon' => 'fa-gear',           'text' => 'How do I change the OpenAI model I\'m using?'],
            ];
            ?>
            <?php foreach ($starters as $s): ?>
                <button class="copilot-starter-chip" data-starter="<?php ee($s['text']); ?>">
                    <i class="fa-solid <?php ee($s['icon']); ?>"></i>
                    <span><?php ee($s['text']); ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </aside>

    <!-- ── Main chat pane ──────────────────────────────────────────────── -->
    <section class="ptmd-copilot-chat">

        <!-- Message area -->
        <div class="ptmd-copilot-messages" id="copilotMessages">
            <!-- Welcome state (shown when no session is active) -->
            <div class="copilot-welcome" id="copilotWelcome">
                <div class="copilot-welcome-icon">
                    <i class="fa-solid fa-robot"></i>
                </div>
                <h2 class="h5 mb-2">The Analyst is ready.</h2>
                <p class="ptmd-muted small mb-4">
                    Ask me anything — or start with a task below.
                </p>

                <!-- Template launcher cards -->
                <div class="analyst-launcher-grid">
                    <?php
                    $launcherCards = [
                        [
                            'icon'   => 'fa-triangle-exclamation',
                            'title'  => 'What Needs Attention',
                            'desc'   => 'Scan for failed posts, pending cases, stale queue items, or system warnings.',
                            'prompt' => 'Scan the admin for anything that needs attention right now: failed social posts, pending cases without descriptions, stale queue items, missing settings, or any system warnings.',
                        ],
                        [
                            'icon'   => 'fa-chart-bar',
                            'title'  => 'Summarize This Page',
                            'desc'   => 'Get a snapshot of current site activity, cases, and key metrics.',
                            'prompt' => 'Give me a clear summary of the current admin state: active cases, recent site activity, publishing queue status, and the most important metrics at a glance.',
                        ],
                        [
                            'icon'   => 'fa-share-nodes',
                            'title'  => 'Review Dispatch',
                            'desc'   => 'Check recent social posts — successes, failures, and platform coverage.',
                            'prompt' => 'Review the recent social dispatch logs. Which posts were sent successfully, which failed, and which platforms or cases have gaps in coverage?',
                        ],
                        [
                            'icon'   => 'fa-magnifying-glass',
                            'title'  => 'Analyze This Case',
                            'desc'   => 'Deep-dive on the latest case: title, description, and social coverage.',
                            'prompt' => 'Pull up the most recently published case and give me a full analysis: title quality, description completeness, social post coverage, and your top recommendations for improvement.',
                        ],
                        [
                            'icon'   => 'fa-film',
                            'title'  => 'Hook Review',
                            'desc'   => 'Rate recent hooks and titles for engagement and brand fit.',
                            'prompt' => 'Review the recent content hooks and video titles. Which are the strongest from a viewer engagement perspective? What patterns make them work, and what could be improved?',
                        ],
                        [
                            'icon'   => 'fa-gauge',
                            'title'  => 'System Bottlenecks',
                            'desc'   => 'Identify workflow blockers, slow queues, and pipeline gaps.',
                            'prompt' => 'Identify any current system bottlenecks or workflow inefficiencies: slow queues, incomplete settings, blocked pipelines, missing integrations, or anything slowing content production.',
                        ],
                    ];
                    ?>
                    <?php foreach ($launcherCards as $card): ?>
                        <button
                            class="analyst-launcher-card"
                            data-prompt="<?php ee($card['prompt']); ?>"
                            type="button"
                            <?php if (!$apiKeySet) echo 'disabled'; ?>
                        >
                            <div class="analyst-launcher-card__icon">
                                <i class="fa-solid <?php ee($card['icon']); ?>"></i>
                            </div>
                            <div class="analyst-launcher-card__title"><?php ee($card['title']); ?></div>
                            <div class="analyst-launcher-card__desc"><?php ee($card['desc']); ?></div>
                        </button>
                    <?php endforeach; ?>
                </div>
>>>>>>> ed91b0b00085c31bb54401dc4f172e51e1c727e9
            </div>
            <p class="analyst-copy analyst-summary-card__body">
                Dispatch and review pressure are elevated across active workflow areas.
            </p>
            <div class="analyst-summary-card__footer">
                <span class="analyst-meta analyst-summary-card__source">Control Room</span>
                <a href="<?php ee(route_admin('control-room')); ?>" class="btn btn-ptmd-ghost btn-sm analyst-action-link">Open Control Room</a>
            </div>
<<<<<<< HEAD
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
=======
            <span class="ptmd-muted" style="font-size:var(--text-xs)">The Analyst is thinking…</span>
        </div>

        <!-- Input bar -->
        <div class="ptmd-copilot-input-bar">
            <form id="copilotForm" class="copilot-input-form" autocomplete="off">
                <input type="hidden" id="copilotSessionId" name="session_id" value="">
                <textarea
                    class="form-control copilot-textarea"
                    id="copilotInput"
                    name="message"
                    rows="1"
                    placeholder="Ask The Analyst anything…"
                    <?php if (!$apiKeySet) echo 'disabled'; ?>
                ></textarea>
                <button
                    type="submit"
                    class="btn btn-ptmd-teal copilot-send-btn"
                    id="copilotSendBtn"
                    <?php if (!$apiKeySet) echo 'disabled'; ?>
                    title="Send (Enter)"
                >
                    <i class="fa-solid fa-paper-plane"></i>
                </button>
            </form>
            <div class="copilot-input-hint">
                Press <kbd>Enter</kbd> to send &middot; <kbd>Shift+Enter</kbd> for new line
>>>>>>> ed91b0b00085c31bb54401dc4f172e51e1c727e9
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
