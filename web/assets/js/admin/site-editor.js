/**
 * PTMD Admin — Site Editor: homepage module drag-and-drop + toggle
 *
 * Loaded only on admin/site-editor.php via $extraScripts.
 * Depends on: no external libraries (plain DOM).
 */
(() => {
    const list        = document.getElementById('siteModuleList');
    const orderInput  = document.getElementById('moduleOrderInput');
    if (!list || !orderInput) return;

    /** Rebuild the hidden comma-separated order input from current DOM order. */
    function updateOrderInput() {
        const order = [...list.querySelectorAll('[data-module-id]')]
            .map(el => el.dataset.moduleId)
            .filter(Boolean);
        orderInput.value = order.join(',');
    }

    /** Mirror enabled/disabled state onto the item for CSS opacity. */
    function syncDisabledState() {
        list.querySelectorAll('.ptmd-module-item').forEach((item) => {
            const checked = item.querySelector('.module-toggle')?.checked;
            item.classList.toggle('is-disabled', !checked);
        });
    }

    // ── Drag-and-drop reorder ─────────────────────────────────────────────────
    let draggingItem = null;

    list.addEventListener('dragstart', (e) => {
        const item = e.target.closest('.ptmd-module-item');
        if (!item) return;
        draggingItem = item;
        item.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    });

    list.addEventListener('dragend', () => {
        if (draggingItem) draggingItem.classList.remove('dragging');
        draggingItem = null;
        updateOrderInput();
    });

    list.addEventListener('dragover', (e) => {
        e.preventDefault();
        const overItem = e.target.closest('.ptmd-module-item');
        if (!draggingItem || !overItem || overItem === draggingItem) return;

        const rect  = overItem.getBoundingClientRect();
        const after = e.clientY > rect.top + rect.height / 2;
        if (after) {
            overItem.after(draggingItem);
        } else {
            overItem.before(draggingItem);
        }
    });

    // ── Up / Down arrow buttons ───────────────────────────────────────────────
    list.addEventListener('click', (e) => {
        const upBtn   = e.target.closest('.module-up');
        const downBtn = e.target.closest('.module-down');
        if (!upBtn && !downBtn) return;

        const item = e.target.closest('.ptmd-module-item');
        if (!item) return;

        if (upBtn && item.previousElementSibling) {
            item.previousElementSibling.before(item);
            updateOrderInput();
        }

        if (downBtn && item.nextElementSibling) {
            item.nextElementSibling.after(item);
            updateOrderInput();
        }
    });

    // ── Toggle switch ─────────────────────────────────────────────────────────
    list.addEventListener('change', (e) => {
        if (!e.target.closest('.module-toggle')) return;
        syncDisabledState();
    });

    // Initialise
    updateOrderInput();
    syncDisabledState();
})();
