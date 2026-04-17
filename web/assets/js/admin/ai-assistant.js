/**
 * PTMD Admin — AI Copilot chat UI
 *
 * Loaded only on admin/ai-assistant.php via $extraScripts.
 * Requires: a <meta name="csrf-token"> with a valid token to be present
 * before this script executes.
 */
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
    s = s.replace(/^### (.+)$/gm, '<h6 class="mb-1 mt-3 ptmd-text-teal">$1</h6>');
    s = s.replace(/^## (.+)$/gm,  '<h5 class="mb-1 mt-3">$1</h5>');
    s = s.replace(/^# (.+)$/gm,   '<h4 class="mb-1 mt-3">$1</h4>');

    // Unordered list items (- or *)
    s = s.replace(/^[ \t]*[-*] (.+)$/gm, '<li>$1</li>');
    s = s.replace(/(<li>[\s\S]*?<\/li>)/g, (match) => '<ul class="mb-2 ps-4">' + match + '</ul>');

    // Ordered list items
    s = s.replace(/^[ \t]*\d+\. (.+)$/gm, '<li>$1</li>');

    // Links [text](url) — only allow safe admin links
    s = s.replace(/\[([^\]]+)\]\((\/admin\/[^\)]+)\)/g, '<a href="$2" class="ptmd-text-teal">$1</a>');

    // Line breaks
    s = s.replace(/\n{2,}/g, '</p><p class="mb-2">');
    s = s.replace(/\n/g, '<br>');

    return '<p class="mb-2">' + s + '</p>';
}

// ── Append a message bubble ───────────────────────────────────────────────────
function appendMessage(role, content) {
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
