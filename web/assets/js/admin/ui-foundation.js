'use strict';

(function initPtmdUiFoundation() {
    const shell = document.querySelector('.ptmd-admin-shell');

    const sidebarToggle = document.getElementById('sidebarToggle');
    sidebarToggle?.addEventListener('click', () => {
        shell?.classList.toggle('sidebar-open');
    });

    const commandPalette = document.getElementById('ptmdCommandPalette');
    const commandInput = document.getElementById('ptmdCommandInput');

    function openCommandPalette() {
        if (!commandPalette) return;
        commandPalette.classList.add('is-open');
        commandInput?.focus();
    }

    function closeCommandPalette() {
        commandPalette?.classList.remove('is-open');
    }

    document.querySelectorAll('[data-ptmd-command-open]').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            openCommandPalette();
        });
    });

    document.querySelectorAll('[data-ptmd-command-close]').forEach((btn) => {
        btn.addEventListener('click', closeCommandPalette);
    });

    commandPalette?.addEventListener('click', (e) => {
        if (e.target === commandPalette) closeCommandPalette();
    });

    document.addEventListener('keydown', (e) => {
        const isCmdK = (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k';
        if (isCmdK) {
            e.preventDefault();
            if (commandPalette?.classList.contains('is-open')) closeCommandPalette();
            else openCommandPalette();
        }

        if (e.key === 'Escape') {
            closeCommandPalette();
            document.querySelectorAll('.ptmd-drawer.is-open').forEach((d) => d.classList.remove('is-open'));
            document.querySelectorAll('.ptmd-modal.is-open').forEach((m) => m.classList.remove('is-open'));
        }
    });

    document.querySelectorAll('[data-drawer-target]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const drawer = document.querySelector(trigger.getAttribute('data-drawer-target'));
            drawer?.classList.add('is-open');
        });
    });

    document.querySelectorAll('[data-drawer-close]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            trigger.closest('.ptmd-drawer')?.classList.remove('is-open');
        });
    });

    document.querySelectorAll('[data-modal-target]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const modal = document.querySelector(trigger.getAttribute('data-modal-target'));
            modal?.classList.add('is-open');
        });
    });

    document.querySelectorAll('[data-modal-close]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            trigger.closest('.ptmd-modal')?.classList.remove('is-open');
        });
    });

    document.querySelectorAll('.ptmd-modal').forEach((modal) => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.classList.remove('is-open');
        });
    });

    document.querySelectorAll('[data-ptmd-tab]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const group = btn.closest('[data-ptmd-tab-group]');
            if (!group) return;

            group.querySelectorAll('[data-ptmd-tab]').forEach((tabBtn) => {
                tabBtn.setAttribute('aria-selected', String(tabBtn === btn));
            });

            const target = btn.getAttribute('data-ptmd-tab');
            const panelSelector = group.getAttribute('data-ptmd-tab-panel-prefix');
            if (!target || !panelSelector) return;

            document.querySelectorAll(`${panelSelector}`).forEach((panel) => {
                panel.hidden = panel.id !== target;
            });
        });
    });

    document.querySelectorAll('[data-view-switch]').forEach((switcher) => {
        switcher.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-view]');
            if (!btn) return;

            const targetSelector = switcher.getAttribute('data-view-switch');
            const target = targetSelector ? document.querySelector(targetSelector) : null;
            const view = btn.getAttribute('data-view');
            if (!target || !view) return;

            switcher.querySelectorAll('[data-view]').forEach((b) => b.setAttribute('aria-pressed', String(b === btn)));
            target.setAttribute('data-view-mode', view);
        });
    });
})();
