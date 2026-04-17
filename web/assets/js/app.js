/**
 * PTMD — app.js
 * Modern vanilla ES2022+: no jQuery dependency for our own code.
 * Relies on globally loaded: tippy, ClipboardJS, Swal (sweetalert2), Bootstrap
 */
'use strict';

// ─── Toast system ─────────────────────────────────────────────────────────────
const Toast = {
    _container: null,

    _getContainer() {
        if (!this._container) {
            this._container = document.createElement('div');
            this._container.id = 'ptmd-toast-container';
            document.body.appendChild(this._container);
        }
        return this._container;
    },

    show(message, type = 'info', duration = 4000) {
        const icons = {
            success: 'fa-circle-check',
            error:   'fa-circle-xmark',
            warning: 'fa-triangle-exclamation',
            info:    'fa-circle-info',
        };
        const colors = {
            success: 'var(--ptmd-success)',
            error:   'var(--ptmd-error)',
            warning: 'var(--ptmd-warning)',
            info:    'var(--ptmd-teal)',
        };

        const el = document.createElement('div');
        el.className = 'ptmd-toast';
        el.innerHTML = `
            <i class="fa-solid ${icons[type] ?? icons.info}" style="color:${colors[type] ?? colors.info};font-size:1.1rem;flex-shrink:0"></i>
            <span>${escHtml(message)}</span>
        `;

        this._getContainer().appendChild(el);

        setTimeout(() => {
            el.classList.add('toast-out');
            el.addEventListener('animationend', () => el.remove(), { once: true });
        }, duration);
    },

    success: (msg, d) => Toast.show(msg, 'success', d),
    error:   (msg, d) => Toast.show(msg, 'error',   d),
    warning: (msg, d) => Toast.show(msg, 'warning',  d),
    info:    (msg, d) => Toast.show(msg, 'info',     d),
};

// ─── Utility: HTML escape ─────────────────────────────────────────────────────
function escHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// ─── Utility: format datetime ─────────────────────────────────────────────────
function fmtDateTime(str) {
    try {
        return new Intl.DateTimeFormat('en-US', {
            month: 'short', day: 'numeric',
            hour: 'numeric', minute: '2-digit',
        }).format(new Date(str));
    } catch {
        return str;
    }
}

// ─── Sticky header scroll shadow ─────────────────────────────────────────────
(function initScrollHeader() {
    const header = document.querySelector('.ptmd-header');
    if (!header) return;

    const observer = new IntersectionObserver(
        ([entry]) => header.classList.toggle('scrolled', !entry.isIntersecting),
        { rootMargin: '-1px 0px 0px 0px', threshold: 0 }
    );

    const sentinel = document.createElement('div');
    sentinel.style.cssText = 'position:absolute;top:0;height:1px;width:100%;pointer-events:none';
    document.body.prepend(sentinel);
    observer.observe(sentinel);
})();

// ─── Scroll-triggered fade-up animations ─────────────────────────────────────
(function initScrollAnimations() {
    const targets = document.querySelectorAll('[data-animate]');
    if (!targets.length) return;

    // Preset: hidden
    targets.forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'opacity 0.5s cubic-bezier(0.16,1,0.3,1), transform 0.5s cubic-bezier(0.16,1,0.3,1)';
    });

    const io = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const el = entry.target;
                const delay = el.dataset.animateDelay ?? '0ms';
                setTimeout(() => {
                    el.style.opacity = '1';
                    el.style.transform = 'none';
                }, parseInt(delay));
                io.unobserve(el);
            }
        });
    }, { threshold: 0.12 });

    targets.forEach(el => io.observe(el));
})();

// ─── Tippy tooltips ───────────────────────────────────────────────────────────
(function initTippy() {
    if (typeof tippy === 'undefined') return;
    tippy('[data-tippy-content]', {
        theme: 'ptmd',
        animation: 'shift-away',
        duration: [200, 150],
    });
})();

