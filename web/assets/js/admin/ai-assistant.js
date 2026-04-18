/**
 * PTMD Admin — The Analyst UI
 *
 * Real session-backed assistant UI using /api/ai_assistant.php
 * Reworked from the older Copilot DOM bindings to the new Analyst page shell.
 *
 * Requires:
 * - <meta name="csrf-token"> in the admin head
 * - The Analyst page markup IDs/classes from admin/ai-assistant.php
 */
'use strict';

// ── Small safe HTML escape helper ─────────────────────────────────────────────
const escHtml = (str) =>
    String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

// ── State ─────────────────────────────────────────────────────────────────────
let activeSessionId = null;
const ENDPOINT = '/api/ai_assistant.php';
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

// ── DOM refs (The Analyst page) ───────────────────────────────────────────────
const conversationEl = document.getElementById('analystConversation');
const emptyStateEl = document.getElementById('analystEmptyState');
const loadingStateEl = document.getElementById('analystLoadingState');
const promptInputEl = document.getElementById('analystPromptInput');
const sendButtonEl = document.getElementById('analystSendButton');
const scopeEl = document.getElementById('analystScope');
const modeEl = document.getElementById('analystMode');

// Optional session/history support if you add these later
const sessionListEl = document.getElementById('analystSessionList');
const newSessionBtnEl = document.getElementById('analystNewSessionBtn');

// Hidden session id support if you add it later
let sessionIdEl = document.getElementById('analystSessionId');
if (!sessionIdEl) {
    sessionIdEl = document.createElement('input');
    sessionIdEl.type = 'hidden';
    sessionIdEl.id = 'analystSessionId';
    sessionIdEl.value = '';
    document.body.appendChild(sessionIdEl);
}

