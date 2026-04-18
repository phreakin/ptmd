<?php
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
                <p class="ptmd-muted small mb-0">
                    Ask for hook variants, queue triage plans, case summaries, or platform-specific publish copy.
                </p>
                <div class="d-flex flex-wrap gap-2 mt-3 justify-content-center">
                    <span class="ptmd-chip"><i class="fa-solid fa-list-check"></i>Queue Triage</span>
                    <span class="ptmd-chip"><i class="fa-solid fa-chart-line"></i>Trend Read</span>
                    <span class="ptmd-chip"><i class="fa-solid fa-lightbulb"></i>Hook Lab</span>
                </div>
            </div>
        </div>

        <!-- Typing indicator (hidden by default) -->
        <div class="copilot-typing" id="copilotTyping" style="display:none">
            <div class="typing-dots">
                <span></span><span></span><span></span>
            </div>
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
