<?php
/**
 * PTMD Admin — AI Copilot
 *
 * A conversational admin assistant powered by OpenAI.
 * Helps with episodes, social publishing, media, moderation,
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
                ['icon' => 'fa-film',           'text' => 'Draft titles for a new episode about housing policy'],
                ['icon' => 'fa-tags',           'text' => 'Generate YouTube keywords for my latest episode'],
                ['icon' => 'fa-calendar-check', 'text' => 'How do I schedule a social post?'],
                ['icon' => 'fa-lightbulb',      'text' => 'Suggest 5 documentary ideas for the PTMD brand'],
                ['icon' => 'fa-align-left',     'text' => 'Write a description for my most recent episode'],
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
                <p class="ptmd-muted small mb-0">
                    Ask me anything — episode ideas, social captions, how to use admin features, or site stats.
                </p>
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

<!-- Inject CSRF for JS -->
<meta name="csrf-token" content="<?php ee(csrf_token()); ?>">

<script>
'use strict';

// ── Local HTML-escape helper (self-contained; app.js loads after this block) ──
const escHtml = (str) =>
    String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

// ── State ─────────────────────────────────────────────────────────────────────
let activeSessionId = null;
const ENDPOINT      = '/api/ai_assistant.php';
const csrfToken     = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

// ── DOM refs ──────────────────────────────────────────────────────────────────
const messagesEl   = document.getElementById('copilotMessages');
const welcomeEl    = document.getElementById('copilotWelcome');
const typingEl     = document.getElementById('copilotTyping');
const form         = document.getElementById('copilotForm');
const inputEl      = document.getElementById('copilotInput');
const sendBtn      = document.getElementById('copilotSendBtn');
const sessionIdEl  = document.getElementById('copilotSessionId');
const sessionList  = document.getElementById('sessionList');
const newConvoBtn  = document.getElementById('newConvoBtn');

// ── Markdown → HTML (minimal, safe subset) ────────────────────────────────────
function renderMarkdown(text) {
    // Escape HTML first, then apply simple markdown transforms
    let s = escHtml(text);

    // Code blocks
    s = s.replace(/```[\w]*\n?([\s\S]*?)```/g, '<pre><code>$1</code></pre>');

    // Inline code
    s = s.replace(/`([^`]+)`/g, '<code>$1</code>');

    // Bold
    s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

    // Italic
    s = s.replace(/\*([^*]+)\*/g, '<em>$1</em>');

    // Headings (### ## #)
    s = s.replace(/^### (.+)$/gm, '<h6 class="mb-1 mt-3" style="color:var(--ptmd-teal)">$1</h6>');
    s = s.replace(/^## (.+)$/gm,  '<h5 class="mb-1 mt-3">$1</h5>');
    s = s.replace(/^# (.+)$/gm,   '<h4 class="mb-1 mt-3">$1</h4>');

    // Unordered list items (- or *)
    s = s.replace(/^[ \t]*[-*] (.+)$/gm, '<li>$1</li>');
    s = s.replace(/(<li>[\s\S]*?<\/li>)/g, (match) => '<ul class="mb-2 ps-4">' + match + '</ul>');

    // Ordered list items
    s = s.replace(/^[ \t]*\d+\. (.+)$/gm, '<li>$1</li>');

    // Links  [text](url) — only allow safe admin links
    s = s.replace(/\[([^\]]+)\]\((\/admin\/[^\)]+)\)/g, '<a href="$2" class="ptmd-text-teal">$1</a>');

    // Line breaks
    s = s.replace(/\n{2,}/g, '</p><p class="mb-2">');
    s = s.replace(/\n/g, '<br>');

    return '<p class="mb-2">' + s + '</p>';
}

// ── Append a message bubble ───────────────────────────────────────────────────
function appendMessage(role, content) {
    // Hide welcome screen on first message
    if (welcomeEl) welcomeEl.style.display = 'none';

    const wrap = document.createElement('div');
    wrap.className = 'ptmd-copilot-message ptmd-copilot-message--' + role;

    if (role === 'user') {
        wrap.innerHTML = `
            <div class="copilot-msg-bubble copilot-msg-bubble--user">
                ${escHtml(content)}
            </div>`;
    } else {
        wrap.innerHTML = `
            <div class="copilot-msg-avatar">
                <i class="fa-solid fa-robot"></i>
            </div>
            <div class="copilot-msg-bubble copilot-msg-bubble--assistant">
                ${renderMarkdown(content)}
            </div>`;
    }

    messagesEl.appendChild(wrap);
    messagesEl.scrollTop = messagesEl.scrollHeight;
}

