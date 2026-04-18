<?php
/**
 * PTMD Admin — AI Copilot
 *
 * A conversational admin assistant powered by OpenAI.
 * Helps with cases, social publishing, media, moderation,
 * content drafting, and any other site admin task.
 */

$pageTitle      = 'AI Copilot | PTMD Admin';
$activePage     = 'ai-assistant';
$pageHeading    = 'AI Copilot';
$pageSubheading = 'Your conversational admin assistant — ask anything about managing the site.';

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
        <strong>OpenAI API Key</strong> to enable AI Copilot.
    </div>
<?php endif; ?>

<div class="ptmd-screen-ai-bot">
<!-- Copilot layout: sessions sidebar + main chat -->
<div class="ptmd-copilot-layout">

    <!-- ── Session sidebar ─────────────────────────────────────────────── -->
    <aside class="ptmd-copilot-sessions">
        <div class="copilot-sessions-header">
            <span class="copilot-sessions-title">Conversations</span>
            <button class="btn btn-ptmd-teal btn-sm" id="newConvoBtn" title="Start a new conversation">
                <i class="fa-solid fa-plus"></i>
            </button>
        </div>

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
                <h2 class="h5 mb-2">How can I help you today?</h2>
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
            </div>
        </div>

        <!-- Typing indicator (hidden by default) -->
        <div class="copilot-typing" id="copilotTyping" style="display:none">
            <div class="typing-dots">
                <span></span><span></span><span></span>
            </div>
            <span class="ptmd-muted" style="font-size:var(--text-xs)">Copilot is thinking…</span>
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
                    placeholder="Ask the Copilot anything…"
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
            </div>
        </div>

    </section>
</div>
</div>

<!-- Inject CSRF for JS -->
<meta name="csrf-token" content="<?php ee(csrf_token()); ?>">

<?php
$extraScripts = '<script src="/assets/js/admin/ai-assistant.js"></script>';
include __DIR__ . '/_admin_footer.php';
?>