// Guard: only run on The Analyst page
if (!conversationEl || !promptInputEl || !sendButtonEl) {
    // Quietly do nothing if this script was loaded elsewhere
} else {
    // ── Markdown → HTML (minimal, safe subset) ───────────────────────────────
    function renderMarkdown(text) {
        let s = escHtml(text);

        // Code blocks
        s = s.replace(/```[\w]*\n?([\s\S]*?)```/g, '<pre><code>$1</code></pre>');

        // Inline code
        s = s.replace(/`([^`]+)`/g, '<code>$1</code>');

        // Bold
        s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

        // Italic
        s = s.replace(/\*([^*]+)\*/g, '<em>$1</em>');

        // Headings
        s = s.replace(/^### (.+)$/gm, '<h6 class="mb-1 mt-3 ptmd-text-teal">$1</h6>');
        s = s.replace(/^## (.+)$/gm, '<h5 class="mb-1 mt-3">$1</h5>');
        s = s.replace(/^# (.+)$/gm, '<h4 class="mb-1 mt-3">$1</h4>');

        // Unordered lists
        s = s.replace(/^[ \t]*[-*] (.+)$/gm, '<li>$1</li>');
        s = s.replace(/(<li>[\s\S]*?<\/li>)/g, (match) => '<ul class="mb-2 ps-4">' + match + '</ul>');

        // Safe admin links only
        s = s.replace(/\[([^\]]+)\]\((\/admin\/[^\)]+)\)/g, '<a href="$2" class="ptmd-text-teal">$1</a>');

        // Paragraphs / line breaks
        s = s.replace(/\n{2,}/g, '</p><p class="mb-2">');
        s = s.replace(/\n/g, '<br>');

        return '<p class="mb-2">' + s + '</p>';
    }

    // ── UI helpers ────────────────────────────────────────────────────────────
    function hideEmptyState() {
        if (emptyStateEl) emptyStateEl.classList.add('d-none');
    }

    function showEmptyState() {
        if (emptyStateEl) emptyStateEl.classList.remove('d-none');
    }

    function showLoading() {
        if (loadingStateEl) loadingStateEl.classList.remove('d-none');
        sendButtonEl.disabled = true;
    }

    function hideLoading() {
        if (loadingStateEl) loadingStateEl.classList.add('d-none');
        sendButtonEl.disabled = false;
    }

    function scrollConversationToBottom() {
        conversationEl.scrollTop = conversationEl.scrollHeight;
    }

    function autoResizeTextarea() {
        promptInputEl.style.height = 'auto';
        promptInputEl.style.height = Math.min(promptInputEl.scrollHeight, 160) + 'px';
    }

    // ── Message rendering ─────────────────────────────────────────────────────
    function appendMessage(role, content) {
        hideEmptyState();

        const wrap = document.createElement('article');
        wrap.className = `analyst-message analyst-message--${role}`;

        const bubble = document.createElement('div');
        bubble.className = 'analyst-bubble glass-card';

        const label = document.createElement('div');
        label.className = 'analyst-bubble__label';
        label.textContent = role === 'user' ? 'You' : 'The Analyst';

        const body = document.createElement('div');
        body.className = 'analyst-bubble__body';

        if (role === 'user') {
            body.innerHTML = escHtml(content);
        } else {
            body.innerHTML = renderMarkdown(content);
        }

        bubble.appendChild(label);
        bubble.appendChild(body);
        wrap.appendChild(bubble);
        conversationEl.appendChild(wrap);
        scrollConversationToBottom();
    }

    function clearConversation() {
        conversationEl.innerHTML = '';
        if (emptyStateEl) {
            conversationEl.appendChild(emptyStateEl);
            showEmptyState();
        }
    }

    // ── Session sidebar/history helpers (optional) ───────────────────────────
    function markActiveSessionInList(sid) {
        if (!sessionListEl) return;

        sessionListEl.querySelectorAll('.analyst-history-item, .copilot-session-item').forEach((item) => {
            const itemSid = parseInt(item.dataset.sessionId || '0', 10);
            item.classList.toggle('is-active', itemSid === sid);
            item.classList.toggle('active', itemSid === sid);
        });
    }

    function prependSessionToList(sid, title) {
        if (!sessionListEl) return;

        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'analyst-history-item glass-card is-active';
        item.dataset.sessionId = String(sid);
        item.innerHTML = `
            <div class="analyst-history-item__top">
                <span class="analyst-history-item__label">${escHtml(title)}</span>
                <span class="analyst-history-item__time">Now</span>
            </div>
            <div class="analyst-history-item__meta">
                <span class="analyst-history-item__type">Session</span>
                <span class="analyst-history-item__context">The Analyst</span>
            </div>
        `;
        item.addEventListener('click', () => loadSession(sid));

        sessionListEl.querySelectorAll('.analyst-history-item, .copilot-session-item').forEach((btn) => {
            btn.classList.remove('is-active', 'active');
        });

        sessionListEl.prepend(item);
    }

    function startNewSession() {
        activeSessionId = null;
        sessionIdEl.value = '';
        clearConversation();
        markActiveSessionInList(-1);
        promptInputEl.focus();
    }

    // ── Load an existing session ──────────────────────────────────────────────
    async function loadSession(sid) {
        try {
            const res = await fetch(`${ENDPOINT}?session_id=${encodeURIComponent(sid)}`, {
                credentials: 'same-origin',
            });
            const data = await res.json();

            if (!data.ok) {
                window.PTMDToast?.error(data.error ?? 'Could not load session.');
                return;
            }

            activeSessionId = sid;
            sessionIdEl.value = String(sid);

            clearConversation();
            hideEmptyState();

            data.messages.forEach((m) => appendMessage(m.role, m.content));
            markActiveSessionInList(sid);
        } catch {
            window.PTMDToast?.error('Network error loading session.');
        }
    }

    // ── Send a message using the real backend ─────────────────────────────────
    async function sendMessage(textOverride = null) {
        const text = (textOverride ?? promptInputEl.value).trim();
        if (!text) return;

        appendMessage('user', text);

        if (!textOverride) {
            promptInputEl.value = '';
            autoResizeTextarea();
        }

        showLoading();

        const fd = new FormData();
        fd.append('csrf_token', csrfToken());
        fd.append('message', text);
        fd.append('session_id', sessionIdEl.value);

        // Extra context fields for future backend use
        if (scopeEl) fd.append('scope', scopeEl.value);
        if (modeEl) fd.append('mode', modeEl.value);

        try {
            const res = await fetch(ENDPOINT, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
            });

            const data = await res.json();
            hideLoading();

            if (data.ok) {
                appendMessage('assistant', data.text);

                if (!activeSessionId && data.session_id) {
                    activeSessionId = data.session_id;
                    sessionIdEl.value = String(data.session_id);

                    const title = text.length > 60 ? text.slice(0, 60) + '…' : text;
                    prependSessionToList(data.session_id, title);
                }
            } else {
                window.PTMDToast?.error(data.error ?? 'Something went wrong.');
            }
        } catch {
            hideLoading();
            window.PTMDToast?.error('Network error. Please try again.');
        } finally {
            promptInputEl.focus();
        }
    }

    // ── Events ────────────────────────────────────────────────────────────────
    sendButtonEl.addEventListener('click', () => sendMessage());

    promptInputEl.addEventListener('keydown', (e) => {
        // Enter sends, Shift+Enter for newline
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }

        // Ctrl/Cmd + Enter also sends
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            sendMessage();
        }
    });

    promptInputEl.addEventListener('input', autoResizeTextarea);

    document.querySelectorAll('[data-analyst-prompt]').forEach((button) => {
        button.addEventListener('click', () => {
            sendMessage(button.getAttribute('data-analyst-prompt') || '');
        });
    });

    if (newSessionBtnEl) {
        newSessionBtnEl.addEventListener('click', startNewSession);
    }

    if (sessionListEl) {
        sessionListEl.querySelectorAll('[data-session-id]').forEach((item) => {
            item.addEventListener('click', () => {
                const sid = parseInt(item.dataset.sessionId || '0', 10);
                if (sid) loadSession(sid);
            });
        });
    }

    // Initial sizing
    autoResizeTextarea();
}