// ── Load an existing session ──────────────────────────────────────────────────
async function loadSession(sid) {
    try {
        const res  = await fetch(`${ENDPOINT}?session_id=${encodeURIComponent(sid)}`, {
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!data.ok) {
            window.PTMDToast?.error(data.error ?? 'Could not load session.');
            return;
        }

        // Clear chat
        messagesEl.innerHTML = '';
        if (welcomeEl) messagesEl.appendChild(welcomeEl);
        if (welcomeEl) welcomeEl.style.display = 'none';

        activeSessionId = sid;
        sessionIdEl.value = String(sid);

        data.messages.forEach(m => appendMessage(m.role, m.content));

        // Mark active in sidebar
        document.querySelectorAll('.copilot-session-item').forEach(btn => {
            btn.classList.toggle('active', parseInt(btn.dataset.sessionId) === sid);
        });
    } catch {
        window.PTMDToast?.error('Network error loading session.');
    }
}

// ── Start a new conversation ──────────────────────────────────────────────────
function startNewConvo() {
    activeSessionId   = null;
    sessionIdEl.value = '';

    // Reset chat area
    messagesEl.innerHTML = '';
    if (welcomeEl) {
        messagesEl.appendChild(welcomeEl);
        welcomeEl.style.display = '';
    }

    document.querySelectorAll('.copilot-session-item').forEach(btn => btn.classList.remove('active'));
    inputEl.focus();
}

// ── Add session to sidebar list ───────────────────────────────────────────────
function prependSessionToList(sid, title) {
    const emptyNote = sessionList.querySelector('.copilot-sessions-empty');
    if (emptyNote) emptyNote.remove();

    const btn = document.createElement('button');
    btn.className = 'copilot-session-item active';
    btn.dataset.sessionId = String(sid);
    btn.title = title;
    btn.innerHTML = `
        <i class="fa-regular fa-message copilot-session-icon"></i>
        <span class="copilot-session-label">${escHtml(title)}</span>`;
    btn.addEventListener('click', () => loadSession(sid));

    // Deactivate others
    document.querySelectorAll('.copilot-session-item').forEach(b => b.classList.remove('active'));

    sessionList.prepend(btn);
}

// ── Send a message ────────────────────────────────────────────────────────────
async function sendMessage(text) {
    text = text.trim();
    if (!text) return;

    appendMessage('user', text);
    inputEl.value = '';
    autoResizeTextarea();

    sendBtn.disabled = true;
    typingEl.style.display = 'flex';
    messagesEl.scrollTop = messagesEl.scrollHeight;

    const fd = new FormData();
    fd.append('csrf_token', csrfToken());
    fd.append('message',    text);
    fd.append('session_id', sessionIdEl.value);

    try {
        const res  = await fetch(ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd,
        });
        const data = await res.json();

        typingEl.style.display = 'none';

        if (data.ok) {
            appendMessage('assistant', data.text);

            // New session was created
            if (!activeSessionId && data.session_id) {
                activeSessionId   = data.session_id;
                sessionIdEl.value = String(data.session_id);

                const title = text.length > 60 ? text.slice(0, 60) + '…' : text;
                prependSessionToList(data.session_id, title);
            }
        } else {
            window.PTMDToast?.error(data.error ?? 'Something went wrong.');
        }
    } catch {
        typingEl.style.display = 'none';
        window.PTMDToast?.error('Network error. Please try again.');
    } finally {
        sendBtn.disabled = false;
        inputEl.focus();
    }
}

// ── Auto-resize textarea ──────────────────────────────────────────────────────
function autoResizeTextarea() {
    inputEl.style.height = 'auto';
    inputEl.style.height = Math.min(inputEl.scrollHeight, 160) + 'px';
}

// ── Event: form submit ────────────────────────────────────────────────────────
form.addEventListener('submit', (e) => {
    e.preventDefault();
    sendMessage(inputEl.value);
});

// ── Event: Enter to send, Shift+Enter for newline ─────────────────────────────
inputEl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage(inputEl.value);
    }
});

inputEl.addEventListener('input', autoResizeTextarea);

// ── Event: new conversation button ────────────────────────────────────────────
newConvoBtn?.addEventListener('click', startNewConvo);

// ── Event: session items in sidebar ──────────────────────────────────────────
document.querySelectorAll('.copilot-session-item').forEach(btn => {
    btn.addEventListener('click', () => loadSession(parseInt(btn.dataset.sessionId)));
});

// ── Event: quick-start chips ──────────────────────────────────────────────────
document.querySelectorAll('.copilot-starter-chip').forEach(chip => {
    chip.addEventListener('click', () => {
        startNewConvo();
        sendMessage(chip.dataset.starter);
    });
});
</script>

<?php include __DIR__ . '/_admin_footer.php'; ?>
