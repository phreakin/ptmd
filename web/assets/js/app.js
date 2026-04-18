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
    const shellEl    = document.getElementById('caseChatShell');
    const messagesEl = document.getElementById('caseChatMessages');
    const formEl     = document.getElementById('caseChatForm');
    const pinnedEl   = document.getElementById('caseChatPinned');
    if (!shellEl || !messagesEl) return;

    const endpoint         = shellEl.dataset.endpoint         ?? '/api/chat_messages.php';
    const reactEndpoint    = shellEl.dataset.reactEndpoint    ?? '/api/chat_react.php';
    const moderateEndpoint = shellEl.dataset.moderateEndpoint ?? '/api/chat_moderate.php';
    const roomSlug         = shellEl.dataset.room             ?? 'case-chat';
    const isMod            = shellEl.dataset.isMod            === '1';
    const currentUserId    = parseInt(shellEl.dataset.userId  ?? '0', 10);

    let lastId     = 0;
    let polling    = null;
    let roomConfig = null;
    let slowTimer  = null;

    // ── Role badge ────────────────────────────────────────────────────────────
    const ROLE_LABELS = { super_admin: 'Super Admin', admin: 'Admin', moderator: 'Mod' };
    const ROLE_CLASSES = {
        'super-admin': 'ptmd-chat-role-badge--super-admin',
        'admin':       'ptmd-chat-role-badge--admin',
        'moderator':   'ptmd-chat-role-badge--mod',
    };

    function roleBadgeHtml(role, badgeLabel) {
        const slug  = String(role ?? '').replace('_', '-');
        const label = badgeLabel || ROLE_LABELS[role] || '';
        const cls   = ROLE_CLASSES[slug] || '';
        if (!label || !cls) return '';
        return `<span class="ptmd-chat-role-badge ${cls}">${escHtml(label)}</span>`;
    }

    // ── Avatar ────────────────────────────────────────────────────────────────
    function avatarHtml(name, color) {
        return `<div class="ptmd-chat-avatar" style="--avatar-color:${escHtml(color || '#2EC4B6')}">${escHtml(String(name || '?')[0].toUpperCase())}</div>`;
    }

    // ── Reactions ─────────────────────────────────────────────────────────────
    function reactionsHtml(reactions, msgId) {
        const pills = Object.entries(reactions || {}).map(([emoji, cnt]) =>
            `<button class="ptmd-chat-reaction" type="button"
                     data-msg-id="${msgId}" data-reaction="${escHtml(emoji)}"
                     title="React ${escHtml(emoji)}">${emoji} <span class="reaction-count">${escHtml(String(cnt))}</span></button>`
        ).join('');
        const addBtn = currentUserId > 0
            ? `<button class="ptmd-chat-reaction ptmd-chat-reaction--add" type="button"
                       data-msg-id="${msgId}" title="Add reaction">+</button>`
            : '';
        return `<div class="ptmd-chat-reactions" data-msg-id="${msgId}">${pills}${addBtn}</div>`;
    }

    // ── Mod context menu ──────────────────────────────────────────────────────
    function modMenuHtml(msg) {
        if (!isMod) return '';
        const uid    = msg.chat_user_id || 0;
        const pin    = msg.is_pinned ? 'Unpin' : 'Pin';
        const pact   = msg.is_pinned ? 'unpin' : 'pin';
        const hideItem = msg.is_hidden
            ? `<li><button class="dropdown-item" type="button" data-mod-action="unhide" data-msg-id="${msg.id}">
                   <i class="fa-solid fa-eye me-2" style="color:var(--ptmd-teal)"></i>Unhide
               </button></li>`
            : `<li><button class="dropdown-item" type="button" data-mod-action="hide" data-msg-id="${msg.id}">
                   <i class="fa-solid fa-eye-slash me-2" style="color:var(--ptmd-warning)"></i>Hide
               </button></li>`;
        const userItems = uid
            ? `<li><hr class="dropdown-divider"></li>
               <li><button class="dropdown-item" type="button" data-mod-action="mute_user" data-target-user-id="${uid}">
                   <i class="fa-solid fa-microphone-slash me-2" style="color:var(--ptmd-warning)"></i>Mute User
               </button></li>
               <li><button class="dropdown-item" type="button" data-mod-action="ban_user" data-target-user-id="${uid}">
                   <i class="fa-solid fa-ban me-2" style="color:var(--ptmd-error)"></i>Ban User
               </button></li>`
            : '';
        return `<div class="ptmd-chat-mod-menu dropdown">
            <button class="btn btn-ptmd-ghost btn-sm ptmd-chat-mod-btn dropdown-toggle" type="button"
                    data-bs-toggle="dropdown" aria-expanded="false" title="Mod actions">⋮</button>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width:160px">
                <li><button class="dropdown-item" type="button" data-mod-action="${pact}" data-msg-id="${msg.id}">
                    <i class="fa-solid fa-thumbtack me-2"></i>${pin}
                </button></li>
                ${hideItem}
                <li><button class="dropdown-item" type="button" data-mod-action="delete" data-msg-id="${msg.id}">
                    <i class="fa-solid fa-trash me-2" style="color:var(--ptmd-error)"></i>Delete
                </button></li>${userItems}
            </ul>
        </div>`;
    }

    // ── Render one bubble ─────────────────────────────────────────────────────
    function renderBubble(msg) {
        const el = document.createElement('div');
        el.className = 'ptmd-chat-bubble';
        if (msg.is_highlighted) el.classList.add('ptmd-chat-bubble--highlighted');
        if (msg.highlight_color) el.style.setProperty('--highlight-color', msg.highlight_color);
        if (msg.is_hidden) el.classList.add('ptmd-chat-bubble--hidden');
        el.dataset.msgId = msg.id;

        const name  = escHtml(msg.display_name || msg.username);
        const color = msg.avatar_color || '#2EC4B6';

        const supBanner = msg.is_highlighted
            ? `<div class="ptmd-chat-super-banner" style="background:${escHtml(msg.highlight_color || '#FFD60A')}22;border-left:3px solid ${escHtml(msg.highlight_color || '#FFD60A')}">
                   <i class="fa-solid fa-star me-1" style="color:${escHtml(msg.highlight_color || '#FFD60A')}"></i>
                   ${msg.highlight_amount ? escHtml('$' + Number(msg.highlight_amount).toFixed(2)) : 'Highlighted'}
               </div>` : '';

        const replyRef = msg.parent_id
            ? `<div class="ptmd-chat-reply-ref"><i class="fa-solid fa-reply me-1"></i>Reply to #${escHtml(String(msg.parent_id))}</div>`
            : '';

        const replyBtn = currentUserId > 0
            ? `<button class="btn btn-ptmd-ghost btn-sm ptmd-chat-reply-btn py-0 px-2" type="button"
                        data-msg-id="${msg.id}" data-display-name="${name}"
                        title="Reply"><i class="fa-solid fa-reply"></i></button>`
            : '';

        const hiddenBadge = msg.is_hidden
            ? `<span class="ptmd-chat-hidden-badge"><i class="fa-solid fa-eye-slash"></i> Hidden — visible to mods only</span>`
            : '';

        el.innerHTML = `${supBanner}
            <div class="d-flex gap-3 align-items-start">
                ${avatarHtml(msg.display_name || msg.username, color)}
                <div class="bubble-body flex-grow-1">
                    ${replyRef}
                    <div class="bubble-meta d-flex align-items-center gap-2 flex-wrap mb-1">
                        <span class="bubble-username">${name}</span>
                        ${roleBadgeHtml(msg.user_role, msg.badge_label)}
                        <span class="bubble-time">${fmtDateTime(msg.created_at)}</span>
                        ${hiddenBadge}
                    </div>
                    <div class="bubble-text">${escHtml(msg.message)}</div>
                    <div class="bubble-actions d-flex align-items-center gap-1 mt-2 flex-wrap">
                        ${reactionsHtml(msg.reactions, msg.id)}
                        ${replyBtn}
                        ${modMenuHtml(msg)}
                    </div>
                </div>
            </div>`;
        return el;
    }

    // ── Pinned banner ─────────────────────────────────────────────────────────
    function renderPinned(pinnedMsgs) {
        if (!pinnedEl) return;
        if (!pinnedMsgs?.length) { pinnedEl.classList.add('d-none'); pinnedEl.innerHTML = ''; return; }
        pinnedEl.classList.remove('d-none');
        pinnedEl.innerHTML = pinnedMsgs.map(p =>
            `<div class="ptmd-chat-pinned-item">
                <i class="fa-solid fa-thumbtack me-2 ptmd-text-yellow"></i>
                <strong>${escHtml(p.display_name || p.username)}</strong>: ${escHtml(p.message)}
             </div>`
        ).join('');
    }

    // ── Load messages ─────────────────────────────────────────────────────────
    async function loadMessages(initialLoad = false) {
        try {
            const url  = `${endpoint}?room=${encodeURIComponent(roomSlug)}&since=${initialLoad ? 0 : lastId}`;
            const res  = await fetch(url, { credentials: 'same-origin' });
            const data = await res.json();

            if (!data.ok) {
                if (data.members_only) {
                    messagesEl.innerHTML = `<div class="ptmd-muted small text-center py-4">
                        <i class="fa-solid fa-lock me-2"></i>Members only room.
                        <a href="/index.php?page=chat-login" class="ptmd-text-teal">Sign in</a> to view messages.
                    </div>`;
                }
                return;
            }

            if (data.room) { roomConfig = data.room; }

            const msgs = data.messages ?? [];
            if (initialLoad) {
                messagesEl.innerHTML = '';
                msgs.forEach(m => messagesEl.appendChild(renderBubble(m)));
            } else {
                const newMsgs = msgs.filter(m => Number(m.id) > lastId);
                newMsgs.forEach(m => messagesEl.appendChild(renderBubble(m)));
            }
            if (msgs.length > 0) lastId = Math.max(...msgs.map(m => Number(m.id)));

            renderPinned(data.pinned ?? []);
            messagesEl.scrollTop = messagesEl.scrollHeight;
        } catch (err) {
            console.warn('[PTMD Chat] fetch error:', err);
        }
    }

    // ── Slow mode countdown ────────────────────────────────────────────────────
    function startSlowCountdown(seconds) {
        const slowEl  = document.getElementById('caseChatSlowMode');
        const countEl = document.getElementById('caseChatSlowCount');
        const sendBtn = document.getElementById('caseChatSendBtn');
        if (!slowEl || !countEl) return;
        let remain = Math.max(1, seconds);
        slowEl.classList.remove('d-none');
        if (sendBtn) sendBtn.disabled = true;
        countEl.textContent = String(remain);
        clearInterval(slowTimer);
        slowTimer = setInterval(() => {
            remain--;
            if (remain <= 0) {
                clearInterval(slowTimer);
                slowEl.classList.add('d-none');
                if (sendBtn) sendBtn.disabled = false;
            } else {
                countEl.textContent = String(remain);
            }
        }, 1000);
    }

    // ── Form submission ────────────────────────────────────────────────────────
    if (formEl) {
        formEl.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd  = new FormData(formEl);
            const btn = formEl.querySelector('[type=submit]');

            // Attach highlight color if toggle is active
            const highlightOpts  = document.getElementById('caseChatHighlightOpts');
            const highlightColor = document.getElementById('caseChatHighlightColor');
            if (highlightOpts && !highlightOpts.classList.contains('d-none') && highlightColor) {
                fd.set('highlight_color', highlightColor.value);
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

            try {
                const res  = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', body: fd });
                const data = await res.json();

                if (data.ok) {
                    formEl.reset();
                    // Clear reply context
                    document.getElementById('caseChatReplyCtx')?.classList.add('d-none');
                    if (document.getElementById('caseChatParentId')) document.getElementById('caseChatParentId').value = '';
                    // Reset highlight toggle
                    if (highlightOpts) highlightOpts.classList.add('d-none');
                    document.getElementById('caseChatHighlightToggle')?.classList.remove('btn-ptmd-teal');
                    await loadMessages(false);
                    if (roomConfig?.slow_mode_seconds > 0) startSlowCountdown(roomConfig.slow_mode_seconds);
                } else if (data.slow_mode) {
                    startSlowCountdown(data.wait ?? roomConfig?.slow_mode_seconds ?? 0);
                    Toast.warning(data.error ?? 'Slow mode active.');
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
    }

    // ── Emoji picker ──────────────────────────────────────────────────────────
    const emojiBtn    = document.getElementById('caseChatEmojiBtn');
    const emojiPicker = document.getElementById('caseChatEmojiPicker');
    const msgInput    = document.getElementById('caseChatMessageInput');
    let emojiData     = null; // cached categories

    async function loadEmojiData() {
        if (emojiData) return emojiData;
        try {
            const res  = await fetch('/api/chat_emojis.php', { credentials: 'same-origin' });
            const data = await res.json();
            emojiData  = data.categories ?? {};
        } catch {
            emojiData = {
                'Reactions': ['😀','😂','😭','😤','😱','🤔','🥲','😎','🤦','😅'],
                'Vibes':     ['🔥','💯','❤️','✅','⚠️','💀','👀','🎉','👏','🙏'],
                'Case Work': ['📄','🔍','⚖️','🔒','📰','🕵️','🗂️','✍️','📋','📊'],
            };
        }
        return emojiData;
    }

    function renderEmojiPicker(categories, activeTab) {
        const tabsEl = document.getElementById('caseChatEmojiTabs');
        const gridEl = document.getElementById('caseChatEmojiGrid');
        if (!tabsEl || !gridEl) return;
        const tabNames = Object.keys(categories);
        activeTab = activeTab || tabNames[0] || '';

        tabsEl.innerHTML = tabNames.map(tab =>
            `<button class="ptmd-emoji-tab${tab === activeTab ? ' active' : ''}" type="button" data-tab="${escHtml(tab)}">${escHtml(tab)}</button>`
        ).join('');

        const emojis = categories[activeTab] ?? [];
        gridEl.innerHTML = emojis.map(em =>
            `<button type="button" class="ptmd-emoji-btn" aria-label="${em}">${em}</button>`
        ).join('');

        tabsEl.querySelectorAll('.ptmd-emoji-tab').forEach(btn => {
            btn.addEventListener('click', () => renderEmojiPicker(categories, btn.dataset.tab));
        });
        gridEl.addEventListener('click', e => {
            const btn = e.target.closest('.ptmd-emoji-btn');
            if (btn && msgInput) { msgInput.value += btn.textContent; msgInput.focus(); }
        });
    }

    if (emojiBtn && emojiPicker) {
        emojiBtn.addEventListener('click', async e => {
            e.stopPropagation();
            const isOpen = !emojiPicker.classList.contains('d-none');
            emojiPicker.classList.toggle('d-none');
            if (!isOpen) {
                const cats = await loadEmojiData();
                renderEmojiPicker(cats, null);
            }
        });
        document.addEventListener('click', e => {
            if (!emojiPicker.contains(e.target) && e.target !== emojiBtn) emojiPicker.classList.add('d-none');
        });
    }

    // ── Highlight toggle ──────────────────────────────────────────────────────
    const highlightBtn  = document.getElementById('caseChatHighlightToggle');
    const highlightOpts = document.getElementById('caseChatHighlightOpts');
    if (highlightBtn && highlightOpts) {
        highlightBtn.addEventListener('click', () => {
            const open = !highlightOpts.classList.contains('d-none');
            highlightOpts.classList.toggle('d-none', open);
            highlightBtn.classList.toggle('btn-ptmd-teal', !open);
        });
    }

    // ── Reply ─────────────────────────────────────────────────────────────────
    messagesEl.addEventListener('click', e => {
        const btn = e.target.closest('.ptmd-chat-reply-btn');
        if (!btn) return;
        const replyCtx   = document.getElementById('caseChatReplyCtx');
        const replyLabel = document.getElementById('caseChatReplyLabel');
        const parentId   = document.getElementById('caseChatParentId');
        if (replyCtx && replyLabel && parentId) {
            parentId.value         = btn.dataset.msgId;
            replyLabel.textContent = `Replying to ${btn.dataset.displayName}`;
            replyCtx.classList.remove('d-none');
            msgInput?.focus();
        }
    });
    document.getElementById('caseChatReplyClear')?.addEventListener('click', () => {
        document.getElementById('caseChatReplyCtx')?.classList.add('d-none');
        if (document.getElementById('caseChatParentId')) document.getElementById('caseChatParentId').value = '';
    });

    // ── Reactions ─────────────────────────────────────────────────────────────
    if (currentUserId > 0) {
        messagesEl.addEventListener('click', async e => {
            const btn = e.target.closest('.ptmd-chat-reaction[data-reaction]');
            if (!btn) return;
            const csrfInput = formEl?.querySelector('[name=csrf_token]');
            if (!csrfInput) return;
            try {
                const fd = new FormData();
                fd.set('csrf_token',  csrfInput.value);
                fd.set('message_id',  btn.dataset.msgId);
                fd.set('reaction',    btn.dataset.reaction);
                const res  = await fetch(reactEndpoint, { method: 'POST', credentials: 'same-origin', body: fd });
                const data = await res.json();
                if (data.ok) {
                    const div = messagesEl.querySelector(`.ptmd-chat-reactions[data-msg-id="${btn.dataset.msgId}"]`);
                    if (div) {
                        const pills = Object.entries(data.counts || {}).map(([emoji, cnt]) =>
                            `<button class="ptmd-chat-reaction" type="button"
                                     data-msg-id="${btn.dataset.msgId}" data-reaction="${escHtml(emoji)}">${emoji}
                                <span class="reaction-count">${escHtml(String(cnt))}</span></button>`
                        ).join('');
                        div.innerHTML = pills + `<button class="ptmd-chat-reaction ptmd-chat-reaction--add" type="button"
                            data-msg-id="${btn.dataset.msgId}">+</button>`;
                    }
                }
            } catch (err) { console.warn('[PTMD Chat] react error:', err); }
        });
    }

    // ── Moderator actions ─────────────────────────────────────────────────────
    if (isMod) {
        document.addEventListener('click', async e => {
            const btn = e.target.closest('[data-mod-action]');
            if (!btn || !btn.closest('#caseChatShell')) return;

            const action       = btn.dataset.modAction;
            const msgId        = btn.dataset.msgId        ?? '';
            const targetUserId = btn.dataset.targetUserId ?? '';
            const csrfInput    = formEl?.querySelector('[name=csrf_token]');
            if (!csrfInput) return;

            let reason    = '';
            let expiresAt = '';

            if (['delete', 'mute_user', 'ban_user'].includes(action) && typeof Swal !== 'undefined') {
                const durationHtml = ['mute_user', 'ban_user'].includes(action)
                    ? `<select id="swal-dur" class="swal2-input" style="width:auto;display:block;margin-top:.5rem">
                           <option value="">Permanent</option>
                           <option value="+5 minutes">5 minutes</option>
                           <option value="+1 hour">1 hour</option>
                           <option value="+24 hours">24 hours</option>
                           <option value="+7 days">7 days</option>
                       </select>` : '';

                const res = await Swal.fire({
                    title: { delete: 'Delete message?', mute_user: 'Mute user?', ban_user: 'Ban user?' }[action],
                    html: `<input id="swal-reason" class="swal2-input" placeholder="Reason (optional)">${durationHtml}`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Confirm',
                    background: 'var(--ptmd-surface-2, #1a1d22)',
                    color: 'var(--ptmd-white, #F5F5F3)',
                    confirmButtonColor: 'var(--ptmd-red, #C1121F)',
                    preConfirm: () => ({
                        reason:   document.getElementById('swal-reason')?.value ?? '',
                        duration: document.getElementById('swal-dur')?.value    ?? '',
                    }),
                });
                if (!res.isConfirmed) return;
                reason = res.value?.reason ?? '';
                const dur = res.value?.duration ?? '';
                if (dur) {
                    const parts = dur.match(/\+(\d+)\s+(minutes?|hours?|days?)/i);
                    if (parts) {
                        const now = new Date();
                        const n   = parseInt(parts[1], 10);
                        const u   = parts[2].toLowerCase();
                        if (u.startsWith('min'))      now.setMinutes(now.getMinutes() + n);
                        else if (u.startsWith('h'))   now.setHours(now.getHours() + n);
                        else if (u.startsWith('d'))   now.setDate(now.getDate() + n);
                        expiresAt = now.toISOString().slice(0, 19).replace('T', ' ');
                    }
                }
            }

            try {
                const fd = new FormData();
                fd.set('csrf_token', csrfInput.value);
                fd.set('action', action);
                if (msgId)        fd.set('message_id',     msgId);
                if (targetUserId) fd.set('target_user_id', targetUserId);
                if (reason)       fd.set('reason',         reason);
                if (expiresAt)    fd.set('expires_at',     expiresAt);

                const r    = await fetch(moderateEndpoint, { method: 'POST', credentials: 'same-origin', body: fd });
                const data = await r.json();
                if (data.ok) {
                    Toast.success('Done.');
                    if (action === 'delete' && msgId) {
                        messagesEl.querySelector(`[data-msg-id="${msgId}"]`)?.remove();
                    }
                    if (action === 'hide' && msgId) {
                        const bubble = messagesEl.querySelector(`[data-msg-id="${msgId}"]`);
                        if (bubble) {
                            bubble.classList.add('ptmd-chat-bubble--hidden');
                            const meta = bubble.querySelector('.bubble-meta');
                            if (meta && !meta.querySelector('.ptmd-chat-hidden-badge')) {
                                const badge = document.createElement('span');
                                badge.className = 'ptmd-chat-hidden-badge';
                                badge.innerHTML = '<i class="fa-solid fa-eye-slash"></i> Hidden — visible to mods only';
                                meta.appendChild(badge);
                            }
                        }
                    }
                    if (action === 'unhide' && msgId) {
                        const bubble = messagesEl.querySelector(`[data-msg-id="${msgId}"]`);
                        if (bubble) {
                            bubble.classList.remove('ptmd-chat-bubble--hidden');
                            bubble.querySelector('.ptmd-chat-hidden-badge')?.remove();
                        }
                    }
                    if (action === 'pin' || action === 'unpin') await loadMessages(true);
                } else {
                    Toast.error(data.error ?? 'Action failed.');
                }
            } catch (err) { console.warn('[PTMD Chat] mod error:', err); Toast.error('Network error.'); }
        });
    }

    // ── Initial load + polling ─────────────────────────────────────────────────
    loadMessages(true);
    polling = setInterval(() => loadMessages(false), 15000);
    window.addEventListener('unload', () => clearInterval(polling));

    // ── SSE client ─────────────────────────────────────────────────────────────
    function initSSE() {
        if (!window.EventSource) return null;
        const sseEndpoint = shellEl.dataset.sseEndpoint ?? '';
        if (!sseEndpoint) return null;

        const url = `${sseEndpoint}?room=${encodeURIComponent(roomSlug)}&since=${lastId}`;
        const es  = new EventSource(url, { withCredentials: true });

        es.addEventListener('messages', e => {
            try {
                const data = JSON.parse(e.data);
                const msgs = data.messages ?? [];
                const newMsgs = msgs.filter(m => Number(m.id) > lastId);
                newMsgs.forEach(m => messagesEl.appendChild(renderBubble(m)));
                if (msgs.length > 0) {
                    lastId = Math.max(...msgs.map(m => Number(m.id)));
                    messagesEl.scrollTop = messagesEl.scrollHeight;
                }
            } catch {}
        });

        es.addEventListener('reconnect', () => { es.close(); setTimeout(initSSE, 2000); });
        es.onerror = () => { es.close(); }; // fall back to polling silently

        return es;
    }

    if (shellEl.dataset.sseEndpoint) {
        const es = initSSE();
        // When SSE is active, reduce polling frequency to 30s fallback
        if (es) {
            clearInterval(polling);
            polling = setInterval(() => loadMessages(false), 30000);
        }
    }

    // ── Trivia widget ───────────────────────────────────────────────────────────
    function initTriviaWidget() {
        const triviaEl   = document.getElementById('triviaWidget');
        const triviaEndpoint = shellEl.dataset.triviaEndpoint ?? '';
        if (!triviaEl || !triviaEndpoint) return;

        let triviaInterval = null;
        let currentSessionId = null;
        let countdownTimer   = null;

        async function fetchTrivia() {
            try {
                const res  = await fetch(`${triviaEndpoint}?room=${encodeURIComponent(roomSlug)}`, { credentials: 'same-origin' });
                const data = await res.json();
                if (!data.ok) return;
                if (!data.trivia_enabled && !data.session) {
                    triviaEl.classList.add('d-none');
                    return;
                }
                triviaEl.classList.remove('d-none');
                renderTrivia(data.session);
            } catch {}
        }

        function renderTrivia(session) {
            if (!session) {
                const isModUser = shellEl.dataset.isMod === '1';
                triviaEl.innerHTML = `
                    <div class="ptmd-trivia-header">
                        <i class="fa-solid fa-brain" style="color:var(--ptmd-teal)"></i>
                        <strong style="font-size:var(--text-sm)">Trivia</strong>
                    </div>
                    <div class="ptmd-trivia-body">
                        <p class="ptmd-muted small mb-0">No trivia active.</p>
                        ${isModUser ? `<button class="btn btn-ptmd-teal btn-sm mt-3" id="triviaStartBtn">
                            <i class="fa-solid fa-play me-1"></i>Start Trivia
                        </button>` : ''}
                    </div>`;
                document.getElementById('triviaStartBtn')?.addEventListener('click', startTrivia);
                return;
            }

            currentSessionId = session.id;
            const closes    = new Date(session.closes_at.replace(' ', 'T') + 'Z');
            const answered  = session.my_answer;

            triviaEl.innerHTML = `
                <div class="ptmd-trivia-header">
                    <i class="fa-solid fa-brain" style="color:var(--ptmd-teal)"></i>
                    <strong style="font-size:var(--text-sm)">Trivia</strong>
                    <span class="ptmd-muted small ms-auto">${escHtml(session.category ?? '')} · ${escHtml(session.difficulty ?? '')}</span>
                </div>
                <div class="ptmd-trivia-body">
                    <div class="ptmd-trivia-question">${escHtml(session.question)}</div>
                    <div class="ptmd-trivia-answers" id="triviaAnswers">
                        ${['a','b','c','d'].map(k => {
                            const text = session['answer_' + k];
                            if (!text) return '';
                            let cls = '';
                            if (answered) {
                                if (answered.answer === k) cls = answered.is_correct ? 'correct' : 'incorrect selected';
                                else cls = '';
                            }
                            return `<button class="ptmd-trivia-answer-btn ${cls}" type="button"
                                        data-answer="${k}" ${answered ? 'disabled' : ''}>
                                <span class="answer-key">${k}</span>
                                ${escHtml(text)}
                            </button>`;
                        }).join('')}
                    </div>
                    <div class="ptmd-trivia-timer" id="triviaTimer"></div>
                    <div class="ptmd-muted" style="font-size:var(--text-xs);text-align:center;margin-top:4px">${escHtml(String(session.answer_count ?? 0))} answered</div>
                </div>`;

            // Countdown
            clearInterval(countdownTimer);
            function updateCountdown() {
                const timerEl = document.getElementById('triviaTimer');
                if (!timerEl) return;
                const secsLeft = Math.max(0, Math.round((closes - Date.now()) / 1000));
                timerEl.textContent = secsLeft > 0 ? `⏱ ${secsLeft}s remaining` : 'Time\'s up!';
                if (secsLeft <= 0) clearInterval(countdownTimer);
            }
            updateCountdown();
            countdownTimer = setInterval(updateCountdown, 1000);

            // Answer click
            document.getElementById('triviaAnswers')?.addEventListener('click', async e => {
                const btn = e.target.closest('.ptmd-trivia-answer-btn');
                if (!btn || btn.disabled) return;
                const answer    = btn.dataset.answer;
                const csrfInput = formEl?.querySelector('[name=csrf_token]');
                if (!csrfInput) return;

                document.querySelectorAll('.ptmd-trivia-answer-btn').forEach(b => b.disabled = true);

                const fd = new FormData();
                fd.set('csrf_token', csrfInput.value);
                fd.set('action',     'answer');
                fd.set('room',       roomSlug);
                fd.set('session_id', String(currentSessionId));
                fd.set('answer',     answer);

                try {
                    const r    = await fetch(triviaEndpoint, { method: 'POST', credentials: 'same-origin', body: fd });
                    const data = await r.json();
                    if (data.ok) {
                        btn.classList.add(data.is_correct ? 'correct' : 'incorrect');
                        Toast[data.is_correct ? 'success' : 'warning'](data.is_correct ? 'Correct! 🎉' : 'Wrong answer.');
                    } else {
                        Toast.error(data.error ?? 'Could not submit.');
                    }
                } catch {
                    Toast.error('Network error.');
                }
            });
        }

        async function startTrivia() {
            const csrfInput = formEl?.querySelector('[name=csrf_token]');
            if (!csrfInput) return;
            const fd = new FormData();
            fd.set('csrf_token', csrfInput.value);
            fd.set('action',     'start');
            fd.set('room',       roomSlug);
            try {
                const r    = await fetch(triviaEndpoint, { method: 'POST', credentials: 'same-origin', body: fd });
                const data = await r.json();
                if (data.ok) { await fetchTrivia(); }
                else Toast.error(data.error ?? 'Failed to start trivia.');
            } catch { Toast.error('Network error.'); }
        }

        fetchTrivia();
        triviaInterval = setInterval(fetchTrivia, 10000);
        window.addEventListener('unload', () => clearInterval(triviaInterval));
    }

    // ── Donation panel ──────────────────────────────────────────────────────────
    function initDonationPanel() {
        const donationEl    = document.getElementById('donationPanel');
        const donateEndpoint = shellEl.dataset.donateEndpoint ?? '';
        if (!donationEl || !donateEndpoint) return;

        fetch(`${donateEndpoint}?room=${encodeURIComponent(roomSlug)}`, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (!data.ok || !data.donations_enabled) return;
                const links = data.links ?? {};
                if (!Object.keys(links).length) return;

                donationEl.classList.remove('d-none');

                const brandIcons = { paypal: '💳', venmo: '💙', cashapp: '💚' };
                const brandLabels = { paypal: 'PayPal', venmo: 'Venmo', cashapp: 'Cash App' };

                let html = `<h2 class="h6 mb-3 ptmd-text-teal"><i class="fa-solid fa-heart me-2"></i>Support the Investigation</h2>`;
                if (data.message) html += `<p class="ptmd-muted small mb-3">${escHtml(data.message)}</p>`;
                if (data.goal)    html += `<p class="ptmd-muted" style="font-size:var(--text-xs);margin-bottom:var(--space-3)">${escHtml(data.goal)}</p>`;

                for (const [platform, url] of Object.entries(links)) {
                    html += `<button class="ptmd-donation-btn ptmd-donation-btn--${escHtml(platform)}" type="button"
                                     data-platform="${escHtml(platform)}" data-url="${escHtml(url)}">
                        ${brandIcons[platform] ?? '💰'} ${brandLabels[platform] ?? platform}
                    </button>`;
                }
                donationEl.innerHTML = html;

                donationEl.addEventListener('click', async e => {
                    const btn = e.target.closest('[data-platform]');
                    if (!btn) return;
                    const platform = btn.dataset.platform;
                    const url      = btn.dataset.url;
                    const csrfInput = formEl?.querySelector('[name=csrf_token]');

                    if (csrfInput) {
                        const fd = new FormData();
                        fd.set('csrf_token', csrfInput.value);
                        fd.set('platform',   platform);
                        fd.set('room',       roomSlug);
                        fetch(donateEndpoint, { method: 'POST', credentials: 'same-origin', body: fd }).catch(() => {});
                    }
                    window.open(url, '_blank', 'noopener,noreferrer');
                });
            })
            .catch(() => {});
    }

    initTriviaWidget();
    initDonationPanel();
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

// ─── Episode Favorite buttons ─────────────────────────────────────────────────
(function initFavorites() {
    const buttons = document.querySelectorAll('[data-favorite-episode]');
    if (!buttons.length) return;

    buttons.forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();

            const episodeId = btn.dataset.favoriteEpisode;
            const csrf      = btn.dataset.csrf;

            if (!episodeId || !csrf) return;

            btn.disabled = true;

            try {
                const res  = await fetch('/api/toggle_favorite.php', {
                    method:      'POST',
                    credentials: 'same-origin',
                    headers:     { 'Content-Type': 'application/json' },
                    body:        JSON.stringify({ episode_id: parseInt(episodeId, 10), csrf_token: csrf }),
                });
                const data = await res.json();

                if (!res.ok || !data.ok) {
                    Toast.error(data.error ?? 'Could not update favorite.');
                    return;
                }

                const favorited = data.favorited === true;
                const icon      = btn.querySelector('i');

                if (favorited) {
                    btn.classList.add('is-favorited');
                    btn.setAttribute('aria-pressed', 'true');
                    btn.setAttribute('aria-label', 'Remove from favorites');
                    if (icon) {
                        icon.classList.remove('fa-regular');
                        icon.classList.add('fa-solid');
                    }
                    Toast.success('Saved to favorites');
                } else {
                    btn.classList.remove('is-favorited');
                    btn.setAttribute('aria-pressed', 'false');
                    btn.setAttribute('aria-label', 'Add to favorites');
                    if (icon) {
                        icon.classList.remove('fa-solid');
                        icon.classList.add('fa-regular');
                    }
                    Toast.info('Removed from favorites');
                }
            } catch {
                Toast.error('Network error. Please try again.');
            } finally {
                btn.disabled = false;
            }
        });
    });
})();

// ─── Native Share API ─────────────────────────────────────────────────────────
(function initNativeShare() {
    const btn = document.getElementById('btnNativeShare');
    if (!btn || !navigator.share) return;

    btn.style.display = '';   // reveal only when API is available

    btn.addEventListener('click', async () => {
        const title = btn.dataset.shareTitle ?? document.title;
        const url   = btn.dataset.shareUrl   ?? window.location.href;

        try {
            await navigator.share({ title, url });
        } catch (err) {
            if (err.name !== 'AbortError') {
                Toast.error('Share failed. Please try another option.');
            }
        }
    });
})();