// ─── Clipboard.js ─────────────────────────────────────────────────────────────
(function initClipboard() {
    if (typeof ClipboardJS === 'undefined') return;
    const cb = new ClipboardJS('[data-clipboard-text], [data-clipboard-target]');
    cb.on('success', () => Toast.success('Copied to clipboard'));
    cb.on('error',   () => Toast.error('Copy failed'));
})();

// ─── Case Chat system ─────────────────────────────────────────────────────────
(function initCaseChat() {
    const messagesEl = document.getElementById('caseChatMessages');
    const formEl     = document.getElementById('caseChatForm');
    if (!messagesEl || !formEl) return;

    const endpoint = messagesEl.dataset.endpoint ?? '/api/chat_messages.php';
    let lastId = 0;
    let polling = null;

    function avatarInitial(username) {
        return escHtml(String(username ?? '?')[0].toUpperCase());
    }

    function renderBubble(msg) {
        const bubble = document.createElement('div');
        bubble.className = 'ptmd-chat-bubble fade-in';
        bubble.dataset.msgId = msg.id;
        bubble.innerHTML = `
            <div class="bubble-avatar">${avatarInitial(msg.username)}</div>
            <div class="bubble-body">
                <div class="bubble-username">${escHtml(msg.username)}</div>
                <div class="bubble-text">${escHtml(msg.message)}</div>
                <div class="bubble-time">${fmtDateTime(msg.created_at)}</div>
            </div>
        `;
        return bubble;
    }

    async function loadMessages(initialLoad = false) {
        try {
            const res  = await fetch(endpoint, { credentials: 'same-origin' });
            const data = await res.json();
            if (!data.ok) return;

            const msgs = data.messages ?? [];

            if (initialLoad) {
                messagesEl.innerHTML = '';
                msgs.forEach(msg => messagesEl.appendChild(renderBubble(msg)));
            } else {
                const newMsgs = msgs.filter(m => Number(m.id) > lastId);
                newMsgs.forEach(msg => messagesEl.appendChild(renderBubble(msg)));
            }

            if (msgs.length > 0) {
                lastId = Math.max(...msgs.map(m => Number(m.id)));
            }

            // Scroll to bottom
            messagesEl.scrollTop = messagesEl.scrollHeight;
        } catch (err) {
            console.warn('[PTMD Chat] fetch error:', err);
        }
    }

    formEl.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd  = new FormData(formEl);
        const btn = formEl.querySelector('[type=submit]');

        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

        try {
            const res  = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
            });
            const data = await res.json();

            if (data.ok) {
                formEl.reset();
                await loadMessages();
            } else {
                Toast.error(data.error ?? 'Could not send message.');
            }
        } catch {
            Toast.error('Network error. Please try again.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i>Send';
        }
    });

    // Initial load + polling every 15 s
    loadMessages(true);
    polling = setInterval(() => loadMessages(false), 15000);

    // Cleanup on unload
    window.addEventListener('unload', () => clearInterval(polling));
})();

// ─── Generic confirm-delete via SweetAlert2 ───────────────────────────────────
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-confirm]');
    if (!btn) return;

    e.preventDefault();

    const message = btn.dataset.confirm ?? 'Are you sure?';
    const form    = btn.closest('form');

    if (typeof Swal === 'undefined') {
        if (confirm(message)) form?.submit();
        return;
    }

    const result = await Swal.fire({
        title:             'Are you sure?',
        text:              message,
        icon:              'warning',
        showCancelButton:  true,
        confirmButtonText: 'Yes, do it',
        cancelButtonText:  'Cancel',
        background:        'var(--ptmd-surface-2, #1a1d22)',
        color:             'var(--ptmd-white, #F5F5F3)',
        confirmButtonColor: 'var(--ptmd-red, #C1121F)',
    });

    if (result.isConfirmed) {
        form?.submit();
    }
});

// ─── Admin: inline status update (select → auto-submit) ──────────────────────
document.addEventListener('change', (e) => {
    const select = e.target.closest('[data-auto-submit]');
    if (!select) return;
    select.closest('form')?.submit();
});

// ─── Expose Toast globally for inline use ─────────────────────────────────────
window.PTMDToast = Toast;
